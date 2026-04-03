<?php
/**
 * API: мгновенная покупка лота по фиксированной цене (buy now)
 * - Завершает аукцион, создаёт сделку, уведомляет продавца и покупателя
 * - Публикует событие в Redis для SSE
 * - Требует, чтобы у лота было заполнено поле buy_now_price
 */
session_start();
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../services/Database.php';
require_once __DIR__ . '/../../services/RedisService.php';

$db = Database::getInstance();
$userId = $_SESSION['user_id'] ?? 0;
if (!$userId) json_error('Требуется авторизация');

$data = json_decode(file_get_contents('php://input'), true);
$listingId = (int)($data['listing_id'] ?? 0);
$csrf = $data['csrf_token'] ?? '';
if (!verify_csrf_token($csrf)) json_error('Неверный CSRF-токен');

// Проверка блокировки
$block = $db->fetchOne("SELECT expires_at FROM user_blocks WHERE user_id = ? AND block_type = 'auction' AND expires_at > NOW()", [$userId]);
if ($block) json_error('Вы заблокированы до ' . $block['expires_at']);

// Получаем лот с buy_now_price
$listing = $db->fetchOne("
    SELECT * FROM listings
    WHERE id = ? AND auction_type = 1 AND auction_status = 'active' AND buy_now_price IS NOT NULL
", [$listingId]);
if (!$listing) json_error('Лот не найден, уже продан или недоступен для мгновенной покупки');
if ($listing['user_id'] == $userId) json_error('Нельзя купить свой собственный лот');
if (strtotime($listing['auction_end_at']) <= time()) json_error('Аукцион уже завершён');

$buyNowPrice = (float)$listing['buy_now_price'];

// Транзакция
$db->beginTransaction();

// Блокируем строку для предотвращения гонок
$db->query("SELECT id FROM listings WHERE id = ? FOR UPDATE", [$listingId]);

// Повторно проверяем статус (на случай, если изменился за время проверки)
$check = $db->fetchOne("SELECT auction_status, winner_id FROM listings WHERE id = ?", [$listingId]);
if ($check['auction_status'] != 'active') {
    $db->rollBack();
    json_error('Лот уже неактивен');
}

// Завершаем аукцион, победитель — текущий пользователь
$db->update('listings', [
    'auction_status' => 'payment_pending',
    'winner_id' => $userId,
    'final_price' => $buyNowPrice
], 'id = ?', [$listingId]);

// Создаём сделку
$expires = date('Y-m-d H:i:s', strtotime('+24 hours'));
$dealId = $db->insert('deals', [
    'listing_id' => $listingId,
    'seller_id' => $listing['user_id'],
    'buyer_id' => $userId,
    'price' => $buyNowPrice,
    'expires_at' => $expires
]);
$db->update('listings', ['deal_id' => $dealId], 'id = ?', [$listingId]);

// Логируем событие
$db->insert('audit_log', [
    'event_type' => 'buy_now',
    'user_id' => $userId,
    'listing_id' => $listingId,
    'details' => json_encode(['price' => $buyNowPrice])
]);

$db->commit();

// Уведомления
sendNotification($userId, 'auction_won', [
    'listing_id' => $listingId,
    'price' => $buyNowPrice,
    'title' => $listing['title']
]);
sendNotification($listing['user_id'], 'auction_sold', [
    'listing_id' => $listingId,
    'price' => $buyNowPrice,
    'title' => $listing['title']
]);

// Публикация в Redis для SSE (сообщаем всем участникам, что аукцион завершён)
$redis = RedisService::getInstance();
$redis->publish("auction:{$listingId}", json_encode([
    'type' => 'auction_ended',
    'winner_id' => $userId,
    'final_price' => $buyNowPrice
]));

json_success(['deal_id' => $dealId, 'message' => 'Поздравляем! Вы купили лот.']);