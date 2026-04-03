<?php
/* ============================================
   НАЙДУК — Основные функции (v5.1)
   - Универсальное кэширование (Redis + файлы)
   - Rate limiting с Redis fallback
   - Стандартизированные ответы API
   - Глобальный обработчик ошибок (лог + БД)
   - Гео-функции (безопасные, с проверкой)
   ============================================ */

// ===== ГЛОБАЛЬНЫЙ ОБРАБОТЧИК ОШИБОК =====
set_error_handler(function($errno, $errstr, $errfile, $errline) {
    $logEntry = date('Y-m-d H:i:s') . " ERROR [$errno] $errstr in $errfile on line $errline\n";
    @file_put_contents(__DIR__ . '/../storage/logs/errors.log', $logEntry, FILE_APPEND);

    try {
        $db = Database::getInstance();
        if ($db && method_exists($db, 'insert')) {
            $userId = null;
            if (session_status() === PHP_SESSION_ACTIVE && isset($_SESSION['user_id'])) {
                $userId = (int)$_SESSION['user_id'];
            }
            $db->insert('security_logs', [
                'user_id' => $userId,
                'ip_address' => getUserIP(),
                'event_type' => 'error',
                'description' => $errstr . " in $errfile:$errline",
                'severity' => 'high',
                'created_at' => date('Y-m-d H:i:s')
            ]);
        }
    } catch (Exception $e) {
        // игнорируем ошибки БД
    }

    return false;
});

// Подключаем автозагрузку Composer (если есть)
if (file_exists(__DIR__ . '/../vendor/autoload.php')) {
    require_once __DIR__ . '/../vendor/autoload.php';
}

// ==================== CSRF-ЗАЩИТА ====================

/**
 * Генерация CSRF-токена и сохранение в сессию
 * @return string
 */
function csrf_token() {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}
function generateCsrfToken() { return csrf_token(); } // алиас

/**
 * Генерация HTML-поля с CSRF-токеном
 * @return string
 */
function csrf_field() {
    return '<input type="hidden" name="csrf_token" value="' . csrf_token() . '">';
}

/**
 * Проверка CSRF-токена (вызывается в middleware)
 * @param string $token
 * @return bool
 */
function verify_csrf_token($token) {
    return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}
function verifyCsrfToken($token) { return verify_csrf_token($token); } // алиас

// ==================== КЭШИРОВАНИЕ (Redis + файлы) ====================

/**
 * Получить значение из кэша
 * @param string $key
 * @param int $ttl TTL в секундах (только для файлового кэша, Redis использует свой TTL)
 * @return mixed|null
 */
function cacheGet($key, $ttl = 3600) {
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
        $val = $redis->get($key);
        return $val !== false ? json_decode($val, true) : null;
    }
    // Файловый fallback
    $file = __DIR__ . '/../storage/cache/' . md5($key) . '.json';
    if (!file_exists($file)) return null;
    $data = json_decode(file_get_contents($file), true);
    if (!$data || !isset($data['expires']) || $data['expires'] < time()) {
        @unlink($file);
        return null;
    }
    return $data['value'];
}

/**
 * Сохранить значение в кэш
 * @param string $key
 * @param mixed $value
 * @param int $ttl TTL в секундах
 */
function cacheSet($key, $value, $ttl = 3600) {
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
        $redis->setex($key, $ttl, json_encode($value));
        return;
    }
    $file = __DIR__ . '/../storage/cache/' . md5($key) . '.json';
    $dir = dirname($file);
    if (!is_dir($dir)) mkdir($dir, 0755, true);
    $data = [
        'expires' => time() + $ttl,
        'value' => $value
    ];
    file_put_contents($file, json_encode($data), LOCK_EX);
}

/**
 * Удалить ключ из кэша
 * @param string $key
 */
function cacheDelete($key) {
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
        $redis->del($key);
    }
    $file = __DIR__ . '/../storage/cache/' . md5($key) . '.json';
    @unlink($file);
}

// ==================== RATE LIMITING (Redis + файлы) ====================

/**
 * Проверка лимита запросов (поддерживает Redis и файловый fallback)
 * @param string $key уникальный идентификатор (например, 'profile_' . $userId)
 * @param int $limit максимальное количество за период
 * @param int $window период в секундах (по умолчанию 3600 = 1 час)
 * @return bool true – лимит не превышен, false – превышен
 */
function checkRateLimit($key, $limit = 10, $window = 3600) {
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
    $file = __DIR__ . '/../storage/rate/' . md5($key) . '.txt';
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

// ==================== СТАНДАРТИЗИРОВАННЫЕ ОТВЕТЫ API ====================

/**
 * Успешный JSON-ответ
 * @param mixed $data
 * @param string $message
 * @param int $code HTTP код
 */
function json_success($data = null, $message = 'Success', $code = 200) {
    http_response_code($code);
    echo json_encode([
        'success' => true,
        'message' => $message,
        'data' => $data
    ]);
    exit;
}

/**
 * Ошибочный JSON-ответ
 * @param string $error
 * @param string $errorCode
 * @param int $code HTTP код
 */
function json_error($error, $errorCode = 'ERROR', $code = 400) {
    http_response_code($code);
    echo json_encode([
        'success' => false,
        'error' => $error,
        'error_code' => $errorCode
    ]);
    exit;
}

// ==================== ВСПОМОГАТЕЛЬНЫЕ УТИЛИТЫ ====================

/**
 * Получение IP-адреса пользователя (с учётом Cloudflare)
 * @return string
 */
function get_user_ip() {
    return $_SERVER['HTTP_CF_CONNECTING_IP'] ?? $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
}
function getUserIP() { return get_user_ip(); } // алиас

/**
 * Получить текущего пользователя (если авторизован)
 * @return array|null
 */
function get_current_user() {
    if (!isset($_SESSION['user_id'])) return null;
    static $user = null;
    if ($user === null) {
        $db = Database::getInstance();
        $user = $db->getUserById($_SESSION['user_id']);
    }
    return $user;
}

/**
 * Проверить, является ли пользователь администратором
 * @return bool
 */
function is_admin() {
    $user = get_current_user();
    return $user && isset($user['role']) && in_array($user['role'], ['admin', 'superadmin']);
}

/**
 * Проверить, является ли пользователь партнёром
 * @return bool
 */
function is_partner() {
    $user = get_current_user();
    return $user && !empty($user['is_partner']);
}

// ==================== FLASH-СООБЩЕНИЯ ====================

/**
 * Сохранить flash-сообщение в сессию
 * @param string $type success|error|warning|info
 * @param string $message
 */
function set_flash($type, $message) {
    $_SESSION['flash'][$type] = $message;
}

/**
 * Получить и очистить flash-сообщения
 * @return array
 */
function get_flash() {
    $flash = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $flash;
}

/**
 * Вывести flash-сообщения в виде JavaScript (Toastify)
 */
function render_flash_toast() {
    $flash = get_flash();
    if (empty($flash)) return;
    echo "<script>";
    foreach ($flash as $type => $message) {
        $color = match ($type) {
            'success' => 'linear-gradient(135deg, #34C759, #2C9B4E)',
            'error' => 'linear-gradient(135deg, #FF3B30, #C72A2A)',
            'warning' => 'linear-gradient(135deg, #FF9500, #E68600)',
            default => 'linear-gradient(135deg, #5A67D8, #4C51BF)',
        };
        echo "Toastify({ text: `" . addslashes($message) . "`, duration: 3000, gravity: 'top', position: 'right', backgroundColor: '$color' }).showToast();";
    }
    echo "</script>";
}

// ==================== УПРАВЛЕНИЕ ТЕМОЙ ====================

/**
 * Установить тему (сохранить в сессию и куку)
 * @param string $theme light|dark|auto
 */
function set_theme($theme) {
    if (!in_array($theme, ['light', 'dark', 'auto'])) {
        $theme = 'auto';
    }
    $_SESSION['theme'] = $theme;
    setcookie('theme', $theme, time() + 31536000, '/', '', false, true);
}

/**
 * Получить текущую тему (из куки или сессии)
 * @return string
 */
function get_theme() {
    if (isset($_COOKIE['theme'])) {
        return $_COOKIE['theme'];
    }
    if (isset($_SESSION['theme'])) {
        return $_SESSION['theme'];
    }
    return 'auto';
}

/**
 * Генерация класса для html-тега на основе темы
 * @return string
 */
function theme_class() {
    $theme = get_theme();
    if ($theme === 'dark') return 'dark';
    if ($theme === 'light') return '';
    return '';
}

// ==================== ТРАНСЛИТЕРАЦИЯ ====================

/**
 * Транслитерация кириллицы в латиницу
 * @param string $text
 * @return string
 */
function transliterate($text) {
    $map = [
        'а' => 'a', 'б' => 'b', 'в' => 'v', 'г' => 'g', 'д' => 'd',
        'е' => 'e', 'ё' => 'e', 'ж' => 'zh', 'з' => 'z', 'и' => 'i',
        'й' => 'y', 'к' => 'k', 'л' => 'l', 'м' => 'm', 'н' => 'n',
        'о' => 'o', 'п' => 'p', 'р' => 'r', 'с' => 's', 'т' => 't',
        'у' => 'u', 'ф' => 'f', 'х' => 'h', 'ц' => 'ts', 'ч' => 'ch',
        'ш' => 'sh', 'щ' => 'sch', 'ъ' => '', 'ы' => 'y', 'ь' => '',
        'э' => 'e', 'ю' => 'yu', 'я' => 'ya',
        'А' => 'A', 'Б' => 'B', 'В' => 'V', 'Г' => 'G', 'Д' => 'D',
        'Е' => 'E', 'Ё' => 'E', 'Ж' => 'Zh', 'З' => 'Z', 'И' => 'I',
        'Й' => 'Y', 'К' => 'K', 'Л' => 'L', 'М' => 'M', 'Н' => 'N',
        'О' => 'O', 'П' => 'P', 'Р' => 'R', 'С' => 'S', 'Т' => 'T',
        'У' => 'U', 'Ф' => 'F', 'Х' => 'H', 'Ц' => 'Ts', 'Ч' => 'Ch',
        'Ш' => 'Sh', 'Щ' => 'Sch', 'Ъ' => '', 'Ы' => 'Y', 'Ь' => '',
        'Э' => 'E', 'Ю' => 'Yu', 'Я' => 'Ya',
    ];
    $text = strtr($text, $map);
    $text = preg_replace('/[^a-zA-Z0-9-]/', '-', $text);
    $text = preg_replace('/-+/', '-', $text);
    $text = trim($text, '-');
    return strtolower($text);
}

// ==================== ГЕО-ФУНКЦИИ ====================

/**
 * Получить текущий город пользователя (единая точка доступа)
 * @return array|null
 */
function get_current_city()
{
    static $city = null;
    if ($city !== null) return $city;
    
    // Проверяем, существует ли класс GeoService
    if (!class_exists('GeoService')) {
        return null;
    }
    
    try {
        $geo = new GeoService();
        $city = $geo->getUserCity();
        return $city;
    } catch (Exception $e) {
        // Логируем, но не выводим ошибку пользователю
        error_log("GeoService error: " . $e->getMessage());
        return null;
    }
}

/**
 * Получить ID текущего города (если определён)
 * @return int|null
 */
function get_current_city_id()
{
    $city = get_current_city();
    return $city['id'] ?? null;
}