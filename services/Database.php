<?php
/* ============================================
   НАЙДУК — Database Service (MySQL) 2026
   Версия 12.1 — добавлены поля city_id, city_name в users
   Сохранены все таблицы и методы
   ============================================ */

class Database {
    private static $instance = null;
    private $pdo;
    private $redis;
    private $redisAvailable = false;
    private $cacheDir;
    private $logFile;

    private function __construct() {
        $config = $this->loadConfig();
        $this->cacheDir = __DIR__ . '/../storage/cache/';
        $this->logFile = __DIR__ . '/../logs/database.log';
        $this->ensureDirectories();

        try {
            $dsn = "mysql:host={$config['host']};dbname={$config['database']};charset={$config['charset']}";
            $this->pdo = new PDO($dsn, $config['username'], $config['password'], [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);
            $this->initRedis();
            $this->ensureTables();
        } catch (PDOException $e) {
            $this->logError("Database connection failed: " . $e->getMessage());
            die('Ошибка подключения к базе данных');
        }
    }

    private function loadConfig() {
        $envFile = __DIR__ . '/../.env';
        $config = [
            'host' => 'localhost',
            'database' => 'nayduk',
            'username' => 'root',
            'password' => '',
            'charset' => 'utf8mb4'
        ];
        if (file_exists($envFile)) {
            $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            foreach ($lines as $line) {
                if (strpos($line, '=') !== false && strpos($line, '#') !== 0) {
                    list($key, $value) = explode('=', $line, 2);
                    $key = trim($key);
                    $value = trim($value);
                    if ($key === 'DB_HOST') $config['host'] = $value;
                    elseif ($key === 'DB_NAME') $config['database'] = $value;
                    elseif ($key === 'DB_USER') $config['username'] = $value;
                    elseif ($key === 'DB_PASS') $config['password'] = $value;
                }
            }
        }
        return $config;
    }

    private function ensureDirectories() {
        $dirs = [dirname($this->logFile), $this->cacheDir];
        foreach ($dirs as $dir) {
            if (!is_dir($dir)) mkdir($dir, 0755, true);
        }
    }

    private function initRedis() {
        if (class_exists('Redis')) {
            try {
                $this->redis = new Redis();
                $this->redisAvailable = $this->redis->connect('127.0.0.1', 6379, 1);
                if ($this->redisAvailable) $this->redis->ping();
            } catch (Exception $e) {
                $this->redisAvailable = false;
                $this->logError("Redis connection failed: " . $e->getMessage());
            }
        }
    }

    private function ensureTables() {
        // ===== 1. ПОЛЬЗОВАТЕЛИ =====
        $this->query("
            CREATE TABLE IF NOT EXISTS users (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(255) NOT NULL,
                email VARCHAR(255) UNIQUE NOT NULL,
                password_hash VARCHAR(255),
                phone VARCHAR(20),
                avatar_url TEXT,
                is_partner BOOLEAN DEFAULT FALSE,
                role VARCHAR(50) DEFAULT 'user',
                trust_score INT DEFAULT 0,
                notify_email BOOLEAN DEFAULT TRUE,
                notify_sms BOOLEAN DEFAULT FALSE,
                deleted_at TIMESTAMP NULL,
                delete_token VARCHAR(255),
                city_id BIGINT UNSIGNED NULL,
                city_name VARCHAR(255) NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_email (email),
                INDEX idx_role (role),
                INDEX idx_deleted (deleted_at),
                INDEX idx_city (city_id),
                INDEX idx_city_name (city_name),
                FOREIGN KEY (city_id) REFERENCES russian_cities(id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

        // Дополнительные поля, если их нет
        $columns = $this->fetchAll("SHOW COLUMNS FROM users");
        $existing = array_column($columns, 'Field');
        $required = ['phone', 'avatar_url', 'is_partner', 'trust_score', 'notify_email', 'notify_sms', 'deleted_at', 'delete_token', 'phone_visible', 'telegram', 'whatsapp', 'role', 'city_id', 'city_name'];
        foreach ($required as $col) {
            if (!in_array($col, $existing)) {
                $type = match($col) {
                    'is_partner' => 'BOOLEAN DEFAULT FALSE',
                    'trust_score' => 'INT DEFAULT 0',
                    'notify_email' => 'BOOLEAN DEFAULT TRUE',
                    'notify_sms' => 'BOOLEAN DEFAULT FALSE',
                    'deleted_at' => 'TIMESTAMP NULL',
                    'delete_token' => 'VARCHAR(255)',
                    'phone_visible' => 'BOOLEAN DEFAULT FALSE',
                    'telegram' => 'VARCHAR(100)',
                    'whatsapp' => 'VARCHAR(100)',
                    'role' => 'VARCHAR(50) DEFAULT \'user\'',
                    'city_id' => 'BIGINT UNSIGNED NULL',
                    'city_name' => 'VARCHAR(255) NULL',
                    default => 'TEXT'
                };
                $this->query("ALTER TABLE users ADD COLUMN $col $type");
            }
        }

        // ===== 2. ОБЪЯВЛЕНИЯ (listings) =====
        $this->query("
            CREATE TABLE IF NOT EXISTS listings (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                user_id BIGINT UNSIGNED NOT NULL,
                type VARCHAR(20) NOT NULL DEFAULT 'sell' CHECK (type IN ('sell', 'wanted', 'resume', 'service')),
                category_id BIGINT UNSIGNED,
                title VARCHAR(255) NOT NULL,
                description TEXT,
                price DECIMAL(12,2),
                price_type VARCHAR(20) DEFAULT 'fixed',
                condition VARCHAR(20) DEFAULT 'used',
                address TEXT,
                lat DECIMAL(10,8),
                lng DECIMAL(11,8),
                city VARCHAR(255),
                views INT DEFAULT 0,
                status VARCHAR(50) DEFAULT 'pending',
                moderation_comment TEXT,
                is_featured BOOLEAN DEFAULT FALSE,
                featured_until TIMESTAMP NULL,
                custom_fields JSON,
                has_warranty BOOLEAN DEFAULT FALSE,
                has_delivery BOOLEAN DEFAULT FALSE,
                booking_settings JSON,
                promoted_until TIMESTAMP NULL,
                promotion_type VARCHAR(50),
                min_offer_percent INT DEFAULT NULL,
                is_sealed BOOLEAN DEFAULT FALSE,
                gift JSON DEFAULT NULL,
                auto_refresh_count INT DEFAULT 0,
                last_refresh TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                next_auto_boost_at TIMESTAMP NULL,
                views_last_30_days INT DEFAULT 0,
                pending_ai BOOLEAN DEFAULT FALSE,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                expires_at TIMESTAMP DEFAULT (CURRENT_TIMESTAMP + INTERVAL 30 DAY),
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
                INDEX idx_user (user_id),
                INDEX idx_status (status),
                INDEX idx_city (city),
                INDEX idx_type (type),
                INDEX idx_category (category_id),
                INDEX idx_price (price),
                INDEX idx_created_at (created_at),
                INDEX idx_expires (expires_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

        $this->query("
            CREATE TABLE IF NOT EXISTS listing_photos (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                listing_id BIGINT UNSIGNED NOT NULL,
                url TEXT NOT NULL,
                sort_order INT DEFAULT 0,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (listing_id) REFERENCES listings(id) ON DELETE CASCADE,
                INDEX idx_listing (listing_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

        // ===== 3. ОБРАТНЫЙ АУКЦИОН И СЛОТЫ =====
        $this->query("
            CREATE TABLE IF NOT EXISTS offers (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                listing_id BIGINT UNSIGNED NOT NULL,
                user_id BIGINT UNSIGNED NOT NULL,
                discount_percent INT NOT NULL,
                status ENUM('active','accepted','expired') DEFAULT 'active',
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY unique_offer (listing_id, user_id),
                FOREIGN KEY (listing_id) REFERENCES listings(id) ON DELETE CASCADE,
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
                INDEX idx_listing_status (listing_id, status)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

        $this->query("
            CREATE TABLE IF NOT EXISTS slots (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                listing_id BIGINT UNSIGNED NOT NULL,
                start_time TIMESTAMP NOT NULL,
                end_time TIMESTAMP NOT NULL,
                buyer_id BIGINT UNSIGNED,
                status ENUM('free','pending','confirmed','expired') DEFAULT 'free',
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                confirmed_at TIMESTAMP NULL,
                FOREIGN KEY (listing_id) REFERENCES listings(id) ON DELETE CASCADE,
                INDEX idx_listing_status (listing_id, status)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

        // ===== 4. ОТЗЫВЫ =====
        $this->query("
            CREATE TABLE IF NOT EXISTS reviews (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                listing_id BIGINT UNSIGNED NOT NULL,
                reviewer_id BIGINT UNSIGNED NOT NULL,
                reviewed_id BIGINT UNSIGNED NOT NULL,
                rating TINYINT UNSIGNED NOT NULL CHECK (rating BETWEEN 1 AND 5),
                comment TEXT,
                photos JSON,
                is_visible BOOLEAN DEFAULT TRUE,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (listing_id) REFERENCES listings(id) ON DELETE CASCADE,
                FOREIGN KEY (reviewer_id) REFERENCES users(id) ON DELETE CASCADE,
                FOREIGN KEY (reviewed_id) REFERENCES users(id) ON DELETE CASCADE,
                INDEX idx_reviewed (reviewed_id, created_at DESC),
                INDEX idx_visible (is_visible)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

        $this->query("
            CREATE TABLE IF NOT EXISTS review_replies (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                review_id BIGINT UNSIGNED NOT NULL,
                user_id BIGINT UNSIGNED NOT NULL,
                reply TEXT NOT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                FOREIGN KEY (review_id) REFERENCES reviews(id) ON DELETE CASCADE,
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
                UNIQUE KEY unique_reply (review_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

        $this->query("
            CREATE TABLE IF NOT EXISTS review_helpfulness (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                review_id BIGINT UNSIGNED NOT NULL,
                user_id BIGINT UNSIGNED NOT NULL,
                is_helpful BOOLEAN NOT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY unique_vote (review_id, user_id),
                FOREIGN KEY (review_id) REFERENCES reviews(id) ON DELETE CASCADE,
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

        $this->query("
            CREATE TABLE IF NOT EXISTS review_reports (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                review_id BIGINT UNSIGNED NOT NULL,
                reporter_id BIGINT UNSIGNED NOT NULL,
                reason VARCHAR(255),
                status ENUM('pending','resolved','rejected') DEFAULT 'pending',
                admin_note TEXT,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                resolved_at TIMESTAMP NULL,
                FOREIGN KEY (review_id) REFERENCES reviews(id) ON DELETE CASCADE,
                FOREIGN KEY (reporter_id) REFERENCES users(id) ON DELETE CASCADE,
                UNIQUE KEY unique_report (review_id, reporter_id),
                INDEX idx_status (status)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

        $this->query("
            CREATE TABLE IF NOT EXISTS user_ratings (
                user_id BIGINT UNSIGNED PRIMARY KEY,
                avg_rating DECIMAL(3,2) DEFAULT 0,
                total_reviews INT UNSIGNED DEFAULT 0,
                rating_distribution JSON,
                last_30_days_count INT DEFAULT 0,
                response_rate DECIMAL(5,2) DEFAULT 0,
                avg_response_time_seconds INT DEFAULT 0,
                successful_deals INT DEFAULT 0,
                report_accuracy DECIMAL(5,2) DEFAULT 0,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

        // ===== 5. ПОДПИСКИ =====
        $this->query("
            CREATE TABLE IF NOT EXISTS subscriptions (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                user_id BIGINT UNSIGNED NOT NULL,
                type VARCHAR(50) NOT NULL CHECK (type IN ('search', 'category', 'city', 'shop')),
                params JSON NOT NULL,
                last_notified_at TIMESTAMP,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
                INDEX idx_user (user_id),
                INDEX idx_type (type)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

        $this->query("
            CREATE TABLE IF NOT EXISTS subscription_events (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                subscription_id BIGINT UNSIGNED NOT NULL,
                listing_id BIGINT UNSIGNED NOT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (subscription_id) REFERENCES subscriptions(id) ON DELETE CASCADE,
                FOREIGN KEY (listing_id) REFERENCES listings(id) ON DELETE CASCADE,
                INDEX idx_subscription (subscription_id),
                INDEX idx_listing (listing_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

        // ===== 6. РЕФЕРАЛЬНАЯ СИСТЕМА =====
        $this->query("
            CREATE TABLE IF NOT EXISTS referrals (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                referrer_id BIGINT UNSIGNED NOT NULL,
                referred_id BIGINT UNSIGNED NOT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (referrer_id) REFERENCES users(id) ON DELETE CASCADE,
                FOREIGN KEY (referred_id) REFERENCES users(id) ON DELETE CASCADE,
                UNIQUE KEY unique_referral (referrer_id, referred_id),
                INDEX idx_referrer (referrer_id),
                INDEX idx_referred (referred_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

        $this->query("
            CREATE TABLE IF NOT EXISTS referral_commissions (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                referral_id BIGINT UNSIGNED NOT NULL,
                amount DECIMAL(12,2) NOT NULL,
                status ENUM('pending','paid','cancelled') DEFAULT 'pending',
                paid_at TIMESTAMP NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (referral_id) REFERENCES referrals(id) ON DELETE CASCADE,
                INDEX idx_referral (referral_id),
                INDEX idx_status (status)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

        // ===== 7. ПАРТНЁРЫ (affiliates) — расширенная версия =====
        $this->query("
            CREATE TABLE IF NOT EXISTS affiliates (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                -- Старые поля (из 10.2)
                name VARCHAR(255) NOT NULL,
                slug VARCHAR(255) UNIQUE NOT NULL,
                url VARCHAR(500),
                description TEXT,
                commission_rate DECIMAL(5,2) DEFAULT 10,
                type ENUM('link','widget','api') DEFAULT 'link',
                widget_code TEXT,
                api_key VARCHAR(255),
                webhook_url VARCHAR(500),
                icon_url VARCHAR(500),
                categories JSON,
                is_active BOOLEAN DEFAULT TRUE,
                -- Новые поля (из 11.0 для Рефералки)
                partner_name VARCHAR(255),
                offer_name VARCHAR(255),
                commission_type VARCHAR(20) DEFAULT 'cpa' CHECK (commission_type IN ('cpa','cps','cpl','recurring','fixed')),
                commission_value DECIMAL(12,2),
                currency VARCHAR(10) DEFAULT 'RUB',
                our_parameter VARCHAR(100),
                is_smartlink BOOLEAN DEFAULT FALSE,
                city_id BIGINT UNSIGNED,
                address TEXT,
                phone VARCHAR(50),
                website TEXT,
                working_hours JSON,
                priority INT DEFAULT 0,
                expires_at TIMESTAMP NULL,
                budget DECIMAL(12,2),
                display_rule JSON,
                keywords JSON,
                source VARCHAR(50) DEFAULT 'manual',
                cpa_network VARCHAR(100),
                geo_availability VARCHAR(255),
                notes TEXT,
                is_approved BOOLEAN DEFAULT FALSE,
                approved_at TIMESTAMP NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_active (is_active),
                INDEX idx_slug (slug),
                INDEX idx_city (city_id),
                INDEX idx_priority (priority),
                INDEX idx_expires (expires_at),
                FULLTEXT INDEX idx_keywords (keywords)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

        // JSON-индекс для категорий (если MySQL 8.0.17+)
        $mysqlVersion = $this->fetchColumn("SELECT VERSION()");
        if (version_compare($mysqlVersion, '8.0.17', '>=')) {
            $this->query("ALTER TABLE affiliates ADD INDEX idx_categories ((CAST(categories AS CHAR(255) ARRAY)))");
        }

        // ===== 8. ЛОГИ КЛИКОВ =====
        $this->query("
            CREATE TABLE IF NOT EXISTS affiliate_clicks (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                affiliate_id BIGINT UNSIGNED NOT NULL,
                ip_address VARCHAR(45),
                user_agent TEXT,
                referer TEXT,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (affiliate_id) REFERENCES affiliates(id) ON DELETE CASCADE,
                INDEX idx_affiliate (affiliate_id),
                INDEX idx_created (created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

        // ===== 9. ЛОГИ ПОКАЗОВ (партиционированная) =====
        $this->query("
            CREATE TABLE IF NOT EXISTS affiliate_display_log (
                id BIGINT UNSIGNED AUTO_INCREMENT,
                affiliate_id BIGINT UNSIGNED NOT NULL,
                user_id BIGINT UNSIGNED,
                city_id BIGINT UNSIGNED,
                category VARCHAR(50),
                displayed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id, displayed_at),
                FOREIGN KEY (affiliate_id) REFERENCES affiliates(id) ON DELETE CASCADE,
                INDEX idx_affiliate (affiliate_id),
                INDEX idx_category (category)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            PARTITION BY RANGE (UNIX_TIMESTAMP(displayed_at)) (
                PARTITION p202601 VALUES LESS THAN (UNIX_TIMESTAMP('2026-02-01')),
                PARTITION p202602 VALUES LESS THAN (UNIX_TIMESTAMP('2026-03-01')),
                PARTITION p202603 VALUES LESS THAN (UNIX_TIMESTAMP('2026-04-01')),
                PARTITION p202604 VALUES LESS THAN (UNIX_TIMESTAMP('2026-05-01')),
                PARTITION p_future VALUES LESS THAN MAXVALUE
            )
        ");

        // ===== 10. ЛОГИ ОШИБОК ИМПОРТА =====
        $this->query("
            CREATE TABLE IF NOT EXISTS affiliate_import_errors (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                source VARCHAR(50) NOT NULL,
                error_message TEXT,
                raw_data JSON,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_source (source),
                INDEX idx_created (created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

        // ===== 11. АДМИНСКИЕ ЗАДАЧИ =====
        $this->query("
            CREATE TABLE IF NOT EXISTS admin_tasks (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                type VARCHAR(50) NOT NULL,
                data JSON,
                status ENUM('pending','in_progress','completed','ignored') DEFAULT 'pending',
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                resolved_at TIMESTAMP NULL,
                INDEX idx_status (status),
                INDEX idx_type (type)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

        // ===== 12. ИМПОРТЫ ИЗ ВНЕШНИХ ИСТОЧНИКОВ =====
        $this->query("
            CREATE TABLE IF NOT EXISTS partner_imports (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                source VARCHAR(50) NOT NULL,
                external_id VARCHAR(255) NOT NULL,
                name VARCHAR(255),
                category VARCHAR(100),
                city VARCHAR(100),
                address TEXT,
                phone VARCHAR(50),
                website TEXT,
                lat DECIMAL(10,8),
                lng DECIMAL(11,8),
                raw_data JSON,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY unique_source_external (source, external_id),
                INDEX idx_source (source),
                INDEX idx_city (city)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

        // ===== 13. СПРАВОЧНИК ГОРОДОВ =====
        $this->query("
            CREATE TABLE IF NOT EXISTS russian_cities (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                city_name VARCHAR(255) NOT NULL,
                region_name VARCHAR(255),
                federal_district VARCHAR(100),
                population INT,
                latitude DECIMAL(10,8),
                longitude DECIMAL(11,8),
                is_active BOOLEAN DEFAULT TRUE,
                INDEX idx_name (city_name),
                INDEX idx_region (region_name),
                INDEX idx_coords (latitude, longitude)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

        // ===== 14. НАСТРОЙКИ ВЛАДЕЛЬЦА =====
        $this->query("
            CREATE TABLE IF NOT EXISTS owner_settings (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                key_name VARCHAR(255) UNIQUE NOT NULL,
                value TEXT,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

        // ===== 15. ТАБЛИЦЫ ДЛЯ AI-ГЕНЕРАЦИИ =====
        $this->query("
            CREATE TABLE IF NOT EXISTS ai_generation_jobs (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                job_id VARCHAR(64) UNIQUE NOT NULL,
                listing_id BIGINT UNSIGNED,
                generator VARCHAR(50) NOT NULL,
                prompt TEXT NOT NULL,
                status ENUM('pending', 'processing', 'completed', 'failed') DEFAULT 'pending',
                result_path TEXT,
                error TEXT,
                attempts TINYINT UNSIGNED DEFAULT 0,
                idempotency_key VARCHAR(128) UNIQUE,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                started_at TIMESTAMP NULL,
                finished_at TIMESTAMP NULL,
                FOREIGN KEY (listing_id) REFERENCES listings(id) ON DELETE SET NULL,
                INDEX idx_status (status),
                INDEX idx_created (created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

        $this->query("
            CREATE TABLE IF NOT EXISTS ai_api_keys (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                provider VARCHAR(50) NOT NULL UNIQUE,
                api_key TEXT NOT NULL,
                is_active BOOLEAN DEFAULT TRUE,
                daily_limit INT DEFAULT 0,
                used_today INT DEFAULT 0,
                last_used_at TIMESTAMP NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_provider (provider)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

        $this->query("
            CREATE TABLE IF NOT EXISTS ai_generation_logs (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                job_id VARCHAR(64) NOT NULL,
                provider VARCHAR(50),
                event VARCHAR(100),
                details JSON,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_job (job_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

        // ===== 16. СЛУЖЕБНЫЕ ТАБЛИЦЫ =====
        $this->query("
            CREATE TABLE IF NOT EXISTS rate_limits (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                key_name VARCHAR(255) NOT NULL,
                count INT UNSIGNED NOT NULL DEFAULT 0,
                expires_at TIMESTAMP NOT NULL,
                INDEX idx_key (key_name),
                INDEX idx_expires (expires_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

        $this->query("
            CREATE TABLE IF NOT EXISTS system_health (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                metric VARCHAR(100) NOT NULL,
                value DECIMAL(12,2),
                recorded_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_metric (metric),
                INDEX idx_recorded (recorded_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

        $this->query("
            CREATE TABLE IF NOT EXISTS security_logs (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                user_id BIGINT UNSIGNED,
                ip_address VARCHAR(45),
                event_type VARCHAR(100),
                description TEXT,
                severity VARCHAR(20),
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
                INDEX idx_user (user_id),
                INDEX idx_created (created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
    }

    // ===== БАЗОВЫЕ МЕТОДЫ =====
    public function query($sql, $params = []) {
        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);
            return $stmt;
        } catch (PDOException $e) {
            $this->logError("Query failed: " . $e->getMessage() . " SQL: $sql");
            throw $e;
        }
    }

    public function fetchOne($sql, $params = []) {
        $stmt = $this->query($sql, $params);
        return $stmt->fetch();
    }

    public function fetchAll($sql, $params = []) {
        $stmt = $this->query($sql, $params);
        return $stmt->fetchAll();
    }

    public function fetchColumn($sql, $params = []) {
        $stmt = $this->query($sql, $params);
        return $stmt->fetchColumn();
    }

    public function fetchCount($sql, $params = []) {
        return (int)$this->fetchColumn($sql, $params);
    }

    public function insert($table, $data) {
        $columns = implode(', ', array_keys($data));
        $placeholders = implode(', ', array_fill(0, count($data), '?'));
        $sql = "INSERT INTO {$table} ({$columns}) VALUES ({$placeholders})";
        $this->query($sql, array_values($data));
        return $this->pdo->lastInsertId();
    }

    public function insertBatch($table, $rows, $batchSize = 1000) {
        if (empty($rows)) return 0;
        $firstRow = reset($rows);
        $columns = array_keys($firstRow);
        $columnsList = implode(', ', $columns);
        $placeholders = implode(', ', array_fill(0, count($columns), '?'));
        $inserted = 0;
        $batches = array_chunk($rows, $batchSize);
        foreach ($batches as $batch) {
            $values = [];
            foreach ($batch as $row) {
                foreach ($columns as $col) {
                    $values[] = $row[$col] ?? null;
                }
            }
            $sql = "INSERT INTO {$table} ({$columnsList}) VALUES " . implode(', ', array_fill(0, count($batch), "($placeholders)"));
            $this->query($sql, $values);
            $inserted += count($batch);
        }
        return $inserted;
    }

    public function update($table, $data, $where, $whereParams = []) {
        $set = implode(' = ?, ', array_keys($data)) . ' = ?';
        $sql = "UPDATE {$table} SET {$set} WHERE {$where}";
        $params = array_merge(array_values($data), $whereParams);
        $stmt = $this->query($sql, $params);
        return $stmt->rowCount();
    }

    public function delete($table, $where, $params = []) {
        $sql = "DELETE FROM {$table} WHERE {$where}";
        $stmt = $this->query($sql, $params);
        return $stmt->rowCount();
    }

    public function exists($table, $where, $params = []) {
        $sql = "SELECT 1 FROM {$table} WHERE {$where} LIMIT 1";
        return $this->fetchOne($sql, $params) !== false;
    }

    public function beginTransaction() {
        return $this->pdo->beginTransaction();
    }

    public function commit() {
        return $this->pdo->commit();
    }

    public function rollback() {
        return $this->pdo->rollBack();
    }

    // ===== КЭШИРОВАНИЕ (Redis + файловый fallback) =====
    private function fileCacheGet($key) {
        $file = $this->cacheDir . md5($key) . '.cache';
        if (!file_exists($file)) return null;
        $data = @file_get_contents($file);
        if ($data === false) return null;
        $cache = json_decode($data, true);
        if ($cache && $cache['expires'] > time()) return $cache['value'];
        @unlink($file);
        return null;
    }

    private function fileCacheSet($key, $value, $ttl = 3600) {
        $file = $this->cacheDir . md5($key) . '.cache';
        $data = json_encode(['expires' => time() + $ttl, 'value' => $value]);
        file_put_contents($file, $data, LOCK_EX);
    }

    private function fileCacheDelete($key) {
        $file = $this->cacheDir . md5($key) . '.cache';
        @unlink($file);
    }

    public function cacheGet($key) {
        if ($this->redisAvailable) {
            try {
                $val = $this->redis->get($key);
                if ($val === false) return null;
                return json_decode($val, true);
            } catch (Exception $e) {
                $this->redisAvailable = false;
            }
        }
        return $this->fileCacheGet($key);
    }

    public function cacheSet($key, $value, $ttl = 3600) {
        $encoded = json_encode($value);
        if ($this->redisAvailable) {
            try {
                $this->redis->setex($key, $ttl, $encoded);
                return;
            } catch (Exception $e) {
                $this->redisAvailable = false;
            }
        }
        $this->fileCacheSet($key, $value, $ttl);
    }

    public function cacheDelete($key) {
        if ($this->redisAvailable) {
            try {
                $this->redis->del($key);
            } catch (Exception $e) {}
        }
        $this->fileCacheDelete($key);
    }

    private function lock($key, $ttl = 5) {
        $lockKey = $key . '.lock';
        if ($this->redisAvailable) {
            try {
                return $this->redis->set($lockKey, time(), ['nx', 'ex' => $ttl]);
            } catch (Exception $e) {
                $this->logError("Redis lock error: " . $e->getMessage());
            }
        }
        $lockFile = $this->cacheDir . md5($lockKey) . '.lock';
        $now = time();
        if (file_exists($lockFile)) {
            $data = @file_get_contents($lockFile);
            if ($data && (int)$data > $now - $ttl) return false;
        }
        file_put_contents($lockFile, $now, LOCK_EX);
        return true;
    }

    private function unlock($key) {
        $lockKey = $key . '.lock';
        if ($this->redisAvailable) {
            try {
                $this->redis->del($lockKey);
            } catch (Exception $e) {}
        } else {
            $lockFile = $this->cacheDir . md5($lockKey) . '.lock';
            @unlink($lockFile);
        }
    }

    public function cacheRemember($key, $ttl, $callback, $maxRetries = 3) {
        $cached = $this->cacheGet($key);
        if ($cached !== null) return $cached;
        for ($attempt = 0; $attempt < $maxRetries; $attempt++) {
            if ($this->lock($key, 5)) {
                $data = $callback();
                $variation = rand(-round($ttl * 0.1), round($ttl * 0.1));
                $actualTtl = max(60, $ttl + $variation);
                $this->cacheSet($key, $data, $actualTtl);
                $this->unlock($key);
                return $data;
            }
            usleep(50000);
            $cached = $this->cacheGet($key);
            if ($cached !== null) return $cached;
        }
        return $callback();
    }

    // ===== МЕТОДЫ ДЛЯ ПОЛЬЗОВАТЕЛЕЙ =====
    public function getUserById($id) {
        return $this->fetchOne("SELECT id, name, email, phone, avatar_url, is_partner, role, trust_score FROM users WHERE id = ?", [$id]);
    }

    public function getUserByEmail($email) {
        return $this->fetchOne("SELECT id, name, email, phone, avatar_url, is_partner, role, trust_score FROM users WHERE email = ?", [$email]);
    }

    public function createUser($data) {
        $data['created_at'] = date('Y-m-d H:i:s');
        $data['updated_at'] = date('Y-m-d H:i:s');
        return $this->insert('users', $data);
    }

    public function updateUser($id, $data) {
        $data['updated_at'] = date('Y-m-d H:i:s');
        return $this->update('users', $data, 'id = ?', [$id]);
    }

    // ===== МЕТОДЫ ДЛЯ КАТЕГОРИЙ =====
    public function getCategories($mainOnly = false) {
        $sql = "SELECT * FROM listing_categories WHERE is_active = 1";
        if ($mainOnly) $sql .= " AND is_main = 1";
        $sql .= " ORDER BY sort_order";
        return $this->cacheRemember('categories_' . ($mainOnly ? 'main' : 'all'), 3600, function() use ($sql) {
            return $this->fetchAll($sql);
        });
    }

    // ===== МЕТОДЫ ДЛЯ ОБЪЯВЛЕНИЙ =====
    public function getListings($limit = 20, $offset = 0, $filters = []) {
        $sql = "SELECT l.*, c.name as category_name, u.name as seller_name, u.trust_score as seller_trust
                FROM listings l
                LEFT JOIN listing_categories c ON l.category_id = c.id
                LEFT JOIN users u ON l.user_id = u.id
                WHERE l.status = 'approved'";
        $params = [];
        if (!empty($filters['category_id'])) {
            $sql .= " AND l.category_id = ?";
            $params[] = $filters['category_id'];
        }
        if (!empty($filters['search'])) {
            $sql .= " AND (l.title LIKE ? OR l.description LIKE ?)";
            $searchTerm = "%{$filters['search']}%";
            $params[] = $searchTerm; $params[] = $searchTerm;
        }
        if (!empty($filters['city'])) {
            $sql .= " AND l.city = ?";
            $params[] = $filters['city'];
        }
        if (isset($filters['min_price'])) {
            $sql .= " AND l.price >= ?";
            $params[] = $filters['min_price'];
        }
        if (isset($filters['max_price'])) {
            $sql .= " AND l.price <= ?";
            $params[] = $filters['max_price'];
        }
        if (isset($filters['min_rating'])) {
            $sql .= " AND EXISTS (SELECT 1 FROM user_ratings ur WHERE ur.user_id = l.user_id AND ur.avg_rating >= ?)";
            $params[] = (float)$filters['min_rating'];
        }
        $sql .= " ORDER BY l.is_featured DESC, l.created_at DESC LIMIT ? OFFSET ?";
        $params[] = $limit;
        $params[] = $offset;
        return $this->fetchAll($sql, $params);
    }

    public function getListingById($id) {
        return $this->fetchOne("
            SELECT l.*, c.name as category_name, u.name as seller_name, u.avatar_url as seller_avatar, u.trust_score as seller_trust
            FROM listings l
            LEFT JOIN listing_categories c ON l.category_id = c.id
            LEFT JOIN users u ON l.user_id = u.id
            WHERE l.id = ?
        ", [$id]);
    }

    public function createListing($data) {
        $data['created_at'] = date('Y-m-d H:i:s');
        $data['updated_at'] = date('Y-m-d H:i:s');
        return $this->insert('listings', $data);
    }

    public function updateListing($id, $data) {
        $data['updated_at'] = date('Y-m-d H:i:s');
        return $this->update('listings', $data, 'id = ?', [$id]);
    }

    public function deleteListing($id) {
        return $this->delete('listings', 'id = ?', [$id]);
    }

    public function incrementViews($id) {
        $this->query("UPDATE listings SET views = views + 1 WHERE id = ?", [$id]);
    }

    // ===== МЕТОДЫ ДЛЯ ОБРАТНОГО АУКЦИОНА =====
    public function getOffersSummary($listingId) {
        $stmt = $this->query("SELECT discount_percent, COUNT(*) as cnt FROM offers WHERE listing_id = ? AND status = 'active' GROUP BY discount_percent", [$listingId]);
        $rows = $stmt->fetchAll();
        $summary = [5 => 0, 10 => 0, 15 => 0, 20 => 0];
        foreach ($rows as $row) {
            $summary[(int)$row['discount_percent']] = (int)$row['cnt'];
        }
        return $summary;
    }

    public function createOffer($listingId, $userId, $discount) {
        return $this->insert('offers', [
            'listing_id' => $listingId,
            'user_id' => $userId,
            'discount_percent' => $discount,
            'status' => 'active',
            'created_at' => date('Y-m-d H:i:s')
        ]);
    }

    // ===== МЕТОДЫ ДЛЯ ОТЗЫВОВ =====
    public function getReviews($userId, $page = 1, $limit = 20, $filters = []) {
        $offset = ($page - 1) * $limit;
        $where = "r.reviewed_id = ? AND r.is_visible = 1";
        $params = [$userId];
        if (!empty($filters['rating'])) {
            $where .= " AND r.rating = ?";
            $params[] = (int)$filters['rating'];
        }
        if (!empty($filters['has_photos'])) {
            $where .= " AND r.photos IS NOT NULL AND JSON_LENGTH(r.photos) > 0";
        }
        $sql = "
            SELECT r.*, u.name as reviewer_name, u.avatar_url as reviewer_avatar,
                   rr.reply as seller_reply,
                   (SELECT COUNT(*) FROM review_helpfulness WHERE review_id = r.id AND is_helpful = 1) as helpful_count,
                   (SELECT COUNT(*) FROM review_helpfulness WHERE review_id = r.id AND is_helpful = 0) as not_helpful_count
            FROM reviews r
            LEFT JOIN users u ON r.reviewer_id = u.id
            LEFT JOIN review_replies rr ON r.id = rr.review_id
            WHERE $where
            ORDER BY r.created_at DESC
            LIMIT ? OFFSET ?
        ";
        $stmt = $this->query($sql, array_merge($params, [$limit, $offset]));
        $reviews = $stmt->fetchAll();
        $total = $this->fetchCount("SELECT COUNT(*) FROM reviews WHERE reviewed_id = ? AND is_visible = 1", [$userId]);
        return ['data' => $reviews, 'total' => $total, 'page' => $page, 'limit' => $limit, 'pages' => ceil($total / $limit)];
    }

    public function addReview($listingId, $reviewerId, $reviewedId, $rating, $comment, $photos = null) {
        $stmt = $this->query("SELECT id FROM deal_confirmations WHERE listing_id = ? AND confirmed_at IS NOT NULL", [$listingId]);
        if (!$stmt->fetch()) {
            throw new Exception('Отзыв можно оставить только после взаимного подтверждения сделки');
        }
        $reviewId = $this->insert('reviews', [
            'listing_id' => $listingId,
            'reviewer_id' => $reviewerId,
            'reviewed_id' => $reviewedId,
            'rating' => $rating,
            'comment' => $comment,
            'photos' => $photos ? json_encode($photos) : null,
            'created_at' => date('Y-m-d H:i:s')
        ]);
        $this->recalcUserRating($reviewedId);
        return $reviewId;
    }

    public function addReviewReply($reviewId, $userId, $reply) {
        return $this->insert('review_replies', [
            'review_id' => $reviewId,
            'user_id' => $userId,
            'reply' => $reply,
            'created_at' => date('Y-m-d H:i:s')
        ]);
    }

    public function reportReview($reviewId, $reporterId, $reason) {
        try {
            return $this->insert('review_reports', [
                'review_id' => $reviewId,
                'reporter_id' => $reporterId,
                'reason' => $reason,
                'status' => 'pending',
                'created_at' => date('Y-m-d H:i:s')
            ]);
        } catch (PDOException $e) {
            if ($e->errorInfo[1] == 1062) {
                throw new Exception('Вы уже жаловались на этот отзыв');
            }
            throw $e;
        }
    }

    public function recalcUserRating($userId) {
        $stmt = $this->query("SELECT rating FROM reviews WHERE reviewed_id = ? AND is_visible = 1", [$userId]);
        $ratings = $stmt->fetchAll(PDO::FETCH_COLUMN);
        if (empty($ratings)) {
            $avg = 0;
            $dist = json_encode([]);
        } else {
            $avg = array_sum($ratings) / count($ratings);
            $dist = [];
            foreach ($ratings as $r) {
                $dist[$r] = ($dist[$r] ?? 0) + 1;
            }
            $dist = json_encode($dist);
        }
        $this->query("
            INSERT INTO user_ratings (user_id, avg_rating, total_reviews, rating_distribution, updated_at)
            VALUES (?, ?, ?, ?, NOW())
            ON DUPLICATE KEY UPDATE
                avg_rating = VALUES(avg_rating),
                total_reviews = VALUES(total_reviews),
                rating_distribution = VALUES(rating_distribution),
                updated_at = NOW()
        ", [$userId, round($avg, 2), count($ratings), $dist]);
        return ['avg' => round($avg, 2), 'total' => count($ratings), 'distribution' => json_decode($dist, true)];
    }

    public function getUserRating($userId) {
        return $this->fetchOne("SELECT * FROM user_ratings WHERE user_id = ?", [$userId]);
    }

    // ===== РЕФЕРАЛЬНЫЕ МЕТОДЫ =====
    public function getReferralByReferred($referredId) {
        return $this->fetchOne("SELECT * FROM referrals WHERE referred_id = ?", [$referredId]);
    }

    public function createReferral($referrerId, $referredId) {
        return $this->insert('referrals', [
            'referrer_id' => $referrerId,
            'referred_id' => $referredId,
            'created_at' => date('Y-m-d H:i:s')
        ]);
    }

    public function addReferralCommission($referralId, $amount) {
        return $this->insert('referral_commissions', [
            'referral_id' => $referralId,
            'amount' => $amount,
            'status' => 'pending',
            'created_at' => date('Y-m-d H:i:s')
        ]);
    }

    public function getReferralCommissions($referrerId) {
        return $this->fetchAll("
            SELECT rc.*, r.referred_id, u.name as referred_name
            FROM referral_commissions rc
            JOIN referrals r ON rc.referral_id = r.id
            JOIN users u ON r.referred_id = u.id
            WHERE r.referrer_id = ?
            ORDER BY rc.created_at DESC
        ", [$referrerId]);
    }

    public function getReferralStats($referrerId) {
        return $this->fetchOne("
            SELECT
                COUNT(DISTINCT r.referred_id) as total_referrals,
                COUNT(rc.id) as total_commissions,
                COALESCE(SUM(rc.amount), 0) as total_earned,
                COALESCE(SUM(CASE WHEN rc.status = 'paid' THEN rc.amount ELSE 0 END), 0) as paid_earned
            FROM referrals r
            LEFT JOIN referral_commissions rc ON r.id = rc.referral_id
            WHERE r.referrer_id = ?
        ", [$referrerId]);
    }

    // ===== ПАРТНЁРСКИЕ МЕТОДЫ (affiliates) =====
    public function getActiveAffiliates($category = null, $cityId = null, $limit = 5) {
        $sql = "SELECT id, partner_name, offer_name, url_template, description, icon_url, priority, is_smartlink
                FROM affiliates
                WHERE is_active = 1 AND (expires_at IS NULL OR expires_at > NOW())
                  AND (is_approved = 1 OR source = 'manual')";
        $params = [];
        if ($category) {
            $sql .= " AND (categories LIKE ? OR category = ?)";
            $params[] = '%"' . addslashes($category) . '"%';
            $params[] = $category;
        }
        if ($cityId) {
            $sql .= " AND (city_id = ? OR city_id IS NULL)";
            $params[] = $cityId;
        }
        $sql .= " ORDER BY priority DESC, RAND() LIMIT ?";
        $params[] = $limit;
        return $this->fetchAll($sql, $params);
    }

    public function addAffiliateClick($affiliateId, $userId = null) {
        return $this->insert('affiliate_clicks', [
            'affiliate_id' => $affiliateId,
            'user_id' => $userId,
            'ip_address' => getUserIP(),
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null,
            'referer' => $_SERVER['HTTP_REFERER'] ?? null,
            'created_at' => date('Y-m-d H:i:s')
        ]);
    }

    public function addAffiliateDisplay($affiliateId, $userId = null, $cityId = null, $category = null) {
        return $this->insert('affiliate_display_log', [
            'affiliate_id' => $affiliateId,
            'user_id' => $userId,
            'city_id' => $cityId,
            'category' => $category,
            'displayed_at' => date('Y-m-d H:i:s')
        ]);
    }

    public function addAffiliateOrder($affiliateId, $orderData, $amount, $commission) {
        return $this->insert('affiliate_orders', [
            'affiliate_id' => $affiliateId,
            'order_data' => json_encode($orderData),
            'amount' => $amount,
            'commission' => $commission,
            'status' => 'pending',
            'created_at' => date('Y-m-d H:i:s')
        ]);
    }

    // ===== ЗАДАЧИ ДЛЯ АДМИНА =====
    public function createAdminTask($type, $data) {
        return $this->insert('admin_tasks', [
            'type' => $type,
            'data' => json_encode($data),
            'status' => 'pending',
            'created_at' => date('Y-m-d H:i:s')
        ]);
    }

    public function getPendingTasks($limit = 20) {
        return $this->fetchAll("SELECT * FROM admin_tasks WHERE status = 'pending' ORDER BY created_at ASC LIMIT ?", [$limit]);
    }

    // ===== НАСТРОЙКИ ВЛАДЕЛЬЦА =====
    public function getOwnerSetting($key) {
        $row = $this->fetchOne("SELECT value FROM owner_settings WHERE key_name = ?", [$key]);
        return $row ? $row['value'] : null;
    }

    public function setOwnerSetting($key, $value) {
        $this->query("INSERT INTO owner_settings (key_name, value) VALUES (?, ?) ON DUPLICATE KEY UPDATE value = VALUES(value)", [$key, $value]);
    }

    // ===== НОВЫЕ МЕТОДЫ ДЛЯ РАБОТЫ С ГОРОДАМИ =====
    /**
     * Получить город пользователя по ID
     * @param int $userId
     * @return array|null
     */
    public function getUserCity($userId) {
        return $this->fetchOne("SELECT city_id, city_name FROM users WHERE id = ?", [$userId]);
    }

    /**
     * Обновить город в профиле пользователя
     * @param int $userId
     * @param int $cityId
     * @param string $cityName
     * @return bool
     */
    public function updateUserCity($userId, $cityId, $cityName) {
        return $this->update('users', [
            'city_id' => $cityId,
            'city_name' => $cityName,
            'updated_at' => date('Y-m-d H:i:s')
        ], 'id = ?', [$userId]);
    }

    /**
     * Поиск города по названию (автодополнение)
     * @param string $query
     * @param int $limit
     * @return array
     */
    public function suggestCities($query, $limit = 10) {
        $stmt = $this->getPdo()->prepare("
            SELECT id, city_name, region_name, latitude, longitude
            FROM russian_cities
            WHERE city_name LIKE ? AND is_active = 1
            ORDER BY population DESC
            LIMIT ?
        ");
        $searchTerm = $query . '%';
        $stmt->execute([$searchTerm, $limit]);
        return $stmt->fetchAll();
    }

    /**
     * Получить город по ID
     * @param int $cityId
     * @return array|null
     */
    public function getCityById($cityId) {
        return $this->fetchOne("SELECT id, city_name, region_name, latitude, longitude FROM russian_cities WHERE id = ?", [$cityId]);
    }

    /**
     * Найти ближайший город к координатам
     * @param float $lat
     * @param float $lng
     * @return array|null
     */
    public function findNearestCity($lat, $lng) {
        // Формула гаверсинуса: расстояние в километрах
        $sql = "
            SELECT id, city_name, region_name, latitude, longitude,
                   (6371 * acos(cos(radians(?)) * cos(radians(latitude)) *
                    cos(radians(longitude) - radians(?)) + sin(radians(?)) *
                    sin(radians(latitude)))) AS distance
            FROM russian_cities
            WHERE latitude IS NOT NULL AND longitude IS NOT NULL
            ORDER BY distance
            LIMIT 1
        ";
        $stmt = $this->getPdo()->prepare($sql);
        $stmt->execute([$lat, $lng, $lat]);
        return $stmt->fetch();
    }

    // ===== СТАТИСТИКА =====
    public function getStats() {
        return $this->cacheRemember('admin_stats', 60, function() {
            return [
                'total_users' => $this->fetchCount("SELECT COUNT(*) FROM users"),
                'total_listings' => $this->fetchCount("SELECT COUNT(*) FROM listings"),
                'active_listings' => $this->fetchCount("SELECT COUNT(*) FROM listings WHERE status = 'approved'"),
                'pending_listings' => $this->fetchCount("SELECT COUNT(*) FROM listings WHERE status = 'pending'"),
                'pending_ai_jobs' => $this->fetchCount("SELECT COUNT(*) FROM ai_generation_jobs WHERE status = 'pending'"),
                'total_reviews' => $this->fetchCount("SELECT COUNT(*) FROM reviews WHERE is_visible = 1"),
                'pending_tasks' => $this->fetchCount("SELECT COUNT(*) FROM admin_tasks WHERE status = 'pending'")
            ];
        });
    }

    // ===== ОЧИСТКА УСТАРЕВШИХ ДАННЫХ =====
    public function cleanupOldData() {
        $this->query("UPDATE listings SET is_active = 0, status = 'archived' WHERE created_at < NOW() - INTERVAL 90 DAY AND status = 'approved'");
        $this->query("DELETE FROM listings WHERE created_at < NOW() - INTERVAL 180 DAY AND status IN ('archived', 'rejected')");
        $this->query("DELETE FROM messages WHERE created_at < NOW() - INTERVAL 90 DAY");
        $this->query("DELETE FROM sessions WHERE expires_at < NOW() OR created_at < NOW() - INTERVAL 30 DAY");
        $this->query("DELETE FROM rate_limits WHERE expires_at < NOW()");
        $this->query("DELETE FROM ai_generation_jobs WHERE created_at < NOW() - INTERVAL 30 DAY AND status IN ('completed', 'failed')");
        $this->query("DELETE FROM admin_tasks WHERE status = 'completed' AND resolved_at < NOW() - INTERVAL 30 DAY");
        return true;
    }

    // ===== ЛОГИРОВАНИЕ =====
    private function logError($message) {
        $trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 3);
        $caller = $trace[1] ?? ['function' => 'unknown', 'line' => 0];
        $logLine = sprintf("[%s] ERROR: %s in %s:%d\n", date('Y-m-d H:i:s'), $message, $caller['function'], $caller['line']);
        file_put_contents($this->logFile, $logLine, FILE_APPEND | LOCK_EX);
        if (filesize($this->logFile) > 10 * 1024 * 1024) {
            rename($this->logFile, $this->logFile . '.' . date('Ymd-His'));
        }
    }

    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function getPdo() {
        return $this->pdo;
    }
}