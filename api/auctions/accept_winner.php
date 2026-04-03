<?php
/**
 * API: продажа лота победителю при недостижении резервной цены
 * - Продавец может согласиться продать лот по максимальной ставке, даже если резерв не достигнут
 * - Создаётся сделка, статус аукциона становится payment_pending
 */
session_start();
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../services/Database.php';

$db = Database::getInstance();
$userId = $_SESSION['user_id'] ?? 0;
if (!$userId) json_error('Требуется авторизация');

$data = json_decode(file_get_contents('php://input'), true);
$listingId = (int)($data['listing_id'] ?? 0);
$csrf = $data['csrf_token'] ?? '';
if (!verify_csrf_token($csrf)) json_error('Неверный CSRF-токен');

// Получаем аукцион, принадлежащий продавцу, со статусом reserve_not_met
$listing = $db->fetchOne("
    SELECT id, user_id, auction_status, title
    FROM listings
    WHERE id = ? AND user_id = ? AND auction_type = 1
", [$listingId, $userId]);

if (!$listing) json_error('Аукцион не найден или не принадлежит вам');
if ($listing['auction_status'] != 'reserve_not_met') json_error('Аукцион не в состоянии "резерв не достигнут"');

// Получаем победителя (максимальную ставку)
$winnerBid = $db->fetchOne("
    SELECT user_id, bid_price
    FROM auction_bids
    WHERE listing_id = ?
    ORDER BY bid_price DESC
    LIMIT 1
", [$listingId]);

if (!$winnerBid) json_error('Победитель не найден');

$winnerId = $winnerBid['user_id'];
$price = $winnerBid['bid_price'];

// Транзакция
$db->beginTransaction();

// Обновляем статус аукциона
$db->update('listings', [
    'auction_status' => 'payment_pending',
    'winner_id' => $winnerId,
    'final_price' => $price
], 'id = ?', [$listingId]);

// Создаём сделку
$expires = date('Y-m-d H:i:s', strtotime('+24 hours'));
$dealId = $db->insert('deals', [
    'listing_id' => $listingId,
    'seller_id' => $userId,
    'buyer_id' => $winnerId,
    'price' => $price,
    'expires_at' => $expires
]);

$db->update('listings', ['deal_id' => $dealId], 'id = ?', [$listingId]);

// Логируем
$db->insert('audit_log', [
    'event_type' => 'auction_winner_accepted',
    'listing_id' => $listingId,
    'user_id' => $userId,
    'details' => json_encode(['winner_id' => $winnerId, 'price' => $price])
]);

$db->commit();

// Уведомляем победителя и продавца
sendNotification($winnerId, 'auction_won', [
    'listing_id' => $listingId,
    'price' => $price,
    'title' => $listing['title']
]);
sendNotification($userId, 'auction_sold', [
    'listing_id' => $listingId,
    'price' => $price,
    'title' => $listing['title']
]);

json_success(['deal_id' => $dealId]);