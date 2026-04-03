<?php
/* ============================================
   НАЙДУК — Самодиагностика системы
   Версия 1.0 (март 2026)
   - Проверка PHP, расширений, прав на папки
   - Проверка базы данных, Redis, таблиц
   - Анализ логов и очистки
   - Красивый отчёт с цветовой индикацией
   ============================================ */

// Отключаем лимит времени
set_time_limit(60);

// Функция для получения размера папки
function folderSize($dir) {
    $size = 0;
    foreach (glob(rtrim($dir, '/').'/*', GLOB_NOSORT) as $each) {
        $size += is_file($each) ? filesize($each) : folderSize($each);
    }
    return $size;
}

function formatBytes($bytes, $precision = 2) {
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $bytes = max($bytes, 0);
    $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
    $pow = min($pow, count($units) - 1);
    $bytes /= pow(1024, $pow);
    return round($bytes, $precision) . ' ' . $units[$pow];
}

// Сбор данных
$checks = [];

// 1. Версия PHP
$checks['php_version'] = [
    'name' => 'Версия PHP',
    'status' => version_compare(PHP_VERSION, '8.2', '>=') ? 'ok' : 'error',
    'message' => PHP_VERSION,
    'suggestion' => version_compare(PHP_VERSION, '8.2', '>=') ? '' : 'Требуется PHP 8.2 или выше. Обновите PHP.'
];

// 2. Необходимые расширения
$extensions = ['pdo', 'pdo_mysql', 'json', 'curl', 'mbstring', 'gd'];
$extensions_extra = ['redis', 'zip', 'xml'];
foreach ($extensions as $ext) {
    $loaded = extension_loaded($ext);
    $checks["ext_{$ext}"] = [
        'name' => "Расширение {$ext}",
        'status' => $loaded ? 'ok' : 'error',
        'message' => $loaded ? 'доступно' : 'отсутствует',
        'suggestion' => $loaded ? '' : "Установите расширение PHP {$ext}."
    ];
}
foreach ($extensions_extra as $ext) {
    $loaded = extension_loaded($ext);
    $checks["ext_{$ext}"] = [
        'name' => "Расширение {$ext}",
        'status' => $loaded ? 'warning' : 'error',
        'message' => $loaded ? 'доступно' : 'отсутствует',
        'suggestion' => $loaded ? '' : "Рекомендуется установить расширение {$ext} для полной функциональности."
    ];
}

// 3. Права на папки
$folders = [
    '/storage',
    '/storage/logs',
    '/storage/cache',
    '/storage/sessions',
    '/uploads',
    '/uploads/import_temp',
    '/uploads/offers_logos',
    '/uploads/qrcodes'
];
foreach ($folders as $folder) {
    $path = __DIR__ . $folder;
    $isWritable = is_writable($path);
    $exists = file_exists($path);
    $checks["folder_{$folder}"] = [
        'name' => "Папка {$folder}",
        'status' => ($exists && $isWritable) ? 'ok' : ($exists ? 'error' : 'warning'),
        'message' => $exists ? ($isWritable ? 'доступна для записи' : 'нет прав на запись') : 'не существует',
        'suggestion' => $exists ? ($isWritable ? '' : 'Установите права 755 или 777 на папку.') : 'Создайте папку и установите права 755.'
    ];
}

// 4. Файл .env
$envFile = __DIR__ . '/.env';
$configFile = __DIR__ . '/config/database.php';
$envExists = file_exists($envFile);
$configExists = file_exists($configFile);
$checks['env'] = [
    'name' => 'Файл .env',
    'status' => $envExists ? 'ok' : 'error',
    'message' => $envExists ? 'найден' : 'отсутствует',
    'suggestion' => $envExists ? '' : 'Создайте .env на основе .env.example или запустите install.php.'
];
$checks['config'] = [
    'name' => 'Файл config/database.php',
    'status' => $configExists ? 'ok' : 'warning',
    'message' => $configExists ? 'найден' : 'отсутствует',
    'suggestion' => $configExists ? '' : 'Рекомендуется создать config/database.php (автоматически при установке).'
];

// 5. База данных
$dbOk = false;
$dbError = '';
try {
    if ($configExists) {
        $config = require $configFile;
        $dsn = "mysql:host={$config['host']};dbname={$config['database']};charset={$config['charset']}";
        $pdo = new PDO($dsn, $config['username'], $config['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $dbOk = true;
    } elseif ($envExists) {
        $env = parse_ini_file($envFile);
        $host = $env['DB_HOST'] ?? 'localhost';
        $dbname = $env['DB_NAME'] ?? 'nayduk';
        $user = $env['DB_USER'] ?? 'root';
        $pass = $env['DB_PASS'] ?? '';
        $dsn = "mysql:host={$host};dbname={$dbname};charset=utf8mb4";
        $pdo = new PDO($dsn, $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $dbOk = true;
    }
} catch (Exception $e) {
    $dbError = $e->getMessage();
}
$checks['database'] = [
    'name' => 'Подключение к БД',
    'status' => $dbOk ? 'ok' : 'error',
    'message' => $dbOk ? 'успешно' : $dbError,
    'suggestion' => $dbOk ? '' : 'Проверьте настройки подключения в .env или config/database.php.'
];

// 6. Таблицы в БД (если БД доступна)
$tables = ['users', 'partner_offers', 'partner_clicks', 'partner_conversions', 'price_offers', 'supplier_buyer_relations', 'cpa_networks', 'webhooks'];
$missingTables = [];
if ($dbOk) {
    $stmt = $pdo->query("SHOW TABLES");
    $existing = $stmt->fetchAll(PDO::FETCH_COLUMN);
    foreach ($tables as $table) {
        if (!in_array($table, $existing)) {
            $missingTables[] = $table;
        }
    }
}
$checks['tables'] = [
    'name' => 'Таблицы БД',
    'status' => empty($missingTables) ? 'ok' : 'error',
    'message' => empty($missingTables) ? 'все основные таблицы созданы' : 'отсутствуют: ' . implode(', ', $missingTables),
    'suggestion' => empty($missingTables) ? '' : 'Выполните миграции (SQL в /database/migrations/) или запустите install.php.'
];

// 7. Redis (опционально)
$redisOk = false;
if (class_exists('Redis')) {
    try {
        $redis = new Redis();
        $redis->connect('127.0.0.1', 6379, 0.5);
        $redisOk = true;
    } catch (Exception $e) {
        // Игнорируем
    }
}
$checks['redis'] = [
    'name' => 'Redis',
    'status' => $redisOk ? 'ok' : 'warning',
    'message' => $redisOk ? 'доступен' : 'недоступен',
    'suggestion' => $redisOk ? '' : 'Redis не используется или не настроен. Для кэширования рекомендуется установить Redis.'
];

// 8. Размер логов
$logDir = __DIR__ . '/storage/logs';
$logSize = 0;
if (is_dir($logDir)) {
    $logSize = folderSize($logDir);
}
$logSizeFormatted = formatBytes($logSize);
$checks['logs'] = [
    'name' => 'Логи',
    'status' => $logSize < 50 * 1024 * 1024 ? 'ok' : 'warning',
    'message' => "размер {$logSizeFormatted}",
    'suggestion' => $logSize < 50 * 1024 * 1024 ? '' : 'Логи занимают много места. Запустите очистку.'
];

// 9. Последняя очистка (по файлу или по таблице)
$lastCleanup = null;
$cleanupFile = __DIR__ . '/storage/last_cleanup.txt';
if (file_exists($cleanupFile)) {
    $lastCleanup = file_get_contents($cleanupFile);
}
$checks['cleanup'] = [
    'name' => 'Очистка данных',
    'status' => $lastCleanup ? 'ok' : 'warning',
    'message' => $lastCleanup ? date('d.m.Y H:i:s', (int)$lastCleanup) : 'не выполнялась',
    'suggestion' => $lastCleanup ? '' : 'Запустите воркер /workers/cleanup.php для автоматической очистки (настройте cron).'
];

// 10. Наличие файла установки
$installLock = __DIR__ . '/storage/install.lock';
$checks['install'] = [
    'name' => 'Установка',
    'status' => file_exists($installLock) ? 'ok' : 'warning',
    'message' => file_exists($installLock) ? 'завершена' : 'не завершена',
    'suggestion' => file_exists($installLock) ? '' : 'Запустите /install.php для завершения установки.'
];

// 11. PHP memory limit
$memoryLimit = ini_get('memory_limit');
$memoryBytes = (int) $memoryLimit;
if (strpos($memoryLimit, 'G') !== false) $memoryBytes *= 1024;
if (strpos($memoryLimit, 'M') !== false) $memoryBytes *= 1024;
$checks['memory'] = [
    'name' => 'PHP Memory Limit',
    'status' => $memoryBytes >= 128 * 1024 * 1024 ? 'ok' : 'warning',
    'message' => $memoryLimit,
    'suggestion' => $memoryBytes >= 128 * 1024 * 1024 ? '' : 'Рекомендуется установить memory_limit не менее 128M.'
];

// 12. Максимальное время выполнения
$maxExec = ini_get('max_execution_time');
$checks['max_exec'] = [
    'name' => 'max_execution_time',
    'status' => $maxExec >= 60 ? 'ok' : 'warning',
    'message' => "{$maxExec} сек",
    'suggestion' => $maxExec >= 60 ? '' : 'Для воркеров рекомендуется установить 60–120 секунд.'
];

// Подсчёт итогов
$totalOk = 0;
$totalWarning = 0;
$totalError = 0;
foreach ($checks as $c) {
    if ($c['status'] === 'ok') $totalOk++;
    elseif ($c['status'] === 'warning') $totalWarning++;
    else $totalError++;
}
$overallStatus = ($totalError === 0) ? ($totalWarning === 0 ? 'perfect' : 'good') : 'bad';
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Диагностика системы — Найдук</title>
    <style>
        /* ===== ПОЛНАЯ ДИЗАЙН-СИСТЕМА ===== */
        :root {
            --primary: #4A90E2;
            --primary-dark: #2E5C8A;
            --primary-light: #6BA5E7;
            --accent: #FF6B35;
            --success: #34C759;
            --warning: #FF9500;
            --danger: #FF3B30;
            --info: #5A67D8;
            --bg: #F5F5F7;
            --bg-secondary: #EFEFF4;
            --surface: #FFFFFF;
            --text: #1D1D1F;
            --text-secondary: #86868B;
            --border: #D2D2D7;
            --border-light: #E5E5EA;
            --radius-sm: 6px;
            --radius: 10px;
            --radius-lg: 14px;
            --radius-xl: 20px;
            --radius-2xl: 28px;
            --radius-full: 9999px;
            --shadow-sm: 0 2px 8px rgba(0,0,0,0.05);
            --shadow-md: 0 4px 16px rgba(0,0,0,0.1);
            --shadow-lg: 0 8px 32px rgba(0,0,0,0.15);
            --transition: 0.2s ease;
        }

        @media (prefers-color-scheme: dark) {
            :root {
                --bg: #000000;
                --bg-secondary: #1C1C1E;
                --surface: #1C1C1E;
                --text: #F5F5F7;
                --text-secondary: #A1A1A6;
                --border: #38383A;
                --border-light: #2C2C2E;
            }
        }

        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: var(--bg);
            color: var(--text);
            line-height: 1.5;
            padding: 20px;
        }
        .container {
            max-width: 1200px;
            margin: 0 auto;
        }
        h1 {
            font-size: 32px;
            font-weight: 700;
            margin-bottom: 8px;
        }
        .subtitle {
            color: var(--text-secondary);
            margin-bottom: 30px;
        }
        .overall {
            background: var(--surface);
            border-radius: var(--radius-2xl);
            padding: 24px;
            margin-bottom: 30px;
            box-shadow: var(--shadow-sm);
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 20px;
        }
        .overall-status {
            display: flex;
            align-items: center;
            gap: 12px;
        }
        .status-badge {
            padding: 8px 20px;
            border-radius: var(--radius-full);
            font-weight: 600;
            font-size: 18px;
        }
        .status-perfect {
            background: rgba(52,199,89,0.2);
            color: var(--success);
        }
        .status-good {
            background: rgba(255,149,0,0.2);
            color: var(--warning);
        }
        .status-bad {
            background: rgba(255,59,48,0.2);
            color: var(--danger);
        }
        .stats {
            display: flex;
            gap: 20px;
        }
        .stat {
            text-align: center;
        }
        .stat-number {
            font-size: 28px;
            font-weight: 700;
        }
        .stat-label {
            font-size: 14px;
            color: var(--text-secondary);
        }
        .grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(350px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }
        .card {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: var(--radius-xl);
            padding: 20px;
            transition: all var(--transition);
        }
        .card-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 12px;
        }
        .card-title {
            font-weight: 600;
            font-size: 16px;
        }
        .status-icon {
            font-size: 24px;
        }
        .status-ok { color: var(--success); }
        .status-warning { color: var(--warning); }
        .status-error { color: var(--danger); }
        .card-message {
            font-size: 14px;
            color: var(--text-secondary);
            margin-bottom: 12px;
            word-break: break-word;
        }
        .card-suggestion {
            font-size: 13px;
            background: var(--bg-secondary);
            padding: 8px 12px;
            border-radius: var(--radius);
            margin-top: 12px;
            color: var(--text-secondary);
        }
        .btn {
            display: inline-block;
            background: var(--primary);
            color: white;
            padding: 8px 20px;
            border-radius: var(--radius-full);
            text-decoration: none;
            font-weight: 600;
            transition: all var(--transition);
        }
        .btn:hover {
            background: var(--primary-dark);
            transform: translateY(-2px);
        }
        .btn-secondary {
            background: var(--bg-secondary);
            color: var(--text);
            border: 1px solid var(--border);
        }
        .btn-secondary:hover {
            background: var(--border-light);
        }
        .actions {
            display: flex;
            gap: 12px;
            flex-wrap: wrap;
            margin-top: 20px;
        }
        @media (max-width: 768px) {
            .overall {
                flex-direction: column;
                align-items: flex-start;
            }
            .stats {
                width: 100%;
                justify-content: space-between;
            }
            .grid {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
<div class="container">
    <h1>🔍 Диагностика системы</h1>
    <div class="subtitle">Проверка здоровья платформы Найдук</div>

    <div class="overall">
        <div class="overall-status">
            <span class="status-badge <?= $overallStatus === 'perfect' ? 'status-perfect' : ($overallStatus === 'good' ? 'status-good' : 'status-bad') ?>">
                <?= $overallStatus === 'perfect' ? 'ИДЕАЛЬНО' : ($overallStatus === 'good' ? 'ХОРОШО' : 'ТРЕБУЕТ ВНИМАНИЯ') ?>
            </span>
            <div>Всего проверок: <?= count($checks) ?></div>
        </div>
        <div class="stats">
            <div class="stat">
                <div class="stat-number" style="color: var(--success);"><?= $totalOk ?></div>
                <div class="stat-label">Успешно</div>
            </div>
            <div class="stat">
                <div class="stat-number" style="color: var(--warning);"><?= $totalWarning ?></div>
                <div class="stat-label">Предупреждения</div>
            </div>
            <div class="stat">
                <div class="stat-number" style="color: var(--danger);"><?= $totalError ?></div>
                <div class="stat-label">Ошибки</div>
            </div>
        </div>
    </div>

    <div class="grid">
        <?php foreach ($checks as $key => $check): 
            $icon = $check['status'] === 'ok' ? '✅' : ($check['status'] === 'warning' ? '⚠️' : '❌');
            $colorClass = $check['status'] === 'ok' ? 'status-ok' : ($check['status'] === 'warning' ? 'status-warning' : 'status-error');
        ?>
        <div class="card">
            <div class="card-header">
                <span class="card-title"><?= htmlspecialchars($check['name']) ?></span>
                <span class="status-icon <?= $colorClass ?>"><?= $icon ?></span>
            </div>
            <div class="card-message"><?= htmlspecialchars($check['message']) ?></div>
            <?php if (!empty($check['suggestion'])): ?>
                <div class="card-suggestion">💡 <?= htmlspecialchars($check['suggestion']) ?></div>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>
    </div>

    <div class="actions">
        <a href="?" class="btn">🔄 Обновить</a>
        <?php if (!file_exists(__DIR__ . '/storage/install.lock')): ?>
            <a href="/install.php" class="btn btn-primary">📦 Запустить установку</a>
        <?php endif; ?>
        <a href="/" class="btn btn-secondary">🏠 На главную</a>
    </div>
    <div class="subtitle" style="margin-top: 30px; text-align: center;">
        Последняя проверка: <?= date('d.m.Y H:i:s') ?>
    </div>
</div>
</body>
</html>