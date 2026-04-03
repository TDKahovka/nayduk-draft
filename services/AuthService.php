<?php
/* ============================================
   НАЙДУК — Сервис аутентификации (безопасность) v2.0
   - Rate limiting по IP + email + fingerprint (Redis + файлы)
   - Автоочистка старых записей login_attempts
   - Логирование успешных и неудачных попыток
   - Поддержка OAuth привязки аккаунтов
   - Кэширование блокировок
   ============================================ */

class AuthService {
    private $db;
    private $redis;
    private $redisAvailable = false;
    private $secretKey;
    private $cacheDir;
    private $logFile;

    public function __construct($db) {
        $this->db = $db;
        $this->secretKey = getenv('APP_SECRET') ?: 'default_secret_change_me';
        $this->cacheDir = __DIR__ . '/../../storage/cache/';
        $this->logFile = __DIR__ . '/../../logs/auth.log';
        $this->ensureDirectories();
        $this->initRedis();
        $this->ensureTables();
        $this->cleanupOldAttempts();
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
        $this->db->query("
            CREATE TABLE IF NOT EXISTS login_attempts (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                email VARCHAR(255) NOT NULL,
                ip VARCHAR(45) NOT NULL,
                fingerprint VARCHAR(64),
                successful BOOLEAN DEFAULT FALSE,
                attempted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_email_ip (email, ip),
                INDEX idx_created (attempted_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
        ");

        $this->db->query("
            CREATE TABLE IF NOT EXISTS magic_links (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                user_id BIGINT UNSIGNED NOT NULL,
                token VARCHAR(64) NOT NULL UNIQUE,
                expires_at TIMESTAMP NOT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
                INDEX idx_token (token)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
        ");

        $this->db->query("
            CREATE TABLE IF NOT EXISTS oauth_accounts (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                user_id BIGINT UNSIGNED NOT NULL,
                provider VARCHAR(50) NOT NULL,
                provider_id VARCHAR(255) NOT NULL,
                access_token TEXT,
                refresh_token TEXT,
                expires_at TIMESTAMP NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY unique_provider (provider, provider_id),
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
                INDEX idx_user (user_id),
                INDEX idx_provider (provider)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
        ");
    }

    private function cleanupOldAttempts() {
        // Очищаем записи старше 30 дней
        $this->db->delete('login_attempts', 'attempted_at < NOW() - INTERVAL 30 DAY');
    }

    private function logError($message) {
        $logLine = '[' . date('Y-m-d H:i:s') . '] ' . $message . PHP_EOL;
        file_put_contents($this->logFile, $logLine, FILE_APPEND | LOCK_EX);
        if (filesize($this->logFile) > 10 * 1024 * 1024) {
            rename($this->logFile, $this->logFile . '.' . date('Ymd-His'));
        }
    }

    /**
     * Генерация отпечатка (fingerprint) на основе user-agent, IP и скрытого ключа
     * @return string
     */
    public function generateFingerprint() {
        $data = $_SERVER['HTTP_USER_AGENT'] . $_SERVER['REMOTE_ADDR'] . session_id();
        return hash_hmac('sha256', $data, $this->secretKey);
    }

    /**
     * Проверка лимита попыток входа (Redis + файлы)
     * @param string $email
     * @param string $ip
     * @param string $fingerprint
     * @param int $limit
     * @param int $window
     * @return bool
     */
    public function checkLoginAttempts($email, $ip, $fingerprint, $limit = 3, $window = 900) {
        $key = "login_attempts:{$email}:{$ip}:{$fingerprint}";
        if ($this->redisAvailable) {
            try {
                $count = $this->redis->incr($key);
                if ($count == 1) $this->redis->expire($key, $window);
                return $count <= $limit;
            } catch (Exception $e) {
                $this->logError("Redis incr error: " . $e->getMessage());
                $this->redisAvailable = false;
            }
        }

        // Файловый fallback
        $file = $this->cacheDir . md5($key) . '.attempts';
        $now = time();
        $data = [];
        if (file_exists($file)) {
            $data = json_decode(file_get_contents($file), true);
            if (!is_array($data)) $data = [];
        }
        $data = array_filter($data, fn($t) => $t > $now - $window);
        if (count($data) >= $limit) return false;
        $data[] = $now;
        file_put_contents($file, json_encode($data), LOCK_EX);
        return true;
    }

    /**
     * Сброс счётчика попыток после успешного входа
     * @param string $email
     * @param string $ip
     * @param string $fingerprint
     */
    public function clearFailedAttempts($email, $ip, $fingerprint) {
        $key = "login_attempts:{$email}:{$ip}:{$fingerprint}";
        if ($this->redisAvailable) {
            try {
                $this->redis->del($key);
            } catch (Exception $e) {}
        } else {
            $file = $this->cacheDir . md5($key) . '.attempts';
            @unlink($file);
        }
        $this->db->delete('login_attempts', 'email = ? AND ip = ? AND fingerprint = ? AND successful = 0', [$email, $ip, $fingerprint]);
    }

    /**
     * Логирование попытки входа
     * @param string $email
     * @param string $ip
     * @param string $fingerprint
     * @param bool $successful
     */
    public function logAttempt($email, $ip, $fingerprint, $successful = false) {
        $this->db->insert('login_attempts', [
            'email' => $email,
            'ip' => $ip,
            'fingerprint' => $fingerprint,
            'successful' => $successful ? 1 : 0,
            'attempted_at' => date('Y-m-d H:i:s')
        ]);

        // Дополнительное логирование в security_logs для успешных
        if ($successful) {
            $user = $this->db->getUserByEmail($email);
            if ($user) {
                $this->db->insert('security_logs', [
                    'user_id' => $user['id'],
                    'ip_address' => $ip,
                    'event_type' => 'login_success',
                    'description' => "Успешный вход пользователя $email",
                    'severity' => 'low',
                    'created_at' => date('Y-m-d H:i:s')
                ]);
            }
        }
    }

    /**
     * Привязка OAuth-аккаунта к пользователю
     * @param int $userId
     * @param string $provider
     * @param string $providerId
     * @param string $accessToken
     * @param string|null $refreshToken
     * @param int|null $expiresIn
     * @return bool
     */
    public function linkOAuthAccount($userId, $provider, $providerId, $accessToken, $refreshToken = null, $expiresIn = null) {
        $expiresAt = $expiresIn ? date('Y-m-d H:i:s', time() + $expiresIn) : null;
        $existing = $this->db->fetchOne("SELECT id FROM oauth_accounts WHERE provider = ? AND provider_id = ?", [$provider, $providerId]);
        if ($existing) {
            $this->db->update('oauth_accounts', [
                'user_id' => $userId,
                'access_token' => $accessToken,
                'refresh_token' => $refreshToken,
                'expires_at' => $expiresAt,
                'updated_at' => date('Y-m-d H:i:s')
            ], 'id = ?', [$existing['id']]);
        } else {
            $this->db->insert('oauth_accounts', [
                'user_id' => $userId,
                'provider' => $provider,
                'provider_id' => $providerId,
                'access_token' => $accessToken,
                'refresh_token' => $refreshToken,
                'expires_at' => $expiresAt,
                'created_at' => date('Y-m-d H:i:s')
            ]);
        }
        return true;
    }

    /**
     * Получить пользователя по OAuth-аккаунту
     * @param string $provider
     * @param string $providerId
     * @return array|null
     */
    public function getUserByOAuth($provider, $providerId) {
        $stmt = $this->db->getPdo()->prepare("
            SELECT u.* FROM users u
            JOIN oauth_accounts oa ON u.id = oa.user_id
            WHERE oa.provider = ? AND oa.provider_id = ?
        ");
        $stmt->execute([$provider, $providerId]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    /**
     * Создание Magic Link
     * @param int $userId
     * @param int $ttl
     * @return string
     */
    public function createMagicLink($userId, $ttl = 3600) {
        $token = bin2hex(random_bytes(32));
        $expires = date('Y-m-d H:i:s', time() + $ttl);
        $this->db->insert('magic_links', [
            'user_id' => $userId,
            'token' => $token,
            'expires_at' => $expires,
            'created_at' => date('Y-m-d H:i:s')
        ]);
        return $token;
    }

    /**
     * Проверка и использование Magic Link
     * @param string $token
     * @return int|false user_id or false
     */
    public function consumeMagicLink($token) {
        $stmt = $this->db->getPdo()->prepare("SELECT user_id FROM magic_links WHERE token = ? AND expires_at > NOW()");
        $stmt->execute([$token]);
        $row = $stmt->fetch();
        if ($row) {
            $this->db->delete('magic_links', 'token = ?', [$token]);
            return (int)$row['user_id'];
        }
        return false;
    }
}