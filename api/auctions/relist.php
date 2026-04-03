<?php
/**
 * API: бесплатное перевыставление аукциона (если не было ставок)
 * - Доступно только для аукционов со статусом closed_no_bids
 * - Можно использовать один раз (поле free_relist_used)
 * - Устанавливает новую дату окончания (длительность берётся из сохранённой)
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

// Получаем аукцион, принадлежащий пользователю, со статусом closed_no_bids
$listing = $db->fetchOne("
    SELECT id, user_id, auction_status, free_relist_used, created_at
    FROM listings
    WHERE id = ? AND user_id = ? AND auction_type = 1
", [$listingId, $userId]);

if (!$listing) json_error('Аукцион не найден или не принадлежит вам');
if ($listing['auction_status'] != 'closed_no_bids') json_error('Аукцион не завершён без ставок');
if ($listing['free_relist_used']) json_error('Бесплатное перевыставление уже использовано');

// Вычисляем новую дату окончания (длительность: 7 дней, можно брать из поля auction_duration, если оно есть, иначе 7)
$duration = 7; // дней
$newEnd = date('Y-m-d H:i:s', strtotime("+{$duration} days"));

// Обновляем запись
$db->beginTransaction();
$db->update('listings', [
    'auction_status' => 'active',
    'auction_end_at' => $newEnd,
    'free_relist_used' => 1,
    'status' => 'active'
], 'id = ?', [$listingId]);

// Логируем
$db->insert('audit_log', [
    'event_type' => 'auction_relisted',
    'listing_id' => $listingId,
    'user_id' => $userId,
    'details' => json_encode(['new_end' => $newEnd])
]);

$db->commit();

// Уведомляем продавца
sendNotification($userId, 'auction_relisted', [
    'listing_id' => $listingId,
    'new_end' => $newEnd
]);

json_success(['new_end' => $newEnd]);