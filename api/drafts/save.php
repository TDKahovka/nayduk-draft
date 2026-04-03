<?php
/* ============================================
   НАЙДУК — Сохранение черновика (v2.0)
   - Redis кэширование, rate limiting
   - Идемпотентность, транзакции
   - Логирование, валидация размера
   ============================================ */
session_start();
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../services/Database.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Требуется авторизация']);
    exit;
}

$userId = (int)$_SESSION['user_id'];
$ip = getUserIP();
$input = json_decode(file_get_contents('php://input'), true);
if (!$input) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Неверный формат JSON']);
    exit;
}

// CSRF
$csrfToken = $input['csrf_token'] ?? '';
if (!verify_csrf_token($csrfToken)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Недействительный токен безопасности']);
    exit;
}

// Rate limiting (Redis + файлы)
function checkRateLimitDraft($userId, $ip, $limit = 10, $window = 60) {
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
    $key = "rate:draft:{$userId}:" . md5($ip);
    if ($redis) {
        $count = $redis->incr($key);
        if ($count == 1) $redis->expire($key, $window);
        return $count <= $limit;
    }
    // Файловый fallback
    $file = __DIR__ . '/../../storage/rate/draft_' . $userId . '_' . md5($ip) . '.txt';
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

if (!checkRateLimitDraft($userId, $ip, 10, 60)) {
    http_response_code(429);
    echo json_encode(['success' => false, 'error' => 'Слишком много запросов. Попробуйте позже.']);
    exit;
}

// Идемпотентность
$idempotencyKey = $input['idempotency_key'] ?? '';
if ($idempotencyKey) {
    $cacheKeyIdem = "draft:idempotent:{$userId}:{$idempotencyKey}";
    $cached = cacheGet($cacheKeyIdem, 86400);
    if ($cached !== null) {
        echo $cached;
        exit;
    }
}

$data = $input['data'] ?? [];
if (empty($data)) {
    echo json_encode(['success' => true, 'message' => 'Нет данных для сохранения']);
    exit;
}

// Валидация размера (макс 2 МБ)
$jsonSize = strlen(json_encode($data));
if ($jsonSize > 2 * 1024 * 1024) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Черновик слишком большой (макс 2 МБ)']);
    exit;
}

$db = Database::getInstance();
$pdo = $db->getPdo();

// Создаём таблицу
$db->query("
    CREATE TABLE IF NOT EXISTS drafts (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        user_id BIGINT UNSIGNED NOT NULL,
        listing_type VARCHAR(20) NOT NULL DEFAULT 'sell',
        data JSON NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
        UNIQUE KEY unique_user (user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
");

// Транзакция с блокировкой
$pdo->beginTransaction();
try {
    // Проверяем существование
    $stmt = $pdo->prepare("SELECT id FROM drafts WHERE user_id = ? FOR UPDATE");
    $stmt->execute([$userId]);
    $exists = $stmt->fetch();

    $type = $data['type'] ?? 'housing';

    if ($exists) {
        $stmt = $pdo->prepare("UPDATE drafts SET data = ?, listing_type = ?, updated_at = NOW() WHERE user_id = ?");
        $stmt->execute([json_encode($data), $type, $userId]);
    } else {
        $stmt = $pdo->prepare("INSERT INTO drafts (user_id, listing_type, data) VALUES (?, ?, ?)");
        $stmt->execute([$userId, $type, json_encode($data)]);
    }

    $pdo->commit();

    // Логируем
    $db->insert('security_logs', [
        'user_id' => $userId,
        'ip_address' => $ip,
        'event_type' => 'draft_saved',
        'description' => "Сохранён черновик для пользователя #$userId, размер: $jsonSize байт",
        'severity' => 'low',
        'created_at' => date('Y-m-d H:i:s')
    ]);

    // Кэшируем черновик в Redis
    cacheSet("draft:user:{$userId}", $data, 600); // 10 минут

    $response = json_encode(['success' => true]);
    if ($idempotencyKey) {
        cacheSet("draft:idempotent:{$userId}:{$idempotencyKey}", $response, 86400);
    }
    echo $response;

} catch (Exception $e) {
    $pdo->rollBack();
    error_log("Draft save error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Ошибка сохранения черновика']);
}