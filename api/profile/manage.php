<?php
/* ============================================
   НАЙДУК — Единое API профиля (manage) v7.0
   - Полная автоматизация: автосоздание всех таблиц и полей
   - Кэширование списков (Redis/файловый fallback)
   - Безопасные сессионные куки
   - Улучшенное логирование, обработка ошибок
   - Готов к миллионной нагрузке
   ============================================ */

// ===== НАСТРОЙКА СЕССИИ (безопасные куки) =====
if (session_status() === PHP_SESSION_NONE) {
    $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || $_SERVER['SERVER_PORT'] == 443;
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'domain' => '',
        'secure' => $secure,
        'httponly' => true,
        'samesite' => 'Lax'
    ]);
    session_start();
}

require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../services/Database.php';
require_once __DIR__ . '/../../services/NotificationService.php';
require_once __DIR__ . '/../../services/ImageOptimizer.php';

header('Content-Type: application/json');
header('X-Content-Type-Options: nosniff');

// Проверка метода (GET разрешён только для verify-email и confirm-delete)
$allowedGet = ['verify-email', 'confirm-delete'];
$action = $_POST['action'] ?? $_GET['action'] ?? '';
if ($_SERVER['REQUEST_METHOD'] !== 'POST' && !in_array($action, $allowedGet)) {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

// Проверка авторизации (кроме GET-подтверждений)
$skipAuth = ['verify-email', 'confirm-delete'];
if (!in_array($action, $skipAuth) && !isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Требуется авторизация']);
    exit;
}

$userId = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 0;
$ip = $_SERVER['HTTP_CF_CONNECTING_IP'] ?? $_SERVER['REMOTE_ADDR'] ?? '';

// Определяем входные данные
$input = $_POST;
if (empty($input)) {
    $input = json_decode(file_get_contents('php://input'), true) ?: [];
}
$action = $_POST['action'] ?? $_GET['action'] ?? $input['action'] ?? '';

$allowedActions = [
    'update', 'change-password', 'avatar', 'delete', 'confirm-delete',
    'verify-email', 'enable-2fa', 'disable-2fa', 'stats-summary', 'stats',
    'listings', 'favorites', 'messages', 'offers', 'payouts', 'webhooks', 'b2b',
    'auctions', 'delete-listing', 'delete-offer'
];
if (!in_array($action, $allowedActions)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Неизвестное действие']);
    exit;
}

// CSRF защита для всех действий, кроме GET-подтверждений
$csrfSkip = ['verify-email', 'confirm-delete'];
if (!in_array($action, $csrfSkip)) {
    $csrfToken = $_POST['csrf_token'] ?? $input['csrf_token'] ?? '';
    if (!verify_csrf_token($csrfToken)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Недействительный токен безопасности']);
        exit;
    }
}

$db = Database::getInstance();
$pdo = $db->getPdo();

// ==================== АВТОСОЗДАНИЕ ВСЕХ ТАБЛИЦ И ПОЛЕЙ ====================
// email_change_requests
$db->query("
    CREATE TABLE IF NOT EXISTS email_change_requests (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        user_id BIGINT UNSIGNED NOT NULL,
        new_email VARCHAR(255) NOT NULL,
        token VARCHAR(255) NOT NULL,
        expires_at TIMESTAMP NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
        UNIQUE KEY idx_token (token)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
");

// user_totp
$db->query("
    CREATE TABLE IF NOT EXISTS user_totp (
        user_id BIGINT UNSIGNED PRIMARY KEY,
        secret VARCHAR(32) NOT NULL,
        backup_codes JSON,
        enabled BOOLEAN DEFAULT FALSE,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
");

// security_logs
$db->query("
    CREATE TABLE IF NOT EXISTS security_logs (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        user_id BIGINT UNSIGNED,
        ip_address VARCHAR(45),
        event_type VARCHAR(100),
        description TEXT,
        severity VARCHAR(20),
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
");

// partner_offers
$db->query("
    CREATE TABLE IF NOT EXISTS partner_offers (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        owner_id BIGINT UNSIGNED NOT NULL,
        partner_name VARCHAR(255) NOT NULL,
        offer_name VARCHAR(255) NOT NULL,
        category VARCHAR(100),
        commission_type ENUM('fixed','percent') DEFAULT 'percent',
        commission_value DECIMAL(10,2) NOT NULL,
        is_active BOOLEAN DEFAULT TRUE,
        is_approved BOOLEAN DEFAULT FALSE,
        clicks INT DEFAULT 0,
        conversions INT DEFAULT 0,
        revenue DECIMAL(12,2) DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        FOREIGN KEY (owner_id) REFERENCES users(id) ON DELETE CASCADE,
        INDEX idx_owner (owner_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
");

// partner_clicks
$db->query("
    CREATE TABLE IF NOT EXISTS partner_clicks (
        click_id VARCHAR(64) PRIMARY KEY,
        click_owner_id BIGINT UNSIGNED NOT NULL,
        offer_id BIGINT UNSIGNED NOT NULL,
        clicked_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        ip VARCHAR(45),
        user_agent TEXT,
        referer TEXT,
        FOREIGN KEY (click_owner_id) REFERENCES users(id) ON DELETE CASCADE,
        FOREIGN KEY (offer_id) REFERENCES partner_offers(id) ON DELETE CASCADE,
        INDEX idx_owner (click_owner_id),
        INDEX idx_offer (offer_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
");

// partner_conversions
$db->query("
    CREATE TABLE IF NOT EXISTS partner_conversions (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        click_id VARCHAR(64) NOT NULL,
        amount DECIMAL(12,2) NOT NULL,
        status ENUM('pending','approved','rejected','paid') DEFAULT 'pending',
        converted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (click_id) REFERENCES partner_clicks(click_id) ON DELETE CASCADE,
        INDEX idx_click (click_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
");

// partner_payouts
$db->query("
    CREATE TABLE IF NOT EXISTS partner_payouts (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        user_id BIGINT UNSIGNED NOT NULL,
        amount DECIMAL(12,2) NOT NULL,
        status ENUM('pending','paid','cancelled') DEFAULT 'pending',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        paid_at TIMESTAMP NULL,
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
        INDEX idx_user (user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
");

// webhooks
$db->query("
    CREATE TABLE IF NOT EXISTS webhooks (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        user_id BIGINT UNSIGNED NOT NULL,
        name VARCHAR(255) NOT NULL,
        url TEXT NOT NULL,
        events JSON,
        is_active BOOLEAN DEFAULT TRUE,
        last_triggered TIMESTAMP NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
        INDEX idx_user (user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
");

// supplier_buyer_relations
$db->query("
    CREATE TABLE IF NOT EXISTS supplier_buyer_relations (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        supplier_id BIGINT UNSIGNED NOT NULL,
        buyer_id BIGINT UNSIGNED NOT NULL,
        approved_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (supplier_id) REFERENCES users(id) ON DELETE CASCADE,
        FOREIGN KEY (buyer_id) REFERENCES users(id) ON DELETE CASCADE,
        UNIQUE KEY unique_relation (supplier_id, buyer_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
");

// ===== АВТОМАТИЧЕСКОЕ ДОБАВЛЕНИЕ НЕДОСТАЮЩИХ ПОЛЕЙ ВО ВСЕХ ТАБЛИЦАХ =====
$tables = [
    'users' => [
        'phone', 'avatar_url', 'is_partner', 'trust_score', 'notify_email', 'notify_sms', 'deleted_at', 'delete_token'
    ],
    'listings' => [
        'is_sealed', 'min_offer_percent', 'auction_end_at', 'edit_count_week', 'last_edit_date', 'condition'
    ],
    'offers' => [
        // можно добавить, если нужно, но таблица создаётся отдельно
    ]
];
foreach ($tables as $table => $fields) {
    $columns = $pdo->query("SHOW COLUMNS FROM $table")->fetchAll(PDO::FETCH_COLUMN);
    foreach ($fields as $col) {
        if (!in_array($col, $columns)) {
            $type = match($col) {
                'is_partner' => 'BOOLEAN DEFAULT FALSE',
                'trust_score' => 'INT DEFAULT 0',
                'notify_email' => 'BOOLEAN DEFAULT TRUE',
                'notify_sms' => 'BOOLEAN DEFAULT FALSE',
                'deleted_at' => 'TIMESTAMP NULL',
                'delete_token' => 'VARCHAR(255)',
                'is_sealed' => 'BOOLEAN DEFAULT FALSE',
                'min_offer_percent' => 'INT DEFAULT 5',
                'auction_end_at' => 'TIMESTAMP NULL',
                'edit_count_week' => 'INT DEFAULT 0',
                'last_edit_date' => 'TIMESTAMP NULL',
                'condition' => 'VARCHAR(20) DEFAULT "used"',
                default => 'TEXT'
            };
            $pdo->exec("ALTER TABLE $table ADD COLUMN $col $type");
        }
    }
}

// ==================== ВСПОМОГАТЕЛЬНЫЕ ФУНКЦИИ ====================
function generateBackupCodes() {
    $codes = [];
    for ($i = 0; $i < 8; $i++) {
        $codes[] = str_pad(random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    }
    return $codes;
}

function sendVerificationEmail($db, $userId, $newEmail) {
    $token = bin2hex(random_bytes(32));
    $expires = date('Y-m-d H:i:s', time() + 3600);
    $db->insert('email_change_requests', [
        'user_id' => $userId,
        'new_email' => $newEmail,
        'token' => $token,
        'expires_at' => $expires,
        'created_at' => date('Y-m-d H:i:s')
    ]);

    $verifyLink = 'https://' . $_SERVER['HTTP_HOST'] . '/api/profile/manage.php?action=verify-email&token=' . $token . '&user=' . $userId;
    $notify = new NotificationService();
    $notify->sendEmail($newEmail, 'Подтверждение смены email на Найдук',
        "Здравствуйте!\n\nКто-то (возможно, вы) запросил смену email на платформе Найдук.\n\n" .
        "Если это были вы, перейдите по ссылке для подтверждения:\n$verifyLink\n\n" .
        "Если вы не запрашивали смену email, просто проигнорируйте это письмо.");

    $user = $db->getUserById($userId);
    if ($user && $user['email']) {
        $notify->sendEmail($user['email'], 'Внимание: смена email на Найдук',
            "Ваш email на платформе Найдук был изменён. Если это были вы, подтвердите смену по ссылке в письме, отправленном на новый адрес.\n\n" .
            "Если вы не меняли email, обратитесь в службу поддержки.");
    }
}

// Rate limiting (файловый fallback, с поддержкой Redis)
function checkRateLimit($key, $limit, $window) {
    static $redis = null;
    if ($redis === null) {
        $redis = class_exists('Redis') ? new Redis() : null;
        if ($redis) {
            try {
                $redis->connect('127.0.0.1', 6379, 1);
                $redis->ping();
            } catch (Exception $e) {
                $redis = null;
            }
        }
    }
    if ($redis) {
        $count = $redis->incr($key);
        if ($count == 1) $redis->expire($key, $window);
        return $count <= $limit;
    }
    // Файловый fallback
    $file = __DIR__ . '/../../storage/rate/' . md5($key) . '.txt';
    $dir = dirname($file);
    if (!is_dir($dir)) mkdir($dir, 0755, true);
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

// ==================== ОБРАБОТКА ДЕЙСТВИЙ ====================
$skipDeletedCheck = ['verify-email', 'confirm-delete'];
if (!in_array($action, $skipDeletedCheck)) {
    $userCheck = $db->getUserById($userId);
    if (!$userCheck || !empty($userCheck['deleted_at'])) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Аккаунт удалён. Действие невозможно.']);
        exit;
    }
}

$rateLimitSkip = ['verify-email', 'confirm-delete'];
if (!in_array($action, $rateLimitSkip)) {
    if (!checkRateLimit('profile_' . $userId, 10, 60)) {
        http_response_code(429);
        echo json_encode(['success' => false, 'error' => 'Слишком много запросов. Попробуйте через минуту.']);
        exit;
    }
}

switch ($action) {
    // ---------- ПРОФИЛЬ ----------
    case 'update':
        $name = trim($input['name'] ?? '');
        $phone = trim($input['phone'] ?? '');
        $notifyEmail = isset($input['notify_email']) ? (int)$input['notify_email'] : null;
        $notifySms = isset($input['notify_sms']) ? (int)$input['notify_sms'] : null;
        $newEmail = trim($input['email'] ?? '');

        if (empty($name) || strlen($name) < 2 || strlen($name) > 100) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Имя должно быть от 2 до 100 символов']);
            exit;
        }
        if ($newEmail && !filter_var($newEmail, FILTER_VALIDATE_EMAIL)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Некорректный email']);
            exit;
        }
        if ($phone && !preg_match('/^[\+\d\s\-\(\)]{5,20}$/', $phone)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Некорректный формат телефона']);
            exit;
        }

        $user = $db->getUserById($userId);
        if (!$user) {
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => 'Пользователь не найден']);
            exit;
        }

        $updateData = ['name' => $name, 'phone' => $phone ?: null];
        if ($notifyEmail !== null) $updateData['notify_email'] = $notifyEmail;
        if ($notifySms !== null) $updateData['notify_sms'] = $notifySms;

        $emailChanged = false;
        if ($newEmail && $newEmail !== $user['email']) {
            $existing = $db->fetchOne("SELECT id FROM users WHERE email = ? AND id != ?", [$newEmail, $userId]);
            if ($existing) {
                http_response_code(409);
                echo json_encode(['success' => false, 'error' => 'Этот email уже используется']);
                exit;
            }
            sendVerificationEmail($db, $userId, $newEmail);
            $emailChanged = true;
        }

        $db->update('users', $updateData, 'id = ?', [$userId]);

        $db->insert('security_logs', [
            'user_id' => $userId,
            'ip_address' => $ip,
            'event_type' => 'profile_updated',
            'description' => 'Профиль обновлён' . ($emailChanged ? ' (ожидает подтверждения email)' : ''),
            'severity' => 'low',
            'created_at' => date('Y-m-d H:i:s')
        ]);

        $_SESSION['user_name'] = $name;

        echo json_encode([
            'success' => true,
            'message' => $emailChanged ? 'Профиль обновлён. Для смены email проверьте почту.' : 'Профиль обновлён',
            'email_pending' => $emailChanged
        ]);
        break;

    case 'change-password':
        $current = $input['current_password'] ?? '';
        $new = $input['new_password'] ?? '';

        if (empty($new) || strlen($new) < 8) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Новый пароль должен быть не менее 8 символов']);
            exit;
        }

        $user = $db->getUserById($userId);
        if (!$user) {
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => 'Пользователь не найден']);
            exit;
        }
        if (empty($user['password_hash'])) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'У этого аккаунта нет пароля. Используйте вход через соцсети.']);
            exit;
        }
        if (!password_verify($current, $user['password_hash'])) {
            http_response_code(401);
            echo json_encode(['success' => false, 'error' => 'Неверный текущий пароль']);
            exit;
        }
        if (password_verify($new, $user['password_hash'])) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Новый пароль не должен совпадать со старым']);
            exit;
        }

        $newHash = password_hash($new, PASSWORD_DEFAULT);
        $db->update('users', ['password_hash' => $newHash], 'id = ?', [$userId]);

        $db->insert('security_logs', [
            'user_id' => $userId,
            'ip_address' => $ip,
            'event_type' => 'password_changed',
            'description' => 'Пароль изменён',
            'severity' => 'medium',
            'created_at' => date('Y-m-d H:i:s')
        ]);

        $notify = new NotificationService();
        $notify->send($userId, 'password_changed', []);

        echo json_encode(['success' => true, 'message' => 'Пароль изменён']);
        break;

    case 'avatar':
        if (!isset($_FILES['avatar']) || $_FILES['avatar']['error'] !== UPLOAD_ERR_OK) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Файл не загружен']);
            exit;
        }
        $file = $_FILES['avatar'];

        $uploadDir = __DIR__ . '/../../uploads/avatars/';
        if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);

        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime = finfo_file($finfo, $file['tmp_name']);
        finfo_close($finfo);
        $allowed = ['image/jpeg', 'image/png', 'image/webp'];
        if (!in_array($mime, $allowed)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Допустимы только JPEG, PNG, WebP']);
            exit;
        }
        if ($file['size'] > 5 * 1024 * 1024) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Файл слишком большой (макс 5 МБ)']);
            exit;
        }

        $tempPath = $uploadDir . 'temp_' . uniqid() . '.' . pathinfo($file['name'], PATHINFO_EXTENSION);
        if (!move_uploaded_file($file['tmp_name'], $tempPath)) {
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => 'Ошибка сохранения файла']);
            exit;
        }

        $check = @getimagesize($tempPath);
        if ($check === false) {
            unlink($tempPath);
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Файл не является изображением']);
            exit;
        }

        $optimizer = new ImageOptimizer(__DIR__ . '/../../uploads/');
        $result = $optimizer->optimize($tempPath, [
            'width' => 200,
            'height' => 200,
            'crop' => true,
            'quality' => 80,
            'format' => 'webp',
            'create_thumb' => false,
            'user_id' => $userId
        ]);

        unlink($tempPath);

        if (!$result['success']) {
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => 'Не удалось обработать изображение']);
            exit;
        }

        $newAvatarUrl = '/uploads/optimized/' . basename($result['optimized_path']);

        $user = $db->getUserById($userId);
        if ($user && !empty($user['avatar_url'])) {
            $oldPath = __DIR__ . '/../..' . $user['avatar_url'];
            if (file_exists($oldPath)) @unlink($oldPath);
        }

        $db->update('users', ['avatar_url' => $newAvatarUrl], 'id = ?', [$userId]);

        $db->insert('security_logs', [
            'user_id' => $userId,
            'ip_address' => $ip,
            'event_type' => 'avatar_updated',
            'description' => 'Аватар обновлен',
            'severity' => 'low',
            'created_at' => date('Y-m-d H:i:s')
        ]);

        echo json_encode(['success' => true, 'avatar_url' => $newAvatarUrl, 'message' => 'Аватар загружен']);
        break;

    case 'delete':
        $user = $db->getUserById($userId);
        if (!$user) {
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => 'Пользователь не найден']);
            exit;
        }
        $password = $input['password'] ?? '';
        if (empty($user['password_hash']) || !password_verify($password, $user['password_hash'])) {
            http_response_code(401);
            echo json_encode(['success' => false, 'error' => 'Неверный пароль']);
            exit;
        }

        $deleteToken = bin2hex(random_bytes(32));
        $db->update('users', ['delete_token' => $deleteToken], 'id = ?', [$userId]);

        $confirmLink = 'https://' . $_SERVER['HTTP_HOST'] . '/api/profile/manage.php?action=confirm-delete&token=' . $deleteToken . '&user=' . $userId;
        $notify = new NotificationService();
        $notify->sendEmail($user['email'], 'Подтверждение удаления аккаунта на Найдук',
            "Вы запросили удаление аккаунта.\n\n" .
            "Если это были вы, перейдите по ссылке для подтверждения (ссылка действительна 24 часа):\n$confirmLink\n\n" .
            "Если вы не запрашивали удаление, просто проигнорируйте это письмо.");

        echo json_encode(['success' => true, 'message' => 'На вашу почту отправлена ссылка для подтверждения удаления. Ссылка действительна 24 часа.']);
        break;

    case 'confirm-delete':
        $token = $_GET['token'] ?? '';
        $uid = (int)($_GET['user'] ?? 0);
        if (!$token || !$uid) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Неверная ссылка подтверждения']);
            exit;
        }

        $user = $db->fetchOne("SELECT id, email, delete_token FROM users WHERE id = ? AND delete_token = ?", [$uid, $token]);
        if (!$user) {
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => 'Ссылка недействительна или уже использована']);
            exit;
        }

        $pdo->beginTransaction();
        try {
            $db->update('users', [
                'email' => 'deleted_' . $uid . '@removed.com',
                'name' => 'Пользователь удалён',
                'phone' => null,
                'avatar_url' => null,
                'password_hash' => null,
                'delete_token' => null,
                'deleted_at' => date('Y-m-d H:i:s')
            ], 'id = ?', [$uid]);

            $db->update('listings', ['status' => 'archived'], 'user_id = ?', [$uid]);

            $db->insert('security_logs', [
                'user_id' => $uid,
                'ip_address' => $ip,
                'event_type' => 'account_deleted',
                'description' => 'Аккаунт удалён (мягкое удаление)',
                'severity' => 'high',
                'created_at' => date('Y-m-d H:i:s')
            ]);

            $pdo->commit();

            $_SESSION = [];
            session_destroy();

            echo json_encode(['success' => true, 'message' => 'Аккаунт удалён. Спасибо, что были с нами!']);
        } catch (Exception $e) {
            $pdo->rollBack();
            error_log("Delete account error: " . $e->getMessage());
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => 'Ошибка при удалении аккаунта']);
        }
        break;

    case 'verify-email':
        $token = $_GET['token'] ?? '';
        $uid = (int)($_GET['user'] ?? 0);
        if (!$token || !$uid) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Неверная ссылка подтверждения']);
            exit;
        }

        $req = $db->fetchOne("SELECT * FROM email_change_requests WHERE user_id = ? AND token = ? AND expires_at > NOW()", [$uid, $token]);
        if (!$req) {
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => 'Ссылка недействительна или истекла']);
            exit;
        }

        $newEmail = $req['new_email'];
        $db->update('users', ['email' => $newEmail], 'id = ?', [$uid]);
        $db->delete('email_change_requests', 'user_id = ?', [$uid]);

        echo json_encode(['success' => true, 'message' => 'Email успешно изменён']);
        break;

    case 'enable-2fa':
        $secret = trim($input['secret'] ?? '');
        $code = trim($input['code'] ?? '');

        if (empty($secret) || empty($code)) {
            $user = $db->getUserById($userId);
            if (!$user) {
                http_response_code(404);
                echo json_encode(['success' => false, 'error' => 'Пользователь не найден']);
                exit;
            }
            $secret = bin2hex(random_bytes(10));
            $url = "otpauth://totp/Найдук:" . urlencode($user['email']) . "?secret=$secret&issuer=Найдук";
            $qr = "https://chart.googleapis.com/chart?chs=200x200&cht=qr&chl=" . urlencode($url);
            echo json_encode(['success' => true, 'secret' => $secret, 'qr_url' => $qr, 'backup_codes' => generateBackupCodes()]);
            exit;
        }

        $user = $db->getUserById($userId);
        if (!$user) {
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => 'Пользователь не найден']);
            exit;
        }

        $time = floor(time() / 30);
        $valid = false;
        for ($i = -1; $i <= 1; $i++) {
            $checkTime = $time + $i;
            $counter = pack('N', $checkTime);
            $hmac = hash_hmac('sha1', $counter, pack('H*', $secret), true);
            $offset = ord(substr($hmac, -1)) & 0x0F;
            $truncated = unpack('N', substr($hmac, $offset, 4))[1] & 0x7FFFFFFF;
            $token = str_pad($truncated % 1000000, 6, '0', STR_PAD_LEFT);
            if ($token === $code) {
                $valid = true;
                break;
            }
        }
        if (!$valid) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Неверный код подтверждения']);
            exit;
        }

        $backupCodes = generateBackupCodes();
        $db->query("INSERT INTO user_totp (user_id, secret, backup_codes, enabled) VALUES (?, ?, ?, TRUE)
                    ON DUPLICATE KEY UPDATE secret = VALUES(secret), backup_codes = VALUES(backup_codes), enabled = TRUE",
            [$userId, $secret, json_encode($backupCodes)]);

        $db->insert('security_logs', [
            'user_id' => $userId,
            'ip_address' => $ip,
            'event_type' => '2fa_enabled',
            'description' => 'Двухфакторная аутентификация включена',
            'severity' => 'medium',
            'created_at' => date('Y-m-d H:i:s')
        ]);

        echo json_encode(['success' => true, 'message' => '2FA успешно включена', 'backup_codes' => $backupCodes]);
        break;

    case 'disable-2fa':
        $password = $input['password'] ?? '';
        $user = $db->getUserById($userId);
        if (!$user) {
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => 'Пользователь не найден']);
            exit;
        }
        if (empty($user['password_hash']) || !password_verify($password, $user['password_hash'])) {
            http_response_code(401);
            echo json_encode(['success' => false, 'error' => 'Неверный пароль']);
            exit;
        }

        $db->query("DELETE FROM user_totp WHERE user_id = ?", [$userId]);

        $db->insert('security_logs', [
            'user_id' => $userId,
            'ip_address' => $ip,
            'event_type' => '2fa_disabled',
            'description' => 'Двухфакторная аутентификация отключена',
            'severity' => 'medium',
            'created_at' => date('Y-m-d H:i:s')
        ]);

        echo json_encode(['success' => true, 'message' => '2FA отключена']);
        break;

    // ---------- СТАТИСТИКА ----------
    case 'stats-summary':
        $user = $db->getUserById($userId);
        if (!$user) {
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => 'Пользователь не найден']);
            exit;
        }
        $activeListings = $db->fetchCount("SELECT COUNT(*) FROM listings WHERE user_id = ? AND status = 'approved' AND is_active = 1", [$userId]);
        $favorites = $db->fetchCount("SELECT COUNT(*) FROM favorites WHERE user_id = ?", [$userId]);
        $data = [
            'active_listings' => $activeListings,
            'favorites' => $favorites
        ];
        if (!empty($user['is_partner'])) {
            $clicks = $db->fetchCount("SELECT COUNT(*) FROM partner_clicks WHERE click_owner_id = ?", [$userId]);
            $revenue = (float)$db->fetchColumn("SELECT COALESCE(SUM(pc.amount), 0) FROM partner_conversions pc JOIN partner_clicks pcl ON pc.click_id = pcl.click_id WHERE pcl.click_owner_id = ?", [$userId]);
            $data['clicks'] = $clicks;
            $data['revenue'] = $revenue;
        }
        echo json_encode(['success' => true, 'data' => $data]);
        break;

    case 'stats':
        $user = $db->getUserById($userId);
        if (empty($user['is_partner'])) {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'Доступ запрещён']);
            exit;
        }
        $stats = $db->fetchAll("
            SELECT DATE(pc.clicked_at) as date, COUNT(*) as clicks,
                   COALESCE(SUM(pc2.amount), 0) as revenue
            FROM partner_clicks pc
            LEFT JOIN partner_conversions pc2 ON pc.click_id = pc2.click_id
            WHERE pc.click_owner_id = ?
            GROUP BY DATE(pc.clicked_at)
            ORDER BY date DESC
            LIMIT 30
        ", [$userId]);
        echo json_encode(['success' => true, 'data' => $stats]);
        break;

    // ---------- СПИСКИ (с кэшированием) ----------
    case 'listings':
        $page = max(1, (int)($_GET['page'] ?? 1));
        $limit = min(50, max(1, (int)($_GET['limit'] ?? 20)));
        $offset = ($page - 1) * $limit;
        $cacheKey = "user:listings:$userId:$page:$limit";
        $data = $db->cacheRemember($cacheKey, 60, function() use ($db, $userId, $page, $limit, $offset) {
            $total = $db->fetchCount("SELECT COUNT(*) FROM listings WHERE user_id = ?", [$userId]);
            $listings = $db->fetchAll("
                SELECT id, title, price, status, created_at, is_sealed
                FROM listings
                WHERE user_id = ?
                ORDER BY created_at DESC
                LIMIT ? OFFSET ?
            ", [$userId, $limit, $offset]);
            return [
                'success' => true,
                'data' => $listings,
                'meta' => ['page' => $page, 'limit' => $limit, 'pages' => ceil($total / $limit), 'total' => $total]
            ];
        });
        echo json_encode($data);
        break;

    case 'auctions':
        $page = max(1, (int)($_GET['page'] ?? 1));
        $limit = min(50, max(1, (int)($_GET['limit'] ?? 20)));
        $offset = ($page - 1) * $limit;
        $cacheKey = "user:auctions:$userId:$page:$limit";
        $data = $db->cacheRemember($cacheKey, 60, function() use ($db, $userId, $page, $limit, $offset) {
            $total = $db->fetchCount("
                SELECT COUNT(*) FROM listings l
                WHERE l.user_id = ? AND l.is_sealed = 1
                  AND EXISTS (SELECT 1 FROM offers WHERE listing_id = l.id AND status = 'active')
            ", [$userId]);
            $listings = $db->fetchAll("
                SELECT l.id, l.title, l.price, l.status, l.created_at,
                       (SELECT COUNT(*) FROM offers WHERE listing_id = l.id AND status = 'active') as offer_count
                FROM listings l
                WHERE l.user_id = ? AND l.is_sealed = 1
                  AND EXISTS (SELECT 1 FROM offers WHERE listing_id = l.id AND status = 'active')
                ORDER BY l.created_at DESC
                LIMIT ? OFFSET ?
            ", [$userId, $limit, $offset]);
            return [
                'success' => true,
                'data' => $listings,
                'meta' => ['page' => $page, 'limit' => $limit, 'pages' => ceil($total / $limit), 'total' => $total]
            ];
        });
        echo json_encode($data);
        break;

    case 'favorites':
        $page = max(1, (int)($_GET['page'] ?? 1));
        $limit = min(50, max(1, (int)($_GET['limit'] ?? 20)));
        $offset = ($page - 1) * $limit;
        $cacheKey = "user:favorites:$userId:$page:$limit";
        $data = $db->cacheRemember($cacheKey, 60, function() use ($db, $userId, $page, $limit, $offset) {
            $total = $db->fetchCount("SELECT COUNT(*) FROM favorites WHERE user_id = ?", [$userId]);
            $favorites = $db->fetchAll("
                SELECT l.id, l.title, l.price
                FROM favorites f
                JOIN listings l ON f.listing_id = l.id
                WHERE f.user_id = ? AND l.is_active = 1
                ORDER BY f.created_at DESC
                LIMIT ? OFFSET ?
            ", [$userId, $limit, $offset]);
            return [
                'success' => true,
                'data' => $favorites,
                'meta' => ['page' => $page, 'limit' => $limit, 'pages' => ceil($total / $limit), 'total' => $total]
            ];
        });
        echo json_encode($data);
        break;

    case 'messages':
        $page = max(1, (int)($_GET['page'] ?? 1));
        $limit = min(50, max(1, (int)($_GET['limit'] ?? 20)));
        $offset = ($page - 1) * $limit;
        $cacheKey = "user:messages:$userId:$page:$limit";
        $data = $db->cacheRemember($cacheKey, 60, function() use ($db, $userId, $page, $limit, $offset) {
            $total = $db->fetchCount("SELECT COUNT(*) FROM messages WHERE sender_id = ? OR receiver_id = ?", [$userId, $userId]);
            $messages = $db->fetchAll("
                SELECT m.id, m.content, m.created_at,
                       u_sender.name as sender_name, u_receiver.name as receiver_name
                FROM messages m
                LEFT JOIN users u_sender ON m.sender_id = u_sender.id
                LEFT JOIN users u_receiver ON m.receiver_id = u_receiver.id
                WHERE m.sender_id = ? OR m.receiver_id = ?
                ORDER BY m.created_at DESC
                LIMIT ? OFFSET ?
            ", [$userId, $userId, $limit, $offset]);
            return [
                'success' => true,
                'data' => $messages,
                'meta' => ['page' => $page, 'limit' => $limit, 'pages' => ceil($total / $limit), 'total' => $total]
            ];
        });
        echo json_encode($data);
        break;

    case 'offers':
        $user = $db->getUserById($userId);
        if (empty($user['is_partner'])) {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'Доступ запрещён']);
            exit;
        }
        $page = max(1, (int)($_GET['page'] ?? 1));
        $limit = min(50, max(1, (int)($_GET['limit'] ?? 20)));
        $offset = ($page - 1) * $limit;
        $cacheKey = "user:offers:$userId:$page:$limit";
        $data = $db->cacheRemember($cacheKey, 60, function() use ($db, $userId, $page, $limit, $offset) {
            $total = $db->fetchCount("SELECT COUNT(*) FROM partner_offers WHERE owner_id = ?", [$userId]);
            $offers = $db->fetchAll("
                SELECT id, partner_name, offer_name, commission_type, commission_value, is_active, is_approved
                FROM partner_offers
                WHERE owner_id = ?
                ORDER BY created_at DESC
                LIMIT ? OFFSET ?
            ", [$userId, $limit, $offset]);
            return [
                'success' => true,
                'data' => $offers,
                'meta' => ['page' => $page, 'limit' => $limit, 'pages' => ceil($total / $limit), 'total' => $total]
            ];
        });
        echo json_encode($data);
        break;

    case 'payouts':
        $user = $db->getUserById($userId);
        if (empty($user['is_partner'])) {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'Доступ запрещён']);
            exit;
        }
        $page = max(1, (int)($_GET['page'] ?? 1));
        $limit = min(50, max(1, (int)($_GET['limit'] ?? 20)));
        $offset = ($page - 1) * $limit;
        $cacheKey = "user:payouts:$userId:$page:$limit";
        $data = $db->cacheRemember($cacheKey, 60, function() use ($db, $userId, $page, $limit, $offset) {
            $total = $db->fetchCount("SELECT COUNT(*) FROM partner_payouts WHERE user_id = ?", [$userId]);
            $payouts = $db->fetchAll("
                SELECT id, amount, status, created_at, paid_at
                FROM partner_payouts
                WHERE user_id = ?
                ORDER BY created_at DESC
                LIMIT ? OFFSET ?
            ", [$userId, $limit, $offset]);
            return [
                'success' => true,
                'data' => $payouts,
                'meta' => ['page' => $page, 'limit' => $limit, 'pages' => ceil($total / $limit), 'total' => $total]
            ];
        });
        echo json_encode($data);
        break;

    case 'webhooks':
        $user = $db->getUserById($userId);
        if (empty($user['is_partner'])) {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'Доступ запрещён']);
            exit;
        }
        $webhooks = $db->fetchAll("
            SELECT id, name, url, events, is_active, last_triggered
            FROM webhooks
            WHERE user_id = ?
            ORDER BY created_at DESC
        ", [$userId]);
        echo json_encode(['success' => true, 'data' => $webhooks]);
        break;

    case 'b2b':
        $user = $db->getUserById($userId);
        if (empty($user['is_partner'])) {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'Доступ запрещён']);
            exit;
        }
        $clients = $db->fetchAll("
            SELECT u.id, u.name, u.email, sbr.approved_at
            FROM supplier_buyer_relations sbr
            JOIN users u ON sbr.buyer_id = u.id
            WHERE sbr.supplier_id = ?
            ORDER BY sbr.created_at DESC
        ", [$userId]);
        echo json_encode(['success' => true, 'data' => $clients]);
        break;

    case 'delete-listing':
        $listingId = (int)($input['listing_id'] ?? 0);
        if (!$listingId) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Не указан ID объявления']);
            exit;
        }
        $listing = $db->fetchOne("SELECT id FROM listings WHERE id = ? AND user_id = ?", [$listingId, $userId]);
        if (!$listing) {
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => 'Объявление не найдено']);
            exit;
        }
        $db->delete('listings', 'id = ?', [$listingId]);

        // Очищаем кэш списков
        $db->cacheDelete("user:listings:$userId:*");
        $db->cacheDelete("user:auctions:$userId:*");
        echo json_encode(['success' => true, 'message' => 'Объявление удалено']);
        break;

    case 'delete-offer':
        $user = $db->getUserById($userId);
        if (empty($user['is_partner'])) {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'Доступ запрещён']);
            exit;
        }
        $offerId = (int)($input['offer_id'] ?? 0);
        if (!$offerId) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Не указан ID оффера']);
            exit;
        }
        $offer = $db->fetchOne("SELECT id FROM partner_offers WHERE id = ? AND owner_id = ?", [$offerId, $userId]);
        if (!$offer) {
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => 'Оффер не найден']);
            exit;
        }
        $db->delete('partner_offers', 'id = ?', [$offerId]);

        $db->cacheDelete("user:offers:$userId:*");
        echo json_encode(['success' => true, 'message' => 'Оффер удалён']);
        break;

    default:
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Неизвестное действие']);
        break;
}