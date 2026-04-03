<?php
/* ============================================
   НАЙДУК — Единый OAuth-сервис
   Версия 1.0 (март 2026)
   - Поддержка Яндекс, VK, Mail.ru, Google
   - Получение данных пользователя
   - Сохранение в БД
   ============================================ */

class OAuthService
{
    private $providers = [];

    public function __construct()
    {
        $this->providers = [
            'yandex' => [
                'client_id' => $_ENV['YANDEX_CLIENT_ID'] ?? '',
                'client_secret' => $_ENV['YANDEX_CLIENT_SECRET'] ?? '',
                'auth_url' => 'https://oauth.yandex.ru/authorize',
                'token_url' => 'https://oauth.yandex.ru/token',
                'userinfo_url' => 'https://login.yandex.ru/info',
                'scope' => 'login:email login:info',
                'response_type' => 'code',
                'fields' => 'id,login,email,name,default_avatar'
            ],
            'vk' => [
                'client_id' => $_ENV['VK_CLIENT_ID'] ?? '',
                'client_secret' => $_ENV['VK_CLIENT_SECRET'] ?? '',
                'auth_url' => 'https://oauth.vk.com/authorize',
                'token_url' => 'https://oauth.vk.com/access_token',
                'userinfo_url' => 'https://api.vk.com/method/users.get',
                'scope' => 'email',
                'response_type' => 'code',
                'v' => '5.131'
            ],
            'mailru' => [
                'client_id' => $_ENV['MAILRU_CLIENT_ID'] ?? '',
                'client_secret' => $_ENV['MAILRU_CLIENT_SECRET'] ?? '',
                'auth_url' => 'https://oauth.mail.ru/login',
                'token_url' => 'https://oauth.mail.ru/token',
                'userinfo_url' => 'https://oauth.mail.ru/userinfo',
                'scope' => 'userinfo',
                'response_type' => 'code'
            ],
            'google' => [
                'client_id' => $_ENV['GOOGLE_CLIENT_ID'] ?? '',
                'client_secret' => $_ENV['GOOGLE_CLIENT_SECRET'] ?? '',
                'auth_url' => 'https://accounts.google.com/o/oauth2/v2/auth',
                'token_url' => 'https://oauth2.googleapis.com/token',
                'userinfo_url' => 'https://www.googleapis.com/oauth2/v3/userinfo',
                'scope' => 'email profile',
                'response_type' => 'code'
            ]
        ];
    }

    public function getAuthUrl($provider)
    {
        if (!isset($this->providers[$provider])) {
            throw new Exception("Unknown provider: $provider");
        }
        $p = $this->providers[$provider];
        $params = [
            'client_id' => $p['client_id'],
            'redirect_uri' => $this->getRedirectUri($provider),
            'response_type' => $p['response_type'],
            'scope' => $p['scope']
        ];
        if ($provider === 'vk') {
            $params['v'] = $p['v'];
        }
        return $p['auth_url'] . '?' . http_build_query($params);
    }

    private function getRedirectUri($provider)
    {
        return "https://{$_SERVER['HTTP_HOST']}/api/auth/oauth-callback.php?provider={$provider}";
    }

    public function getAccessToken($provider, $code)
    {
        if (!isset($this->providers[$provider])) {
            throw new Exception("Unknown provider: $provider");
        }
        $p = $this->providers[$provider];
        $params = [
            'client_id' => $p['client_id'],
            'client_secret' => $p['client_secret'],
            'code' => $code,
            'redirect_uri' => $this->getRedirectUri($provider),
            'grant_type' => 'authorization_code'
        ];
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $p['token_url'],
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query($params),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_TIMEOUT => 10
        ]);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($httpCode !== 200) {
            throw new Exception("OAuth token error: HTTP $httpCode");
        }
        $data = json_decode($response, true);
        if (!isset($data['access_token'])) {
            throw new Exception("Invalid token response");
        }
        return $data;
    }

    public function getUserInfo($provider, $accessToken)
    {
        if (!isset($this->providers[$provider])) {
            throw new Exception("Unknown provider: $provider");
        }
        $p = $this->providers[$provider];
        $url = $p['userinfo_url'];
        $headers = ['Authorization: Bearer ' . $accessToken];
        if ($provider === 'vk') {
            $url .= "?access_token={$accessToken}&v={$p['v']}&fields=email,photo_50";
            $headers = [];
        }
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => 10
        ]);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($httpCode !== 200) {
            throw new Exception("Userinfo error: HTTP $httpCode");
        }
        $data = json_decode($response, true);
        return $this->normalizeUserInfo($provider, $data);
    }

    private function normalizeUserInfo($provider, $data)
    {
        switch ($provider) {
            case 'yandex':
                return [
                    'provider_user_id' => $data['id'],
                    'email' => $data['default_email'] ?? $data['email'],
                    'name' => $data['real_name'] ?? $data['display_name'] ?? '',
                    'avatar' => $data['default_avatar'] ?? ''
                ];
            case 'vk':
                $user = $data['response'][0] ?? [];
                return [
                    'provider_user_id' => $user['id'],
                    'email' => $user['email'] ?? '',
                    'name' => ($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? ''),
                    'avatar' => $user['photo_50'] ?? ''
                ];
            case 'mailru':
                return [
                    'provider_user_id' => $data['id'],
                    'email' => $data['email'],
                    'name' => $data['name'],
                    'avatar' => $data['image'] ?? ''
                ];
            case 'google':
                return [
                    'provider_user_id' => $data['sub'],
                    'email' => $data['email'],
                    'name' => $data['name'],
                    'avatar' => $data['picture'] ?? ''
                ];
            default:
                throw new Exception("Unknown provider: $provider");
        }
    }
}