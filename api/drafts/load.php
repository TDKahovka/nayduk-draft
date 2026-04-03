<?php
/* ============================================
   НАЙДУК — Загрузка черновика (v2.0)
   - Redis кэширование, rate limiting
   - Кэширование списка черновиков
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

$csrfToken = $input['csrf_token'] ?? '';
if (!verify_csrf_token($csrfToken)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Недействительный токен безопасности']);
    exit;
}

// Rate limiting
function checkRateLimitLoad($userId, $ip, $limit = 30, $window = 60) {
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
    $key = "rate:draft_load:{$userId}:" . md5($ip);
    if ($redis) {
        $count = $redis->incr($key);
        if ($count == 1) $redis->expire($key, $window);
        return $count <= $limit;
    }
    $file = __DIR__ . '/../../storage/rate/draft_load_' . $userId . '_' . md5($ip) . '.txt';
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

if (!checkRateLimitLoad($userId, $ip, 30, 60)) {
    http_response_code(429);
    echo json_encode(['success' => false, 'error' => 'Слишком много запросов. Попробуйте позже.']);
    exit;
}

$db = Database::getInstance();

// Кэш в Redis
$cached = cacheGet("draft:user:{$userId}", 600);
if ($cached !== null) {
    echo json_encode(['success' => true, 'data' => $cached]);
    exit;
}

$pdo = $db->getPdo();
$stmt = $pdo->prepare("SELECT data FROM drafts WHERE user_id = ?");
$stmt->execute([$userId]);
$row = $stmt->fetch();

if ($row) {
    $data = json_decode($row['data'], true);
    cacheSet("draft:user:{$userId}", $data, 600);
    echo json_encode(['success' => true, 'data' => $data]);
} else {
    echo json_encode(['success' => true, 'data' => null]);
}