<?php
/* ============================================
   НАЙДУК — API избранных объявлений (listings/favorites) v4.0
   - Авто-создание таблицы listing_favorites
   - Rate limiting (Redis + файлы)
   - Кэширование списка и total с TTL (Redis + файлы)
   - Логирование действий
   - Оптимизированный запрос списка (LEFT JOIN)
   ============================================ */

session_start();
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../services/Database.php';
require_once __DIR__ . '/../../services/NotificationService.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Требуется авторизация']);
    exit;
}

$userId = (int)$_SESSION['user_id'];
$ip = getUserIP();
$db = Database::getInstance();
$pdo = $db->getPdo();

// ==================== АВТО-СОЗДАНИЕ ТАБЛИЦЫ ====================
$db->query("
    CREATE TABLE IF NOT EXISTS listing_favorites (
        user_id BIGINT UNSIGNED NOT NULL,
        listing_id BIGINT UNSIGNED NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (user_id, listing_id),
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
        FOREIGN KEY (listing_id) REFERENCES listings(id) ON DELETE CASCADE,
        INDEX idx_user (user_id),
        INDEX idx_listing (listing_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
");

// ==================== ВСПОМОГАТЕЛЬНЫЕ ФУНКЦИИ ====================

function rateLimitFavorites($userId, $ip, $limit = 30, $window = 60) {
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
    $key = 'rate:favorites:' . $userId . ':' . md5($ip);
    if ($redis) {
        $count = $redis->incr($key);
        if ($count == 1) $redis->expire($key, $window);
        return $count <= $limit;
    }
    // Файловый fallback
    $file = __DIR__ . '/../../storage/rate/favorites_' . $userId . '_' . md5($ip) . '.txt';
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
    $file = __DIR__ . '/../../storage/cache/' . md5($key) . '.json';
    if (!file_exists($file)) return null;
    $data = json_decode(file_get_contents($file), true);
    if (!$data || !isset($data['expires']) || $data['expires'] < time()) {
        @unlink($file);
        return null;
    }
    return $data['value'];
}

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
    $file = __DIR__ . '/../../storage/cache/' . md5($key) . '.json';
    $dir = dirname($file);
    if (!is_dir($dir)) mkdir($dir, 0755, true);
    $data = [
        'expires' => time() + $ttl,
        'value' => $value
    ];
    file_put_contents($file, json_encode($data), LOCK_EX);
}

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
    $file = __DIR__ . '/../../storage/cache/' . md5($key) . '.json';
    @unlink($file);
}

function clearUserFavoritesCache($userId) {
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
        $keys = $redis->keys("favorites:user:$userId:*");
        foreach ($keys as $k) $redis->del($k);
        $redis->del("favorites:user:$userId:total");
    } else {
        $pattern = __DIR__ . "/../../storage/cache/" . md5("favorites:user:$userId") . "*";
        foreach (glob($pattern) as $f) @unlink($f);
        $totalFile = __DIR__ . "/../../storage/cache/" . md5("favorites:user:$userId:total") . ".json";
        @unlink($totalFile);
    }
}

// ==================== ОСНОВНАЯ ЛОГИКА ====================
$input = json_decode(file_get_contents('php://input'), true);
if (!$input) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Неверный формат JSON']);
    exit;
}

$action = $input['action'] ?? '';
$allowedActions = ['add', 'remove', 'list'];
if (!in_array($action, $allowedActions)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Неизвестное действие']);
    exit;
}

// CSRF защита
$csrfToken = $input['csrf_token'] ?? '';
if (!verify_csrf_token($csrfToken)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Недействительный токен безопасности']);
    exit;
}

// Rate limiting
if (!rateLimitFavorites($userId, $ip, 30, 60)) {
    http_response_code(429);
    echo json_encode(['success' => false, 'error' => 'Слишком много запросов. Попробуйте позже.']);
    exit;
}

switch ($action) {
    case 'add':
        $listingId = isset($input['listing_id']) ? (int)$input['listing_id'] : 0;
        if (!$listingId) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Не указан ID объявления']);
            exit;
        }

        $listing = $db->fetchOne("SELECT id, user_id, is_hidden, status FROM listings WHERE id = ?", [$listingId]);
        if (!$listing) {
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => 'Объявление не найдено']);
            exit;
        }
        if ($listing['is_hidden'] || $listing['status'] !== 'approved') {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Объявление не доступно']);
            exit;
        }

        try {
            $stmt = $pdo->prepare("INSERT INTO listing_favorites (user_id, listing_id) VALUES (?, ?)");
            $stmt->execute([$userId, $listingId]);

            // Уведомление продавцу (если не сам себе) – синхронно, но NotificationService может ставить в очередь
            if ($listing['user_id'] != $userId) {
                $notify = new NotificationService();
                $notify->send($listing['user_id'], 'listing_favorited', [
                    'listing_id' => $listingId,
                    'user_id' => $userId
                ]);
            }

            // Логируем
            $db->insert('security_logs', [
                'user_id' => $userId,
                'ip_address' => $ip,
                'event_type' => 'favorite_added',
                'description' => "Добавлено в избранное объявление #$listingId",
                'severity' => 'low',
                'created_at' => date('Y-m-d H:i:s')
            ]);

            // Сброс кэша
            clearUserFavoritesCache($userId);

            echo json_encode(['success' => true, 'message' => 'Добавлено в избранное']);
        } catch (PDOException $e) {
            if ($e->errorInfo[1] == 1062) {
                echo json_encode(['success' => true, 'message' => 'Уже в избранном']);
            } else {
                throw $e;
            }
        }
        break;

    case 'remove':
        $listingId = isset($input['listing_id']) ? (int)$input['listing_id'] : 0;
        if (!$listingId) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Не указан ID объявления']);
            exit;
        }

        $stmt = $pdo->prepare("DELETE FROM listing_favorites WHERE user_id = ? AND listing_id = ?");
        $stmt->execute([$userId, $listingId]);

        // Логируем
        $db->insert('security_logs', [
            'user_id' => $userId,
            'ip_address' => $ip,
            'event_type' => 'favorite_removed',
            'description' => "Удалено из избранного объявление #$listingId",
            'severity' => 'low',
            'created_at' => date('Y-m-d H:i:s')
        ]);

        // Сброс кэша
        clearUserFavoritesCache($userId);

        echo json_encode(['success' => true, 'message' => 'Удалено из избранного']);
        break;

    case 'list':
        $page = max(1, (int)($input['page'] ?? 1));
        $limit = min(50, max(1, (int)($input['limit'] ?? 20)));
        $offset = ($page - 1) * $limit;

        $cacheKey = "favorites:user:$userId:$page:$limit";
        $totalCacheKey = "favorites:user:$userId:total";

        // Кэш страницы
        $cached = cacheGet($cacheKey, 600);
        if ($cached !== null) {
            echo $cached;
            exit;
        }

        // Кэш общего количества
        $total = cacheGet($totalCacheKey, 300);
        if ($total === null) {
            $total = $db->fetchCount("SELECT COUNT(*) FROM listing_favorites WHERE user_id = ?", [$userId]);
            cacheSet($totalCacheKey, $total, 300);
        }

        $sql = "
            SELECT l.id, l.type, l.title, l.price, l.price_type, l.`condition`, l.city, l.created_at, l.views,
                   l.has_warranty, l.has_delivery, l.is_sealed,
                   p.url as photo
            FROM listing_favorites f
            JOIN listings l ON f.listing_id = l.id
            LEFT JOIN listing_photos p ON p.listing_id = l.id AND p.sort_order = 0
            WHERE f.user_id = ? AND l.status IN ('approved', 'featured') AND l.is_hidden = FALSE
            ORDER BY f.created_at DESC
            LIMIT ? OFFSET ?
        ";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$userId, $limit, $offset]);
        $favorites = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $response = json_encode([
            'success' => true,
            'data' => $favorites,
            'meta' => [
                'total' => $total,
                'page' => $page,
                'limit' => $limit,
                'pages' => ceil($total / $limit)
            ]
        ]);

        cacheSet($cacheKey, $response, 600);
        echo $response;
        break;

    default:
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Неизвестное действие']);
        break;
}