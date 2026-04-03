<?php
/**
 * Воркер: завершение аукционов
 * Запускать по cron каждые 5 минут:
 * * * * * php /path/to/workers/close_auctions.php >> /path/to/storage/logs/close_auctions.log 2>&1
 */
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../services/Database.php';
require_once __DIR__ . '/../services/NotificationService.php';

$db = Database::getInstance();
$notify = new NotificationService();

// Выбираем активные аукционы, время которых истекло
$auctions = $db->fetchAll("
    SELECT * FROM listings
    WHERE auction_type = 1
      AND auction_status = 'active'
      AND auction_end_at <= NOW()
");

foreach ($auctions as $auction) {
    // Находим максимальную ставку (победителя)
    $winnerBid = $db->fetchOne("
        SELECT * FROM auction_bids
        WHERE listing_id = ?
        ORDER BY bid_price DESC
        LIMIT 1
    ", [$auction['id']]);

    if (!$winnerBid) {
        // Нет ставок — закрываем как "без ставок"
        $db->update('listings', [
            'auction_status' => 'closed_no_bids'
        ], 'id = ?', [$auction['id']]);

        // Уведомляем продавца
        $notify->send($auction['user_id'], 'auction_no_bids', [
            'listing_id' => $auction['id'],
            'title' => $auction['title']
        ]);

        // Логируем
        $db->insert('audit_log', [
            'event_type' => 'auction_closed_no_bids',
            'listing_id' => $auction['id'],
            'user_id' => $auction['user_id']
        ]);
        continue;
    }

    // Проверяем резервную цену
    $reserveMet = ($auction['reserve_price'] === null || $winnerBid['bid_price'] >= $auction['reserve_price']);

    if ($reserveMet) {
        // Успешный аукцион: создаём сделку
        $db->update('listings', [
            'auction_status' => 'payment_pending',
            'winner_id' => $winnerBid['user_id'],
            'final_price' => $winnerBid['bid_price']
        ], 'id = ?', [$auction['id']]);

        $expires = date('Y-m-d H:i:s', strtotime('+24 hours'));
        $dealId = $db->insert('deals', [
            'listing_id' => $auction['id'],
            'seller_id' => $auction['user_id'],
            'buyer_id' => $winnerBid['user_id'],
            'price' => $winnerBid['bid_price'],
            'expires_at' => $expires
        ]);

        $db->update('listings', ['deal_id' => $dealId], 'id = ?', [$auction['id']]);

        // Уведомляем победителя и продавца
        $notify->send($winnerBid['user_id'], 'auction_won', [
            'listing_id' => $auction['id'],
            'price' => $winnerBid['bid_price'],
            'title' => $auction['title']
        ]);
        $notify->send($auction['user_id'], 'auction_sold', [
            'listing_id' => $auction['id'],
            'price' => $winnerBid['bid_price'],
            'title' => $auction['title']
        ]);

        // Логируем
        $db->insert('audit_log', [
            'event_type' => 'auction_closed_success',
            'listing_id' => $auction['id'],
            'winner_id' => $winnerBid['user_id'],
            'details' => json_encode(['price' => $winnerBid['bid_price']])
        ]);
    } else {
        // Резерв не достигнут
        $db->update('listings', [
            'auction_status' => 'reserve_not_met'
        ], 'id = ?', [$auction['id']]);

        // Уведомляем продавца о возможности продать победителю
        $notify->send($auction['user_id'], 'auction_reserve_not_met', [
            'listing_id' => $auction['id'],
            'best_bid' => $winnerBid['bid_price'],
            'title' => $auction['title']
        ]);

        // Логируем
        $db->insert('audit_log', [
            'event_type' => 'auction_reserve_not_met',
            'listing_id' => $auction['id'],
            'user_id' => $auction['user_id'],
            'details' => json_encode(['best_bid' => $winnerBid['bid_price']])
        ]);
    }
}