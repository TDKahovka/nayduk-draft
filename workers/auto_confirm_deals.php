<?php
/* ============================================
   НАЙДУК — Воркер авто-подтверждения сделок
   Запуск: раз в сутки через cron
   ============================================ */

if (php_sapi_name() !== 'cli') {
    die("CLI only");
}

require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../services/Database.php';
require_once __DIR__ . '/../services/NotificationService.php';

$db = Database::getInstance();
$pdo = $db->getPdo();

// Ищем сделки, которые не подтверждены более 14 дней
$stmt = $pdo->prepare("
    SELECT d.*, l.title as listing_title,
           u_seller.name as seller_name,
           u_buyer.name as buyer_name
    FROM deal_confirmations d
    JOIN listings l ON d.listing_id = l.id
    JOIN users u_seller ON d.seller_id = u_seller.id
    JOIN users u_buyer ON d.buyer_id = u_buyer.id
    WHERE d.created_at < NOW() - INTERVAL 14 DAY
      AND d.confirmed_at IS NULL
");
$stmt->execute();
$deals = $stmt->fetchAll(PDO::FETCH_ASSOC);

$notify = new NotificationService();

foreach ($deals as $deal) {
    // Если только одна сторона подтвердила – автоматически подтверждаем другую
    if ($deal['seller_confirmed'] && !$deal['buyer_confirmed']) {
        $pdo->prepare("UPDATE deal_confirmations SET buyer_confirmed = 1, confirmed_at = NOW() WHERE id = ?")
            ->execute([$deal['id']]);
        // Увеличиваем счётчик успешных сделок для покупателя
        $pdo->prepare("UPDATE users SET trust_score = trust_score + 1 WHERE id = ?")->execute([$deal['buyer_id']]);
        $notify->send($deal['buyer_id'], 'deal_auto_confirmed', [
            'listing_id' => $deal['listing_id'],
            'title' => $deal['listing_title'],
            'message' => 'Сделка автоматически подтверждена через 14 дней (продавец подтвердил ранее)'
        ]);
        $notify->send($deal['seller_id'], 'deal_auto_confirmed', [
            'listing_id' => $deal['listing_id'],
            'title' => $deal['listing_title'],
            'message' => 'Покупатель автоматически подтвердил сделку через 14 дней'
        ]);
    }
    elseif (!$deal['seller_confirmed'] && $deal['buyer_confirmed']) {
        $pdo->prepare("UPDATE deal_confirmations SET seller_confirmed = 1, confirmed_at = NOW() WHERE id = ?")
            ->execute([$deal['id']]);
        $pdo->prepare("UPDATE users SET trust_score = trust_score + 1 WHERE id = ?")->execute([$deal['seller_id']]);
        $notify->send($deal['seller_id'], 'deal_auto_confirmed', [
            'listing_id' => $deal['listing_id'],
            'title' => $deal['listing_title'],
            'message' => 'Сделка автоматически подтверждена через 14 дней (покупатель подтвердил ранее)'
        ]);
        $notify->send($deal['buyer_id'], 'deal_auto_confirmed', [
            'listing_id' => $deal['listing_id'],
            'title' => $deal['listing_title'],
            'message' => 'Продавец автоматически подтвердил сделку через 14 дней'
        ]);
    }
    else {
        // Никто не подтвердил – удаляем запись (сделка не состоялась)
        $pdo->prepare("DELETE FROM deal_confirmations WHERE id = ?")->execute([$deal['id']]);
    }
}