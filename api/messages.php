<?php
/* ============================================
   НАЙДУК — API сообщений (с мягким лимитом)
   Версия 2.0 — защита от флуда, подсказка перечитать описание
   ============================================ */

session_start();
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../services/Database.php';
require_once __DIR__ . '/../services/NotificationService.php';

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

// Rate limiting (не более 20 сообщений в минуту)
if (!checkRateLimit('messages_' . $userId, 20, 60)) {
    http_response_code(429);
    echo json_encode(['success' => false, 'error' => 'Слишком много сообщений. Попробуйте позже.']);
    exit;
}

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

$action = $input['action'] ?? '';
if ($action !== 'send') {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Неизвестное действие']);
    exit;
}

$listingId = (int)($input['listing_id'] ?? 0);
$content = trim($input['content'] ?? '');
if (!$listingId || !$content) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Не указано объявление или текст сообщения']);
    exit;
}

$db = Database::getInstance();
$pdo = $db->getPdo();

// Получаем объявление и продавца
$stmt = $pdo->prepare("
    SELECT l.user_id as seller_id, l.title, u.name as seller_name
    FROM listings l
    LEFT JOIN users u ON l.user_id = u.id
    WHERE l.id = ? AND l.status IN ('approved', 'featured')
");
$stmt->execute([$listingId]);
$listing = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$listing) {
    http_response_code(404);
    echo json_encode(['success' => false, 'error' => 'Объявление не найдено']);
    exit;
}

$sellerId = (int)$listing['seller_id'];

// Нельзя писать самому себе
if ($userId === $sellerId) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Нельзя отправить сообщение самому себе']);
    exit;
}

// ==================== ТАБЛИЦА ЛИМИТОВ ====================
$pdo->exec("
    CREATE TABLE IF NOT EXISTS chat_limits (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        listing_id BIGINT UNSIGNED NOT NULL,
        buyer_id BIGINT UNSIGNED NOT NULL,
        seller_id BIGINT UNSIGNED NOT NULL,
        messages_since_last_reply INT DEFAULT 0,
        last_seller_reply_at TIMESTAMP NULL,
        UNIQUE KEY unique_chat (listing_id, buyer_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
");

// Добавляем новые поля, если их нет (для совместимости)
try {
    $pdo->exec("ALTER TABLE chat_limits ADD COLUMN messages_since_last_reply INT DEFAULT 0");
} catch (Exception $e) {}
try {
    $pdo->exec("ALTER TABLE chat_limits ADD COLUMN last_seller_reply_at TIMESTAMP NULL");
} catch (Exception $e) {}

// Получаем или создаём запись лимита
$stmt = $pdo->prepare("
    SELECT id, messages_since_last_reply, last_seller_reply_at
    FROM chat_limits
    WHERE listing_id = ? AND buyer_id = ?
");
$stmt->execute([$listingId, $userId]);
$limit = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$limit) {
    $pdo->prepare("
        INSERT INTO chat_limits (listing_id, buyer_id, seller_id, messages_since_last_reply, last_seller_reply_at)
        VALUES (?, ?, ?, 0, NULL)
    ")->execute([$listingId, $userId, $sellerId]);
    $limit = ['messages_since_last_reply' => 0, 'last_seller_reply_at' => null];
}

// Проверка: покупатель уже отправил 2 сообщения и продавец ещё не ответил
$isBuyerBlocked = ($limit['messages_since_last_reply'] >= 2 && $limit['last_seller_reply_at'] === null);

if ($isBuyerBlocked) {
    echo json_encode([
        'success' => false,
        'blocked' => true,
        'hint' => '💡 Продавец пока не ответил. Пожалуйста, перечитайте описание — возможно, там уже есть ответ на ваш вопрос.'
    ]);
    exit;
}

// ==================== ОТПРАВКА СООБЩЕНИЯ ====================
$pdo->beginTransaction();

try {
    // Вставляем сообщение
    $stmt = $pdo->prepare("
        INSERT INTO messages (listing_id, sender_id, receiver_id, content, created_at)
        VALUES (?, ?, ?, ?, NOW())
    ");
    $stmt->execute([$listingId, $userId, $sellerId, $content]);

    // Обновляем счётчик лимита (если покупатель)
    if ($userId !== $sellerId) {
        $newCount = $limit['messages_since_last_reply'] + 1;
        $pdo->prepare("
            UPDATE chat_limits
            SET messages_since_last_reply = ?
            WHERE listing_id = ? AND buyer_id = ?
        ")->execute([$newCount, $listingId, $userId]);
    }

    $pdo->commit();

    // Уведомление продавцу
    $notify = new NotificationService();
    $notify->send($sellerId, 'new_message', [
        'listing_id' => $listingId,
        'title' => $listing['title'],
        'sender_id' => $userId
    ]);

    echo json_encode(['success' => true, 'message' => 'Сообщение отправлено']);

} catch (Exception $e) {
    $pdo->rollBack();
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Ошибка при отправке сообщения']);
}