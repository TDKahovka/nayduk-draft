<?php
/* ============================================
   НАЙДУК — Notification Service (v3.1)
   - Отправка уведомлений (in-app, email, telegram)
   - Полная очередь email с обработкой воркером
   - Поддержка SMTP (PHPMailer, если установлен)
   - Redis с fallback на файловую очередь
   - Rate limiting, идемпотентность
   - Публичный метод sendEmail для внешнего вызова
   ============================================ */

require_once __DIR__ . '/Database.php';

class NotificationService {
    private $db;
    private $redis;
    private $redisAvailable = false;
    private $queueDir;
    private $logFile;
    private $smtpEnabled = false;
    private $smtpHost;
    private $smtpPort;
    private $smtpUser;
    private $smtpPass;
    private $smtpSecure;
    private $mailerClassExists;

    public function __construct() {
        $this->db = Database::getInstance();
        $this->queueDir = __DIR__ . '/../../storage/queue/';
        $this->logFile = __DIR__ . '/../../logs/notifications.log';
        $this->ensureDirectories();
        $this->initRedis();
        $this->initSMTP();
        $this->ensureTables();
    }

    private function ensureDirectories() {
        $dirs = [dirname($this->logFile), $this->queueDir, $this->queueDir . 'idempotent'];
        foreach ($dirs as $dir) {
            if (!is_dir($dir)) {
                mkdir($dir, 0755, true);
            }
        }
    }

    private function initRedis() {
        if (class_exists('Redis')) {
            try {
                $this->redis = new Redis();
                $this->redisAvailable = $this->redis->connect('127.0.0.1', 6379, 1);
                if ($this->redisAvailable) {
                    $this->redis->ping();
                }
            } catch (Exception $e) {
                $this->redisAvailable = false;
                $this->logError("Redis connection failed: " . $e->getMessage());
            }
        }
    }

    private function initSMTP() {
        $this->smtpEnabled = getenv('SMTP_ENABLED') === 'true';
        if ($this->smtpEnabled) {
            $this->smtpHost = getenv('SMTP_HOST') ?: 'smtp.gmail.com';
            $this->smtpPort = (int)(getenv('SMTP_PORT') ?: 587);
            $this->smtpUser = getenv('SMTP_USER') ?: '';
            $this->smtpPass = getenv('SMTP_PASS') ?: '';
            $this->smtpSecure = getenv('SMTP_SECURE') ?: 'tls';
            $this->mailerClassExists = class_exists('PHPMailer\PHPMailer\PHPMailer');
            if (!$this->mailerClassExists && $this->smtpEnabled) {
                $this->logError("PHPMailer not installed, SMTP disabled");
                $this->smtpEnabled = false;
            }
        }
    }

    private function ensureTables() {
        $this->db->query("
            CREATE TABLE IF NOT EXISTS notification_templates (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                event_type VARCHAR(100) NOT NULL,
                channel VARCHAR(50) NOT NULL,
                lang VARCHAR(10) DEFAULT 'ru',
                subject VARCHAR(255),
                body TEXT NOT NULL,
                is_active BOOLEAN DEFAULT TRUE,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY unique_template (event_type, channel, lang)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
        ");

        $this->db->query("
            CREATE TABLE IF NOT EXISTS notifications (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                user_id BIGINT UNSIGNED NOT NULL,
                event_type VARCHAR(100) NOT NULL,
                title VARCHAR(255),
                message TEXT NOT NULL,
                data JSON,
                is_read BOOLEAN DEFAULT FALSE,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
                INDEX idx_user_read (user_id, is_read)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
        ");

        $this->db->query("
            CREATE TABLE IF NOT EXISTS notification_logs (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                notification_id BIGINT UNSIGNED,
                user_id BIGINT UNSIGNED NOT NULL,
                event_type VARCHAR(100),
                channel VARCHAR(50),
                status VARCHAR(50),
                error TEXT,
                sent_at TIMESTAMP NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_user (user_id),
                INDEX idx_status (status)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
        ");

        $this->db->query("
            CREATE TABLE IF NOT EXISTS user_notification_settings (
                user_id BIGINT UNSIGNED NOT NULL,
                event_type VARCHAR(100) NOT NULL,
                channels JSON NOT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (user_id, event_type),
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
        ");

        $count = $this->db->fetchCount("SELECT COUNT(*) FROM notification_templates");
        if ($count == 0) {
            $this->initDefaultTemplates();
        }
    }

    private function initDefaultTemplates() {
        $templates = [
            ['profile_updated', 'inapp', 'ru', null, 'Профиль обновлён'],
            ['password_changed', 'inapp', 'ru', null, 'Пароль изменён'],
            ['2fa_enabled', 'inapp', 'ru', null, 'Двухфакторная аутентификация включена'],
            ['2fa_disabled', 'inapp', 'ru', null, 'Двухфакторная аутентификация отключена'],
            ['account_deleted', 'inapp', 'ru', null, 'Аккаунт удалён'],
            ['avatar_updated', 'inapp', 'ru', null, 'Аватар обновлён'],
            ['new_order', 'inapp', 'ru', null, 'Новый заказ #{{order_id}} на сумму {{amount}} руб.'],
            ['new_order', 'email', 'ru', 'Новый заказ #{{order_id}}', '<h1>Новый заказ</h1><p>Заказ #{{order_id}} на сумму {{amount}} руб.</p>'],
        ];
        $stmt = $this->db->getPdo()->prepare("INSERT INTO notification_templates (event_type, channel, lang, subject, body) VALUES (?, ?, ?, ?, ?)");
        foreach ($templates as $t) {
            $stmt->execute($t);
        }
    }

    private function logError($message) {
        $logLine = '[' . date('Y-m-d H:i:s') . '] ' . $message . PHP_EOL;
        file_put_contents($this->logFile, $logLine, FILE_APPEND | LOCK_EX);
        if (filesize($this->logFile) > 10 * 1024 * 1024) {
            rename($this->logFile, $this->logFile . '.' . date('Ymd-His'));
        }
    }

    private function logNotification($userId, $eventType, $channel, $status, $notificationId = null, $error = null) {
        $stmt = $this->db->getPdo()->prepare("INSERT INTO notification_logs (notification_id, user_id, event_type, channel, status, error, sent_at) VALUES (?, ?, ?, ?, ?, ?, ?)");
        $sentAt = ($status === 'sent') ? date('Y-m-d H:i:s') : null;
        $stmt->execute([$notificationId, $userId, $eventType, $channel, $status, $error, $sentAt]);
    }

    private function render($text, $data) {
        foreach ($data as $key => $value) {
            $text = str_replace('{{' . $key . '}}', $value, $text);
        }
        return $text;
    }

    private function getUserChannels($userId, $eventType) {
        $key = "notify:user:$userId:prefs";
        if ($this->redisAvailable) {
            try {
                $cached = $this->redis->hget($key, $eventType);
                if ($cached !== false) {
                    return json_decode($cached, true);
                }
            } catch (Exception $e) {
                $this->logError("Redis hget error: " . $e->getMessage());
                $this->redisAvailable = false;
            }
        }

        $cacheFile = __DIR__ . '/../../storage/cache/user_' . $userId . '_' . $eventType . '.json';
        if (file_exists($cacheFile)) {
            $cached = json_decode(file_get_contents($cacheFile), true);
            if ($cached && $cached['expires'] > time()) {
                return $cached['channels'];
            }
        }

        $stmt = $this->db->getPdo()->prepare("SELECT channels FROM user_notification_settings WHERE user_id = ? AND event_type = ?");
        $stmt->execute([$userId, $eventType]);
        $row = $stmt->fetch();
        if ($row) {
            $channels = json_decode($row['channels'], true);
        } else {
            $stmt2 = $this->db->getPdo()->prepare("SELECT DISTINCT channel FROM notification_templates WHERE event_type = ? AND is_active = 1");
            $stmt2->execute([$eventType]);
            $channels = $stmt2->fetchAll(PDO::FETCH_COLUMN);
        }

        if ($this->redisAvailable) {
            try {
                $this->redis->hset($key, $eventType, json_encode($channels));
                $this->redis->expire($key, 3600);
            } catch (Exception $e) {}
        } else {
            file_put_contents($cacheFile, json_encode(['expires' => time() + 3600, 'channels' => $channels]));
        }
        return $channels;
    }

    private function getTemplate($eventType, $channel, $lang = 'ru') {
        $key = "notify:template:$eventType:$channel:$lang";
        if ($this->redisAvailable) {
            try {
                $cached = $this->redis->get($key);
                if ($cached !== false) {
                    return json_decode($cached, true);
                }
            } catch (Exception $e) {}
        }

        $stmt = $this->db->getPdo()->prepare("SELECT subject, body FROM notification_templates WHERE event_type = ? AND channel = ? AND lang = ? AND is_active = 1");
        $stmt->execute([$eventType, $channel, $lang]);
        $row = $stmt->fetch();
        if ($row) {
            if ($this->redisAvailable) {
                $this->redis->setex($key, 600, json_encode($row));
            }
            return $row;
        }
        return null;
    }

    private function checkRateLimit($userId, $eventType, $channel) {
        $key = "notify:rate:$userId:$eventType:$channel";
        if ($this->redisAvailable) {
            try {
                $count = $this->redis->incr($key);
                if ($count == 1) $this->redis->expire($key, 3600);
                return $count <= 5;
            } catch (Exception $e) {}
        }

        $file = __DIR__ . '/../../storage/rate/' . md5($key) . '.txt';
        $dir = dirname($file);
        if (!is_dir($dir)) mkdir($dir, 0755, true);
        $count = 0;
        if (file_exists($file)) {
            $data = json_decode(file_get_contents($file), true);
            if ($data && $data['expires'] > time()) {
                $count = $data['count'];
            }
        }
        $count++;
        file_put_contents($file, json_encode(['count' => $count, 'expires' => time() + 3600]));
        return $count <= 5;
    }

    private function enqueue($queueName, $job) {
        $data = json_encode($job);
        if ($this->redisAvailable) {
            try {
                $this->redis->rpush($queueName, $data);
                return;
            } catch (Exception $e) {
                $this->logError("Redis rpush error: " . $e->getMessage());
                $this->redisAvailable = false;
            }
        }
        $file = $this->queueDir . $queueName . '.queue';
        file_put_contents($file, $data . PHP_EOL, FILE_APPEND | LOCK_EX);
    }

    private function saveInApp($userId, $eventType, $title, $message, $data) {
        $stmt = $this->db->getPdo()->prepare("INSERT INTO notifications (user_id, event_type, title, message, data) VALUES (?, ?, ?, ?, ?)");
        $stmt->execute([$userId, $eventType, $title, $message, $data ? json_encode($data) : null]);
        return $this->db->getPdo()->lastInsertId();
    }

    /**
     * Отправить уведомление пользователю (in-app и другие каналы)
     * @param int $userId
     * @param string $eventType
     * @param array $data
     * @return bool
     */
    public function send($userId, $eventType, $data = []) {
        $user = $this->db->getUserById($userId);
        if (!$user) {
            $this->logError("User $userId not found for notification $eventType");
            return false;
        }

        $eventId = md5($userId . $eventType . json_encode($data));
        $idempotentKey = "notify:sent:$eventId";
        if ($this->redisAvailable) {
            try {
                if ($this->redis->exists($idempotentKey)) return true;
                $this->redis->setex($idempotentKey, 604800, 1);
            } catch (Exception $e) {}
        } else {
            $file = $this->queueDir . 'idempotent/' . $eventId . '.lock';
            if (file_exists($file)) return true;
            touch($file);
        }

        $channels = $this->getUserChannels($userId, $eventType);
        if (empty($channels)) return false;

        foreach ($channels as $channel) {
            if (!$this->checkRateLimit($userId, $eventType, $channel)) continue;

            $template = $this->getTemplate($eventType, $channel);
            if (!$template) continue;

            $subject = isset($template['subject']) ? $this->render($template['subject'], $data) : null;
            $body = $this->render($template['body'], $data);

            if ($channel === 'inapp') {
                $notificationId = $this->saveInApp($userId, $eventType, $subject ?: $body, $body, $data);
                $this->logNotification($userId, $eventType, $channel, 'sent', $notificationId);
            } else {
                $this->enqueue($channel . '_queue', [
                    'user_id' => $userId,
                    'event_type' => $eventType,
                    'subject' => $subject,
                    'body' => $body,
                    'data' => $data,
                    'user_email' => $user['email'],
                    'user_name' => $user['name']
                ]);
                $this->logNotification($userId, $eventType, $channel, 'queued');
            }
        }
        return true;
    }

    /**
     * Отправить email напрямую (ставит в очередь)
     * @param string $to
     * @param string $subject
     * @param string $body
     * @return bool
     */
    public function sendEmail($to, $subject, $body) {
        $this->enqueue('email_queue', [
            'to' => $to,
            'subject' => $subject,
            'body' => $body,
            'user_email' => $to,
            'user_name' => '',
            'user_id' => 0,
            'event_type' => 'external'
        ]);
        return true;
    }

    /**
     * Отправка email через очередь (вызывается воркером)
     * @param array $job
     * @return bool
     */
    public function sendEmailFromQueue($job) {
        $to = $job['to'] ?? $job['user_email'];
        $subject = $job['subject'];
        $body = $job['body'];
        $userName = $job['user_name'] ?? '';

        $body = str_replace('{{name}}', $userName, $body);

        if ($this->smtpEnabled && $this->mailerClassExists) {
            return $this->sendSMTP($to, $subject, $body);
        } else {
            return $this->sendMailFallback($to, $subject, $body);
        }
    }

    private function sendSMTP($to, $subject, $body) {
        try {
            $mail = new PHPMailer\PHPMailer\PHPMailer(true);
            $mail->isSMTP();
            $mail->Host = $this->smtpHost;
            $mail->SMTPAuth = true;
            $mail->Username = $this->smtpUser;
            $mail->Password = $this->smtpPass;
            $mail->SMTPSecure = $this->smtpSecure;
            $mail->Port = $this->smtpPort;
            $mail->setFrom($this->smtpUser, 'Найдук');
            $mail->addAddress($to);
            $mail->isHTML(true);
            $mail->Subject = $subject;
            $mail->Body = $body;
            $mail->AltBody = strip_tags($body);
            $mail->send();
            return true;
        } catch (Exception $e) {
            $this->logError("SMTP error: " . $e->getMessage());
            return false;
        }
    }

    private function sendMailFallback($to, $subject, $body) {
        $headers = "From: noreply@nayduk.ru\r\n";
        $headers .= "Content-Type: text/html; charset=UTF-8\r\n";
        return mail($to, $subject, $body, $headers);
    }

    /**
     * Обработка очереди email (вызывается воркером)
     * @param int $limit
     * @return int количество обработанных писем
     */
    public function processEmailQueue($limit = 100) {
        $processed = 0;
        $queueName = 'email_queue';
        $jobs = [];

        if ($this->redisAvailable) {
            try {
                while ($processed < $limit && ($job = $this->redis->lpop($queueName))) {
                    $jobs[] = json_decode($job, true);
                }
            } catch (Exception $e) {
                $this->logError("Redis lpop error: " . $e->getMessage());
            }
        } else {
            $file = $this->queueDir . $queueName . '.queue';
            if (file_exists($file)) {
                $lines = file($file, FILE_IGNORE_NEW_LINES);
                $jobs = array_slice($lines, 0, $limit);
                $remaining = array_slice($lines, $limit);
                file_put_contents($file, implode(PHP_EOL, $remaining), LOCK_EX);
            }
        }

        foreach ($jobs as $job) {
            $success = $this->sendEmailFromQueue($job);
            $userId = $job['user_id'] ?? 0;
            $eventType = $job['event_type'] ?? 'external';
            $this->logNotification($userId, $eventType, 'email', $success ? 'sent' : 'failed', null, $success ? null : 'SMTP error');
            if ($success) $processed++;
        }
        return $processed;
    }

    public function clearUserCache($userId) {
        if ($this->redisAvailable) {
            try {
                $this->redis->del("notify:user:$userId:prefs");
            } catch (Exception $e) {}
        }
        array_map('unlink', glob(__DIR__ . '/../../storage/cache/user_' . $userId . '_*.json'));
    }
}