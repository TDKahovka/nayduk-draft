#!/usr/bin/env php
<?php
/* ============================================
   НАЙДУК — Воркер обновления просмотров (раз в сутки)
   - Блокировка параллельного запуска (flock)
   - Установка таймаута
   ============================================ */

if (PHP_SAPI !== 'cli') {
    die("Этот скрипт только для командной строки.\n");
}

set_time_limit(0);

$lockFile = __DIR__ . '/../storage/update_views.lock';
$fp = fopen($lockFile, 'w');
if (!$fp || !flock($fp, LOCK_EX | LOCK_NB)) {
    echo "Скрипт уже запущен, выход.\n";
    exit(0);
}

require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../services/Database.php';

$db = Database::getInstance();
$pdo = $db->getPdo();

$pdo->exec("
    CREATE TABLE IF NOT EXISTS listing_views (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        listing_id BIGINT UNSIGNED NOT NULL,
        user_id BIGINT UNSIGNED NULL,
        viewed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_listing_id (listing_id),
        INDEX idx_viewed_at (viewed_at),
        FOREIGN KEY (listing_id) REFERENCES listings(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
");

$sql = "
    UPDATE listings l
    LEFT JOIN (
        SELECT listing_id, COUNT(*) as cnt
        FROM listing_views
        WHERE viewed_at > NOW() - INTERVAL 30 DAY
        GROUP BY listing_id
    ) v ON l.id = v.listing_id
    SET l.views_last_30_days = COALESCE(v.cnt, 0)
    WHERE l.status IN ('approved', 'featured')
";
$pdo->exec($sql);

echo "[" . date('Y-m-d H:i:s') . "] views_last_30_days updated\n";

flock($fp, LOCK_UN);
fclose($fp);
@unlink($lockFile);