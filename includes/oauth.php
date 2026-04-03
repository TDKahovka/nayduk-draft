<?php
/* ============================================
   НАЙДУК — OAuth сервис (поддержка 7 провайдеров)
   ============================================ */

class OAuthService {
    private $provider;
    private $config;

    public function __construct($provider) {
        $this->provider = $provider;
        $this->config = require __DIR__ . '/../config/oauth.php';
        if (!isset($this->config[$provider])) {
            throw new Exception("OAuth provider $provider not configured");
        }
    }

    public function getAuthUrl() {
        $cfg = $this->config[$this->provider];
        $params = [
            'client_id' => $cfg['client_id'],
            'redirect_uri' => $cfg['redirect_uri'],
            'response_type' => 'code',
            'scope' => $cfg['scope']
        ];
        if (!empty($cfg['state'])) {
            $_SESSION['oauth_state'] = bin2hex(random_bytes(16));
            $params['state'] = $_SESSION['oauth_state'];
        }
        return $cfg['auth_url'] . '?' . http_build_query($params);
    }

    public function handleCallback($get) {
        // Проверка state
        if (!empty($_SESSION['oauth_state'])) {
            if (empty($get['state']) || $get['state'] !== $_SESSION['oauth_state']) {
                throw new Exception('Invalid state');
            }
            unset($_SESSION['oauth_state']);
        }
        if (empty($get['code'])) {
            throw new Exception('No authorization code');
        }
        $cfg = $this->config[$this->provider];
        // Обмен кода на токен
        $token = $this->exchangeCode($get['code'], $cfg);
        if (!$token) {
            throw new Exception('Failed to get access token');
        }
        // Получение данных пользователя
        $userData = $this->getUserData($token, $cfg);
        if (empty($userData['email'])) {
            throw new Exception('Email not provided');
        }
        return $userData;
    }

    private function exchangeCode($code, $cfg) {
        $postData = [
            'client_id' => $cfg['client_id'],
            'client_secret' => $cfg['client_secret'],
            'code' => $code,
            'redirect_uri' => $cfg['redirect_uri'],
            'grant_type' => 'authorization_code'
        ];
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $cfg['token_url']);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($postData));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        $response = curl_exec($ch);
        curl_close($ch);
        $data = json_decode($response, true);
        return $data['access_token'] ?? null;
    }

    private function getUserData($token, $cfg) {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $cfg['userinfo_url'] . '?access_token=' . $token);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        $response = curl_exec($ch);
        curl_close($ch);
        $data = json_decode($response, true);
        // Нормализуем под единый формат
        switch ($this->provider) {
            case 'google':
                return [
                    'id' => $data['sub'],
                    'email' => $data['email'],
                    'name' => $data['name'] ?? $data['given_name']
                ];
            case 'vk':
                $user = $data['response'][0] ?? [];
                return [
                    'id' => $user['id'],
                    'email' => $user['email'] ?? null,
                    'name' => $user['first_name'] . ' ' . ($user['last_name'] ?? '')
                ];
            case 'yandex':
                return [
                    'id' => $data['id'],
                    'email' => $data['default_email'],
                    'name' => $data['real_name'] ?? $data['display_name']
                ];
            default:
                // Аналогично для остальных (заглушка)
                return [
                    'id' => $data['id'],
                    'email' => $data['email'],
                    'name' => $data['name']
                ];
        }
    }
}