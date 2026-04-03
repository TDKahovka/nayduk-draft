<?php
/**
 * Временный endpoint для опроса обновлений
 * Позже заменим на SSE через Redis
 */
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../services/Database.php';

$db = Database::getInstance();
$listingId = (int)($_GET['listing_id'] ?? 0);
$last = (int)($_GET['last'] ?? 0);
if (!$listingId) json_error('Нет ID аукциона');

// Выбираем ставки, созданные после last
$updates = $db->fetchAll("
    SELECT anonymous_id, color_code, bid_price, created_at, UNIX_TIMESTAMP(created_at) as ts
    FROM auction_bids
    WHERE listing_id = ? AND UNIX_TIMESTAMP(created_at) > ?
    ORDER BY created_at DESC
    LIMIT 10
", [$listingId, $last]);

$listing = $db->fetchOne("SELECT hidden_bids, auction_end_at FROM listings WHERE id = ?", [$listingId]);
$result = [];
foreach ($updates as $row) {
    $result[] = [
        'type' => 'new_bid',
        'anonymous_id' => $row['anonymous_id'],
        'color_code' => $row['color_code'],
        'bid_price' => (float)$row['bid_price'],
        'hidden' => (bool)$listing['hidden_bids'],
        'timestamp' => $row['ts'],
        'new_end_time' => strtotime($listing['auction_end_at'])
    ];
}
json_success(['updates' => $result]);