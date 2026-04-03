#!/usr/bin/env php
<?php
/* ============================================
   НАЙДУК — Очистка файлов-сирот v3.0
   - Рекурсивное сканирование всех папок /uploads
   - Сбор путей из БД (все таблицы)
   - Точное сравнение, с учётом миниатюр
   - Безопасное удаление, логирование
   ============================================ */

if (PHP_SAPI !== 'cli') {
    die("Этот скрипт предназначен только для запуска из командной строки.\n");
}

require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../services/Database.php';

define('ROOT_DIR', realpath(__DIR__ . '/..'));
define('LOCK_FILE', ROOT_DIR . '/storage/cleanup_orphan.lock');
define('LOG_FILE', ROOT_DIR . '/storage/logs/cleanup_orphan.log');
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

logMessage("========== НАЧАЛО ОЧИСТКИ ФАЙЛОВ-СИРОТ ==========");

try {
    $db = Database::getInstance();
    $pdo = $db->getPdo();
} catch (Exception $e) {
    logMessage("Не удалось подключиться к БД: " . $e->getMessage(), 'ERROR');
    exit(1);
}

// Собираем все пути из БД
$paths = [];
$tables = [
    'listing_photos' => 'url',
    'users' => 'avatar_url',
    'partner_offers' => 'logo_url',
    'shops' => ['logo_url', 'banner_url'],
    'promotions' => 'image_url',
];
foreach ($tables as $table => $field) {
    if (is_array($field)) {
        foreach ($field as $f) {
            $stmt = $pdo->query("SELECT $f FROM $table WHERE $f IS NOT NULL");
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                if ($row[$f]) $paths[] = $row[$f];
            }
        }
    } else {
        $stmt = $pdo->query("SELECT $field FROM $table WHERE $field IS NOT NULL");
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            if ($row[$field]) $paths[] = $row[$field];
        }
    }
}

// Нормализуем пути
$normalized = [];
foreach (array_unique($paths) as $p) {
    $p = ltrim($p, '/');
    $p = str_replace('\\', '/', $p);
    $normalized[] = $p;
}
logMessage("Собрано " . count($normalized) . " путей из базы данных");

$uploadDir = ROOT_DIR . '/uploads';
if (!is_dir($uploadDir)) {
    logMessage("Директория uploads не найдена", 'ERROR');
    exit(1);
}

$deleted = 0;
$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($uploadDir, RecursiveDirectoryIterator::SKIP_DOTS),
    RecursiveIteratorIterator::CHILD_FIRST
);

foreach ($iterator as $file) {
    if (!$file->isFile()) continue;
    $relative = str_replace(ROOT_DIR, '', $file->getPathname());
    $relative = ltrim($relative, '/');
    $relative = str_replace('\\', '/', $relative);

    $found = false;
    foreach ($normalized as $dbPath) {
        if ($relative === $dbPath) {
            $found = true;
            break;
        }
        // Проверка миниатюр: если в имени файла есть часть имени из БД и суффикс _thumb или _w
        $dbBase = pathinfo($dbPath, PATHINFO_FILENAME);
        $fileBase = pathinfo($relative, PATHINFO_FILENAME);
        if (strpos($fileBase, $dbBase) === 0 && preg_match('/_(thumb|w\d+)/', $fileBase)) {
            $found = true;
            break;
        }
    }
    if (!$found) {
        if (@unlink($file->getPathname())) {
            $deleted++;
            logMessage("Удалён orphan-файл: $relative");
        } else {
            logMessage("Не удалось удалить: $relative", 'WARNING');
        }
    }
}

logMessage("Удалено $deleted файлов-сирот");
logMessage("========== ОЧИСТКА ЗАВЕРШЕНА ==========");