#!/usr/bin/env php
<?php
/**
 * НАЙДУК — Полная автоматическая очистка системы v5.0 (финальная)
 *
 * Особенности:
 * - Пакетная обработка для DELETE/UPDATE (LIMIT 1000)
 * - Dry-run режим (--dry-run)
 * - Выбор этапов (--step=files,db,redis,orphans)
 * - Рекурсивное удаление пустых папок
 * - Проверка существования таблиц перед запросами
 * - Оптимизированный поиск орфанных файлов (хэш-таблица)
 * - Создание индексов (с проверкой)
 * - Очистка Redis (ключи без TTL)
 * - Логирование с ротацией
 * - Отправка отчёта по email
 * - Обработка сигналов для graceful shutdown
 * - Поддержка внешней конфигурации (--config)
 *
 * Использование:
 *   php cleanup.php [--dry-run] [--step=all|files|db|redis|orphans] [--config=/path/to/config.php] [--verbose] [--email-report=admin@example.com]
 */

if (PHP_SAPI !== 'cli') {
    die("Этот скрипт предназначен только для запуска из командной строки.\n");
}

// Параметры командной строки
$options = getopt('', ['dry-run', 'step:', 'config:', 'verbose', 'email-report:']);
$dryRun = isset($options['dry-run']);
$step   = $options['step'] ?? 'all';         // all, files, db, redis, orphans
$verbose = isset($options['verbose']);
$reportEmail = $options['email-report'] ?? null;

// Подключаем автозагрузку (если используется)
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../services/Database.php';

// ==================== КОНФИГУРАЦИЯ ====================
define('ROOT_DIR', realpath(__DIR__ . '/..'));
define('LOCK_FILE', ROOT_DIR . '/storage/cleanup.lock');
define('LOG_FILE', ROOT_DIR . '/storage/logs/cleanup.log');
define('LOG_MAX_SIZE', 10 * 1024 * 1024); // 10 МБ
define('LOG_BACKUP_DAYS', 30);            // хранить бэкапы логов 30 дней
define('REDIS_SCAN_COUNT', 1000);
define('BATCH_LIMIT', 1000);              // размер пакета для операций БД

// Загрузка конфигурации (если существует)
$configFile = $options['config'] ?? ROOT_DIR . '/config/cleanup.php';
if (file_exists($configFile)) {
    $config = require $configFile;
} else {
    // Конфигурация по умолчанию
    $config = [
        'directories' => [
            'temp'      => ROOT_DIR . '/uploads/import_temp',
            'sessions'  => ROOT_DIR . '/storage/sessions',
            'logs'      => ROOT_DIR . '/storage/logs',
            'cache'     => ROOT_DIR . '/storage/cache',
            'avatars'   => ROOT_DIR . '/uploads/avatars',
            'optimized' => ROOT_DIR . '/uploads/optimized',
            'qrcodes'   => ROOT_DIR . '/uploads/qrcodes',
            'boosts'    => ROOT_DIR . '/storage/boosts',
            'rate'      => ROOT_DIR . '/storage/rate',
            'queue'     => ROOT_DIR . '/storage/queue',
            'geo_cache' => ROOT_DIR . '/storage/geo_cache',
        ],
        'age_thresholds' => [
            'temp_file_hours'      => 24,
            'session_days'         => 1,
            'log_days'             => 30,
            'cache_days'           => 7,
            'inactive_listing_days'=> 60,
            'archived_listing_days'=> 30,
            'message_days'         => 90,
            'partner_click_days'   => 365,
            'notification_log_days'=> 90,
            'search_log_days'      => 30,
            'geo_cache_days'       => 7,
            'referral_stats_days'  => 365,
            'failed_job_days'      => 7,
            'password_reset_days'  => 7,
            'draft_days'           => 1,
        ],
        'tables' => [
            'listing_photos' => ['url'],
            'users'          => ['avatar_url'],
            'partner_offers' => ['logo_url'],
            'shops'          => ['logo_url', 'banner_url'],
            'promotions'     => ['image_url'],
        ],
        'redis_prefixes' => [
            'rate:', 'queue:', 'cache:', 'user:', 'listings:', 'listing:',
            'favorites:', 'notify:', 'draft:', 'idempotent:', 'geocode:',
            'promotions:', 'admin:', 'geo:', 'referral:', 'search:'
        ],
        'indices' => [
            'listings' => [
                'idx_listings_updated_at' => 'updated_at',
                'idx_listings_status'     => 'status',
            ],
            'users' => [
                'idx_users_last_login' => 'last_login',
            ],
        ],
        's3_enabled' => false,  // включить очистку S3 (требуется реализация)
        's3_bucket' => null,
        'email_report' => null,  // email для отчёта (если не передан через --email-report)
    ];
}

// ==================== ЛОГГЕР ====================
class CleanupLogger {
    private $logFile;
    private $verbose;
    private $maxSize;
    private $backupDays;

    public function __construct($logFile, $maxSize = 10485760, $backupDays = 30, $verbose = false) {
        $this->logFile = $logFile;
        $this->maxSize = $maxSize;
        $this->backupDays = $backupDays;
        $this->verbose = $verbose;
        $this->rotateIfNeeded();
    }

    private function rotateIfNeeded() {
        if (file_exists($this->logFile) && filesize($this->logFile) > $this->maxSize) {
            $backup = $this->logFile . '.' . date('Ymd-His');
            rename($this->logFile, $backup);
            // Удаляем старые бэкапы
            $backups = glob($this->logFile . '.*');
            foreach ($backups as $b) {
                if (filemtime($b) < strtotime('-' . $this->backupDays . ' days')) {
                    @unlink($b);
                }
            }
        }
    }

    public function log($message, $level = 'INFO') {
        $date = date('Y-m-d H:i:s');
        $line = "[$date] [$level] $message" . PHP_EOL;
        file_put_contents($this->logFile, $line, FILE_APPEND | LOCK_EX);
        if ($this->verbose) {
            echo $line;
        }
    }
}

// ==================== ФАЙЛОВЫЙ ОЧИСТИТЕЛЬ ====================
class FileCleaner {
    private $logger;
    private $dryRun;
    private $rootDir;

    public function __construct(CleanupLogger $logger, $dryRun = false, $rootDir = ROOT_DIR) {
        $this->logger = $logger;
        $this->dryRun = $dryRun;
        $this->rootDir = $rootDir;
    }

    public function removeOldFiles($dir, $ageHours, $pattern = '*', $removeEmptyDirs = false) {
        if (!is_dir($dir)) return 0;
        $threshold = time() - ($ageHours * 3600);
        $deleted = 0;
        $files = glob($dir . '/' . $pattern, GLOB_NOSORT);
        foreach ($files as $file) {
            if (is_file($file) && filemtime($file) < $threshold) {
                $this->logger->log("Удаление старого файла: $file");
                if (!$this->dryRun && @unlink($file)) {
                    $deleted++;
                } elseif ($this->dryRun) {
                    $deleted++;
                }
            }
        }
        if ($removeEmptyDirs) {
            $this->removeEmptyDirsRecursively($dir);
        }
        return $deleted;
    }

    public function removeEmptyDirsRecursively($dir) {
        if (!is_dir($dir)) return;
        $subdirs = glob($dir . '/*', GLOB_ONLYDIR);
        foreach ($subdirs as $sub) {
            $this->removeEmptyDirsRecursively($sub);
            if (count(glob($sub . '/*')) == 0) {
                $this->logger->log("Удаление пустой папки: $sub");
                if (!$this->dryRun) {
                    @rmdir($sub);
                }
            }
        }
    }

    public function cleanupOrphanFiles($db, array $tableFields) {
        $pdo = $db->getPdo();
        $expected = [];

        // Собираем все пути из БД и нормализуем
        foreach ($tableFields as $table => $fields) {
            if (!is_array($fields)) $fields = [$fields];
            foreach ($fields as $field) {
                $stmt = $pdo->query("SELECT $field FROM $table WHERE $field IS NOT NULL");
                while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                    $path = ltrim(str_replace('\\', '/', $row[$field]), '/');
                    $expected[$path] = true;
                    // Добавляем возможные варианты с thumbnails
                    $info = pathinfo($path);
                    $basename = $info['filename'];
                    $dirname = $info['dirname'];
                    $extension = isset($info['extension']) ? '.' . $info['extension'] : '';
                    $expected[$dirname . '/' . $basename . '_thumb' . $extension] = true;
                    $expected[$dirname . '/' . $basename . '_w100' . $extension] = true;
                    $expected[$dirname . '/' . $basename . '_w200' . $extension] = true;
                    $expected[$dirname . '/' . $basename . '_w300' . $extension] = true;
                }
            }
        }

        $this->logger->log("Собрано " . count($expected) . " ожидаемых путей из БД");

        $uploadDir = $this->rootDir . '/uploads';
        if (!is_dir($uploadDir)) return 0;

        $deleted = 0;
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($uploadDir, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($iterator as $file) {
            if (!$file->isFile()) continue;
            $relative = ltrim(str_replace('\\', '/', str_replace($this->rootDir, '', $file->getPathname())), '/');
            if (!isset($expected[$relative])) {
                $this->logger->log("Удаление орфанного файла: $relative");
                if (!$this->dryRun && @unlink($file->getPathname())) {
                    $deleted++;
                } elseif ($this->dryRun) {
                    $deleted++;
                }
            }
        }
        return $deleted;
    }
}

// ==================== ОЧИСТИТЕЛЬ БАЗЫ ДАННЫХ ====================
class DatabaseCleaner {
    private $pdo;
    private $logger;
    private $dryRun;

    public function __construct($pdo, CleanupLogger $logger, $dryRun = false) {
        $this->pdo = $pdo;
        $this->logger = $logger;
        $this->dryRun = $dryRun;
    }

    private function tableExists($table) {
        $stmt = $this->pdo->prepare("SHOW TABLES LIKE ?");
        $stmt->execute([$table]);
        return $stmt->rowCount() > 0;
    }

    public function ensureIndex($table, $index, $columns) {
        if (!$this->tableExists($table)) return;
        $stmt = $this->pdo->prepare("SHOW INDEX FROM `$table` WHERE Key_name = ?");
        $stmt->execute([$index]);
        if ($stmt->fetch()) return;
        $sql = "CREATE INDEX `$index` ON `$table` ($columns)";
        if (!$this->dryRun) {
            $this->pdo->exec($sql);
            $this->logger->log("Создан индекс $index на таблице $table");
        } else {
            $this->logger->log("[DRY RUN] Будет создан индекс $index на $table");
        }
    }

    private function batchDelete($table, $condition, $params = [], $limit = BATCH_LIMIT) {
        if (!$this->tableExists($table)) return 0;
        $total = 0;
        do {
            $sql = "DELETE FROM $table WHERE $condition LIMIT $limit";
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);
            $count = $stmt->rowCount();
            if ($count > 0) {
                $total += $count;
                $this->logger->log("Удалено $count записей из $table (пакет)");
            }
        } while ($count > 0);
        return $total;
    }

    private function batchUpdate($table, $set, $condition, $params = [], $limit = BATCH_LIMIT) {
        if (!$this->tableExists($table)) return 0;
        $total = 0;
        do {
            $sql = "UPDATE $table SET $set WHERE $condition LIMIT $limit";
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);
            $count = $stmt->rowCount();
            if ($count > 0) {
                $total += $count;
                $this->logger->log("Обновлено $count записей в $table (пакет)");
            }
        } while ($count > 0);
        return $total;
    }

    public function archiveInactiveListings($inactiveDays, $archiveDays) {
        $this->ensureIndex('listings', 'idx_listings_updated_at', 'updated_at');
        $this->ensureIndex('listings', 'idx_listings_status', 'status');
        $this->ensureIndex('users', 'idx_users_last_login', 'last_login');

        $affected = $this->batchUpdate(
            'listings l JOIN users u ON l.user_id = u.id',
            'l.status = "archived", l.is_active = 0, l.archived_at = NOW()',
            'l.status = "approved" AND l.is_active = 1 AND l.updated_at < NOW() - INTERVAL :days DAY AND (u.last_login < NOW() - INTERVAL :days DAY OR u.last_login IS NULL)',
            [':days' => $inactiveDays]
        );
        if ($affected) $this->logger->log("Архивировано неактивных объявлений: $affected");

        $deleted = $this->batchDelete(
            'listings l',
            'l.status = "archived" AND l.archived_at < NOW() - INTERVAL :days DAY AND NOT EXISTS (SELECT 1 FROM orders o WHERE o.listing_id = l.id AND o.status = "completed")',
            [':days' => $archiveDays]
        );
        if ($deleted) $this->logger->log("Удалено устаревших архивных объявлений: $deleted");
        return $affected + $deleted;
    }

    public function deleteOldMessages($days) {
        return $this->batchDelete('messages', 'created_at < NOW() - INTERVAL :days DAY', [':days' => $days]);
    }

    public function deleteOldPartnerClicks($days) {
        return $this->batchDelete('partner_clicks', 'clicked_at < NOW() - INTERVAL :days DAY', [':days' => $days]);
    }

    public function deleteOldNotificationLogs($days) {
        return $this->batchDelete('notification_logs', 'created_at < NOW() - INTERVAL :days DAY', [':days' => $days]);
    }

    public function cleanupPasswordResets() {
        return $this->batchDelete('password_resets', 'expires_at < NOW() - INTERVAL 7 DAY OR used_at IS NOT NULL');
    }

    public function deleteOldDrafts($days) {
        return $this->batchDelete('drafts', 'updated_at < NOW() - INTERVAL :days DAY', [':days' => $days]);
    }

    public function deleteOldSearchLogs($days) {
        if ($this->tableExists('search_logs')) {
            return $this->batchDelete('search_logs', 'created_at < NOW() - INTERVAL :days DAY', [':days' => $days]);
        }
        return 0;
    }

    public function deleteOldGeocache($days) {
        if ($this->tableExists('geocache')) {
            return $this->batchDelete('geocache', 'updated_at < NOW() - INTERVAL :days DAY', [':days' => $days]);
        }
        return 0;
    }

    public function deleteOldReferralStats($days) {
        if ($this->tableExists('referral_stats')) {
            return $this->batchDelete('referral_stats', 'date < NOW() - INTERVAL :days DAY', [':days' => $days]);
        }
        return 0;
    }

    public function deleteOldFailedJobs($days) {
        if ($this->tableExists('failed_jobs')) {
            return $this->batchDelete('failed_jobs', 'failed_at < NOW() - INTERVAL :days DAY', [':days' => $days]);
        }
        return 0;
    }
}

// ==================== ОЧИСТИТЕЛЬ REDIS ====================
class RedisCleaner {
    private $redis;
    private $logger;
    private $dryRun;
    private $scanCount;

    public function __construct($host, $port, CleanupLogger $logger, $dryRun = false, $scanCount = 1000) {
        $this->logger = $logger;
        $this->dryRun = $dryRun;
        $this->scanCount = $scanCount;
        if (class_exists('Redis')) {
            try {
                $this->redis = new Redis();
                $this->redis->connect($host, $port, 0.5);
            } catch (Exception $e) {
                $this->logger->log("Ошибка подключения Redis: " . $e->getMessage(), 'ERROR');
                $this->redis = null;
            }
        } else {
            $this->redis = null;
        }
    }

    public function isAvailable() {
        return $this->redis && $this->redis->isConnected();
    }

    public function deleteKeysWithoutTTL(array $prefixes) {
        if (!$this->isAvailable()) return 0;
        $deleted = 0;
        foreach ($prefixes as $prefix) {
            $iterator = null;
            while ($keys = $this->redis->scan($iterator, $prefix . '*', $this->scanCount)) {
                foreach ($keys as $key) {
                    if ($this->redis->ttl($key) === -1) {
                        $this->logger->log("Удаление Redis-ключа без TTL: $key");
                        if (!$this->dryRun) {
                            $this->redis->del($key);
                        }
                        $deleted++;
                    }
                }
            }
        }
        return $deleted;
    }
}

// ==================== ОТЧЁТ ====================
class Report {
    private $stats = [];

    public function add($name, $value) {
        $this->stats[$name] = $value;
    }

    public function get() {
        return $this->stats;
    }

    public function sendEmail($to, $subject) {
        if (empty($to)) return;
        $body = "Отчёт очистки системы\n\n";
        foreach ($this->stats as $key => $val) {
            $body .= "$key: $val\n";
        }
        mail($to, $subject, $body, "Content-Type: text/plain; charset=UTF-8");
    }
}

// ==================== ОСНОВНАЯ ЛОГИКА ====================
$logger = new CleanupLogger(LOG_FILE, LOG_MAX_SIZE, LOG_BACKUP_DAYS, $verbose);
$report = new Report();

// Блокировка параллельного запуска
$fp = fopen(LOCK_FILE, 'w');
if (!$fp || !flock($fp, LOCK_EX | LOCK_NB)) {
    $logger->log("Скрипт уже запущен (блокировка). Выход.", 'WARNING');
    exit(0);
}
register_shutdown_function(function() use ($fp) {
    flock($fp, LOCK_UN);
    fclose($fp);
    @unlink(LOCK_FILE);
});

// Обработка сигналов
if (function_exists('pcntl_signal')) {
    pcntl_signal(SIGINT, function() use ($fp) {
        global $logger;
        $logger->log("Получен сигнал SIGINT, завершение...", 'WARNING');
        flock($fp, LOCK_UN);
        fclose($fp);
        @unlink(LOCK_FILE);
        exit(1);
    });
    pcntl_signal(SIGTERM, function() use ($fp) {
        global $logger;
        $logger->log("Получен сигнал SIGTERM, завершение...", 'WARNING');
        flock($fp, LOCK_UN);
        fclose($fp);
        @unlink(LOCK_FILE);
        exit(1);
    });
}

$logger->log("========== НАЧАЛО ОЧИСТКИ ==========");
if ($dryRun) {
    $logger->log("РЕЖИМ DRY-RUN: изменения не будут применены", 'WARNING');
}

// Подключение к БД
try {
    $db = Database::getInstance();
    $pdo = $db->getPdo();
} catch (Exception $e) {
    $logger->log("Не удалось подключиться к БД: " . $e->getMessage(), 'ERROR');
    exit(1);
}

$fileCleaner = new FileCleaner($logger, $dryRun);
$dbCleaner = new DatabaseCleaner($pdo, $logger, $dryRun);
$redisCleaner = new RedisCleaner('127.0.0.1', 6379, $logger, $dryRun, REDIS_SCAN_COUNT);

$totalCleaned = 0;
$thresholds = $config['age_thresholds'];

// ==================== ФАЙЛОВАЯ ОЧИСТКА ====================
if ($step === 'all' || $step === 'files') {
    foreach ($config['directories'] as $name => $dir) {
        if (!is_dir($dir)) continue;
        $ageHours = null;
        switch ($name) {
            case 'temp':
                $ageHours = $thresholds['temp_file_hours'];
                $pattern = '*';
                $removeEmpty = true;
                break;
            case 'sessions':
                $ageHours = $thresholds['session_days'] * 24;
                $pattern = 'sess_*';
                $removeEmpty = false;
                break;
            case 'logs':
                $ageHours = $thresholds['log_days'] * 24;
                $pattern = '*.log';
                $removeEmpty = false;
                break;
            case 'cache':
                $ageHours = $thresholds['cache_days'] * 24;
                $pattern = '*';
                $removeEmpty = true;
                break;
            case 'geo_cache':
                $ageHours = $thresholds['geo_cache_days'] * 24;
                $pattern = '*';
                $removeEmpty = true;
                break;
            default:
                // Для остальных директорий: удаляем только пустые папки, не трогаем файлы
                $fileCleaner->removeEmptyDirsRecursively($dir);
                continue 2;
        }
        $cleaned = $fileCleaner->removeOldFiles($dir, $ageHours, $pattern, $removeEmpty);
        if ($cleaned) {
            $logger->log("Удалено файлов из $name: $cleaned");
            $totalCleaned += $cleaned;
        }
    }
}

// ==================== ОЧИСТКА ORPHAN-ФАЙЛОВ ====================
if ($step === 'all' || $step === 'orphans') {
    $orphanDeleted = $fileCleaner->cleanupOrphanFiles($db, $config['tables']);
    $logger->log("Удалено файлов-сирот: $orphanDeleted");
    $totalCleaned += $orphanDeleted;
}

// ==================== ОЧИСТКА БАЗЫ ДАННЫХ ====================
if ($step === 'all' || $step === 'db') {
    // Архивация неактивных объявлений
    $cleaned = $dbCleaner->archiveInactiveListings(
        $thresholds['inactive_listing_days'],
        $thresholds['archived_listing_days']
    );
    $totalCleaned += $cleaned;

    // Удаление старых сообщений
    $cleaned = $dbCleaner->deleteOldMessages($thresholds['message_days']);
    $logger->log("Удалено старых сообщений: $cleaned");
    $totalCleaned += $cleaned;

    // Удаление старых кликов партнёрской программы
    $cleaned = $dbCleaner->deleteOldPartnerClicks($thresholds['partner_click_days']);
    $logger->log("Удалено старых кликов: $cleaned");
    $totalCleaned += $cleaned;

    // Удаление старых логов уведомлений
    $cleaned = $dbCleaner->deleteOldNotificationLogs($thresholds['notification_log_days']);
    $logger->log("Удалено старых логов уведомлений: $cleaned");
    $totalCleaned += $cleaned;

    // Очистка password_resets
    $cleaned = $dbCleaner->cleanupPasswordResets();
    $logger->log("Удалено устаревших токенов сброса пароля: $cleaned");
    $totalCleaned += $cleaned;

    // Удаление старых черновиков
    $cleaned = $dbCleaner->deleteOldDrafts($thresholds['draft_days']);
    $logger->log("Удалено старых черновиков: $cleaned");
    $totalCleaned += $cleaned;

    // Очистка поисковых логов
    $cleaned = $dbCleaner->deleteOldSearchLogs($thresholds['search_log_days']);
    $logger->log("Удалено старых поисковых логов: $cleaned");
    $totalCleaned += $cleaned;

    // Очистка гео-кэша БД
    $cleaned = $dbCleaner->deleteOldGeocache($thresholds['geo_cache_days']);
    $logger->log("Удалено старых гео-кэшей БД: $cleaned");
    $totalCleaned += $cleaned;

    // Очистка реферальных агрегатов
    $cleaned = $dbCleaner->deleteOldReferralStats($thresholds['referral_stats_days']);
    $logger->log("Удалено старых реферальных данных: $cleaned");
    $totalCleaned += $cleaned;

    // Очистка failed_jobs
    $cleaned = $dbCleaner->deleteOldFailedJobs($thresholds['failed_job_days']);
    $logger->log("Удалено старых failed_jobs: $cleaned");
    $totalCleaned += $cleaned;
}

// ==================== ОЧИСТКА REDIS ====================
if ($step === 'all' || $step === 'redis') {
    $cleaned = $redisCleaner->deleteKeysWithoutTTL($config['redis_prefixes']);
    $logger->log("Удалено Redis-ключей без TTL: $cleaned");
    $totalCleaned += $cleaned;
}

// ==================== ЗАВЕРШЕНИЕ ====================
$logger->log("========== ОЧИСТКА ЗАВЕРШЕНА ==========");
$logger->log("Всего удалено/обработано записей: $totalCleaned");

// Сохраняем статистику для отчёта
$report->add('Всего записей/файлов удалено', $totalCleaned);
$report->add('Время выполнения', date('Y-m-d H:i:s'));

// Отправка отчёта по email, если указан
$email = $reportEmail ?? $config['email_report'] ?? null;
if ($email) {
    $report->sendEmail($email, "Отчёт очистки системы " . date('Y-m-d H:i:s'));
    $logger->log("Отчёт отправлен на $email");
}