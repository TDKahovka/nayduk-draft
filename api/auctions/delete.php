<?php
/**
 * API: удаление аукциона
 * - Доступно только для черновиков (draft), аукционов без ставок (closed_no_bids),
 *   аукционов с недостигнутым резервом (reserve_not_met) или отменённых (cancelled)
 * - Нельзя удалить активный аукцион или уже завершённый с продажей
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

// Получаем аукцион, принадлежащий пользователю
$listing = $db->fetchOne("
    SELECT id, user_id, auction_status, listing_fee_paid
    FROM listings
    WHERE id = ? AND user_id = ? AND auction_type = 1
", [$listingId, $userId]);

if (!$listing) json_error('Аукцион не найден или не принадлежит вам');

// Разрешаем удаление только для определённых статусов
$allowedStatuses = ['draft', 'closed_no_bids', 'reserve_not_met', 'cancelled'];
if (!in_array($listing['auction_status'], $allowedStatuses)) {
    json_error('Нельзя удалить активный аукцион или уже завершённый с продажей');
}

// Помечаем как удалённый (soft delete)
$db->beginTransaction();
$db->update('listings', [
    'deleted_at' => date('Y-m-d H:i:s'),
    'status' => 'deleted'
], 'id = ?', [$listingId]);

// Логируем
$db->insert('audit_log', [
    'event_type' => 'auction_deleted',
    'listing_id' => $listingId,
    'user_id' => $userId,
    'details' => json_encode(['status' => $listing['auction_status']])
]);

$db->commit();

json_success();