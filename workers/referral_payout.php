#!/usr/bin/env php
<?php
/* ============================================
   НАЙДУК — Воркер автоматических выплат комиссий
   Версия 1.0
   - Запускать раз в месяц (например, 1-го числа)
   - Собирает все pending комиссии, формирует отчёт, отправляет на email админа
   - Помечает комиссии как выплаченные
   ============================================ */

if (php_sapi_name() !== 'cli') {
    die("CLI only");
}

require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../services/Database.php';
require_once __DIR__ . '/../services/NotificationService.php';

$db = Database::getInstance();
$pdo = $db->getPdo();

// Получаем email администратора (из настроек или первого админа)
$adminEmail = '';
$settings = $db->fetchOne("SELECT value FROM settings WHERE name = 'admin_email'");
if ($settings) {
    $adminEmail = $settings['value'];
}
if (empty($adminEmail)) {
    $admin = $db->fetchOne("SELECT email FROM users WHERE role = 'admin' LIMIT 1");
    if ($admin) {
        $adminEmail = $admin['email'];
    } else {
        die("Email администратора не найден\n");
    }
}

// Получаем все комиссии со статусом pending
$commissions = $db->fetchAll("
    SELECT rc.*, r.referrer_id, u.name as referred_name, u.email as referred_email
    FROM referral_commissions rc
    JOIN referrals r ON rc.referral_id = r.id
    JOIN users u ON r.referred_id = u.id
    WHERE rc.status = 'pending'
");

if (empty($commissions)) {
    echo "Нет pending комиссий для выплаты\n";
    exit(0);
}

// Группируем по рефереру (владельцу)
$byReferrer = [];
foreach ($commissions as $c) {
    $rid = $c['referrer_id'];
    if (!isset($byReferrer[$rid])) {
        $byReferrer[$rid] = [
            'total' => 0,
            'commissions' => []
        ];
    }
    $byReferrer[$rid]['total'] += $c['amount'];
    $byReferrer[$rid]['commissions'][] = $c;
}

// Формируем отчёт
$reportLines = [];
$reportLines[] = "Отчёт о начисленных комиссиях за период";
$reportLines[] = "Дата: " . date('Y-m-d H:i:s');
$reportLines[] = "";
$totalAll = 0;

foreach ($byReferrer as $referrerId => $data) {
    $reportLines[] = "Реферер ID: $referrerId";
    $reportLines[] = "Общая сумма к выплате: " . number_format($data['total'], 2, '.', ' ') . " ₽";
    $reportLines[] = "Детали:";
    foreach ($data['commissions'] as $c) {
        $reportLines[] = "  - Реферал: {$c['referred_name']} ({$c['referred_email']}), сумма: " . number_format($c['amount'], 2, '.', ' ') . " ₽, дата: {$c['created_at']}";
    }
    $reportLines[] = "";
    $totalAll += $data['total'];
}
$reportLines[] = "ИТОГО к выплате: " . number_format($totalAll, 2, '.', ' ') . " ₽";

$reportBody = implode("\n", $reportLines);

// Отправляем email
$notify = new NotificationService();
$subject = "[Найдук] Отчёт о комиссиях за месяц";
$sent = $notify->sendEmail($adminEmail, $subject, $reportBody);

if ($sent) {
    // Помечаем комиссии как выплаченные
    $ids = array_column($commissions, 'id');
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $pdo->prepare("UPDATE referral_commissions SET status = 'paid', paid_at = NOW() WHERE id IN ($placeholders)");
    $stmt->execute($ids);
    echo "Отправлен email на $adminEmail, помечено " . count($ids) . " комиссий как выплаченные\n";
} else {
    echo "Ошибка отправки email\n";
    exit(1);
}