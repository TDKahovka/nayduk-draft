#!/usr/bin/env php
<?php
/* ============================================
   НАЙДУК — Очистка черновиков старше 24 часов v3.0
   - Блокировка параллельного запуска (flock)
   - Пакетное удаление (по 1000 записей)
   - Проверка существования таблицы
   - Подробное логирование
   ============================================ */

if (PHP_SAPI !== 'cli') {
    die("Этот скрипт предназначен только для запуска из командной строки.\n");
}

require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../services/Database.php';

define('LOCK_FILE', __DIR__ . '/../storage/cleanup_drafts.lock');
define('LOG_FILE', __DIR__ . '/../storage/logs/cleanup_drafts.log');
define('LOG_MAX_SIZE', 10 * 1024 * 1024); // 10 МБ

function logMessage($message, $level = 'INFO') {
    if (file_exists(LOG_FILE) && filesize(LOG_FILE) > LOG_MAX_SIZE) {
        $backup = LOG_FILE . '.' . date('Ymd-His');
        rename(LOG_FILE, $backup);
    }
    $date = date('Y-m-d H:i:s');
    $line = "[$date] [$level] $message" . PHP_EOL;
    file_put_contents(LOG_FILE, $line, FILE_APPEND | LOCK_EX);
}

$fp = fopen(LOCK_FILE, 'w');
if (!$fp || !flock($fp, LOCK_EX | LOCK_NB)) {
    logMessage("Скрипт уже запущен, выход.", 'WARNING');
    exit(0);
}
register_shutdown_function(function() use ($fp) {
    flock($fp, LOCK_UN);
    fclose($fp);
    @unlink(LOCK_FILE);
});

logMessage("========== НАЧАЛО ОЧИСТКИ ЧЕРНОВИКОВ ==========");

try {
    $db = Database::getInstance();
    $pdo = $db->getPdo();
} catch (Exception $e) {
    logMessage("Не удалось подключиться к БД: " . $e->getMessage(), 'ERROR');
    exit(1);
}

// Проверяем, существует ли таблица drafts
$tableExists = $pdo->query("SHOW TABLES LIKE 'drafts'")->rowCount() > 0;
if (!$tableExists) {
    logMessage("Таблица drafts не существует, пропускаем", 'WARNING');
    exit(0);
}

$deletedTotal = 0;
$batchSize = 1000;
$affected = 0;

do {
    $stmt = $pdo->prepare("
        DELETE FROM drafts
        WHERE updated_at < NOW() - INTERVAL 1 DAY
        LIMIT ?
    ");
    $stmt->execute([$batchSize]);
    $affected = $stmt->rowCount();
    $deletedTotal += $affected;
} while ($affected === $batchSize);

logMessage("Удалено $deletedTotal черновиков старше 24 часов");

// Логируем в security_logs
if ($deletedTotal > 0) {
    $db->insert('security_logs', [
        'user_id' => null,
        'ip_address' => 'system',
        'event_type' => 'drafts_cleanup',
        'description' => "Удалено $deletedTotal черновиков старше 24 часов",
        'severity' => 'low',
        'created_at' => date('Y-m-d H:i:s')
    ]);
}

logMessage("========== ОЧИСТКА ЗАВЕРШЕНА ==========");