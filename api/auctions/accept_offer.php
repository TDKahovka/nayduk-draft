<?php
/**
 * API: принятие предложения вторым участником (second_offers)
 * - Проверяет, что предложение существует, принадлежит пользователю, не истекло и статус pending
 * - Создаёт новую сделку, закрывает старое предложение, обновляет аукцион
 */
session_start();
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../services/Database.php';
require_once __DIR__ . '/../../services/NotificationService.php';

$db = Database::getInstance();
$userId = $_SESSION['user_id'] ?? 0;
if (!$userId) json_error('Требуется авторизация');

$data = json_decode(file_get_contents('php://input'), true);
$offerId = (int)($data['offer_id'] ?? 0);
$csrf = $data['csrf_token'] ?? '';
if (!verify_csrf_token($csrf)) json_error('Неверный CSRF-токен');

// Получаем предложение
$offer = $db->fetchOne("
    SELECT * FROM second_offers
    WHERE id = ? AND buyer_id = ? AND status = 'pending'
", [$offerId, $userId]);

if (!$offer) json_error('Предложение не найдено или уже обработано');
if (strtotime($offer['expires_at']) <= time()) {
    $db->update('second_offers', ['status' => 'expired'], 'id = ?', [$offerId]);
    json_error('Время принятия предложения истекло');
}

// Проверяем, что лот ещё не продан и не завершён
$listing = $db->fetchOne("SELECT * FROM listings WHERE id = ? AND auction_status = 'payment_pending'", [$offer['listing_id']]);
if (!$listing) {
    json_error('Аукцион уже завершён или недоступен');
}

$db->beginTransaction();

// Помечаем предложение как принятое
$db->update('second_offers', ['status' => 'accepted'], 'id = ?', [$offerId]);

// Отменяем старую сделку, если есть (предыдущий победитель не оплатил)
$oldDeal = $db->fetchOne("SELECT id FROM deals WHERE listing_id = ? AND status = 'awaiting_confirmation'", [$offer['listing_id']]);
if ($oldDeal) {
    $db->update('deals', ['status' => 'cancelled'], 'id = ?', [$oldDeal['id']]);
}

// Обновляем аукцион: новый победитель, цена
$db->update('listings', [
    'winner_id' => $offer['buyer_id'],
    'final_price' => $offer['price']
], 'id = ?', [$offer['listing_id']]);

// Создаём новую сделку
$expires = date('Y-m-d H:i:s', strtotime('+24 hours'));
$dealId = $db->insert('deals', [
    'listing_id' => $offer['listing_id'],
    'seller_id' => $offer['seller_id'],
    'buyer_id' => $offer['buyer_id'],
    'price' => $offer['price'],
    'expires_at' => $expires
]);

$db->update('listings', ['deal_id' => $dealId], 'id = ?', [$offer['listing_id']]);

// Логируем
$db->insert('audit_log', [
    'event_type' => 'second_offer_accepted',
    'listing_id' => $offer['listing_id'],
    'user_id' => $userId,
    'details' => json_encode(['offer_id' => $offerId, 'price' => $offer['price']])
]);

$db->commit();

// Уведомляем продавца
$notify = new NotificationService();
$notify->send($offer['seller_id'], 'second_offer_accepted', [
    'listing_id' => $offer['listing_id'],
    'price' => $offer['price'],
    'buyer_id' => $userId
]);

// Уведомляем покупателя (который принял)
$notify->send($userId, 'auction_won', [
    'listing_id' => $offer['listing_id'],
    'price' => $offer['price']
]);

json_success(['deal_id' => $dealId]);