#!/usr/bin/env php
<?php
/* ============================================
   НАЙДУК — Воркер пересчёта рейтинга пользователей
   Запуск: через supervisor (постоянно работает)
   Обрабатывает задачи из Redis очереди rating_recalc_queue
   ============================================ */

if (php_sapi_name() !== 'cli') {
    die("CLI only");
}

require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../services/Database.php';

define('LOG_FILE', __DIR__ . '/../storage/logs/rating_worker.log');
define('QUEUE_KEY', 'rating_recalc_queue');

// Подключение к Redis
$redis = new Redis();
$redis->connect('127.0.0.1', 6379);
if (!$redis->isConnected()) {
    die("Redis not connected\n");
}

$db = Database::getInstance();
$pdo = $db->getPdo();

/**
 * Логирование в файл
 */
function logMessage($message, $level = 'INFO') {
    $log = '[' . date('Y-m-d H:i:s') . "] [$level] $message" . PHP_EOL;
    file_put_contents(LOG_FILE, $log, FILE_APPEND | LOCK_EX);
}

/**
 * Пересчитывает и обновляет рейтинг пользователя
 */
function recalcUserRating($pdo, $userId) {
    // Получаем все видимые отзывы на пользователя
    $stmt = $pdo->prepare("SELECT rating FROM reviews WHERE reviewed_id = ? AND is_visible = 1");
    $stmt->execute([$userId]);
    $ratings = $stmt->fetchAll(PDO::FETCH_COLUMN);

    if (empty($ratings)) {
        $avg = 0;
        $total = 0;
        $distribution = [];
    } else {
        $total = count($ratings);
        $sum = array_sum($ratings);
        $avg = round($sum / $total, 2);
        $distribution = [];
        foreach ($ratings as $r) {
            $distribution[$r] = ($distribution[$r] ?? 0) + 1;
        }
    }

    // Получаем количество отзывов за последние 30 дней
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM reviews WHERE reviewed_id = ? AND is_visible = 1 AND created_at > NOW() - INTERVAL 30 DAY");
    $stmt->execute([$userId]);
    $last30 = $stmt->fetchColumn();

    // Обновляем запись в user_ratings
    $pdo->prepare("
        INSERT INTO user_ratings (user_id, avg_rating, total_reviews, rating_distribution, last_30_days_count, updated_at)
        VALUES (?, ?, ?, ?, ?, NOW())
        ON DUPLICATE KEY UPDATE
            avg_rating = VALUES(avg_rating),
            total_reviews = VALUES(total_reviews),
            rating_distribution = VALUES(rating_distribution),
            last_30_days_count = VALUES(last_30_days_count),
            updated_at = NOW()
    ")->execute([$userId, $avg, $total, json_encode($distribution), $last30]);

    return ['avg' => $avg, 'total' => $total];
}

logMessage("Rating recalc worker started (PID: " . getmypid() . ")");

// Бесконечный цикл
while (true) {
    // Блокирующее чтение очереди (ожидание 30 секунд)
    $job = $redis->blpop(QUEUE_KEY, 30);
    if (!$job) {
        // Таймаут – просто продолжаем
        continue;
    }

    list($queue, $jobData) = $job;
    $data = json_decode($jobData, true);
    if (!$data || empty($data['user_id'])) {
        logMessage("Invalid job data: " . $jobData, 'ERROR');
        continue;
    }

    $userId = (int)$data['user_id'];
    logMessage("Processing recalc for user $userId");

    try {
        $result = recalcUserRating($pdo, $userId);
        logMessage("User $userId recalculated: avg={$result['avg']}, total={$result['total']}");
    } catch (Exception $e) {
        logMessage("Error recalculating user $userId: " . $e->getMessage(), 'ERROR');
        // Можно добавить задачу обратно в очередь с задержкой
        $redis->rpush(QUEUE_KEY, json_encode(['user_id' => $userId, 'retry' => 1, 'time' => time()]));
    }

    // Небольшая пауза, чтобы не перегружать процессор
    usleep(10000);
}