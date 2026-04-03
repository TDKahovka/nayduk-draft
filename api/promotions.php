<?php
/* ============================================
   НАЙДУК — API реферальных предложений (promotions) v3.0
   - Показы: атомарный инкремент в Redis (или файловый fallback)
   - Клики: синхронное обновление БД
   - Кэширование списка
   ============================================ */

session_start();
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../services/Database.php';

header('Content-Type: application/json');

$db = Database::getInstance();
$pdo = $db->getPdo();

// ==================== АВТО-СОЗДАНИЕ ТАБЛИЦЫ ====================
$pdo->exec("
    CREATE TABLE IF NOT EXISTS promotions (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        title VARCHAR(255) NOT NULL,
        description TEXT,
        image_url VARCHAR(500),
        link_url VARCHAR(500) NOT NULL,
        city VARCHAR(255) NULL,
        is_active BOOLEAN DEFAULT TRUE,
        sort_order INT DEFAULT 0,
        clicks INT DEFAULT 0,
        impressions INT DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_city (city),
        INDEX idx_active (is_active)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
");

// ==================== ВСПОМОГАТЕЛЬНЫЕ ФУНКЦИИ ====================

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

function checkRateLimit($userId, $ip, $limit = 5, $window = 60) {
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
    $key = "rate:promo_click:{$userId}:{$ip}";
    if ($redis) {
        $count = $redis->incr($key);
        if ($count == 1) $redis->expire($key, $window);
        return $count <= $limit;
    }
    $file = __DIR__ . '/../../storage/rate/promo_click_' . md5($userId . '_' . $ip) . '.txt';
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

// ==================== ОСНОВНАЯ ЛОГИКА ====================
$action = $_GET['action'] ?? ($_POST['action'] ?? '');

// ----------------------------------------------
// 1. GET: список предложений (кэшируется)
// ----------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'GET' && $action === 'list') {
    $city = isset($_GET['city']) ? trim($_GET['city']) : null;
    $limit = min(20, max(1, (int)($_GET['limit'] ?? 20)));

    if ($city !== null) {
        $city = preg_replace('/[^a-zA-Zа-яА-Я0-9\s\-_]/u', '', $city);
        if (strlen($city) > 100) $city = substr($city, 0, 100);
        if ($city === '') $city = null;
    }

    $cacheKey = 'promotions_list' . ($city ? '_city_' . md5($city) : '');
    $cached = cacheGet($cacheKey, 300);
    if ($cached !== null) {
        echo json_encode(['success' => true, 'data' => $cached['data']]);
        exit;
    }

    $params = [];
    $where = ["is_active = 1"];
    if ($city) {
        $where[] = "(city IS NULL OR city = ?)";
        $params[] = $city;
    }
    $whereClause = implode(' AND ', $where);
    $sql = "SELECT id, title, description, image_url, link_url, sort_order
            FROM promotions
            WHERE $whereClause
            ORDER BY sort_order ASC, id ASC
            LIMIT ?";
    $params[] = $limit;
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $promotions = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $response = ['success' => true, 'data' => $promotions];
    cacheSet($cacheKey, $response, 300);
    echo json_encode($response);
    exit;
}

// ----------------------------------------------
// 2. POST: клик по предложению (синхронно)
// ----------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'click') {
    if (!isset($_SESSION['user_id'])) {
        http_response_code(401);
        echo json_encode(['success' => false, 'error' => 'Требуется авторизация']);
        exit;
    }
    $userId = (int)$_SESSION['user_id'];
    $ip = getUserIP();

    $csrfToken = $_POST['csrf_token'] ?? '';
    if (!verify_csrf_token($csrfToken)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Недействительный токен безопасности']);
        exit;
    }

    if (!checkRateLimit($userId, $ip, 5, 60)) {
        http_response_code(429);
        echo json_encode(['success' => false, 'error' => 'Слишком много кликов. Попробуйте позже.']);
        exit;
    }

    $id = (int)($_POST['id'] ?? 0);
    if ($id <= 0) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Не указан ID предложения']);
        exit;
    }

    $stmt = $pdo->prepare("UPDATE promotions SET clicks = clicks + 1 WHERE id = ? AND is_active = 1");
    $stmt->execute([$id]);

    echo json_encode(['success' => true]);
    exit;
}

// ----------------------------------------------
// 3. POST: показ предложения (Redis или файловый fallback)
// ----------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'impression') {
    $id = (int)($_POST['id'] ?? 0);
    if ($id <= 0) {
        echo json_encode(['success' => false, 'error' => 'Не указан ID']);
        exit;
    }

    // Пытаемся использовать Redis
    $redis = class_exists('Redis') ? new Redis() : null;
    if ($redis) {
        try {
            $redis->connect('127.0.0.1', 6379, 1);
            $redis->incr("promo_impressions:{$id}");
            echo json_encode(['success' => true]);
            exit;
        } catch (Exception $e) {}
    }

    // Fallback: файловая очередь (создаём папку, пишем в файл)
    $dir = __DIR__ . '/../../storage/promotions/';
    if (!is_dir($dir)) mkdir($dir, 0755, true);
    $file = $dir . 'impressions_' . $id . '.count';
    $count = (int)@file_get_contents($file);
    file_put_contents($file, $count + 1, LOCK_EX);

    echo json_encode(['success' => true]);
    exit;
}

http_response_code(400);
echo json_encode(['success' => false, 'error' => 'Неизвестное действие']);