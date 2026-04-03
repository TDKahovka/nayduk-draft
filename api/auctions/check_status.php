<?php
/**
 * API: проверка статуса аукциона
 * Возвращает текущее состояние: таймер, текущая ставка, количество ставок,
 * признак окончания и т.д.
 * Используется для AJAX-опроса, если SSE недоступно.
 */
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../services/Database.php';

$db = Database::getInstance();
$listingId = isset($_GET['listing_id']) ? (int)$_GET['listing_id'] : 0;
if (!$listingId) {
    json_error('Не указан ID аукциона');
}

$listing = $db->fetchOne("SELECT id, auction_status, start_bid, min_bid_increment, hidden_bids, auction_end_at FROM listings WHERE id = ? AND auction_type = 1", [$listingId]);
if (!$listing) {
    json_error('Аукцион не найден');
}

$currentMaxBid = $db->fetchOne("SELECT MAX(bid_price) as max FROM auction_bids WHERE listing_id = ?", [$listingId])['max'] ?? $listing['start_bid'];
$bidsCount = $db->fetchCount("SELECT COUNT(*) FROM auction_bids WHERE listing_id = ?", [$listingId]);

$endTimestamp = strtotime($listing['auction_end_at']);
$now = time();
$isActive = ($listing['auction_status'] == 'active' && $endTimestamp > $now);

json_success([
    'is_active' => $isActive,
    'status' => $listing['auction_status'],
    'current_bid' => $listing['hidden_bids'] ? null : $currentMaxBid,
    'has_bids' => $currentMaxBid > $listing['start_bid'],
    'bids_count' => $bidsCount,
    'end_timestamp' => $endTimestamp,
    'time_left' => max(0, $endTimestamp - $now),
    'min_next_bid' => $currentMaxBid + $listing['min_bid_increment']
]);