#!/usr/bin/env php
<?php
/* ============================================
   НАЙДУК — Воркер AI-генерации
   Версия 2.0 — Redis очередь, idempotency, fallback, EXIF strip, placeholder
   ============================================ */

if (php_sapi_name() !== 'cli') {
    die("This script can only be run from command line.");
}

// Загрузка автозагрузчика Composer
require_once __DIR__ . '/../../vendor/autoload.php';

// Подключение основных файлов
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../services/Database.php';
require_once __DIR__ . '/../../services/ImageOptimizer.php';

// Конфигурация
define('REDIS_QUEUE', 'ai_generation_queue');
define('MAX_RETRIES', 5);
define('BASE_DELAY', 1);
define('MAX_CONCURRENT', 2);
define('PLACEHOLDER_DIR', __DIR__ . '/../../uploads/placeholder/');
define('PLACEHOLDER_FILE', 'naiduk_smile.png');

// Создаём placeholder-директорию, если нет
if (!is_dir(PLACEHOLDER_DIR)) {
    mkdir(PLACEHOLDER_DIR, 0755, true);
}
// Если placeholder отсутствует, создаём простой PNG
if (!file_exists(PLACEHOLDER_DIR . PLACEHOLDER_FILE)) {
    $img = imagecreatetruecolor(400, 400);
    $bg = imagecolorallocate($img, 240, 240, 245);
    $textColor = imagecolorallocate($img, 74, 144, 226);
    imagefilledrectangle($img, 0, 0, 400, 400, $bg);
    imagettftext($img, 20, 0, 100, 200, $textColor, __DIR__ . '/../../assets/fonts/arial.ttf', 'Найдук');
    imagepng($img, PLACEHOLDER_DIR . PLACEHOLDER_FILE);
    imagedestroy($img);
}

// Подключение к Redis
$redis = new Redis();
$redis->connect('127.0.0.1', 6379);
if (!$redis->isConnected()) {
    die("Redis not connected\n");
}

$db = Database::getInstance();
$pdo = $db->getPdo();

// Обработчик сигналов для graceful shutdown
declare(ticks = 1);
pcntl_signal(SIGTERM, 'shutdown');
pcntl_signal(SIGINT, 'shutdown');
$running = true;

function shutdown($signo) {
    global $running;
    echo "Signal $signo received, shutting down...\n";
    $running = false;
}

/**
 * Генерация изображения через выбранный API с fallback цепочкой
 */
function generateImage($prompt, $generator, &$usedProvider) {
    $providers = [
        'banana' => [
            'name' => 'Banana 2 (Gemini)',
            'call' => function($prompt) {
                $apiKey = getenv('BANANA_API_KEY') ?: '';
                if (!$apiKey) return null;
                $url = "https://generativelanguage.googleapis.com/v1beta/models/gemini-2.0-flash-exp-image-generation:predict?key=$apiKey";
                $payload = ['prompt' => $prompt, 'number_of_images' => 1, 'aspect_ratio' => '1:1', 'resolution' => '1024x1024'];
                $ch = curl_init($url);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_POST, true);
                curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
                $response = curl_exec($ch);
                $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                curl_close($ch);
                if ($httpCode === 200) {
                    $data = json_decode($response, true);
                    if (isset($data['predictions'][0]['image']['data'])) {
                        return $data['predictions'][0]['image']['data']; // base64
                    }
                }
                return null;
            }
        ],
        'deepseek' => [
            'name' => 'DeepSeek Janus-Pro-7B',
            'call' => function($prompt) {
                $apiKey = getenv('DEEPSEEK_API_KEY') ?: '';
                if (!$apiKey) return null;
                $url = "https://api.deepseek.com/v1/images/generations";
                $payload = ['model' => 'janus-pro-7b', 'prompt' => $prompt, 'n' => 1, 'size' => '1024x1024'];
                $ch = curl_init($url);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_POST, true);
                curl_setopt($ch, CURLOPT_HTTPHEADER, [
                    'Content-Type: application/json',
                    'Authorization: Bearer ' . $apiKey
                ]);
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
                $response = curl_exec($ch);
                $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                curl_close($ch);
                if ($httpCode === 200) {
                    $data = json_decode($response, true);
                    if (isset($data['data'][0]['url'])) {
                        return $data['data'][0]['url']; // URL
                    }
                }
                return null;
            }
        ],
        'huggingface' => [
            'name' => 'HuggingFace Stable Diffusion',
            'call' => function($prompt) {
                $apiKey = getenv('HF_API_KEY') ?: '';
                if (!$apiKey) return null;
                $url = "https://api-inference.huggingface.co/models/stabilityai/stable-diffusion-xl-base-1.0";
                $payload = ['inputs' => $prompt];
                $ch = curl_init($url);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_POST, true);
                curl_setopt($ch, CURLOPT_HTTPHEADER, [
                    'Content-Type: application/json',
                    'Authorization: Bearer ' . $apiKey
                ]);
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
                $response = curl_exec($ch);
                $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                curl_close($ch);
                if ($httpCode === 200) {
                    return $response; // бинарные данные
                }
                return null;
            }
        ],
        'pexels' => [
            'name' => 'Pexels (stock photo)',
            'call' => function($prompt) {
                $apiKey = getenv('PEXELS_API_KEY') ?: '';
                if (!$apiKey) return null;
                $url = "https://api.pexels.com/v1/search?query=" . urlencode($prompt) . "&per_page=1";
                $ch = curl_init($url);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_HTTPHEADER, ['Authorization: ' . $apiKey]);
                $response = curl_exec($ch);
                $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                curl_close($ch);
                if ($httpCode === 200) {
                    $data = json_decode($response, true);
                    if (isset($data['photos'][0]['src']['large'])) {
                        return $data['photos'][0]['src']['large'];
                    }
                }
                return null;
            }
        ],
        'pixabay' => [
            'name' => 'Pixabay (stock photo)',
            'call' => function($prompt) {
                $apiKey = getenv('PIXABAY_API_KEY') ?: '';
                if (!$apiKey) return null;
                $url = "https://pixabay.com/api/?key=$apiKey&q=" . urlencode($prompt) . "&per_page=1";
                $ch = curl_init($url);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                $response = curl_exec($ch);
                $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                curl_close($ch);
                if ($httpCode === 200) {
                    $data = json_decode($response, true);
                    if (isset($data['hits'][0]['largeImageURL'])) {
                        return $data['hits'][0]['largeImageURL'];
                    }
                }
                return null;
            }
        ]
    ];

    $usedProvider = null;
    foreach ($providers as $key => $provider) {
        if ($generator !== null && $key !== $generator) continue;
        $result = $provider['call']($prompt);
        if ($result) {
            $usedProvider = $key;
            return $result;
        }
    }
    return null;
}

/**
 * Сохранение изображения с удалением EXIF и сохранением цветового профиля
 */
function saveImage($imageData, $prompt, $generator, $listingId = null) {
    // Определяем тип данных: base64, URL или бинарные
    if (preg_match('/^data:image\/(\w+);base64,/', $imageData)) {
        $imageData = preg_replace('/^data:image\/\w+;base64,/', '', $imageData);
        $imageData = base64_decode($imageData);
    } elseif (filter_var($imageData, FILTER_VALIDATE_URL)) {
        $imageData = file_get_contents($imageData);
        if ($imageData === false) return null;
    }

    $tempFile = tempnam(sys_get_temp_dir(), 'ai_img_');
    file_put_contents($tempFile, $imageData);

    // Используем ImageOptimizer для оптимизации и удаления EXIF
    $optimizer = new ImageOptimizer(__DIR__ . '/../../uploads/');
    $result = $optimizer->optimize($tempFile, [
        'user_id' => 0,
        'generate_sizes' => [800],
        'generate_lqip' => false,
        'quality_profile' => 'product'
    ]);
    unlink($tempFile);

    if ($result['success'] && isset($result['sizes'][800]['webp'])) {
        $finalPath = $result['sizes'][800]['webp'];
        // Дополнительно удаляем EXIF (если ImageOptimizer не удалил)
        if (extension_loaded('imagick')) {
            $img = new Imagick($finalPath);
            $profiles = $img->getImageProfiles("icc", true);
            $img->stripImage();
            if (!empty($profiles)) {
                $img->profileImage("icc", $profiles['icc']);
            }
            $img->writeImage($finalPath);
            $img->clear();
        }
        return $finalPath;
    }

    return null;
}

/**
 * Вставка placeholder-изображения
 */
function setPlaceholder($listingId) {
    $placeholderPath = PLACEHOLDER_DIR . PLACEHOLDER_FILE;
    $targetDir = __DIR__ . '/../../uploads/listings/' . $listingId . '/';
    if (!is_dir($targetDir)) mkdir($targetDir, 0755, true);
    $targetFile = $targetDir . 'placeholder_' . time() . '.webp';
    copy($placeholderPath, $targetFile);
    return '/uploads/listings/' . $listingId . '/' . basename($targetFile);
}

// Основной цикл
echo "AI worker started, PID: " . getmypid() . "\n";

while ($running) {
    // Получаем задачу из очереди (блокирующее чтение с таймаутом 10 сек)
    $jobData = $redis->brpop(REDIS_QUEUE, 10);
    if (!$jobData) continue;

    list($queue, $jobJson) = $jobData;
    $job = json_decode($jobJson, true);
    if (!$job || empty($job['job_id'])) {
        echo "Invalid job data\n";
        continue;
    }

    $jobId = $job['job_id'];
    $listingId = $job['listing_id'] ?? null;
    $prompt = $job['prompt'];
    $generator = $job['generator'] ?? null;
    $idempotencyKey = $job['idempotency_key'] ?? null;

    // Проверка идемпотентности
    if ($idempotencyKey) {
        $existing = $db->fetchOne("SELECT result_path FROM ai_generation_jobs WHERE idempotency_key = ? AND status = 'completed'", [$idempotencyKey]);
        if ($existing && !empty($existing['result_path'])) {
            // Уже было, пропускаем
            $db->updateAIGenerationJob($jobId, ['status' => 'completed', 'result_path' => $existing['result_path'], 'finished_at' => date('Y-m-d H:i:s')]);
            continue;
        }
    }

    $db->updateAIGenerationJob($jobId, ['status' => 'processing', 'started_at' => date('Y-m-d H:i:s')]);
    $db->logAIEvent($jobId, $generator ?? 'unknown', 'start', ['prompt' => $prompt]);

    $attempt = 0;
    $success = false;
    $imageData = null;
    $usedProvider = null;

    while ($attempt < MAX_RETRIES && !$success) {
        $imageData = generateImage($prompt, $generator, $usedProvider);
        if ($imageData) {
            $success = true;
        } else {
            $attempt++;
            if ($attempt < MAX_RETRIES) {
                $delay = BASE_DELAY * pow(2, $attempt) + rand(0, 500000) / 1000000;
                usleep($delay * 1000000);
            }
        }
    }

    if ($success) {
        $savedPath = saveImage($imageData, $prompt, $usedProvider, $listingId);
        if ($savedPath) {
            // Привязываем к объявлению, если есть
            if ($listingId) {
                $db->insert('listing_photos', [
                    'listing_id' => $listingId,
                    'url' => $savedPath,
                    'sort_order' => 0,
                    'created_at' => date('Y-m-d H:i:s')
                ]);
            }
            $db->updateAIGenerationJob($jobId, [
                'status' => 'completed',
                'result_path' => $savedPath,
                'finished_at' => date('Y-m-d H:i:s')
            ]);
            $db->logAIEvent($jobId, $usedProvider, 'success', ['path' => $savedPath]);
            echo "Job $jobId completed\n";
        } else {
            // Не удалось сохранить – ставим placeholder
            $placeholderPath = setPlaceholder($listingId);
            if ($listingId) {
                $db->insert('listing_photos', [
                    'listing_id' => $listingId,
                    'url' => $placeholderPath,
                    'sort_order' => 0,
                    'created_at' => date('Y-m-d H:i:s')
                ]);
            }
            $db->updateAIGenerationJob($jobId, [
                'status' => 'failed',
                'error' => 'Failed to save generated image',
                'finished_at' => date('Y-m-d H:i:s')
            ]);
            $db->logAIEvent($jobId, $usedProvider, 'save_failed', []);
            echo "Job $jobId failed to save\n";
        }
    } else {
        // Все попытки не удались – ставим placeholder
        $placeholderPath = setPlaceholder($listingId);
        if ($listingId) {
            $db->insert('listing_photos', [
                'listing_id' => $listingId,
                'url' => $placeholderPath,
                'sort_order' => 0,
                'created_at' => date('Y-m-d H:i:s')
            ]);
        }
        $db->updateAIGenerationJob($jobId, [
            'status' => 'failed',
            'error' => 'All AI providers failed after ' . MAX_RETRIES . ' attempts',
            'finished_at' => date('Y-m-d H:i:s')
        ]);
        $db->logAIEvent($jobId, null, 'all_failed', []);
        echo "Job $jobId failed\n";
    }
}

echo "Worker stopped\n";