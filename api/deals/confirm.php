<?php
/**
 * API: подтверждение сделки продавцом или покупателем
 * - Проверяет, что сделка существует и пользователь имеет право подтвердить
 * - Обновляет seller_confirmed или buyer_confirmed
 * - Если оба подтвердили — статус становится confirmed, создаётся чат, отправляются уведомления
 */
session_start();
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../services/Database.php';

$db = Database::getInstance();
$userId = $_SESSION['user_id'] ?? 0;
if (!$userId) json_error('Требуется авторизация');

$data = json_decode(file_get_contents('php://input'), true);
$dealId = (int)($data['deal_id'] ?? 0);
$role = $data['role'] ?? ''; // 'seller' или 'buyer'
$csrf = $data['csrf_token'] ?? '';

if (!verify_csrf_token($csrf)) json_error('Неверный CSRF-токен');
if (!in_array($role, ['seller', 'buyer'])) json_error('Неверная роль');

$deal = $db->fetchOne("SELECT * FROM deals WHERE id = ?", [$dealId]);
if (!$deal) json_error('Сделка не найдена');

if ($role == 'seller' && $deal['seller_id'] != $userId) json_error('Доступ запрещён');
if ($role == 'buyer' && $deal['buyer_id'] != $userId) json_error('Доступ запрещён');

// Обновляем поле в зависимости от роли
if ($role == 'seller') {
    $db->update('deals', ['seller_confirmed' => 1], 'id = ?', [$dealId]);
} else {
    $db->update('deals', ['buyer_confirmed' => 1], 'id = ?', [$dealId]);
}

// Проверяем, подтвердили ли оба
$updated = $db->fetchOne("SELECT * FROM deals WHERE id = ?", [$dealId]);
if ($updated['seller_confirmed'] && $updated['buyer_confirmed'] && $updated['status'] == 'awaiting_confirmation') {
    // Меняем статус сделки на confirmed
    $db->update('deals', ['status' => 'confirmed'], 'id = ?', [$dealId]);

    // Создаём чат для общения
    $chatId = $db->insert('auction_chats', ['deal_id' => $dealId]);

    // Отправляем уведомления обеим сторонам
    sendNotification($deal['seller_id'], 'deal_confirmed', [
        'deal_id' => $dealId,
        'chat_id' => $chatId
    ]);
    sendNotification($deal['buyer_id'], 'deal_confirmed', [
        'deal_id' => $dealId,
        'chat_id' => $chatId
    ]);
}

json_success();