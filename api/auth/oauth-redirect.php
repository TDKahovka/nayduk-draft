<?php
/* ============================================
   НАЙДУК — Редирект на OAuth-провайдера
   Версия 1.0 (март 2026)
   - Принимает provider, генерирует state, перенаправляет
   - Поддержка: vk, yandex, google, mailru, telegram, rambler, max
   ============================================ */

session_start();
require_once __DIR__ . '/../../includes/functions.php';

$provider = $_GET['provider'] ?? '';
$allowed = ['vk', 'yandex', 'google', 'mailru', 'telegram', 'rambler', 'max'];
if (!in_array($provider, $allowed)) {
    header('Location: /auth/login?error=invalid_provider');
    exit;
}

// Для MAX – показываем страницу с предложением скачать приложение
if ($provider === 'max') {
    ?>
    <!DOCTYPE html>
    <html>
    <head><meta charset="UTF-8"><title>Вход через MAX</title></head>
    <body style="display: flex; align-items: center; justify-content: center; min-height: 100vh; font-family: sans-serif;">
        <div style="text-align: center;">
            <h2>🔜 Вход через MAX</h2>
            <p>Функция в разработке. Пожалуйста, используйте другие способы входа.</p>
            <a href="/auth/login">Вернуться</a>
        </div>
    </body>
    </html>
    <?php
    exit;
}

// Генерация state (CSRF)
$state = bin2hex(random_bytes(32));
$_SESSION['oauth_state'] = $state;
$_SESSION['oauth_provider'] = $provider;
if (isset($_GET['redirect_uri'])) {
    $_SESSION['oauth_redirect_after'] = $_GET['redirect_uri'];
}

// Конфигурация провайдеров
$config = [
    'vk' => [
        'auth_url' => 'https://oauth.vk.com/authorize',
        'client_id' => getenv('VK_CLIENT_ID') ?: '',
        'scope' => 'email',
        'response_type' => 'code',
    ],
    'yandex' => [
        'auth_url' => 'https://oauth.yandex.ru/authorize',
        'client_id' => getenv('YANDEX_CLIENT_ID') ?: '',
        'scope' => 'login:email',
        'response_type' => 'code',
    ],
    'google' => [
        'auth_url' => 'https://accounts.google.com/o/oauth2/v2/auth',
        'client_id' => getenv('GOOGLE_CLIENT_ID') ?: '',
        'scope' => 'email profile',
        'response_type' => 'code',
        'access_type' => 'online',
    ],
    'mailru' => [
        'auth_url' => 'https://connect.mail.ru/oauth/authorize',
        'client_id' => getenv('MAILRU_CLIENT_ID') ?: '',
        'scope' => 'userinfo',
        'response_type' => 'code',
    ],
    'telegram' => [
        'auth_url' => 'https://oauth.telegram.org/auth',
        'bot_id' => getenv('TELEGRAM_BOT_ID') ?: '',
        'scope' => 'user',
        'response_type' => 'code',
    ],
    'rambler' => [
        'auth_url' => 'https://oauth.rambler.ru/authorize',
        'client_id' => getenv('RAMBLER_CLIENT_ID') ?: '',
        'scope' => 'userinfo',
        'response_type' => 'code',
    ],
];

// Для Telegram используем специальный URL
if ($provider === 'telegram') {
    $redirectUri = urlencode('https://' . $_SERVER['HTTP_HOST'] . '/api/auth/oauth-callback.php?provider=telegram');
    $url = "{$config['telegram']['auth_url']}?bot_id={$config['telegram']['bot_id']}&origin=" . urlencode('https://' . $_SERVER['HTTP_HOST']) . "&redirect_uri=$redirectUri&state=$state";
    header("Location: $url");
    exit;
}

$redirectUri = urlencode('https://' . $_SERVER['HTTP_HOST'] . '/api/auth/oauth-callback.php?provider=' . $provider);
$params = [
    'client_id' => $config[$provider]['client_id'],
    'redirect_uri' => $redirectUri,
    'response_type' => $config[$provider]['response_type'],
    'scope' => $config[$provider]['scope'],
    'state' => $state,
];
if ($provider === 'google') {
    $params['access_type'] = 'online';
}
$url = $config[$provider]['auth_url'] . '?' . http_build_query($params);
header("Location: $url");
exit;