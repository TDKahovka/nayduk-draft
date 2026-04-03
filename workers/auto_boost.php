#!/usr/bin/env php
<?php
/* ============================================
   НАЙДУК — Воркер автоматического подъёма объявлений
   - Блокировка параллельного запуска
   - Таймаут
   ============================================ */

if (PHP_SAPI !== 'cli') {
    die("Этот скрипт только для командной строки.\n");
}

set_time_limit(0);

$lockFile = __DIR__ . '/../storage/auto_boost.lock';
$fp = fopen($lockFile, 'w');
if (!$fp || !flock($fp, LOCK_EX | LOCK_NB)) {
    echo "Скрипт уже запущен, выход.\n";
    exit(0);
}

require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../services/Database.php';

$db = Database::getInstance();
$pdo = $db->getPdo();

try {
    $pdo->exec("ALTER TABLE listings ADD COLUMN IF NOT EXISTS next_auto_boost_at TIMESTAMP NULL");
    $pdo->exec("ALTER TABLE listings ADD COLUMN IF NOT EXISTS views_last_30_days INT DEFAULT 0");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_next_auto_boost ON listings(next_auto_boost_at)");
} catch (Exception $e) {}

$limit = 1000;
$updated = 0;

do {
    $stmt = $pdo->prepare("
        UPDATE listings 
        SET 
            next_auto_boost_at = NOW() + INTERVAL (CASE WHEN views_last_30_days > 0 THEN 3 ELSE 10 END) DAY,
            last_auto_boost_at = NOW()
        WHERE 
            next_auto_boost_at <= NOW() 
            AND status IN ('approved', 'featured')
        LIMIT ?
    ");
    $stmt->execute([$limit]);
    $affected = $stmt->rowCount();
    $updated += $affected;
    if ($affected > 0) {
        usleep(50000);
    }
} while ($affected > 0);

echo "[" . date('Y-m-d H:i:s') . "] auto_boost: $updated listings updated\n";

flock($fp, LOCK_UN);
fclose($fp);
@unlink($lockFile);