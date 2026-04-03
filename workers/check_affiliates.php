<?php
/* ============================================
   НАЙДУК — Скрипт проверки партнёрских офферов
   Версия 1.0 (март 2026)
   - Проверяет активные офферы, удаляет просроченные
   - Обновляет статусы, отправляет уведомления владельцам
   - Запускается по cron (ежедневно)
   ============================================ */

if (php_sapi_name() !== 'cli') {
    die("Этот скрипт предназначен только для запуска из командной строки.\n");
}

require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../services/Database.php';
require_once __DIR__ . '/../services/NotificationService.php';

$db = Database::getInstance();
$pdo = $db->getPdo();

echo "[" . date('Y-m-d H:i:s') . "] Запуск проверки партнёрских офферов\n";

// 1. Деактивируем просроченные офферы
$expired = $db->fetchAll("SELECT id, user_id, name FROM partner_offers WHERE is_active = 1 AND expires_at IS NOT NULL AND expires_at < NOW()");
foreach ($expired as $offer) {
    $db->update('partner_offers', ['is_active' => 0], 'id = ?', [$offer['id']]);
    echo "Деактивирован оффер #{$offer['id']} ({$offer['name']})\n";
    // Уведомление владельцу
    $notify = new NotificationService();
    $notify->send($offer['user_id'], 'offer_expired', ['offer_id' => $offer['id'], 'name' => $offer['name']]);
}

// 2. Проверка неиспользуемых офферов (без кликов за 90 дней)
$inactive = $db->fetchAll("
    SELECT o.id, o.user_id, o.name
    FROM partner_offers o
    LEFT JOIN partner_clicks c ON o.id = c.offer_id AND c.clicked_at > NOW() - INTERVAL 90 DAY
    WHERE o.is_active = 1 AND c.id IS NULL
");
foreach ($inactive as $offer) {
    $db->update('partner_offers', ['is_active' => 0], 'id = ?', [$offer['id']]);
    echo "Деактивирован неактивный оффер #{$offer['id']} ({$offer['name']})\n";
    // Уведомление владельцу
    $notify = new NotificationService();
    $notify->send($offer['user_id'], 'offer_inactive', ['offer_id' => $offer['id'], 'name' => $offer['name']]);
}

// 3. Проверка лимитов на количество активных офферов для пользователей (не более 10)
$users = $db->fetchAll("SELECT user_id, COUNT(*) as cnt FROM partner_offers WHERE is_active = 1 GROUP BY user_id HAVING cnt > 10");
foreach ($users as $user) {
    $over = $user['cnt'] - 10;
    $offers = $db->fetchAll("SELECT id FROM partner_offers WHERE user_id = ? AND is_active = 1 ORDER BY created_at ASC LIMIT ?", [$user['user_id'], $over]);
    foreach ($offers as $offer) {
        $db->update('partner_offers', ['is_active' => 0], 'id = ?', [$offer['id']]);
        echo "Деактивирован лишний оффер #{$offer['id']} для пользователя {$user['user_id']}\n";
    }
    $notify = new NotificationService();
    $notify->send($user['user_id'], 'offer_limit_exceeded', ['count' => $user['cnt']]);
}

// 4. Очистка старых кликов (старше 1 года) – уже есть в workers/cleanup.php, но добавим для надёжности
$deleted = $db->query("DELETE FROM partner_clicks WHERE clicked_at < NOW() - INTERVAL 1 YEAR");
echo "Удалено {$deleted} старых кликов\n";

echo "[" . date('Y-m-d H:i:s') . "] Проверка завершена\n";