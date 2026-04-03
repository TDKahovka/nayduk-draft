<?php
/* ============================================
   НАЙДУК — API загрузки фотографий для объявлений
   Версия 6.0 (март 2026)
   - Redis для rate limiting (файловый fallback)
   - Кэширование списка фото (Redis/файлы)
   - Транзакции с блокировкой для лимита фото
   - Улучшенная безопасность (path traversal, валидация)
   - Конфигурируемый лимит через .env
   - Коды ошибок для фронтенда
   ============================================ */

session_start();
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../services/Database.php';
require_once __DIR__ . '/../../services/ImageOptimizer.php';

header('Content-Type: application/json');

// ==================== КОНСТАНТЫ ====================
define('MAX_PHOTOS_PER_LISTING', getenv('MAX_PHOTOS_PER_LISTING') ?: 5);
define('PHOTO_RATE_LIMIT', 20);
define('PHOTO_RATE_WINDOW', 3600);
define('PHOTO_CACHE_TTL', 300); // 5 минут

// ==================== ПРОВЕРКА МЕТОДА ====================
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed', 'error_code' => 'METHOD_NOT_ALLOWED']);
    exit;
}

// ==================== АВТОРИЗАЦИЯ ====================
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Требуется авторизация', 'error_code' => 'UNAUTHORIZED']);
    exit;
}

$userId = (int)$_SESSION['user_id'];
$ip = $_SERVER['HTTP_CF_CONNECTING_IP'] ?? $_SERVER['REMOTE_ADDR'] ?? '';

$db = Database::getInstance();
$pdo = $db->getPdo();

// ==================== АВТО-СОЗДАНИЕ ТАБЛИЦЫ ====================
$pdo->exec("
    CREATE TABLE IF NOT EXISTS listing_photos (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        listing_id BIGINT UNSIGNED NOT NULL,
        url VARCHAR(500) NOT NULL,
        sort_order INT DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (listing_id) REFERENCES listings(id) ON DELETE CASCADE,
        INDEX idx_listing (listing_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
");

// ==================== ВСПОМОГАТЕЛЬНЫЕ ФУНКЦИИ ====================

function checkRateLimitPhoto($userId, $ip, $limit = PHOTO_RATE_LIMIT, $window = PHOTO_RATE_WINDOW) {
    static $redis = null;
    if ($redis === null) {
        $redis = class_exists('Redis') ? new Redis() : null;
        if ($redis) {
            try {
                $redis->connect('127.0.0.1', 6379, 1);
                $redis->ping();
            } catch (Exception $e) {
                $redis = null;
            }
        }
    }
    $key = "rate:photos:{$userId}:" . md5($ip);
    if ($redis) {
        $count = $redis->incr($key);
        if ($count == 1) $redis->expire($key, $window);
        return $count <= $limit;
    }
    // Файловый fallback
    $file = __DIR__ . '/../../storage/rate/photos_' . $userId . '_' . md5($ip) . '.txt';
    $dir = dirname($file);
    if (!is_dir($dir)) mkdir($dir, 0755, true);
    $now = time();
    $data = [];
    if (file_exists($file)) {
        $data = json_decode(file_get_contents($file), true);
        if (!is_array($data)) $data = [];
    }
    $data = array_filter($data, fn($t) => $t > $now - $window);
    if (count($data) >= $limit) return false;
    $data[] = $now;
    file_put_contents($file, json_encode($data), LOCK_EX);
    return true;
}

function cacheGet($key, $ttl = 3600) {
    static $redis = null;
    if ($redis === null) {
        $redis = class_exists('Redis') ? new Redis() : null;
        if ($redis) {
            try {
                $redis->connect('127.0.0.1', 6379, 1);
                $redis->ping();
            } catch (Exception $e) {
                $redis = null;
            }
        }
    }
    if ($redis) {
        $val = $redis->get($key);
        return $val !== false ? json_decode($val, true) : null;
    }
    $file = __DIR__ . '/../../storage/cache/' . md5($key) . '.json';
    if (!file_exists($file)) return null;
    $data = json_decode(file_get_contents($file), true);
    if (!$data || !isset($data['expires']) || $data['expires'] < time()) {
        @unlink($file);
        return null;
    }
    return $data['value'];
}

function cacheSet($key, $value, $ttl = 3600) {
    static $redis = null;
    if ($redis === null) {
        $redis = class_exists('Redis') ? new Redis() : null;
        if ($redis) {
            try {
                $redis->connect('127.0.0.1', 6379, 1);
                $redis->ping();
            } catch (Exception $e) {
                $redis = null;
            }
        }
    }
    if ($redis) {
        $redis->setex($key, $ttl, json_encode($value));
        return;
    }
    $file = __DIR__ . '/../../storage/cache/' . md5($key) . '.json';
    $dir = dirname($file);
    if (!is_dir($dir)) mkdir($dir, 0755, true);
    $data = [
        'expires' => time() + $ttl,
        'value' => $value
    ];
    file_put_contents($file, json_encode($data), LOCK_EX);
}

function cacheDelete($key) {
    static $redis = null;
    if ($redis === null) {
        $redis = class_exists('Redis') ? new Redis() : null;
        if ($redis) {
            try {
                $redis->connect('127.0.0.1', 6379, 1);
                $redis->ping();
            } catch (Exception $e) {
                $redis = null;
            }
        }
    }
    if ($redis) {
        $redis->del($key);
    }
    $file = __DIR__ . '/../../storage/cache/' . md5($key) . '.json';
    @unlink($file);
}

function invalidatePhotosCache($listingId) {
    $cacheKey = "photos:listing:{$listingId}";
    cacheDelete($cacheKey);
}

function getPhotoCountWithLock($pdo, $listingId) {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM listing_photos WHERE listing_id = ? FOR UPDATE");
    $stmt->execute([$listingId]);
    return (int)$stmt->fetchColumn();
}

function isPathSafe($path, $baseDir) {
    $realBase = realpath($baseDir);
    if ($realBase === false) return false;
    $realPath = realpath(dirname($path));
    return $realPath !== false && strpos($realPath, $realBase) === 0;
}

function deleteAllRelatedFiles($filePath) {
    $dir = dirname($filePath);
    $basename = pathinfo($filePath, PATHINFO_FILENAME);
    $pattern = $dir . '/' . preg_quote($basename, '/') . '.*';
    foreach (glob($pattern) as $f) {
        @unlink($f);
    }
}

// ==================== ОБРАБОТКА ДЕЙСТВИЙ ====================
$input = $_POST ?: json_decode(file_get_contents('php://input'), true);
$action = $input['action'] ?? 'upload';
$allowedActions = ['upload', 'delete', 'list', 'set_main', 'set_order'];
if (!in_array($action, $allowedActions)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Неизвестное действие', 'error_code' => 'UNKNOWN_ACTION']);
    exit;
}

// CSRF защита (кроме list)
if ($action !== 'list') {
    $csrfToken = $input['csrf_token'] ?? ($_POST['csrf_token'] ?? '');
    if (!verify_csrf_token($csrfToken)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Недействительный токен безопасности', 'error_code' => 'INVALID_CSRF']);
        exit;
    }
}

// Rate limiting (кроме list)
if ($action !== 'list') {
    if (!checkRateLimitPhoto($userId, $ip, PHOTO_RATE_LIMIT, PHOTO_RATE_WINDOW)) {
        http_response_code(429);
        echo json_encode(['success' => false, 'error' => 'Слишком много запросов. Попробуйте позже.', 'error_code' => 'RATE_LIMIT_EXCEEDED']);
        exit;
    }
}

switch ($action) {
    case 'upload':
        $listingId = isset($input['listing_id']) ? (int)$input['listing_id'] : 0;
        if (!$listingId) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Не указан ID объявления', 'error_code' => 'MISSING_LISTING_ID']);
            exit;
        }

        // Проверка прав
        $stmt = $pdo->prepare("SELECT user_id FROM listings WHERE id = ?");
        $stmt->execute([$listingId]);
        $listing = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$listing || $listing['user_id'] != $userId) {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'Доступ запрещён', 'error_code' => 'FORBIDDEN']);
            exit;
        }

        if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Файл не загружен', 'error_code' => 'FILE_UPLOAD_ERROR']);
            exit;
        }
        $file = $_FILES['file'];

        // Проверка MIME через finfo
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime = finfo_file($finfo, $file['tmp_name']);
        finfo_close($finfo);
        $allowedMimes = ['image/jpeg', 'image/png', 'image/webp', 'image/gif', 'image/avif'];
        if (!in_array($mime, $allowedMimes, true)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Недопустимый формат. Разрешены: JPEG, PNG, WebP, GIF, AVIF', 'error_code' => 'INVALID_MIME']);
            exit;
        }

        // Проверка размера (макс 10 МБ)
        if ($file['size'] > 10 * 1024 * 1024) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Файл слишком большой (макс 10 МБ)', 'error_code' => 'FILE_TOO_LARGE']);
            exit;
        }

        // Проверка, что это действительно изображение
        $imageInfo = @getimagesize($file['tmp_name']);
        if ($imageInfo === false) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Файл не является изображением', 'error_code' => 'NOT_IMAGE']);
            exit;
        }

        // Транзакция для атомарной проверки лимита
        $pdo->beginTransaction();
        try {
            $currentCount = getPhotoCountWithLock($pdo, $listingId);
            if ($currentCount >= MAX_PHOTOS_PER_LISTING) {
                $pdo->rollBack();
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'Нельзя загрузить более ' . MAX_PHOTOS_PER_LISTING . ' фото на одно объявление', 'error_code' => 'PHOTO_LIMIT_REACHED']);
                exit;
            }

            // Создаём директорию
            $uploadDir = __DIR__ . '/../../uploads/listings/' . $listingId . '/';
            if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);

            // Безопасное имя файла
            $originalName = pathinfo($file['name'], PATHINFO_FILENAME);
            $safeName = preg_replace('/[^a-zA-Z0-9_-]/', '', $originalName);
            if (empty($safeName)) $safeName = 'image';
            $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
            $filename = $safeName . '_' . uniqid() . '.' . $ext;
            $targetPath = $uploadDir . $filename;

            if (!move_uploaded_file($file['tmp_name'], $targetPath)) {
                throw new Exception('move_uploaded_file failed');
            }

            $realUploads = realpath(__DIR__ . '/../../uploads/');
            if (!isPathSafe($targetPath, $realUploads)) {
                @unlink($targetPath);
                throw new Exception('path traversal');
            }

            // Оптимизация через ImageOptimizer
            $optimizedPath = $targetPath;
            if (class_exists('ImageOptimizer')) {
                $optimizer = new ImageOptimizer(__DIR__ . '/../../uploads/');
                $result = $optimizer->optimize($targetPath, [
                    'width' => 1600,
                    'height' => 1600,
                    'quality' => 85,
                    'create_thumb' => true,
                    'thumb_size' => 400,
                    'user_id' => $userId
                ]);
                if ($result['success'] && isset($result['optimized_path']) && $result['optimized_path'] !== $targetPath) {
                    @unlink($targetPath);
                    $optimizedPath = $result['optimized_path'];
                }
            }

            $relativePath = str_replace(realpath(__DIR__ . '/../..'), '', $optimizedPath);
            $relativePath = str_replace('\\', '/', $relativePath);

            // Вставляем запись
            $maxSort = $pdo->prepare("SELECT COALESCE(MAX(sort_order), 0) + 1 FROM listing_photos WHERE listing_id = ? FOR UPDATE");
            $maxSort->execute([$listingId]);
            $nextSort = (int)$maxSort->fetchColumn();
            $stmt = $pdo->prepare("INSERT INTO listing_photos (listing_id, url, sort_order) VALUES (?, ?, ?)");
            $stmt->execute([$listingId, $relativePath, $nextSort]);
            $photoId = $pdo->lastInsertId();

            $pdo->commit();

            // Логируем
            $db->insert('security_logs', [
                'user_id' => $userId,
                'ip_address' => $ip,
                'event_type' => 'photo_uploaded',
                'description' => "Загружено фото #$photoId для объявления #$listingId, размер: " . $file['size'] . " байт, MIME: $mime",
                'severity' => 'low',
                'created_at' => date('Y-m-d H:i:s')
            ]);

            // Сброс кэша
            invalidatePhotosCache($listingId);

            echo json_encode([
                'success' => true,
                'photo_id' => $photoId,
                'url' => $relativePath,
                'message' => 'Фото загружено'
            ]);
        } catch (Exception $e) {
            $pdo->rollBack();
            error_log("Photo upload error: " . $e->getMessage());
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => 'Ошибка при загрузке фото', 'error_code' => 'UPLOAD_FAILED']);
        }
        break;

    case 'delete':
        $photoId = isset($input['photo_id']) ? (int)$input['photo_id'] : 0;
        if (!$photoId) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Не указан ID фото', 'error_code' => 'MISSING_PHOTO_ID']);
            exit;
        }

        $stmt = $pdo->prepare("
            SELECT p.*, l.user_id 
            FROM listing_photos p
            JOIN listings l ON p.listing_id = l.id
            WHERE p.id = ?
        ");
        $stmt->execute([$photoId]);
        $photo = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$photo) {
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => 'Фото не найдено', 'error_code' => 'PHOTO_NOT_FOUND']);
            exit;
        }
        if ($photo['user_id'] != $userId) {
            $user = $db->fetchOne("SELECT role FROM users WHERE id = ?", [$userId]);
            if (!$user || $user['role'] !== 'admin') {
                http_response_code(403);
                echo json_encode(['success' => false, 'error' => 'Доступ запрещён', 'error_code' => 'FORBIDDEN']);
                exit;
            }
        }

        $filePath = __DIR__ . '/../..' . $photo['url'];
        if (file_exists($filePath)) {
            deleteAllRelatedFiles($filePath);
        }

        $stmt = $pdo->prepare("DELETE FROM listing_photos WHERE id = ?");
        $stmt->execute([$photoId]);

        $db->insert('security_logs', [
            'user_id' => $userId,
            'ip_address' => $ip,
            'event_type' => 'photo_deleted',
            'description' => "Удалено фото #$photoId из объявления #{$photo['listing_id']}",
            'severity' => 'low',
            'created_at' => date('Y-m-d H:i:s')
        ]);

        invalidatePhotosCache($photo['listing_id']);

        echo json_encode(['success' => true, 'message' => 'Фото удалено']);
        break;

    case 'list':
        $listingId = isset($input['listing_id']) ? (int)$input['listing_id'] : 0;
        if (!$listingId) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Не указан ID объявления', 'error_code' => 'MISSING_LISTING_ID']);
            exit;
        }

        $cacheKey = "photos:listing:{$listingId}";
        $cached = cacheGet($cacheKey, PHOTO_CACHE_TTL);
        if ($cached !== null) {
            echo $cached;
            exit;
        }

        $stmt = $pdo->prepare("SELECT id, url, sort_order FROM listing_photos WHERE listing_id = ? ORDER BY sort_order");
        $stmt->execute([$listingId]);
        $photos = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $response = json_encode(['success' => true, 'data' => $photos]);
        cacheSet($cacheKey, $response, PHOTO_CACHE_TTL);
        echo $response;
        break;

    case 'set_main':
        $photoId = isset($input['photo_id']) ? (int)$input['photo_id'] : 0;
        if (!$photoId) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Не указан ID фото', 'error_code' => 'MISSING_PHOTO_ID']);
            exit;
        }

        $stmt = $pdo->prepare("
            SELECT p.*, l.user_id 
            FROM listing_photos p
            JOIN listings l ON p.listing_id = l.id
            WHERE p.id = ?
        ");
        $stmt->execute([$photoId]);
        $photo = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$photo) {
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => 'Фото не найдено', 'error_code' => 'PHOTO_NOT_FOUND']);
            exit;
        }
        if ($photo['user_id'] != $userId) {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'Доступ запрещён', 'error_code' => 'FORBIDDEN']);
            exit;
        }

        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare("UPDATE listing_photos SET sort_order = sort_order + 1 WHERE listing_id = ? AND sort_order <= ?");
            $stmt->execute([$photo['listing_id'], $photo['sort_order']]);
            $stmt = $pdo->prepare("UPDATE listing_photos SET sort_order = 0 WHERE id = ?");
            $stmt->execute([$photoId]);
            $pdo->commit();
        } catch (Exception $e) {
            $pdo->rollBack();
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => 'Ошибка при обновлении порядка', 'error_code' => 'ORDER_UPDATE_FAILED']);
            exit;
        }

        invalidatePhotosCache($photo['listing_id']);
        echo json_encode(['success' => true, 'message' => 'Главное фото обновлено']);
        break;

    case 'set_order':
        $orders = $input['orders'] ?? [];
        if (!is_array($orders) || empty($orders)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Не передан массив сортировки', 'error_code' => 'MISSING_ORDERS']);
            exit;
        }

        // Валидация sort_order
        foreach ($orders as $order) {
            if (!isset($order['id']) || !isset($order['sort_order']) || $order['sort_order'] < 0) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'Некорректные данные сортировки', 'error_code' => 'INVALID_ORDER_DATA']);
                exit;
            }
        }

        $photoIds = array_column($orders, 'id');
        $placeholders = implode(',', array_fill(0, count($photoIds), '?'));
        $stmt = $pdo->prepare("
            SELECT p.id, l.user_id, p.listing_id
            FROM listing_photos p
            JOIN listings l ON p.listing_id = l.id
            WHERE p.id IN ($placeholders)
        ");
        $stmt->execute($photoIds);
        $photosData = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (count($photosData) !== count($photoIds)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Некоторые фото не найдены', 'error_code' => 'PHOTOS_NOT_FOUND']);
            exit;
        }
        $listingId = null;
        foreach ($photosData as $p) {
            if ($p['user_id'] != $userId) {
                http_response_code(403);
                echo json_encode(['success' => false, 'error' => 'Доступ запрещён к одному из фото', 'error_code' => 'FORBIDDEN']);
                exit;
            }
            if ($listingId === null) $listingId = $p['listing_id'];
            elseif ($listingId != $p['listing_id']) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'Фото из разных объявлений', 'error_code' => 'MIXED_LISTINGS']);
                exit;
            }
        }

        $pdo->beginTransaction();
        try {
            $updateStmt = $pdo->prepare("UPDATE listing_photos SET sort_order = ? WHERE id = ?");
            foreach ($orders as $order) {
                $updateStmt->execute([$order['sort_order'], $order['id']]);
            }
            $pdo->commit();
        } catch (Exception $e) {
            $pdo->rollBack();
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => 'Ошибка при обновлении порядка', 'error_code' => 'ORDER_UPDATE_FAILED']);
            exit;
        }

        invalidatePhotosCache($listingId);
        echo json_encode(['success' => true, 'message' => 'Порядок фото обновлён']);
        break;

    default:
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Неизвестное действие', 'error_code' => 'UNKNOWN_ACTION']);
        break;
}