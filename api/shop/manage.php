<?php
/* ============================================
   НАЙДУК — API управления магазином (shop) v6.1
   - Полный набор действий для бизнес‑кабинета и публичной страницы магазина
   - Включает AI‑функции (генерация, прогноз, анализ, рекомендации)
   - Без сокращений, все обработчики реализованы
   ============================================ */

session_start();
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../services/Database.php';
require_once __DIR__ . '/../../services/GeoService.php';
require_once __DIR__ . '/../../services/ImageOptimizer.php';
require_once __DIR__ . '/../../services/NotificationService.php';

header('Content-Type: application/json');

$method = $_SERVER['REQUEST_METHOD'];

if ($method !== 'POST' && $method !== 'GET') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

$input = [];
if ($method === 'POST') {
    $input = $_POST ?: json_decode(file_get_contents('php://input'), true);
    if (!$input) $input = [];
} else {
    $input = $_GET;
}

$action = $input['action'] ?? '';
$csrfToken = $input['csrf_token'] ?? ($_POST['csrf_token'] ?? '');

$publicActions = ['get_shop_by_slug', 'get_products', 'get_reviews', 'get_shop_info'];
$requiresCsrf = !in_array($action, $publicActions) && $method === 'POST';

if ($requiresCsrf && !verifyCsrfToken($csrfToken)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Недействительный токен безопасности']);
    exit;
}

$db = Database::getInstance();
$pdo = $db->getPdo();
$userId = $_SESSION['user_id'] ?? 0;
$ip = getUserIP();

// Rate limiting для модификаций
if ($method === 'POST' && !in_array($action, $publicActions)) {
    if (!checkRateLimit('shop_' . $userId, 20, 3600)) {
        http_response_code(429);
        echo json_encode(['success' => false, 'error' => 'Слишком много запросов. Попробуйте позже.']);
        exit;
    }
}

// ==================== АВТОСОЗДАНИЕ ТАБЛИЦ И ПОЛЕЙ ====================
$columns = $pdo->query("SHOW COLUMNS FROM shops")->fetchAll(PDO::FETCH_COLUMN);
if (!in_array('layout', $columns)) $pdo->exec("ALTER TABLE shops ADD COLUMN layout JSON DEFAULT NULL");
if (!in_array('faq', $columns)) $pdo->exec("ALTER TABLE shops ADD COLUMN faq JSON DEFAULT NULL");
if (!in_array('theme', $columns)) $pdo->exec("ALTER TABLE shops ADD COLUMN theme VARCHAR(20) DEFAULT 'light'");
if (!in_array('deepseek_api_key', $columns)) $pdo->exec("ALTER TABLE shops ADD COLUMN deepseek_api_key VARCHAR(255) DEFAULT NULL");

$columns = $pdo->query("SHOW COLUMNS FROM shop_products")->fetchAll(PDO::FETCH_COLUMN);
if (!in_array('options', $columns)) $pdo->exec("ALTER TABLE shop_products ADD COLUMN options JSON DEFAULT NULL");
if (!in_array('views', $columns)) $pdo->exec("ALTER TABLE shop_products ADD COLUMN views INT DEFAULT 0");

// Таблицы
$pdo->exec("
    CREATE TABLE IF NOT EXISTS shop_product_options (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        product_id BIGINT UNSIGNED NOT NULL,
        name VARCHAR(255) NOT NULL,
        value VARCHAR(255) NOT NULL,
        price_adjustment DECIMAL(12,2) DEFAULT 0,
        stock INT DEFAULT 0,
        FOREIGN KEY (product_id) REFERENCES shop_products(id) ON DELETE CASCADE,
        INDEX idx_product (product_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
");

$pdo->exec("
    CREATE TABLE IF NOT EXISTS shop_reviews (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        shop_id BIGINT UNSIGNED NOT NULL,
        product_id BIGINT UNSIGNED NULL,
        user_id BIGINT UNSIGNED NOT NULL,
        rating TINYINT UNSIGNED NOT NULL CHECK (rating BETWEEN 1 AND 5),
        comment TEXT,
        photos JSON,
        status ENUM('pending','approved','hidden','disputed') DEFAULT 'pending',
        order_id BIGINT UNSIGNED NULL,
        seller_reply TEXT,
        replied_at TIMESTAMP NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (shop_id) REFERENCES shops(id) ON DELETE CASCADE,
        FOREIGN KEY (product_id) REFERENCES shop_products(id) ON DELETE SET NULL,
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
        INDEX idx_shop (shop_id),
        INDEX idx_product (product_id),
        INDEX idx_status (status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
");

$pdo->exec("
    CREATE TABLE IF NOT EXISTS shop_product_questions (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        product_id BIGINT UNSIGNED NOT NULL,
        user_id BIGINT UNSIGNED NOT NULL,
        question TEXT NOT NULL,
        answer TEXT,
        answered_by BIGINT UNSIGNED NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        answered_at TIMESTAMP NULL,
        FOREIGN KEY (product_id) REFERENCES shop_products(id) ON DELETE CASCADE,
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
        FOREIGN KEY (answered_by) REFERENCES users(id) ON DELETE SET NULL,
        INDEX idx_product (product_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
");

$pdo->exec("
    CREATE TABLE IF NOT EXISTS shop_bookings (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        shop_id BIGINT UNSIGNED NOT NULL,
        product_id BIGINT UNSIGNED NULL,
        user_id BIGINT UNSIGNED NOT NULL,
        booking_date DATE NOT NULL,
        booking_time TIME NOT NULL,
        duration INT DEFAULT 60,
        status ENUM('pending','confirmed','cancelled','completed') DEFAULT 'pending',
        prepaid_amount DECIMAL(12,2) DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (shop_id) REFERENCES shops(id) ON DELETE CASCADE,
        FOREIGN KEY (product_id) REFERENCES shop_products(id) ON DELETE SET NULL,
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
        INDEX idx_shop_date (shop_id, booking_date),
        INDEX idx_status (status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
");

$pdo->exec("
    CREATE TABLE IF NOT EXISTS shop_orders (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        shop_id BIGINT UNSIGNED NOT NULL,
        user_id BIGINT UNSIGNED DEFAULT NULL,
        session_id VARCHAR(255) DEFAULT NULL,
        status ENUM('pending','paid','processing','shipped','completed','cancelled') DEFAULT 'pending',
        total DECIMAL(12,2) NOT NULL,
        currency VARCHAR(3) DEFAULT 'RUB',
        payment_method VARCHAR(50),
        delivery_address TEXT,
        customer_name VARCHAR(255),
        customer_email VARCHAR(255),
        customer_phone VARCHAR(50),
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        FOREIGN KEY (shop_id) REFERENCES shops(id) ON DELETE CASCADE,
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
        INDEX idx_shop (shop_id),
        INDEX idx_status (status),
        INDEX idx_created (created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
");

$pdo->exec("
    CREATE TABLE IF NOT EXISTS shop_order_items (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        order_id BIGINT UNSIGNED NOT NULL,
        product_id BIGINT UNSIGNED NULL,
        service_id BIGINT UNSIGNED NULL,
        quantity INT NOT NULL DEFAULT 1,
        price DECIMAL(12,2) NOT NULL,
        FOREIGN KEY (order_id) REFERENCES shop_orders(id) ON DELETE CASCADE,
        FOREIGN KEY (product_id) REFERENCES shop_products(id) ON DELETE SET NULL,
        FOREIGN KEY (service_id) REFERENCES shop_services(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
");

$pdo->exec("
    CREATE TABLE IF NOT EXISTS coupons (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        shop_id BIGINT UNSIGNED NOT NULL,
        code VARCHAR(50) NOT NULL,
        type ENUM('percent','fixed') DEFAULT 'percent',
        value DECIMAL(12,2) NOT NULL,
        min_order DECIMAL(12,2) DEFAULT 0,
        valid_from TIMESTAMP NULL,
        valid_until TIMESTAMP NULL,
        usage_limit INT DEFAULT 1,
        used_count INT DEFAULT 0,
        is_active BOOLEAN DEFAULT TRUE,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (shop_id) REFERENCES shops(id) ON DELETE CASCADE,
        UNIQUE KEY unique_code (shop_id, code)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
");

$pdo->exec("
    CREATE TABLE IF NOT EXISTS email_queue (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        to_email VARCHAR(255) NOT NULL,
        subject VARCHAR(255) NOT NULL,
        body TEXT NOT NULL,
        scheduled_at TIMESTAMP NULL,
        sent_at TIMESTAMP NULL,
        status ENUM('pending','sent','failed') DEFAULT 'pending',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_status (status),
        INDEX idx_scheduled (scheduled_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
");

// ==================== ВСПОМОГАТЕЛЬНЫЕ ФУНКЦИИ ====================
function getShopByUserId($userId) {
    $db = Database::getInstance();
    return $db->fetchOne("SELECT * FROM shops WHERE user_id = ?", [$userId]);
}

function getShopById($shopId) {
    $db = Database::getInstance();
    return $db->fetchOne("SELECT * FROM shops WHERE id = ?", [$shopId]);
}

function getShopBySlug($slug) {
    $db = Database::getInstance();
    return $db->fetchOne("SELECT * FROM shops WHERE slug = ?", [$slug]);
}

function callAI($apiKey, $prompt) {
    if (empty($apiKey)) return ['success' => false, 'error' => 'API ключ не указан'];
    $ch = curl_init('https://api.deepseek.com/v1/chat/completions');
    $payload = [
        'model' => 'deepseek-chat',
        'messages' => [['role' => 'user', 'content' => $prompt]],
        'temperature' => 0.5,
        'max_tokens' => 800
    ];
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json', 'Authorization: Bearer ' . $apiKey]);
    $response = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($code !== 200) return ['success' => false, 'error' => "HTTP $code"];
    $data = json_decode($response, true);
    return ['success' => true, 'data' => $data['choices'][0]['message']['content']];
}

// ==================== ОБРАБОТКА ДЕЙСТВИЙ ====================
switch ($action) {
    // --- Публичные действия (без авторизации) ---
    case 'get_shop_by_slug':
        $slug = $input['slug'] ?? '';
        if (!$slug) {
            echo json_encode(['success' => false, 'error' => 'Slug не указан']);
            exit;
        }
        $shop = getShopBySlug($slug);
        if (!$shop) {
            echo json_encode(['success' => false, 'error' => 'Магазин не найден']);
            exit;
        }
        unset($shop['deepseek_api_key']);
        echo json_encode(['success' => true, 'shop' => $shop]);
        break;

    case 'get_products':
        $shopId = (int)($input['shop_id'] ?? 0);
        $page = (int)($input['page'] ?? 1);
        $limit = min(50, (int)($input['limit'] ?? 12));
        $offset = ($page - 1) * $limit;

        if (!$shopId) {
            $slug = $input['slug'] ?? '';
            if ($slug) {
                $shop = getShopBySlug($slug);
                if (!$shop) {
                    echo json_encode(['success' => false, 'error' => 'Магазин не найден']);
                    exit;
                }
                $shopId = $shop['id'];
            } else {
                echo json_encode(['success' => false, 'error' => 'Не указан shop_id или slug']);
                exit;
            }
        }

        $total = $db->fetchCount("SELECT COUNT(*) FROM shop_products WHERE shop_id = ? AND is_active = 1", [$shopId]);
        $products = $db->fetchAll("
            SELECT id, name, description, price, old_price, images, quantity, category, views, is_active
            FROM shop_products
            WHERE shop_id = ? AND is_active = 1
            ORDER BY created_at DESC
            LIMIT ? OFFSET ?
        ", [$shopId, $limit, $offset]);

        foreach ($products as &$p) {
            $p['images'] = $p['images'] ? json_decode($p['images'], true) : [];
        }
        echo json_encode([
            'success' => true,
            'products' => $products,
            'total' => $total,
            'page' => $page,
            'pages' => ceil($total / $limit)
        ]);
        break;

    case 'get_reviews':
        $shopId = (int)($input['shop_id'] ?? 0);
        $page = (int)($input['page'] ?? 1);
        $limit = min(50, (int)($input['limit'] ?? 10));
        $offset = ($page - 1) * $limit;
        $ratingFilter = (int)($input['rating'] ?? 0);
        $hasPhotos = !empty($input['has_photos']);

        if (!$shopId) {
            $slug = $input['slug'] ?? '';
            if ($slug) {
                $shop = getShopBySlug($slug);
                if (!$shop) {
                    echo json_encode(['success' => false, 'error' => 'Магазин не найден']);
                    exit;
                }
                $shopId = $shop['id'];
            } else {
                echo json_encode(['success' => false, 'error' => 'Не указан shop_id или slug']);
                exit;
            }
        }

        $where = "r.shop_id = ? AND r.status = 'approved'";
        $params = [$shopId];
        if ($ratingFilter) {
            $where .= " AND r.rating = ?";
            $params[] = $ratingFilter;
        }
        if ($hasPhotos) {
            $where .= " AND r.photos IS NOT NULL AND JSON_LENGTH(r.photos) > 0";
        }

        $total = $db->fetchCount("SELECT COUNT(*) FROM shop_reviews r WHERE $where", $params);
        $reviews = $db->fetchAll("
            SELECT r.*, u.name as user_name
            FROM shop_reviews r
            JOIN users u ON r.user_id = u.id
            WHERE $where
            ORDER BY r.created_at DESC
            LIMIT ? OFFSET ?
        ", array_merge($params, [$limit, $offset]));

        echo json_encode([
            'success' => true,
            'reviews' => $reviews,
            'total' => $total,
            'page' => $page,
            'pages' => ceil($total / $limit)
        ]);
        break;

    // --- Действия, требующие авторизации и магазина текущего пользователя ---
    default:
        if (!$userId) {
            http_response_code(401);
            echo json_encode(['success' => false, 'error' => 'Требуется авторизация']);
            exit;
        }
        $shop = getShopByUserId($userId);
        if (!$shop) {
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => 'Магазин не найден']);
            exit;
        }
        $shopId = $shop['id'];
        break;
}

// ==================== ДЕЙСТВИЯ ДЛЯ ВЛАДЕЛЬЦА МАГАЗИНА ====================
switch ($action) {
    case 'get':
        echo json_encode(['success' => true, 'shop' => $shop]);
        break;

    case 'stats':
        $stats = [
            'products' => $db->fetchCount("SELECT COUNT(*) FROM shop_products WHERE shop_id = ?", [$shopId]),
            'orders' => $db->fetchCount("SELECT COUNT(*) FROM shop_orders WHERE shop_id = ?", [$shopId]),
            'revenue' => $db->fetchColumn("SELECT COALESCE(SUM(total), 0) FROM shop_orders WHERE shop_id = ?", [$shopId]),
            'views' => $db->fetchColumn("SELECT COALESCE(SUM(views), 0) FROM shop_products WHERE shop_id = ?", [$shopId])
        ];
        echo json_encode(['success' => true, 'stats' => $stats]);
        break;

    case 'get_analytics':
        $period = $input['period'] ?? 'month';
        $interval = $period === 'week' ? 7 : 30;
        $data = [];
        for ($i = $interval - 1; $i >= 0; $i--) {
            $date = date('Y-m-d', strtotime("-$i days"));
            $views = $db->fetchColumn("SELECT COALESCE(SUM(views), 0) FROM shop_products WHERE shop_id = ? AND DATE(updated_at) = ?", [$shopId, $date]);
            $orders = $db->fetchColumn("SELECT COUNT(*) FROM shop_orders WHERE shop_id = ? AND DATE(created_at) = ?", [$shopId, $date]);
            $revenue = $db->fetchColumn("SELECT COALESCE(SUM(total), 0) FROM shop_orders WHERE shop_id = ? AND DATE(created_at) = ?", [$shopId, $date]);
            $data[] = ['date' => $date, 'views' => $views, 'orders' => $orders, 'revenue' => $revenue];
        }
        echo json_encode(['success' => true, 'stats' => $data]);
        break;

    case 'get_products_owner':
        $page = (int)($input['page'] ?? 1);
        $limit = 20;
        $offset = ($page - 1) * $limit;
        $total = $db->fetchCount("SELECT COUNT(*) FROM shop_products WHERE shop_id = ?", [$shopId]);
        $products = $db->fetchAll("
            SELECT * FROM shop_products
            WHERE shop_id = ?
            ORDER BY created_at DESC
            LIMIT ? OFFSET ?
        ", [$shopId, $limit, $offset]);
        echo json_encode(['success' => true, 'products' => $products, 'total' => $total, 'page' => $page, 'pages' => ceil($total / $limit)]);
        break;

    case 'create_product':
    case 'update_product':
        $id = (int)($input['id'] ?? 0);
        $data = [
            'shop_id' => $shopId,
            'sku' => trim($input['sku'] ?? ''),
            'name' => trim($input['name'] ?? ''),
            'description' => trim($input['description'] ?? ''),
            'price' => (float)($input['price'] ?? 0),
            'old_price' => (float)($input['old_price'] ?? 0) ?: null,
            'quantity' => (int)($input['quantity'] ?? 0),
            'category' => trim($input['category'] ?? ''),
            'options' => json_encode($input['options'] ?? []),
            'images' => json_encode($input['images'] ?? []),
            'is_active' => (int)($input['is_active'] ?? 1),
            'updated_at' => date('Y-m-d H:i:s')
        ];
        if ($id) {
            $db->update('shop_products', $data, 'id = ?', [$id]);
        } else {
            $data['created_at'] = date('Y-m-d H:i:s');
            $id = $db->insert('shop_products', $data);
        }
        echo json_encode(['success' => true, 'id' => $id]);
        break;

    case 'delete_product':
        $id = (int)($input['id'] ?? 0);
        $db->delete('shop_products', 'id = ?', [$id]);
        echo json_encode(['success' => true]);
        break;

    case 'bulk_price_update':
        $ids = $input['ids'] ?? [];
        $type = $input['type'] ?? 'fixed';
        $value = (float)($input['value'] ?? 0);
        if (empty($ids)) {
            echo json_encode(['success' => false, 'error' => 'Нет выбранных товаров']);
            exit;
        }
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        if ($type === 'percent') {
            $sql = "UPDATE shop_products SET price = price * (1 + ? / 100), updated_at = NOW() WHERE id IN ($placeholders)";
            $params = array_merge([$value], $ids);
        } else {
            $sql = "UPDATE shop_products SET price = price + ?, updated_at = NOW() WHERE id IN ($placeholders)";
            $params = array_merge([$value], $ids);
        }
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        echo json_encode(['success' => true, 'updated' => $stmt->rowCount()]);
        break;

    case 'upload_product_photos':
        if (!isset($_FILES['files'])) {
            echo json_encode(['success' => false, 'error' => 'Нет файлов']);
            exit;
        }
        $productId = (int)($input['product_id'] ?? 0);
        $uploaded = [];
        foreach ($_FILES['files']['tmp_name'] as $i => $tmp) {
            $optimizer = new ImageOptimizer(ROOT_DIR . '/uploads/');
            $result = $optimizer->optimize($tmp, [
                'width' => 800,
                'quality' => 80,
                'format' => 'webp',
                'user_id' => $userId,
                'context' => 'product'
            ]);
            if ($result['success']) {
                $url = '/uploads/optimized/' . basename($result['optimized_path']);
                $sizeMb = filesize($result['optimized_path']) / 1024 / 1024;
                $db->insert('product_photos', [
                    'product_id' => $productId,
                    'url' => $url,
                    'sort_order' => $i,
                    'size_mb' => round($sizeMb, 2),
                    'created_at' => date('Y-m-d H:i:s')
                ]);
                $uploaded[] = $url;
                $db->update('shops', ['storage_used_mb' => $db->fetchColumn("SELECT COALESCE(SUM(size_mb),0) FROM product_photos WHERE product_id IN (SELECT id FROM shop_products WHERE shop_id=?)", [$shopId]) + ($shop['storage_used_mb'] ?? 0)], 'id = ?', [$shopId]);
            }
        }
        echo json_encode(['success' => true, 'photos' => $uploaded]);
        break;

    case 'get_services':
        $page = (int)($input['page'] ?? 1);
        $limit = 20;
        $offset = ($page - 1) * $limit;
        $total = $db->fetchCount("SELECT COUNT(*) FROM shop_services WHERE shop_id = ?", [$shopId]);
        $services = $db->fetchAll("SELECT * FROM shop_services WHERE shop_id = ? ORDER BY created_at DESC LIMIT ? OFFSET ?", [$shopId, $limit, $offset]);
        echo json_encode(['success' => true, 'services' => $services, 'total' => $total, 'page' => $page, 'pages' => ceil($total / $limit)]);
        break;

    case 'save_service':
        $id = (int)($input['id'] ?? 0);
        $data = [
            'shop_id' => $shopId,
            'name' => trim($input['name'] ?? ''),
            'description' => trim($input['description'] ?? ''),
            'price' => (float)($input['price'] ?? 0),
            'duration' => (int)($input['duration'] ?? 60),
            'is_active' => (int)($input['is_active'] ?? 1),
            'updated_at' => date('Y-m-d H:i:s')
        ];
        if ($id) {
            $db->update('shop_services', $data, 'id = ?', [$id]);
        } else {
            $data['created_at'] = date('Y-m-d H:i:s');
            $id = $db->insert('shop_services', $data);
        }
        echo json_encode(['success' => true, 'id' => $id]);
        break;

    case 'delete_service':
        $id = (int)($input['id'] ?? 0);
        $db->delete('shop_services', 'id = ?', [$id]);
        echo json_encode(['success' => true]);
        break;

    case 'get_faq':
        $faq = json_decode($shop['faq'] ?? '[]', true);
        echo json_encode(['success' => true, 'faq' => $faq]);
        break;

    case 'save_faq':
        $faq = $input['faq'] ?? [];
        $db->update('shops', ['faq' => json_encode($faq)], 'id = ?', [$shopId]);
        echo json_encode(['success' => true]);
        break;

    case 'update_layout':
        $layout = $input['layout'] ?? '';
        if ($layout) {
            $db->update('shops', ['layout' => $layout, 'updated_at' => date('Y-m-d H:i:s')], 'id = ?', [$shopId]);
            echo json_encode(['success' => true, 'message' => 'Макет сохранён']);
        } else {
            echo json_encode(['success' => false, 'error' => 'Неверные данные']);
        }
        break;

    case 'save_settings':
        $data = [
            'name' => trim($input['name'] ?? ''),
            'description' => trim($input['description'] ?? ''),
            'contact_phone' => trim($input['contact_phone'] ?? ''),
            'contact_email' => trim($input['contact_email'] ?? ''),
            'contact_telegram' => trim($input['contact_telegram'] ?? ''),
            'contact_whatsapp' => trim($input['contact_whatsapp'] ?? ''),
            'contact_instagram' => trim($input['contact_instagram'] ?? ''),
            'contact_youtube' => trim($input['contact_youtube'] ?? ''),
            'address' => trim($input['address'] ?? ''),
            'theme' => trim($input['theme'] ?? 'light'),
            'deepseek_api_key' => trim($input['deepseek_api_key'] ?? ''),
            'payment_methods' => json_encode($input['payment_methods'] ?? []),
            'updated_at' => date('Y-m-d H:i:s')
        ];
        $db->update('shops', $data, 'id = ?', [$shopId]);
        echo json_encode(['success' => true]);
        break;

    case 'upload_logo':
        if (!isset($_FILES['file'])) {
            echo json_encode(['success' => false, 'error' => 'Нет файла']);
            exit;
        }
        $optimizer = new ImageOptimizer(ROOT_DIR . '/uploads/');
        $result = $optimizer->optimize($_FILES['file']['tmp_name'], [
            'width' => 200,
            'height' => 200,
            'crop' => true,
            'quality' => 80,
            'format' => 'webp',
            'user_id' => $userId,
            'context' => 'logo'
        ]);
        if ($result['success']) {
            $url = '/uploads/optimized/' . basename($result['optimized_path']);
            $db->update('shops', ['logo_url' => $url], 'id = ?', [$shopId]);
            echo json_encode(['success' => true, 'url' => $url]);
        } else {
            echo json_encode(['success' => false, 'error' => 'Ошибка обработки']);
        }
        break;

    case 'upload_banner':
        if (!isset($_FILES['file'])) {
            echo json_encode(['success' => false, 'error' => 'Нет файла']);
            exit;
        }
        $optimizer = new ImageOptimizer(ROOT_DIR . '/uploads/');
        $result = $optimizer->optimize($_FILES['file']['tmp_name'], [
            'width' => 1200,
            'quality' => 80,
            'format' => 'webp',
            'user_id' => $userId,
            'context' => 'banner'
        ]);
        if ($result['success']) {
            $url = '/uploads/optimized/' . basename($result['optimized_path']);
            $db->update('shops', ['banner_url' => $url], 'id'    case 'upload_banner':
        if (!isset($_FILES['file'])) {
            echo json_encode(['success' => false, 'error' => 'Нет файла']);
            exit;
        }
        $optimizer = new ImageOptimizer(ROOT_DIR . '/uploads/');
        $result = $optimizer->optimize($_FILES['file']['tmp_name'], [
            'width' => 1200,
            'quality' => 80,
            'format' => 'webp',
            'user_id' => $userId,
            'context' => 'banner'
        ]);
        if ($result['success']) {
            $url = '/uploads/optimized/' . basename($result['optimized_path']);
            $db->update('shops', ['banner_url' => $url], 'id = ?', [$shopId]);
            echo json_encode(['success' => true, 'url' => $url]);
        } else {
            echo json_encode(['success' => false, 'error' => 'Ошибка обработки']);
        }
        break;

    case 'get_storage_info':
        $limit = $shop['storage_limit_mb'];
        $used = $db->fetchColumn("SELECT COALESCE(SUM(size_mb), 0) FROM product_photos WHERE product_id IN (SELECT id FROM shop_products WHERE shop_id = ?)", [$shopId]);
        if ($shop['logo_url']) {
            $path = ROOT_DIR . parse_url($shop['logo_url'], PHP_URL_PATH);
            if (file_exists($path)) $used += filesize($path) / 1024 / 1024;
        }
        if ($shop['banner_url']) {
            $path = ROOT_DIR . parse_url($shop['banner_url'], PHP_URL_PATH);
            if (file_exists($path)) $used += filesize($path) / 1024 / 1024;
        }
        $used = round($used, 2);
        $db->update('shops', ['storage_used_mb' => $used], 'id = ?', [$shopId]);
        echo json_encode(['success' => true, 'limit' => $limit, 'used' => $used, 'percent' => round($used / max(1, $limit) * 100, 1)]);
        break;

    case 'buy_storage':
        $extraGb = (int)($input['extra_gb'] ?? 1);
        $price = $extraGb * 500;
        $orderId = $db->insert('payment_orders', [
            'user_id' => $userId,
            'type' => 'storage',
            'amount' => $price,
            'data' => json_encode(['extra_gb' => $extraGb]),
            'status' => 'pending',
            'created_at' => date('Y-m-d H:i:s')
        ]);
        echo json_encode(['success' => true, 'order_id' => $orderId, 'amount' => $price]);
        break;

    case 'get_subscription':
        echo json_encode(['success' => true, 'plan' => $shop['plan'], 'expires' => $shop['plan_expires_at'], 'trial_used' => $shop['trial_used']]);
        break;

    case 'upgrade_plan':
        $newPlan = $input['plan'] ?? 'basic';
        $price = ['basic' => 990, 'business' => 2990, 'premium' => 9900][$newPlan];
        $orderId = $db->insert('payment_orders', [
            'user_id' => $userId,
            'type' => 'subscription',
            'amount' => $price,
            'data' => json_encode(['plan' => $newPlan]),
            'status' => 'pending',
            'created_at' => date('Y-m-d H:i:s')
        ]);
        echo json_encode(['success' => true, 'order_id' => $orderId, 'amount' => $price]);
        break;

    // === ЗАКАЗЫ ===
    case 'get_orders':
        $page = (int)($input['page'] ?? 1);
        $limit = 20;
        $offset = ($page - 1) * $limit;
        $status = $input['status'] ?? '';
        $dateFrom = $input['date_from'] ?? '';
        $dateTo = $input['date_to'] ?? '';

        $where = ['shop_id = ?'];
        $params = [$shopId];
        if ($status && $status !== 'all') {
            $where[] = 'status = ?';
            $params[] = $status;
        }
        if ($dateFrom) {
            $where[] = 'created_at >= ?';
            $params[] = $dateFrom . ' 00:00:00';
        }
        if ($dateTo) {
            $where[] = 'created_at <= ?';
            $params[] = $dateTo . ' 23:59:59';
        }
        $whereClause = implode(' AND ', $where);
        $total = $db->fetchCount("SELECT COUNT(*) FROM shop_orders WHERE $whereClause", $params);
        $orders = $db->fetchAll("
            SELECT * FROM shop_orders
            WHERE $whereClause
            ORDER BY created_at DESC
            LIMIT ? OFFSET ?
        ", array_merge($params, [$limit, $offset]));

        foreach ($orders as &$order) {
            $order['items_count'] = $db->fetchCount("SELECT COUNT(*) FROM shop_order_items WHERE order_id = ?", [$order['id']]);
        }
        echo json_encode(['success' => true, 'orders' => $orders, 'total' => $total, 'page' => $page, 'pages' => ceil($total / $limit)]);
        break;

    case 'get_order_details':
        $orderId = (int)($input['order_id'] ?? 0);
        $order = $db->fetchOne("SELECT * FROM shop_orders WHERE id = ? AND shop_id = ?", [$orderId, $shopId]);
        if (!$order) {
            echo json_encode(['success' => false, 'error' => 'Заказ не найден']);
            exit;
        }
        $items = $db->fetchAll("
            SELECT oi.*,
                   COALESCE(p.name, s.name) as name
            FROM shop_order_items oi
            LEFT JOIN shop_products p ON oi.product_id = p.id
            LEFT JOIN shop_services s ON oi.service_id = s.id
            WHERE oi.order_id = ?
        ", [$orderId]);
        $order['items'] = $items;
        echo json_encode(['success' => true, 'order' => $order]);
        break;

    case 'update_order_status':
        $orderId = (int)($input['order_id'] ?? 0);
        $newStatus = $input['status'] ?? '';
        $allowedStatuses = ['pending','paid','processing','shipped','completed','cancelled'];
        if (!in_array($newStatus, $allowedStatuses)) {
            echo json_encode(['success' => false, 'error' => 'Недопустимый статус']);
            exit;
        }
        $order = $db->fetchOne("SELECT id FROM shop_orders WHERE id = ? AND shop_id = ?", [$orderId, $shopId]);
        if (!$order) {
            echo json_encode(['success' => false, 'error' => 'Заказ не найден']);
            exit;
        }
        $db->update('shop_orders', ['status' => $newStatus, 'updated_at' => date('Y-m-d H:i:s')], 'id = ?', [$orderId]);
        echo json_encode(['success' => true]);
        break;

    case 'export_orders':
        $format = $input['format'] ?? 'csv';
        $status = $input['status'] ?? '';
        $dateFrom = $input['date_from'] ?? '';
        $dateTo = $input['date_to'] ?? '';

        $where = ['shop_id = ?'];
        $params = [$shopId];
        if ($status && $status !== 'all') {
            $where[] = 'status = ?';
            $params[] = $status;
        }
        if ($dateFrom) {
            $where[] = 'created_at >= ?';
            $params[] = $dateFrom . ' 00:00:00';
        }
        if ($dateTo) {
            $where[] = 'created_at <= ?';
            $params[] = $dateTo . ' 23:59:59';
        }
        $whereClause = implode(' AND ', $where);
        $orders = $db->fetchAll("SELECT * FROM shop_orders WHERE $whereClause ORDER BY created_at DESC", $params);

        $filename = "orders_export_" . date('Ymd_His');
        if ($format === 'csv') {
            $csvFile = ROOT_DIR . "/storage/exports/{$filename}.csv";
            if (!is_dir(ROOT_DIR . '/storage/exports')) mkdir(ROOT_DIR . '/storage/exports', 0755, true);
            $fp = fopen($csvFile, 'w');
            fputcsv($fp, ['ID', 'Дата', 'Сумма', 'Статус', 'Имя покупателя', 'Email', 'Телефон', 'Адрес']);
            foreach ($orders as $o) {
                fputcsv($fp, [$o['id'], $o['created_at'], $o['total'], $o['status'], $o['customer_name'], $o['customer_email'], $o['customer_phone'], $o['delivery_address']]);
            }
            fclose($fp);
            echo json_encode(['success' => true, 'url' => "/storage/exports/{$filename}.csv"]);
        } elseif ($format === 'pdf') {
            if (!class_exists('Mpdf\Mpdf')) {
                echo json_encode(['success' => false, 'error' => 'Для PDF требуется установка mPDF']);
                exit;
            }
            $html = '<h1>Заказы ' . date('Y-m-d') . '</h1><table border="1"><thead><tr><th>ID</th><th>Дата</th><th>Сумма</th><th>Статус</th><th>Покупатель</th></tr></thead><tbody>';
            foreach ($orders as $o) {
                $html .= "<tr><td>{$o['id']}</td><td>{$o['created_at']}</td><td>{$o['total']}</td><td>{$o['status']}</td><td>{$o['customer_name']}</td></tr>";
            }
            $html .= '</tbody></table>';
            $mpdf = new \Mpdf\Mpdf();
            $mpdf->WriteHTML($html);
            $pdfFile = ROOT_DIR . "/storage/exports/{$filename}.pdf";
            $mpdf->Output($pdfFile, 'F');
            echo json_encode(['success' => true, 'url' => "/storage/exports/{$filename}.pdf"]);
        }
        break;

    // === ОТЗЫВЫ (владельцу) ===
    case 'get_reviews_owner':
        $page = (int)($input['page'] ?? 1);
        $limit = 20;
        $offset = ($page - 1) * $limit;
        $statusFilter = $input['status'] ?? '';
        $where = "shop_id = ?";
        $params = [$shopId];
        if ($statusFilter && in_array($statusFilter, ['pending','approved','hidden','disputed'])) {
            $where .= " AND status = ?";
            $params[] = $statusFilter;
        }
        $total = $db->fetchCount("SELECT COUNT(*) FROM shop_reviews WHERE $where", $params);
        $reviews = $db->fetchAll("
            SELECT r.*, u.name as user_name
            FROM shop_reviews r
            JOIN users u ON r.user_id = u.id
            WHERE $where
            ORDER BY r.created_at DESC
            LIMIT ? OFFSET ?
        ", array_merge($params, [$limit, $offset]));
        echo json_encode(['success' => true, 'reviews' => $reviews, 'total' => $total, 'page' => $page, 'pages' => ceil($total / $limit)]);
        break;

    case 'reply_review':
        $reviewId = (int)($input['review_id'] ?? 0);
        $reply = trim($input['reply'] ?? '');
        if (empty($reply)) {
            echo json_encode(['success' => false, 'error' => 'Текст ответа не может быть пустым']);
            exit;
        }
        $review = $db->fetchOne("SELECT id, shop_id FROM shop_reviews WHERE id = ?", [$reviewId]);
        if (!$review || $review['shop_id'] != $shopId) {
            echo json_encode(['success' => false, 'error' => 'Отзыв не найден']);
            exit;
        }
        $db->update('shop_reviews', ['seller_reply' => $reply, 'replied_at' => date('Y-m-d H:i:s')], 'id = ?', [$reviewId]);
        echo json_encode(['success' => true]);
        break;

    case 'dispute_review':
        $reviewId = (int)($input['review_id'] ?? 0);
        $reason = trim($input['reason'] ?? '');
        if (empty($reason)) {
            echo json_encode(['success' => false, 'error' => 'Укажите причину жалобы']);
            exit;
        }
        $review = $db->fetchOne("SELECT id, shop_id FROM shop_reviews WHERE id = ?", [$reviewId]);
        if (!$review || $review['shop_id'] != $shopId) {
            echo json_encode(['success' => false, 'error' => 'Отзыв не найден']);
            exit;
        }
        $db->update('shop_reviews', ['status' => 'disputed', 'dispute_reason' => $reason], 'id = ?', [$reviewId]);
        echo json_encode(['success' => true]);
        break;

    // === КУПОНЫ ===
    case 'get_coupons':
        $coupons = $db->fetchAll("SELECT * FROM coupons WHERE shop_id = ? ORDER BY created_at DESC", [$shopId]);
        echo json_encode(['success' => true, 'coupons' => $coupons]);
        break;

    case 'create_coupon':
        $code = strtoupper(trim($input['code'] ?? ''));
        $type = $input['type'] ?? 'percent';
        $value = (float)($input['value'] ?? 0);
        $minOrder = (float)($input['min_order'] ?? 0);
        $validFrom = $input['valid_from'] ?? null;
        $validUntil = $input['valid_until'] ?? null;
        $usageLimit = (int)($input['usage_limit'] ?? 1);
        if (empty($code) || $value <= 0) {
            echo json_encode(['success' => false, 'error' => 'Неверные данные купона']);
            exit;
        }
        $existing = $db->fetchOne("SELECT id FROM coupons WHERE shop_id = ? AND code = ?", [$shopId, $code]);
        if ($existing) {
            echo json_encode(['success' => false, 'error' => 'Купон с таким кодом уже существует']);
            exit;
        }
        $db->insert('coupons', [
            'shop_id' => $shopId,
            'code' => $code,
            'type' => $type,
            'value' => $value,
            'min_order' => $minOrder,
            'valid_from' => $validFrom,
            'valid_until' => $validUntil,
            'usage_limit' => $usageLimit,
            'created_at' => date('Y-m-d H:i:s')
        ]);
        echo json_encode(['success' => true]);
        break;

    case 'delete_coupon':
        $id = (int)($input['id'] ?? 0);
        $db->delete('coupons', 'id = ? AND shop_id = ?', [$id, $shopId]);
        echo json_encode(['success' => true]);
        break;

    // === РЕКЛАМА (БАННЕРЫ) ===
    case 'request_banner':
        if (!isset($_FILES['image']) || $_FILES['image']['error'] !== UPLOAD_ERR_OK) {
            echo json_encode(['success' => false, 'error' => 'Файл не загружен']);
            exit;
        }
        $optimizer = new ImageOptimizer(ROOT_DIR . '/uploads/');
        $result = $optimizer->optimize($_FILES['image']['tmp_name'], [
            'width' => 1200,
            'quality' => 80,
            'format' => 'webp',
            'user_id' => $userId,
            'context' => 'banner_request'
        ]);
        if (!$result['success']) {
            echo json_encode(['success' => false, 'error' => 'Ошибка обработки изображения']);
            exit;
        }
        $imageUrl = '/uploads/optimized/' . basename($result['optimized_path']);
        $city = $input['city'] ?? '';
        $duration = (int)($input['duration'] ?? 7);
        $targetUrl = $input['target_url'] ?? '';
        $price = 500 * $duration;
        $orderId = $db->insert('payment_orders', [
            'user_id' => $userId,
            'type' => 'banner',
            'amount' => $price,
            'data' => json_encode(['image_url' => $imageUrl, 'city' => $city, 'duration' => $duration, 'target_url' => $targetUrl]),
            'status' => 'pending',
            'created_at' => date('Y-m-d H:i:s')
        ]);
        echo json_encode(['success' => true, 'order_id' => $orderId, 'amount' => $price]);
        break;

    case 'get_banner_requests':
        $requests = $db->fetchAll("
            SELECT * FROM payment_orders
            WHERE user_id = ? AND type = 'banner'
            ORDER BY created_at DESC
        ", [$userId]);
        echo json_encode(['success' => true, 'data' => $requests]);
        break;

    // === AI-ФУНКЦИИ ===
    case 'ai_generate_description':
        if (empty($shop['deepseek_api_key'])) {
            echo json_encode(['success' => false, 'error' => 'Не введён API-ключ DeepSeek']);
            exit;
        }
        $prompt = "Напиши продающее SEO-описание для товара.\nНазвание: {$input['name']}\nКатегория: {$input['category']}\nЦена: {$input['price']} руб.\n3-4 предложения.";
        $result = callAI($shop['deepseek_api_key'], $prompt);
        echo json_encode(['success' => $result['success'], 'description' => $result['data'] ?? '']);
        break;

    case 'ai_forecast':
        if (empty($shop['deepseek_api_key'])) {
            echo json_encode(['success' => false, 'error' => 'Не введён API-ключ DeepSeek']);
            exit;
        }
        $history = $db->fetchAll("SELECT DATE(created_at) as date, COUNT(*) as sales FROM shop_orders WHERE shop_id = ? AND status = 'completed' AND created_at > NOW() - INTERVAL 30 DAY GROUP BY DATE(created_at)", [$shopId]);
        $prompt = "На основе данных:\n" . json_encode($history) . "\nСделай прогноз на 7 дней. Ответ в JSON: {\"forecast\":[{\"date\":\"YYYY-MM-DD\",\"sales\":число}]}";
        $result = callAI($shop['deepseek_api_key'], $prompt);
        if ($result['success']) {
            $forecast = json_decode($result['data'], true);
            echo json_encode(['success' => true, 'forecast' => $forecast['forecast'] ?? []]);
        } else {
            echo json_encode(['success' => false, 'error' => $result['error']]);
        }
        break;

    case 'ai_review_analysis':
        if (empty($shop['deepseek_api_key'])) {
            echo json_encode(['success' => false, 'error' => 'Не введён API-ключ DeepSeek']);
            exit;
        }
        $reviews = $db->fetchAll("SELECT rating, comment FROM shop_reviews WHERE shop_id = ? AND comment IS NOT NULL AND comment != '' ORDER BY created_at DESC LIMIT 20", [$shopId]);
        if (empty($reviews)) {
            echo json_encode(['success' => true, 'analysis' => 'Нет отзывов для анализа']);
            exit;
        }
        $prompt = "Проанализируй отзывы и выдели ключевые плюсы и минусы. Формат:\nПлюсы: ...\nМинусы: ...\nРекомендации: ...\n\nОтзывы:\n" . json_encode($reviews);
        $result = callAI($shop['deepseek_api_key'], $prompt);
        echo json_encode(['success' => $result['success'], 'analysis' => $result['data'] ?? '']);
        break;

    case 'ai_similar_products':
        if (empty($shop['deepseek_api_key'])) {
            echo json_encode(['success' => false, 'error' => 'Не введён API-ключ DeepSeek']);
            exit;
        }
        $description = $input['description'] ?? '';
        $products = $db->fetchAll("SELECT id, name, description, price FROM shop_products WHERE shop_id = ? LIMIT 50", [$shopId]);
        $prompt = "Пользователь ищет: \"{$description}\". Вот товары:\n" . json_encode($products) . "\nВыбери до 5 наиболее подходящих и верни их ID в JSON: [id1, id2, ...]";
        $result = callAI($shop['deepseek_api_key'], $prompt);
        if ($result['success']) {
            $ids = json_decode($result['data'], true);
            if (is_array($ids)) {
                $similar = $db->fetchAll("SELECT * FROM shop_products WHERE id IN (" . implode(',', array_fill(0, count($ids), '?')) . ")", $ids);
                echo json_encode(['success' => true, 'products' => $similar]);
            } else {
                echo json_encode(['success' => true, 'products' => []]);
            }
        } else {
            echo json_encode(['success' => false, 'error' => $result['error']]);
        }
        break;

    case 'ai_optimize_listings':
        if (empty($shop['deepseek_api_key'])) {
            echo json_encode(['success' => false, 'error' => 'Не введён API-ключ DeepSeek']);
            exit;
        }
        $listings = $db->fetchAll("SELECT id, title, description FROM listings WHERE user_id = ? AND status = 'approved' LIMIT 10", [$userId]);
        if (empty($listings)) {
            echo json_encode(['success' => true, 'optimized' => []]);
            exit;
        }
        $prompt = "Для каждого объявления сгенерируй улучшенный заголовок и описание. Верни JSON: [{\"id\": число, \"title\": \"...\", \"description\": \"...\"}]\nОбъявления:\n" . json_encode($listings);
        $result = callAI($shop['deepseek_api_key'], $prompt);
        if ($result['success']) {
            $optimized = json_decode($result['data'], true);
            echo json_encode(['success' => true, 'optimized' => $optimized]);
        } else {
            echo json_encode(['success' => false, 'error' => $result['error']]);
        }
        break;

    case 'ai_promo_text':
        if (empty($shop['deepseek_api_key'])) {
            echo json_encode(['success' => false, 'error' => 'Не введён API-ключ DeepSeek']);
            exit;
        }
        $prompt = "Создай рекламный текст для партнёрского оффера.\nПартнёр: {$input['partner']}\nОффер: {$input['offer']}\nКомиссия: {$input['commission']}%\nТекст должен привлекать внимание.";
        $result = callAI($shop['deepseek_api_key'], $prompt);
        echo json_encode(['success' => $result['success'], 'text' => $result['data'] ?? '']);
        break;

    case 'seo':
        $seo = [
            'slug' => $shop['slug'],
            'title' => $shop['seo_title'],
            'description' => $shop['seo_description'],
            'keywords' => $shop['seo_keywords']
        ];
        echo json_encode(['success' => true, 'seo' => $seo]);
        break;

    case 'save_seo':
        $slug = trim($input['slug'] ?? '');
        if (empty($slug)) $slug = 'shop_' . $shopId;
        $db->update('shops', [
            'slug' => $slug,
            'seo_title' => trim($input['seo_title'] ?? ''),
            'seo_description' => trim($input['seo_description'] ?? ''),
            'seo_keywords' => trim($input['seo_keywords'] ?? ''),
            'updated_at' => date('Y-m-d H:i:s')
        ], 'id = ?', [$shopId]);
        echo json_encode(['success' => true]);
        break;

    default:
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Неизвестное действие']);
        exit;
}