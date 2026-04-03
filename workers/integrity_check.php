#!/usr/bin/env php
<?php
/* ============================================
   НАЙДУК — Контроль целостности данных v2.0
   - Проверка внешних ключей, поиск сирот
   - Безопасное исправление JSON-полей (json_decode)
   - Детекция циклов в listing_categories
   - Расширенное сканирование файлов-сирот
   - Блокировка параллельного запуска (flock)
   - Ротация логов
   ============================================ */

// ==================== ПРОВЕРКА ДОСТУПА ====================
$isCli = (PHP_SAPI === 'cli');
if (!$isCli && session_status() === PHP_SESSION_NONE) {
    session_start();
}
if (!$isCli && (!isset($_SESSION['user_id']) || !isset($_SESSION['role']) || $_SESSION['role'] !== 'admin')) {
    http_response_code(403);
    die('Доступ запрещён');
}
if (!$isCli && (!isset($_POST['csrf_token']) || !verifyCsrfToken($_POST['csrf_token']))) {
    die('Недействительный CSRF-токен');
}

// ==================== КОНФИГУРАЦИЯ ====================
define('ROOT_DIR', dirname(__DIR__));
define('LOCK_FILE', ROOT_DIR . '/storage/integrity.lock');
define('LOG_FILE', ROOT_DIR . '/storage/logs/integrity.log');
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

// Блокировка параллельного запуска
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

logMessage("========== НАЧАЛО ПРОВЕРКИ ЦЕЛОСТНОСТИ ==========");

// ==================== ПОДКЛЮЧЕНИЕ К БД ====================
require_once ROOT_DIR . '/includes/functions.php';
require_once ROOT_DIR . '/services/Database.php';

try {
    $db = Database::getInstance();
    $pdo = $db->getConnection();
} catch (Exception $e) {
    logMessage("Не удалось подключиться к БД: " . $e->getMessage(), 'ERROR');
    exit(1);
}

// ==================== ВСПОМОГАТЕЛЬНЫЕ ФУНКЦИИ ====================

function tableExists($pdo, $table) {
    $stmt = $pdo->prepare("SHOW TABLES LIKE ?");
    $stmt->execute([$table]);
    return $stmt->fetch() !== false;
}

function jsonIsValid($str) {
    if (!is_string($str)) return false;
    json_decode($str);
    return json_last_error() === JSON_ERROR_NONE;
}

function safeJsonFix($pdo, $table, $field, $id) {
    $stmt = $pdo->prepare("SELECT $field FROM $table WHERE id = ?");
    $stmt->execute([$id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row || $row[$field] === null) return false;

    $value = $row[$field];
    if (!jsonIsValid($value)) {
        // Пытаемся исправить (например, обернуть в кавычки или установить NULL)
        $fixed = null;
        // Если значение начинается с { или [ и заканчивается } или ], но не валидно – возможно, проблема в кодировке
        if (preg_match('/^[\{\[].*[\}\]]$/s', $value)) {
            // Пробуем декодировать с опцией JSON_INVALID_UTF8_SUBSTITUTE (PHP 7.3+)
            json_decode($value, true, 512, JSON_INVALID_UTF8_SUBSTITUTE);
            if (json_last_error() === JSON_ERROR_NONE) {
                $fixed = $value;
            } else {
                $fixed = null;
            }
        } else {
            $fixed = null;
        }
        if ($fixed !== $value) {
            $update = $pdo->prepare("UPDATE $table SET $field = ? WHERE id = ?");
            $update->execute([$fixed, $id]);
            logMessage("Исправлено некорректное JSON в $table.$field (ID $id)", 'INFO');
            return true;
        }
    }
    return false;
}

function detectCategoryCycle($pdo, $startId) {
    $visited = [];
    $stack = [$startId];
    while (!empty($stack)) {
        $current = array_pop($stack);
        if (isset($visited[$current])) continue;
        $visited[$current] = true;
        $stmt = $pdo->prepare("SELECT parent_id FROM listing_categories WHERE id = ?");
        $stmt->execute([$current]);
        $parent = $stmt->fetchColumn();
        if ($parent) {
            if ($parent == $startId) return true;
            if (isset($visited[$parent])) continue;
            $stack[] = $parent;
        }
    }
    return false;
}

// ==================== ПРОВЕРКА ТАБЛИЦ ====================
$tablesToCheck = [
    'partner_offers', 'partner_clicks', 'partner_conversions', 'partner_payouts',
    'price_offers', 'supplier_buyer_relations', 'listing_categories',
    'listings', 'users', 'webhooks'
];

foreach ($tablesToCheck as $table) {
    if (!tableExists($pdo, $table)) {
        logMessage("Таблица $table не существует, пропускаем проверки для неё", 'WARNING');
    }
}

$issues = 0;

// ==================== 1. ВНЕШНИЕ КЛЮЧИ ====================
// partner_clicks -> partner_offers
if (tableExists($pdo, 'partner_clicks') && tableExists($pdo, 'partner_offers')) {
    $sql = "SELECT pc.id FROM partner_clicks pc LEFT JOIN partner_offers po ON pc.offer_id = po.id WHERE po.id IS NULL";
    $stmt = $pdo->query($sql);
    $orphans = $stmt->fetchAll(PDO::FETCH_COLUMN);
    if (!empty($orphans)) {
        logMessage("Найдено сирот в partner_clicks (нет offer_id): " . count($orphans), 'WARNING');
        $issues += count($orphans);
        // Не удаляем, только логируем
    }
}

// partner_conversions -> partner_clicks
if (tableExists($pdo, 'partner_conversions') && tableExists($pdo, 'partner_clicks')) {
    $sql = "SELECT pc.id FROM partner_conversions pc LEFT JOIN partner_clicks pcl ON pc.click_id = pcl.click_id WHERE pcl.click_id IS NULL";
    $stmt = $pdo->query($sql);
    $orphans = $stmt->fetchAll(PDO::FETCH_COLUMN);
    if (!empty($orphans)) {
        logMessage("Найдено сирот в partner_conversions (нет click_id): " . count($orphans), 'WARNING');
        $issues += count($orphans);
    }
}

// supplier_buyer_relations -> users
if (tableExists($pdo, 'supplier_buyer_relations') && tableExists($pdo, 'users')) {
    $sql = "SELECT sbr.id FROM supplier_buyer_relations sbr LEFT JOIN users u ON sbr.supplier_id = u.id WHERE u.id IS NULL";
    $stmt = $pdo->query($sql);
    $orphans = $stmt->fetchAll(PDO::FETCH_COLUMN);
    if (!empty($orphans)) {
        logMessage("Найдено сирот в supplier_buyer_relations (нет supplier_id): " . count($orphans), 'WARNING');
        $issues += count($orphans);
    }
    $sql = "SELECT sbr.id FROM supplier_buyer_relations sbr LEFT JOIN users u ON sbr.buyer_id = u.id WHERE u.id IS NULL";
    $stmt = $pdo->query($sql);
    $orphans = $stmt->fetchAll(PDO::FETCH_COLUMN);
    if (!empty($orphans)) {
        logMessage("Найдено сирот в supplier_buyer_relations (нет buyer_id): " . count($orphans), 'WARNING');
        $issues += count($orphans);
    }
}

// ==================== 2. JSON-ПОЛЯ ====================
// partner_offers: working_hours, wholesale_prices
if (tableExists($pdo, 'partner_offers')) {
    $fields = ['working_hours', 'wholesale_prices'];
    foreach ($fields as $field) {
        // Проверяем, существует ли колонка
        $stmt = $pdo->prepare("SHOW COLUMNS FROM partner_offers LIKE ?");
        $stmt->execute([$field]);
        if ($stmt->fetch()) {
            $stmt = $pdo->query("SELECT id FROM partner_offers WHERE $field IS NOT NULL");
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                if (safeJsonFix($pdo, 'partner_offers', $field, $row['id'])) {
                    $issues++;
                }
            }
        }
    }
}

// ==================== 3. ЦИКЛЫ В КАТЕГОРИЯХ ====================
if (tableExists($pdo, 'listing_categories')) {
    $stmt = $pdo->query("SELECT id FROM listing_categories WHERE parent_id != 0");
    $cycles = 0;
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        if (detectCategoryCycle($pdo, $row['id'])) {
            logMessage("Обнаружен цикл в категории ID {$row['id']}", 'WARNING');
            // Исправление: сбросить parent_id в 0
            $pdo->prepare("UPDATE listing_categories SET parent_id = 0 WHERE id = ?")->execute([$row['id']]);
            $cycles++;
        }
    }
    if ($cycles) {
        logMessage("Исправлено циклов в категориях: $cycles", 'INFO');
        $issues += $cycles;
    }
}

// ==================== 4. ФАЙЛЫ-СИРОТЫ ====================
$uploadDir = ROOT_DIR . '/uploads';
if (is_dir($uploadDir)) {
    // Собираем все пути из БД
    $dbPaths = [];

    $tablesPaths = [
        'listing_photos' => 'url',
        'users' => 'avatar_url',
        'partner_offers' => 'logo_url',
        'shops' => ['logo_url', 'banner_url'],
        'promotions' => 'image_url'
    ];
    foreach ($tablesPaths as $table => $cols) {
        if (!tableExists($pdo, $table)) continue;
        if (is_array($cols)) {
            foreach ($cols as $col) {
                $stmt = $pdo->query("SELECT $col FROM $table WHERE $col IS NOT NULL");
                while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                    if ($row[$col]) $dbPaths[] = ltrim($row[$col], '/');
                }
            }
        } else {
            $stmt = $pdo->query("SELECT $cols FROM $table WHERE $cols IS NOT NULL");
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                if ($row[$cols]) $dbPaths[] = ltrim($row[$cols], '/');
            }
        }
    }

    $dbPaths = array_unique($dbPaths);
    $orphanFiles = 0;

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
        foreach ($dbPaths as $dbPath) {
            if ($relative === $dbPath || strpos($dbPath, basename($relative)) !== false) {
                $found = true;
                break;
            }
        }
        if (!$found) {
            $orphanFiles++;
            // Не удаляем, только логируем
            logMessage("Найден файл-сирота: $relative", 'WARNING');
        }
    }
    if ($orphanFiles) {
        logMessage("Всего файлов-сирот: $orphanFiles", 'WARNING');
        $issues += $orphanFiles;
    }
}

// ==================== 5. ПРОВЕРКА НАЛИЧИЯ НЕОБХОДИМЫХ ИНДЕКСОВ ====================
$requiredIndexes = [
    'listings' => ['user_id', 'status', 'created_at', 'updated_at', 'category_id'],
    'users' => ['email', 'last_login'],
    'partner_clicks' => ['offer_id', 'click_owner_id'],
    'partner_conversions' => ['click_id'],
    'messages' => ['chat_id', 'receiver_id', 'created_at'],
];
foreach ($requiredIndexes as $table => $columns) {
    if (!tableExists($pdo, $table)) continue;
    $existing = $pdo->query("SHOW INDEX FROM $table")->fetchAll(PDO::FETCH_COLUMN);
    foreach ($columns as $col) {
        if (!in_array($col, $existing)) {
            logMessage("Отсутствует индекс на $table.$col. Рекомендуется добавить.", 'WARNING');
            // Не добавляем автоматически, чтобы не нагружать БД; только предупреждение
        }
    }
}

logMessage("========== ПРОВЕРКА ЗАВЕРШЕНА ==========");
logMessage("Всего найдено/исправлено проблем: $issues");

// Удаляем блокировку
flock($fp, LOCK_UN);
fclose($fp);
@unlink(LOCK_FILE);

// Если скрипт запущен через браузер, показываем сообщение
if (!$isCli) {
    echo '<!DOCTYPE html><html><head><title>Проверка целостности</title></head><body>';
    echo '<h1>Проверка целостности выполнена</h1>';
    echo '<p>Найдено/исправлено проблем: ' . $issues . '</p>';
    echo '<p><a href="/admin/system">Вернуться</a></p>';
    echo '</body></html>';
}