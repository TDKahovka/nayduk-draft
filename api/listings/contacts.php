<?php
/* ============================================
   НАЙДУК — API получения контактов продавца
   Версия 1.0
   - Защита от ботов (rate limiting 5/час)
   - CSRF-защита
   - Логирование запросов
   - Для неавторизованных — лимит по IP
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

$listingId = isset($input['listing_id']) ? (int)$input['listing_id'] : 0;
if (!$listingId) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Не указан ID объявления']);
    exit;
}

$db = Database::getInstance();
$pdo = $db->getPdo();

// Получаем информацию об объявлении и продавце
$stmt = $pdo->prepare("
    SELECT l.user_id, u.phone, u.phone_visible
    FROM listings l
    JOIN users u ON l.user_id = u.id
    WHERE l.id = ? AND l.status IN ('approved', 'featured')
");
$stmt->execute([$listingId]);
$listing = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$listing) {
    http_response_code(404);
    echo json_encode(['success' => false, 'error' => 'Объявление не найдено или неактивно']);
    exit;
}

// Если телефон уже открыт для всех, возвращаем сразу (но обычно фронт не вызывает endpoint)
if ($listing['phone_visible']) {
    echo json_encode(['success' => true, 'phone' => $listing['phone']]);
    exit;
}

// Если телефон не открыт, применяем rate limiting
$userId = $_SESSION['user_id'] ?? 0;
$ip = getUserIP();
$rateKey = $userId ? "show_phone_user_{$userId}" : "show_phone_ip_{$ip}";

// Лимит: 5 показов в час на пользователя/IP
if (!checkRateLimit($rateKey, 5, 3600)) {
    http_response_code(429);
    echo json_encode(['success' => false, 'error' => 'Слишком много запросов. Попробуйте позже.']);
    exit;
}

// Логируем действие (для безопасности)
$db->insert('security_logs', [
    'user_id' => $userId ?: null,
    'ip_address' => $ip,
    'event_type' => 'contact_shown',
    'description' => "Показ телефона продавца #{$listing['user_id']} для объявления #{$listingId}",
    'severity' => 'low',
    'created_at' => date('Y-m-d H:i:s')
]);

echo json_encode(['success' => true, 'phone' => $listing['phone']]);