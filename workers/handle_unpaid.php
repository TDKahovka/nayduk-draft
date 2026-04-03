<?php
/**
 * Воркер: обработка неоплаченных сделок
 * Запускать по cron каждые 5 минут:
 * */5 * * * * php /path/to/workers/handle_unpaid.php >> /path/to/storage/logs/handle_unpaid.log 2>&1
 */
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../services/Database.php';
require_once __DIR__ . '/../services/NotificationService.php';

$db = Database::getInstance();
$notify = new NotificationService();

// Находим сделки, срок подтверждения которых истёк, а статус ещё "ожидает подтверждения"
$deals = $db->fetchAll("
    SELECT d.*, l.auto_offer_second, l.title
    FROM deals d
    JOIN listings l ON d.listing_id = l.id
    WHERE d.status = 'awaiting_confirmation'
      AND d.expires_at <= NOW()
");

foreach ($deals as $deal) {
    // Если покупатель не подтвердил
    if ($deal['buyer_confirmed'] == 0) {
        // Блокируем покупателя на 30 дней
        $db->insert('user_blocks', [
            'user_id' => $deal['buyer_id'],
            'block_type' => 'auction',
            'expires_at' => date('Y-m-d H:i:s', strtotime('+30 days')),
            'reason' => 'Неоплата выигрыша'
        ]);

        // Уведомляем покупателя о блокировке
        $notify->send($deal['buyer_id'], 'auction_blocked', [
            'listing_id' => $deal['listing_id'],
            'title' => $deal['title'],
            'expires_days' => 30
        ]);

        // Если продавец включил автоматическое предложение второму участнику
        if ($deal['auto_offer_second']) {
            // Находим второго участника (следующую по величине ставку, не равную победителю)
            $secondBid = $db->fetchOne("
                SELECT user_id, bid_price
                FROM auction_bids
                WHERE listing_id = ? AND user_id != ?
                ORDER BY bid_price DESC
                LIMIT 1
            ", [$deal['listing_id'], $deal['buyer_id']]);

            if ($secondBid) {
                // Создаём предложение второму участнику
                $expires = date('Y-m-d H:i:s', strtotime('+24 hours'));
                $offerId = $db->insert('second_offers', [
                    'listing_id' => $deal['listing_id'],
                    'seller_id' => $deal['seller_id'],
                    'buyer_id' => $secondBid['user_id'],
                    'price' => $secondBid['bid_price'],
                    'expires_at' => $expires
                ]);

                // Уведомляем второго участника
                $notify->send($secondBid['user_id'], 'second_offer', [
                    'listing_id' => $deal['listing_id'],
                    'price' => $secondBid['bid_price'],
                    'title' => $deal['title'],
                    'offer_id' => $offerId,
                    'expires_at' => $expires
                ]);

                // Уведомляем продавца, что предложение отправлено
                $notify->send($deal['seller_id'], 'second_offer_sent', [
                    'listing_id' => $deal['listing_id'],
                    'title' => $deal['title']
                ]);
            }
        }

        // Отменяем сделку
        $db->update('deals', ['status' => 'cancelled'], 'id = ?', [$deal['id']]);

        // Логируем
        $db->insert('audit_log', [
            'event_type' => 'deal_cancelled_buyer_unpaid',
            'deal_id' => $deal['id'],
            'listing_id' => $deal['listing_id'],
            'user_id' => $deal['buyer_id']
        ]);
    } elseif ($deal['seller_confirmed'] == 0 && $deal['buyer_confirmed'] == 1) {
        // Покупатель подтвердил, продавец нет — переводим в спор
        $db->update('deals', ['status' => 'disputed'], 'id = ?', [$deal['id']]);

        // Уведомляем администратора (можно отправить email)
        // и логируем
        $db->insert('audit_log', [
            'event_type' => 'deal_disputed',
            'deal_id' => $deal['id'],
            'listing_id' => $deal['listing_id'],
            'user_id' => $deal['seller_id'],
            'details' => json_encode(['reason' => 'seller_not_confirmed'])
        ]);
    }
}