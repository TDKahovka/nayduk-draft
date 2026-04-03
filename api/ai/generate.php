<?php
/* ============================================
   НАЙДУК — API для постановки задач AI-генерации
   Версия 2.0 — асинхронный, очередь Redis
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

$userId = $_SESSION['user_id'];
$db = Database::getInstance();

// Проверка прав администратора
$user = $db->fetchOne("SELECT role FROM users WHERE id = ?", [$userId]);
if (!$user || $user['role'] !== 'admin') {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Доступ запрещён']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
if (!$input) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Неверный формат JSON']);
    exit;
}

$csrfToken = $input['csrf_token'] ?? '';
if (!verifyCsrfToken($csrfToken)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Недействительный токен безопасности']);
    exit;
}

$prompt = trim($input['prompt'] ?? '');
$generator = $input['generator'] ?? null;
$listingId = isset($input['listing_id']) ? (int)$input['listing_id'] : 0;

if (empty($prompt)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Промпт не может быть пустым']);
    exit;
}

// Rate limiting (не более 5 задач в минуту)
if (!$db->checkRateLimit('ai_gen_' . $userId, 5, 60)) {
    http_response_code(429);
    echo json_encode(['success' => false, 'error' => 'Слишком много запросов. Попробуйте позже.']);
    exit;
}

// Генерируем idempotency-ключ
$idempotencyKey = hash('sha256', $prompt . $generator . ($listingId ?: ''));

// Проверяем, не генерировалось ли уже
$existing = $db->fetchOne("SELECT result_path FROM ai_generation_jobs WHERE idempotency_key = ? AND status = 'completed'", [$idempotencyKey]);
if ($existing && !empty($existing['result_path'])) {
    echo json_encode(['success' => true, 'message' => 'Уже сгенерировано', 'path' => $existing['result_path']]);
    exit;
}

// Создаём задачу в БД
$jobId = $db->createAIGenerationJob($listingId, $generator, $prompt, $idempotencyKey);

// Отправляем в Redis-очередь
$redis = new Redis();
$redis->connect('127.0.0.1', 6379);
if (!$redis->isConnected()) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Очередь недоступна']);
    exit;
}
$redis->rpush('ai_generation_queue', json_encode([
    'job_id' => $jobId,
    'listing_id' => $listingId,
    'prompt' => $prompt,
    'generator' => $generator,
    'idempotency_key' => $idempotencyKey
]));

echo json_encode(['success' => true, 'job_id' => $jobId, 'message' => 'Задача поставлена в очередь']);