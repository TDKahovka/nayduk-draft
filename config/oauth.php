<?php
return [
    'vk' => [
        'client_id' => 'YOUR_VK_ID',
        'client_secret' => 'YOUR_VK_SECRET',
        'redirect_uri' => 'https://yourdomain.com/api/auth/oauth-callback.php?provider=vk',
        'auth_url' => 'https://oauth.vk.com/authorize',
        'token_url' => 'https://oauth.vk.com/access_token',
        'userinfo_url' => 'https://api.vk.com/method/users.get?fields=email,first_name,last_name&v=5.131',
        'scope' => 'email'
    ],
    'yandex' => [
        'client_id' => 'YOUR_YANDEX_ID',
        'client_secret' => 'YOUR_YANDEX_SECRET',
        'redirect_uri' => 'https://yourdomain.com/api/auth/oauth-callback.php?provider=yandex',
        'auth_url' => 'https://oauth.yandex.ru/authorize',
        'token_url' => 'https://oauth.yandex.ru/token',
        'userinfo_url' => 'https://login.yandex.ru/info',
        'scope' => 'login:email login:info'
    ],
    'google' => [
        'client_id' => 'YOUR_GOOGLE_ID',
        'client_secret' => 'YOUR_GOOGLE_SECRET',
        'redirect_uri' => 'https://yourdomain.com/api/auth/oauth-callback.php?provider=google',
        'auth_url' => 'https://accounts.google.com/o/oauth2/v2/auth',
        'token_url' => 'https://oauth2.googleapis.com/token',
        'userinfo_url' => 'https://www.googleapis.com/oauth2/v3/userinfo',
        'scope' => 'email profile'
    ],
    'mailru' => [
        'client_id' => 'YOUR_MAILRU_ID',
        'client_secret' => 'YOUR_MAILRU_SECRET',
        'redirect_uri' => 'https://yourdomain.com/api/auth/oauth-callback.php?provider=mailru',
        'auth_url' => 'https://connect.mail.ru/oauth/authorize',
        'token_url' => 'https://connect.mail.ru/oauth/token',
        'userinfo_url' => 'https://www.googleapis.com/oauth2/v3/userinfo', // Заглушка
        'scope' => 'userinfo'
    ],
    'telegram' => [
        'client_id' => 'YOUR_TELEGRAM_BOT_TOKEN',
        'client_secret' => null,
        'redirect_uri' => 'https://yourdomain.com/api/auth/oauth-callback.php?provider=telegram',
        'auth_url' => 'https://oauth.telegram.org/auth',
        'token_url' => '',
        'userinfo_url' => '',
        'scope' => ''
    ],
    'rambler' => [
        'client_id' => 'YOUR_RAMBLER_ID',
        'client_secret' => 'YOUR_RAMBLER_SECRET',
        'redirect_uri' => 'https://yourdomain.com/api/auth/oauth-callback.php?provider=rambler',
        'auth_url' => 'https://oauth.rambler.ru/authorize',
        'token_url' => 'https://oauth.rambler.ru/token',
        'userinfo_url' => 'https://api.rambler.ru/users/info',
        'scope' => 'user_info'
    ],
    'max' => [
        'client_id' => 'YOUR_MAX_ID',
        'client_secret' => 'YOUR_MAX_SECRET',
        'redirect_uri' => 'https://yourdomain.com/api/auth/oauth-callback.php?provider=max',
        'auth_url' => 'https://auth.max.com/oauth/authorize',
        'token_url' => 'https://auth.max.com/oauth/token',
        'userinfo_url' => 'https://api.max.com/user',
        'scope' => 'email profile'
    ]
];