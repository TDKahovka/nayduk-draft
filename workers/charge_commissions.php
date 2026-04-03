<?php
/**
 * Воркер: начисление комиссии продавцам после подтверждения сделки
 * Запускать по cron раз в час:
 * 0 * * * * php /path/to/workers/charge_commissions.php >> /path/to/storage/logs/charge_commissions.log 2>&1
 */
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../services/Database.php';
require_once __DIR__ . '/../services/NotificationService.php';

$db = Database::getInstance();
$notify = new NotificationService();

// Находим подтверждённые сделки, по которым ещё не начислена комиссия
$deals = $db->fetchAll("
    SELECT * FROM deals
    WHERE status = 'confirmed' AND commission_charged = 0
");

foreach ($deals as $deal) {
    // Рассчитываем комиссию: 2% от цены, минимум 50, максимум 2000
    $commission = $deal['price'] * 0.02;
    $commission = max(50, min(2000, $commission));

    // Создаём запись о комиссии
    $dueDate = date('Y-m-d H:i:s', strtotime('+7 days'));
    $db->insert('seller_commissions', [
        'seller_id' => $deal['seller_id'],
        'deal_id' => $deal['id'],
        'amount' => $commission,
        'status' => 'pending',
        'due_date' => $dueDate
    ]);

    // Отмечаем сделку как обработанную
    $db->update('deals', ['commission_charged' => 1], 'id = ?', [$deal['id']]);

    // Уведомляем продавца о необходимости оплатить комиссию
    $notify->send($deal['seller_id'], 'commission_due', [
        'deal_id' => $deal['id'],
        'amount' => $commission,
        'due_date' => $dueDate
    ]);

    // Логируем
    $db->insert('audit_log', [
        'event_type' => 'commission_charged',
        'deal_id' => $deal['id'],
        'user_id' => $deal['seller_id'],
        'details' => json_encode(['amount' => $commission, 'due_date' => $dueDate])
    ]);
}