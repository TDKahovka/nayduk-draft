<?php
/* ============================================
   НАЙДУК — OAuth-колбэк (полная версия)
   Версия 2.0 (март 2026)
   - Поддержка VK, Яндекс, Google, Mail.ru, Telegram, Рамблер
   - Надёжная проверка Telegram через HMAC-SHA256
   - Детальная обработка ошибок с передачей на страницу входа
   - Логирование, автосоздание таблицы oauth_accounts
   ============================================ */

session_start();
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../services/Database.php';
require_once __DIR__ . '/../../services/NotificationService.php';

$provider = $_GET['provider'] ?? '';
$allowed = ['vk', 'yandex', 'google', 'mailru', 'telegram', 'rambler'];
if (!in_array($provider, $allowed)) {
    header('Location: /auth/login?error=unsupported_provider');
    exit;
}

// ==================== ПРОВЕРКА STATE (CSRF) ====================
$state = $_GET['state'] ?? '';
if (!isset($_SESSION['oauth_state']) || $state !== $_SESSION['oauth_state']) {
    error_log("OAuth state mismatch for provider $provider");
    header('Location: /auth/login?error=invalid_state');
    exit;
}
unset($_SESSION['oauth_state']);

// ==================== ОБРАБОТКА ОШИБОК ПРОВАЙДЕРА ====================
$error = $_GET['error'] ?? '';
$errorDesc = $_GET['error_description'] ?? '';
if ($error) {
    error_log("OAuth error from $provider: $error - $errorDesc");
    header('Location: /auth/login?error=provider_error&error_description=' . urlencode($errorDesc));
    exit;
}

$code = $_GET['code'] ?? '';
if (!$code && $provider !== 'telegram') {
    header('Location: /auth/login?error=no_code');
    exit;
}

$db = Database::getInstance();
$pdo = $db->getPdo();

// ==================== АВТОСОЗДАНИЕ ТАБЛИЦЫ OAuth ====================
$pdo->exec("
    CREATE TABLE IF NOT EXISTS oauth_accounts (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        user_id BIGINT UNSIGNED NOT NULL,
        provider VARCHAR(50) NOT NULL,
        provider_user_id VARCHAR(255) NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
        UNIQUE KEY unique_oauth (provider, provider_user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
");

// ==================== КОНФИГУРАЦИЯ ПРОВАЙДЕРОВ ====================
$redirectUriBase = 'https://' . $_SERVER['HTTP_HOST'] . '/api/auth/oauth-callback.php?provider=';

$config = [
    'vk' => [
        'token_url' => 'https://oauth.vk.com/access_token',
        'user_url' => 'https://api.vk.com/method/users.get',
        'client_id' => getenv('VK_CLIENT_ID') ?: '',
        'client_secret' => getenv('VK_CLIENT_SECRET') ?: '',
        'redirect_uri' => $redirectUriBase . 'vk',
        'map_user' => function($data) {
            return [
                'email' => $data['email'] ?? '',
                'name' => trim(($data['first_name'] ?? '') . ' ' . ($data['last_name'] ?? '')),
                'avatar' => $data['photo_200'] ?? null,
                'provider_id' => $data['user_id']
            ];
        },
    ],
    'yandex' => [
        'token_url' => 'https://oauth.yandex.ru/token',
        'user_url' => 'https://login.yandex.ru/info',
        'client_id' => getenv('YANDEX_CLIENT_ID') ?: '',
        'client_secret' => getenv('YANDEX_CLIENT_SECRET') ?: '',
        'redirect_uri' => $redirectUriBase . 'yandex',
        'map_user' => function($data) {
            return [
                'email' => $data['default_email'] ?? '',
                'name' => $data['real_name'] ?? $data['display_name'] ?? '',
                'avatar' => $data['avatar_url'] ?? null,
                'provider_id' => $data['id']
            ];
        },
    ],
    'google' => [
        'token_url' => 'https://oauth2.googleapis.com/token',
        'user_url' => 'https://www.googleapis.com/oauth2/v2/userinfo',
        'client_id' => getenv('GOOGLE_CLIENT_ID') ?: '',
        'client_secret' => getenv('GOOGLE_CLIENT_SECRET') ?: '',
        'redirect_uri' => $redirectUriBase . 'google',
        'map_user' => function($data) {
            return [
                'email' => $data['email'] ?? '',
                'name' => $data['name'] ?? '',
                'avatar' => $data['picture'] ?? null,
                'provider_id' => $data['id']
            ];
        },
    ],
    'mailru' => [
        'token_url' => 'https://connect.mail.ru/oauth/token',
        'user_url' => 'https://www.appsmail.ru/platform/api',
        'client_id' => getenv('MAILRU_CLIENT_ID') ?: '',
        'client_secret' => getenv('MAILRU_CLIENT_SECRET') ?: '',
        'redirect_uri' => $redirectUriBase . 'mailru',
        'map_user' => function($data) {
            return [
                'email' => $data['email'] ?? '',
                'name' => trim(($data['first_name'] ?? '') . ' ' . ($data['last_name'] ?? '')),
                'avatar' => $data['pic_190'] ?? null,
                'provider_id' => $data['uid']
            ];
        },
    ],
    'rambler' => [
        'token_url' => 'https://oauth.rambler.ru/token',
        'user_url' => 'https://api.rambler.ru/oauth2/userinfo',
        'client_id' => getenv('RAMBLER_CLIENT_ID') ?: '',
        'client_secret' => getenv('RAMBLER_CLIENT_SECRET') ?: '',
        'redirect_uri' => $redirectUriBase . 'rambler',
        'map_user' => function($data) {
            return [
                'email' => $data['email'] ?? '',
                'name' => $data['display_name'] ?? $data['email'],
                'avatar' => null,
                'provider_id' => $data['id']
            ];
        },
    ],
    'telegram' => [
        'bot_token' => getenv('TELEGRAM_BOT_TOKEN') ?: '',
    ],
];

// ==================== ОБРАБОТКА TELEGRAM ====================
if ($provider === 'telegram') {
    $id = (int)($_GET['id'] ?? 0);
    $firstName = $_GET['first_name'] ?? '';
    $lastName = $_GET['last_name'] ?? '';
    $username = $_GET['username'] ?? '';
    $photoUrl = $_GET['photo_url'] ?? '';
    $authDate = (int)($_GET['auth_date'] ?? 0);
    $hash = $_GET['hash'] ?? '';

    if (!$id || !$hash || !$authDate) {
        header('Location: /auth/login?error=telegram_invalid_data');
        exit;
    }

    // Проверка подписи по RFC
    $checkString = implode("\n", [
        "auth_date=$authDate",
        "first_name=$firstName",
        "id=$id",
        "last_name=$lastName",
        "photo_url=$photoUrl",
        "username=$username"
    ]);
    $secretKey = hash('sha256', $config['telegram']['bot_token'], true);
    $checkHash = hash_hmac('sha256', $checkString, $secretKey, false);

    if (!hash_equals($hash, $checkHash)) {
        error_log("Telegram auth hash mismatch");
        header('Location: /auth/login?error=telegram_hash_invalid');
        exit;
    }

    // Telegram не возвращает email, создаём псевдоним
    $email = $username . '@telegram.user';
    $name = trim($firstName . ' ' . $lastName);
    $avatar = $photoUrl;
    $providerId = (string)$id;

    // Поиск существующей связи
    $stmt = $pdo->prepare("SELECT user_id FROM oauth_accounts WHERE provider = ? AND provider_user_id = ?");
    $stmt->execute(['telegram', $providerId]);
    $row = $stmt->fetch();
    if ($row) {
        $userId = $row['user_id'];
        $pdo->prepare("UPDATE users SET name = ?, avatar_url = ? WHERE id = ?")->execute([$name, $avatar, $userId]);
    } else {
        $user = $db->getUserByEmail($email);
        if ($user) {
            $userId = $user['id'];
        } else {
            $userId = $db->createUser([
                'email' => $email,
                'name' => $name,
                'avatar_url' => $avatar,
                'trust_score' => 50,
                'email_verified' => 1,
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s')
            ]);
        }
        $pdo->prepare("INSERT INTO oauth_accounts (user_id, provider, provider_user_id) VALUES (?, ?, ?)")
            ->execute([$userId, 'telegram', $providerId]);
    }

    $_SESSION['user_id'] = $userId;
    $_SESSION['user_name'] = $name;
    $_SESSION['user_email'] = $email;
    $redirect = $_SESSION['oauth_redirect_after'] ?? '/profile';
    unset($_SESSION['oauth_redirect_after']);
    header("Location: $redirect");
    exit;
}

// ==================== ОБРАБОТКА ОСТАЛЬНЫХ ПРОВАЙДЕРОВ ====================
$cfg = $config[$provider];
$tokenUrl = $cfg['token_url'];
$clientId = $cfg['client_id'];
$clientSecret = $cfg['client_secret'];
$redirectUri = $cfg['redirect_uri'];

// Обмен кода на токен
$postData = [
    'client_id' => $clientId,
    'client_secret' => $clientSecret,
    'code' => $code,
    'redirect_uri' => $redirectUri,
    'grant_type' => 'authorization_code',
];
$ch = curl_init($tokenUrl);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($postData));
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 10);
$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($httpCode !== 200) {
    error_log("OAuth token exchange failed for $provider: $response");
    header('Location: /auth/login?error=oauth_token_failed');
    exit;
}

$tokenData = json_decode($response, true);
if (empty($tokenData['access_token'])) {
    error_log("OAuth token missing in response for $provider");
    header('Location: /auth/login?error=oauth_token_missing');
    exit;
}
$accessToken = $tokenData['access_token'];

// Получение данных пользователя
$userUrl = $cfg['user_url'];
if ($provider === 'vk') {
    $userUrl .= "?access_token=$accessToken&v=5.131&fields=photo_200,email";
} elseif ($provider === 'mailru') {
    $userUrl .= "?method=users.getInfo&access_token=$accessToken&app_id={$cfg['client_id']}&format=json";
} elseif ($provider === 'yandex') {
    $userUrl .= "?oauth_token=$accessToken";
} elseif ($provider === 'google' || $provider === 'rambler') {
    $userUrl .= "?access_token=$accessToken";
}

$ch = curl_init($userUrl);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 10);
$userResponse = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($httpCode !== 200) {
    error_log("OAuth userinfo failed for $provider: $userResponse");
    header('Location: /auth/login?error=oauth_userinfo_failed');
    exit;
}

$userData = json_decode($userResponse, true);
if ($provider === 'vk' && isset($userData['response'][0])) {
    $userData = $userData['response'][0];
} elseif ($provider === 'mailru' && isset($userData[0])) {
    $userData = $userData[0];
}

$mapped = $cfg['map_user']($userData);
$email = $mapped['email'];
$name = $mapped['name'];
$avatar = $mapped['avatar'];
$providerId = (string)$mapped['provider_id'];

if (!$email) {
    // Если провайдер не вернул email, создаём псевдоним
    $email = $provider . '_' . $providerId . '@social.user';
}

// Поиск связи
$stmt = $pdo->prepare("SELECT user_id FROM oauth_accounts WHERE provider = ? AND provider_user_id = ?");
$stmt->execute([$provider, $providerId]);
$row = $stmt->fetch();
if ($row) {
    $userId = $row['user_id'];
    $pdo->prepare("UPDATE users SET name = ?, avatar_url = ? WHERE id = ?")->execute([$name, $avatar, $userId]);
} else {
    $user = $db->getUserByEmail($email);
    if ($user) {
        $userId = $user['id'];
    } else {
        $userId = $db->createUser([
            'email' => $email,
            'name' => $name,
            'avatar_url' => $avatar,
            'trust_score' => 50,
            'email_verified' => 1,
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s')
        ]);
    }
    $pdo->prepare("INSERT INTO oauth_accounts (user_id, provider, provider_user_id) VALUES (?, ?, ?)")
        ->execute([$userId, $provider, $providerId]);
}

// Успешный вход
$_SESSION['user_id'] = $userId;
$_SESSION['user_name'] = $name;
$_SESSION['user_email'] = $email;

$redirect = $_SESSION['oauth_redirect_after'] ?? '/profile';
unset($_SESSION['oauth_redirect_after']);
header("Location: $redirect");
exit;