<?php
/**
 * API: обработка ставки
 * Лимиты: 3 бесплатных ставки на аукцион, платные глобально (10 за 50₽), максимум 20 платных на один аукцион
 * - Проверка авторизации, CSRF, блокировки, согласия
 * - Проверка суммы (текущая ставка + шаг)
 * - Списание ставки (бесплатная / платная)
 * - Генерация анонимного ID и цвета
 * - Soft close (продление на 5 минут при ставке в последние 5 минут)
 * - Публикация события в Redis для SSE
 * - Уведомление предыдущему лидеру (email/push)
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
$amount = (float)($data['amount'] ?? 0);
$csrf = $data['csrf_token'] ?? '';

if (!verify_csrf_token($csrf)) json_error('Неверный CSRF-токен');

// Проверка блокировки
$block = $db->fetchOne("SELECT expires_at FROM user_blocks WHERE user_id = ? AND block_type = 'auction' AND expires_at > NOW()", [$userId]);
if ($block) json_error('Вы заблокированы до ' . $block['expires_at']);

$listing = $db->fetchOne("SELECT * FROM listings WHERE id = ? AND auction_type = 1 AND auction_status = 'active' AND auction_end_at > NOW()", [$listingId]);
if (!$listing) json_error('Аукцион не активен или не найден');

// Проверка согласия с правилами
$latestVersion = $db->fetchOne("SELECT version, full_text FROM auction_consent_versions ORDER BY id DESC LIMIT 1");
$consent = $db->fetchOne("SELECT 1 FROM user_auction_consents WHERE user_id = ? AND consent_version = ?", [$userId, $latestVersion['version']]);
if (!$consent) json_error('consent_required', ['consent_text' => $latestVersion['full_text']]);

// Текущая максимальная ставка
$currentMax = $db->fetchOne("SELECT MAX(bid_price) as max FROM auction_bids WHERE listing_id = ?", [$listingId])['max'] ?? $listing['start_bid'];
if ($amount <= $currentMax + $listing['min_bid_increment'] - 0.01) {
    $msg = 'Ставка должна быть выше текущей';
    if (!$listing['hidden_bids']) $msg .= " (минимум " . number_format($currentMax + $listing['min_bid_increment'], 2) . " ₽)";
    json_error($msg);
}

// Проверка наличия ставок (3 бесплатных, платные глобально)
$participant = $db->fetchOne("SELECT free_bids_used, extra_bids_used FROM auction_participants WHERE listing_id = ? AND user_id = ?", [$listingId, $userId]);
$freeUsed = $participant['free_bids_used'] ?? 0;
$extraUsed = $participant['extra_bids_used'] ?? 0;
$user = $db->fetchOne("SELECT extra_bids_balance FROM users WHERE id = ?", [$userId]);
$freeRemaining = 3 - $freeUsed;
$extraRemaining = $user['extra_bids_balance'] - $extraUsed;
if ($freeRemaining <= 0 && $extraRemaining <= 0) json_error('no_bids_left');

// Проверка лимита платных ставок на аукцион (не более 20)
if ($extraUsed >= 20) json_error('Вы превысили лимит платных ставок на этот аукцион (20)');

// Транзакция
$db->beginTransaction();
$db->query("SELECT id FROM listings WHERE id = ? FOR UPDATE", [$listingId]);

$useExtra = ($freeRemaining <= 0);
if ($useExtra) {
    // Используем платную ставку
    $db->query("UPDATE users SET extra_bids_balance = extra_bids_balance - 1 WHERE id = ?", [$userId]);
    $db->query("INSERT INTO auction_participants (listing_id, user_id, free_bids_used, extra_bids_used) VALUES (?, ?, 0, 1)
                ON DUPLICATE KEY UPDATE extra_bids_used = extra_bids_used + 1", [$listingId, $userId]);
} else {
    // Используем бесплатную ставку
    $db->query("INSERT INTO auction_participants (listing_id, user_id, free_bids_used, extra_bids_used) VALUES (?, ?, 1, 0)
                ON DUPLICATE KEY UPDATE free_bids_used = free_bids_used + 1", [$listingId, $userId]);
}

// Генерация анонимных данных
$anonId = substr(md5(uniqid() . $userId . $listingId), 0, 4);
$color = sprintf("#%06x", crc32($anonId) & 0xFFFFFF);

// Сохраняем ставку
$db->insert('auction_bids', [
    'listing_id' => $listingId,
    'user_id' => $userId,
    'bid_price' => $amount,
    'anonymous_id' => $anonId,
    'color_code' => $color
]);

// Soft close
$end = strtotime($listing['auction_end_at']);
$newEndTime = $end;
if ($end - time() < 300) {
    $newEnd = time() + 300;
    $db->update('listings', ['auction_end_at' => date('Y-m-d H:i:s', $newEnd)], 'id = ?', [$listingId]);
    $db->insert('audit_log', [
        'event_type' => 'auction_extended',
        'listing_id' => $listingId,
        'details' => json_encode(['old_end' => $listing['auction_end_at'], 'new_end' => date('Y-m-d H:i:s', $newEnd)])
    ]);
    $newEndTime = $newEnd;
}
$db->commit();

// Уведомление предыдущему лидеру (если он существует и это не текущий пользователь)
if ($currentMax > $listing['start_bid']) {
    $prevLeader = $db->fetchOne("SELECT user_id FROM auction_bids WHERE listing_id = ? AND bid_price = ?", [$listingId, $currentMax]);
    if ($prevLeader && $prevLeader['user_id'] != $userId) {
        sendNotification($prevLeader['user_id'], 'outbid', [
            'listing_id' => $listingId,
            'new_bid' => $amount,
            'title' => $listing['title']
        ]);
    }
}

// Публикация события в Redis для SSE
$redis = RedisService::getInstance();
$event = json_encode([
    'type' => 'new_bid',
    'anonymous_id' => $anonId,
    'color_code' => $color,
    'bid_price' => $listing['hidden_bids'] ? null : $amount,
    'hidden' => (bool)$listing['hidden_bids'],
    'new_end_time' => $newEndTime
]);
$redis->publish("auction:{$listingId}", $event);

// Ответ клиенту
$newFreeRemaining = max(0, 3 - ($useExtra ? $freeUsed : $freeUsed + 1));
$newExtraRemaining = $user['extra_bids_balance'] - ($useExtra ? $extraUsed + 1 : $extraUsed);
json_success([
    'free_remaining' => $newFreeRemaining,
    'extra_remaining' => $newExtraRemaining,
    'new_end_time' => $newEndTime
]);