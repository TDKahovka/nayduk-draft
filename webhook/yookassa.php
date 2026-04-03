<?php
/**
 * Webhook для обработки уведомлений от ЮKassa
 * URL: https://site.ru/webhook/yookassa.php
 * Настроить в личном кабинете ЮKassa: уведомления о платежах
 */
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../services/Database.php';

$source = file_get_contents('php://input');
$data = json_decode($source, true);

// Логируем входящий запрос для отладки
file_put_contents(__DIR__ . '/../storage/logs/yookassa.log', date('Y-m-d H:i:s') . ' ' . $source . PHP_EOL, FILE_APPEND);

if ($data['event'] === 'payment.succeeded') {
    $payment = $data['object'];
    $metadata = $payment['metadata'];
    $db = Database::getInstance();

    if ($metadata['type'] === 'auction_listing') {
        $listingId = $metadata['listing_id'];
        // Активируем аукцион
        $db->update('listings', [
            'listing_fee_paid' => 1,
            'auction_status' => 'active',
            'status' => 'active'
        ], 'id = ?', [$listingId]);

        // Логируем
        $db->insert('audit_log', [
            'event_type' => 'auction_paid',
            'listing_id' => $listingId,
            'details' => json_encode($payment)
        ]);

        // Уведомляем продавца
        $listing = $db->fetchOne("SELECT user_id, title FROM listings WHERE id = ?", [$listingId]);
        if ($listing) {
            sendNotification($listing['user_id'], 'auction_activated', [
                'listing_id' => $listingId,
                'title' => $listing['title']
            ]);
        }
    } elseif ($metadata['type'] === 'extra_bids') {
        $userId = $metadata['user_id'];
        $count = $metadata['count'] ?? 10;
        // Увеличиваем баланс платных ставок
        $db->query("UPDATE users SET extra_bids_balance = extra_bids_balance + ? WHERE id = ?", [$count, $userId]);

        // Логируем
        $db->insert('audit_log', [
            'event_type' => 'extra_bids_purchased',
            'user_id' => $userId,
            'details' => json_encode(['count' => $count])
        ]);

        // Уведомляем пользователя
        sendNotification($userId, 'extra_bids_purchased', ['count' => $count]);
    }

    // Отвечаем ЮKassa, что всё принято
    http_response_code(200);
}