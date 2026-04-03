<?php
/* ============================================
   НАЙДУК — API категорий объявлений (listings/categories) v5.0
   - Древовидная структура, авто-создание таблиц
   - Проверка циклов при изменении родителя
   - Авто-обновление slug при смене имени
   - Поле is_forbidden (запрещённые категории для обычных пользователей)
   - Кэширование дерева (Redis + файловый fallback с TTL)
   - Rate limiting для админских действий (Redis + файлы)
   - Логирование всех админских действий
   ============================================ */

session_start();
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../services/Database.php';

header('Content-Type: application/json');

// ==================== АВТОРИЗАЦИЯ ====================
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

// ==================== АВТО-СОЗДАНИЕ ТАБЛИЦ ====================
$db->query("
    CREATE TABLE IF NOT EXISTS listing_categories (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        parent_id BIGINT UNSIGNED DEFAULT 0,
        name VARCHAR(255) NOT NULL,
        slug VARCHAR(255) UNIQUE NOT NULL,
        icon VARCHAR(255),
        is_active BOOLEAN DEFAULT TRUE,
        is_forbidden BOOLEAN DEFAULT FALSE,
        sort_order INT DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_parent (parent_id),
        INDEX idx_active (is_active),
        INDEX idx_forbidden (is_forbidden)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
");

$db->query("
    CREATE TABLE IF NOT EXISTS listing_category_fields (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        category_id BIGINT UNSIGNED NOT NULL,
        field_name VARCHAR(255) NOT NULL,
        field_type VARCHAR(50) NOT NULL,
        field_options JSON,
        is_required BOOLEAN DEFAULT FALSE,
        sort_order INT DEFAULT 0,
        FOREIGN KEY (category_id) REFERENCES listing_categories(id) ON DELETE CASCADE,
        INDEX idx_category (category_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
");

// ==================== ВСПОМОГАТЕЛЬНЫЕ ФУНКЦИИ ====================

function buildTree($categories, $parentId = 0) {
    $tree = [];
    foreach ($categories as $cat) {
        if ($cat['parent_id'] == $parentId) {
            $children = buildTree($categories, $cat['id']);
            if ($children) $cat['children'] = $children;
            $tree[] = $cat;
        }
    }
    return $tree;
}

function generateSlug($name, $pdo, $excludeId = null) {
    $slug = transliterate($name);
    $slug = preg_replace('/[^a-z0-9-]/', '-', $slug);
    $slug = trim($slug, '-');
    if (empty($slug)) $slug = 'category';
    $original = $slug;
    $i = 1;
    while (true) {
        $sql = "SELECT id FROM listing_categories WHERE slug = ?";
        $params = [$slug];
        if ($excludeId) {
            $sql .= " AND id != ?";
            $params[] = $excludeId;
        }
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        if (!$stmt->fetch()) break;
        $slug = $original . '-' . $i++;
    }
    return $slug;
}

function checkCycle($pdo, $categoryId, $newParentId) {
    if ($newParentId == 0) return false;
    $visited = [];
    $current = $newParentId;
    while ($current != 0) {
        if ($current == $categoryId) return true;
        if (isset($visited[$current])) break;
        $visited[$current] = true;
        $stmt = $pdo->prepare("SELECT parent_id FROM listing_categories WHERE id = ?");
        $stmt->execute([$current]);
        $row = $stmt->fetch();
        if (!$row) break;
        $current = $row['parent_id'];
    }
    return false;
}

function rateLimitAdmin($userId, $limit = 20, $window = 3600) {
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
    $key = 'rate:admin_categories:' . $userId;
    if ($redis) {
        $count = $redis->incr($key);
        if ($count == 1) $redis->expire($key, $window);
        return $count <= $limit;
    }
    // Файловый fallback
    $file = __DIR__ . '/../../storage/rate/admin_categories_' . $userId . '.txt';
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
    // Файловый fallback с TTL
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

// ==================== ОСНОВНАЯ ЛОГИКА ====================
$input = json_decode(file_get_contents('php://input'), true);
if (!$input) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Неверный формат JSON']);
    exit;
}

$action = $input['action'] ?? '';
$allowedActions = ['tree', 'fields', 'create', 'update', 'delete'];
if (!in_array($action, $allowedActions)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Неизвестное действие']);
    exit;
}

// CSRF защита для всех действий, кроме tree и fields (они только для чтения)
$csrfSkip = ['tree', 'fields'];
if (!in_array($action, $csrfSkip)) {
    $csrfToken = $input['csrf_token'] ?? '';
    if (!verify_csrf_token($csrfToken)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Недействительный токен безопасности']);
        exit;
    }
}

switch ($action) {
    case 'tree':
        $cacheKey = 'listing_categories_tree';
        $cached = cacheGet($cacheKey, 3600);
        if ($cached !== null) {
            echo $cached;
            exit;
        }

        $stmt = $pdo->query("SELECT * FROM listing_categories WHERE is_active = 1 ORDER BY parent_id, sort_order, name");
        $categories = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $tree = buildTree($categories);

        $response = json_encode(['success' => true, 'data' => $tree]);
        cacheSet($cacheKey, $response, 3600);
        echo $response;
        break;

    case 'fields':
        $categoryId = isset($input['category_id']) ? (int)$input['category_id'] : 0;
        if (!$categoryId) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Не указан ID категории']);
            exit;
        }

        $cacheKey = "listing_category_fields:$categoryId";
        $cached = cacheGet($cacheKey, 3600);
        if ($cached !== null) {
            echo $cached;
            exit;
        }

        $stmt = $pdo->prepare("SELECT id, field_name, field_type, field_options, is_required, sort_order FROM listing_category_fields WHERE category_id = ? ORDER BY sort_order");
        $stmt->execute([$categoryId]);
        $fields = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $response = json_encode(['success' => true, 'data' => $fields]);
        cacheSet($cacheKey, $response, 3600);
        echo $response;
        break;

    case 'create':
        $user = $db->fetchOne("SELECT role FROM users WHERE id = ?", [$userId]);
        if (!$user || $user['role'] !== 'admin') {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'Доступ запрещён']);
            exit;
        }
        if (!rateLimitAdmin($userId, 20, 3600)) {
            http_response_code(429);
            echo json_encode(['success' => false, 'error' => 'Слишком много запросов']);
            exit;
        }

        $parentId = isset($input['parent_id']) ? (int)$input['parent_id'] : 0;
        $name = trim($input['name'] ?? '');
        $icon = trim($input['icon'] ?? '');
        $sortOrder = (int)($input['sort_order'] ?? 0);
        $isActive = isset($input['is_active']) ? (int)$input['is_active'] : 1;
        $isForbidden = isset($input['is_forbidden']) ? (int)$input['is_forbidden'] : 0;

        if (empty($name)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Название категории обязательно']);
            exit;
        }

        if ($parentId > 0) {
            $parent = $db->fetchOne("SELECT id FROM listing_categories WHERE id = ?", [$parentId]);
            if (!$parent) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'Родительская категория не найдена']);
                exit;
            }
        }

        $slug = generateSlug($name, $pdo);

        $stmt = $pdo->prepare("INSERT INTO listing_categories (parent_id, name, slug, icon, is_active, is_forbidden, sort_order) VALUES (?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([$parentId, $name, $slug, $icon ?: null, $isActive, $isForbidden, $sortOrder]);
        $id = $pdo->lastInsertId();

        // Логируем
        $db->insert('security_logs', [
            'user_id' => $userId,
            'ip_address' => $ip,
            'event_type' => 'category_created',
            'description' => "Создана категория #$id: $name",
            'severity' => 'low',
            'created_at' => date('Y-m-d H:i:s')
        ]);

        cacheDelete('listing_categories_tree');
        echo json_encode(['success' => true, 'id' => $id, 'slug' => $slug]);
        break;

    case 'update':
        $user = $db->fetchOne("SELECT role FROM users WHERE id = ?", [$userId]);
        if (!$user || $user['role'] !== 'admin') {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'Доступ запрещён']);
            exit;
        }
        if (!rateLimitAdmin($userId, 20, 3600)) {
            http_response_code(429);
            echo json_encode(['success' => false, 'error' => 'Слишком много запросов']);
            exit;
        }

        $id = (int)($input['id'] ?? 0);
        if (!$id) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Не указан ID категории']);
            exit;
        }

        $old = $db->fetchOne("SELECT name FROM listing_categories WHERE id = ?", [$id]);
        if (!$old) {
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => 'Категория не найдена']);
            exit;
        }

        $updateData = [];
        if (isset($input['parent_id'])) {
            $newParent = (int)$input['parent_id'];
            if ($newParent == $id) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'Категория не может быть родителем самой себя']);
                exit;
            }
            if (checkCycle($pdo, $id, $newParent)) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'Это действие создаст цикл в иерархии']);
                exit;
            }
            $updateData['parent_id'] = $newParent;
        }
        if (isset($input['name'])) {
            $name = trim($input['name']);
            if (empty($name)) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'Название не может быть пустым']);
                exit;
            }
            $updateData['name'] = $name;
            $updateData['slug'] = generateSlug($name, $pdo, $id);
        }
        if (isset($input['icon'])) $updateData['icon'] = trim($input['icon']) ?: null;
        if (isset($input['is_active'])) $updateData['is_active'] = (int)$input['is_active'];
        if (isset($input['is_forbidden'])) $updateData['is_forbidden'] = (int)$input['is_forbidden'];
        if (isset($input['sort_order'])) $updateData['sort_order'] = (int)$input['sort_order'];

        if (empty($updateData)) {
            echo json_encode(['success' => true, 'message' => 'Нет изменений']);
            exit;
        }

        $updateData['updated_at'] = date('Y-m-d H:i:s');
        $db->update('listing_categories', $updateData, 'id = ?', [$id]);

        // Логируем
        $db->insert('security_logs', [
            'user_id' => $userId,
            'ip_address' => $ip,
            'event_type' => 'category_updated',
            'description' => "Обновлена категория #$id: {$old['name']} → " . ($updateData['name'] ?? $old['name']),
            'severity' => 'low',
            'created_at' => date('Y-m-d H:i:s')
        ]);

        cacheDelete('listing_categories_tree');
        echo json_encode(['success' => true]);
        break;

    case 'delete':
        $user = $db->fetchOne("SELECT role FROM users WHERE id = ?", [$userId]);
        if (!$user || $user['role'] !== 'admin') {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'Доступ запрещён']);
            exit;
        }
        if (!rateLimitAdmin($userId, 20, 3600)) {
            http_response_code(429);
            echo json_encode(['success' => false, 'error' => 'Слишком много запросов']);
            exit;
        }

        $id = (int)($input['id'] ?? 0);
        if (!$id) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Не указан ID категории']);
            exit;
        }

        // Проверка на дочерние категории
        $children = $db->fetchCount("SELECT COUNT(*) FROM listing_categories WHERE parent_id = ?", [$id]);
        if ($children > 0) {
            http_response_code(409);
            echo json_encode(['success' => false, 'error' => 'Сначала удалите или переместите дочерние категории']);
            exit;
        }

        // Проверка на объявления
        $listings = $db->fetchCount("SELECT COUNT(*) FROM listings WHERE category_id = ?", [$id]);
        if ($listings > 0) {
            http_response_code(409);
            echo json_encode(['success' => false, 'error' => 'Категория содержит объявления, удаление невозможно']);
            exit;
        }

        $category = $db->fetchOne("SELECT name FROM listing_categories WHERE id = ?", [$id]);
        if (!$category) {
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => 'Категория не найдена']);
            exit;
        }

        $db->delete('listing_categories', 'id = ?', [$id]);

        // Логируем
        $db->insert('security_logs', [
            'user_id' => $userId,
            'ip_address' => $ip,
            'event_type' => 'category_deleted',
            'description' => "Удалена категория #$id: {$category['name']}",
            'severity' => 'medium',
            'created_at' => date('Y-m-d H:i:s')
        ]);

        cacheDelete('listing_categories_tree');
        echo json_encode(['success' => true]);
        break;

    default:
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Неизвестное действие']);
        break;
}