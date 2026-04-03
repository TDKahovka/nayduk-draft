<?php
/* ============================================
   НАЙДУК — API объявлений (listings) v10.0
   - Полная самодостаточность: автосоздание таблиц с индексами
   - Кэширование списков, детальных страниц, геокодинга
   - Идемпотентность для создания
   - Безопасная обработка ошибок, белый список сортировки
   - Пагинация для предложений
   - Автоматический подъём по расписанию (next_auto_boost_at)
   - ПОДДЕРЖКА ТОВАРОВ ИЗ МАГАЗИНОВ (include_shops=1)
   - Единый формат ответа для объявлений и товаров
   ============================================ */

session_start();
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../services/Database.php';
require_once __DIR__ . '/../../services/GeoService.php';
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

// Rate limiting для создания (не более 10 в сутки)
if (!checkRateLimit('listing_create_' . $userId, 10, 86400)) {
    http_response_code(429);
    echo json_encode(['success' => false, 'error' => 'Слишком много объявлений за сутки. Попробуйте позже.']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
if (!$input) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Неверный формат JSON']);
    exit;
}

$csrfToken = $input['csrf_token'] ?? '';
if (!verifyCsrfToken($csrfToken)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Недействительный токен безопасности']);
    exit;
}

$action = $input['action'] ?? '';
$allowedActions = [
    'create', 'update', 'delete', 'get', 'list', 'search',
    'mark_sold', 'claim_gift', 'get_offers_summary', 'get_seller_offers', 'accept_offer'
];
if (!in_array($action, $allowedActions)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Неизвестное действие']);
    exit;
}

$db = Database::getInstance();
$pdo = $db->getPdo();
$geo = new GeoService();

// ==================== АВТОСОЗДАНИЕ ТАБЛИЦ С ИНДЕКСАМИ ====================
$db->query("
    CREATE TABLE IF NOT EXISTS listings (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        user_id BIGINT UNSIGNED NOT NULL,
        type VARCHAR(20) NOT NULL DEFAULT 'sell' CHECK (type IN ('sell', 'wanted', 'resume', 'service')),
        category_id BIGINT UNSIGNED,
        title VARCHAR(255) NOT NULL,
        description TEXT,
        price DECIMAL(12,2),
        price_type VARCHAR(20) DEFAULT 'fixed',
        condition VARCHAR(20) DEFAULT 'used',
        address TEXT,
        lat DECIMAL(10,8),
        lng DECIMAL(11,8),
        city VARCHAR(255),
        views INT DEFAULT 0,
        status VARCHAR(50) DEFAULT 'pending',
        moderation_comment TEXT,
        is_featured BOOLEAN DEFAULT FALSE,
        featured_until TIMESTAMP NULL,
        custom_fields JSON,
        has_warranty BOOLEAN DEFAULT FALSE,
        has_delivery BOOLEAN DEFAULT FALSE,
        booking_settings JSON,
        promoted_until TIMESTAMP NULL,
        promotion_type VARCHAR(50),
        min_offer_percent INT DEFAULT NULL,
        is_sealed BOOLEAN DEFAULT FALSE,
        gift JSON DEFAULT NULL,
        auto_refresh_count INT DEFAULT 0,
        last_refresh TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        next_auto_boost_at TIMESTAMP NULL,
        views_last_30_days INT DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        expires_at TIMESTAMP DEFAULT (CURRENT_TIMESTAMP + INTERVAL 30 DAY),
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
        INDEX idx_user (user_id),
        INDEX idx_status (status),
        INDEX idx_city (city),
        INDEX idx_type (type),
        INDEX idx_category (category_id),
        INDEX idx_condition (condition),
        INDEX idx_has_warranty (has_warranty),
        INDEX idx_has_delivery (has_delivery),
        INDEX idx_is_sealed (is_sealed),
        INDEX idx_price (price),
        INDEX idx_created_at (created_at),
        INDEX idx_expires (expires_at),
        INDEX idx_category_status (category_id, status),
        INDEX idx_city_status (city, status),
        INDEX idx_next_auto_boost (next_auto_boost_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
");

$db->query("
    CREATE TABLE IF NOT EXISTS listing_photos (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        listing_id BIGINT UNSIGNED NOT NULL,
        url TEXT NOT NULL,
        sort_order INT DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (listing_id) REFERENCES listings(id) ON DELETE CASCADE,
        INDEX idx_listing (listing_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
");

$db->query("
    CREATE TABLE IF NOT EXISTS listing_views (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        listing_id BIGINT UNSIGNED NOT NULL,
        user_id BIGINT UNSIGNED NULL,
        viewed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_listing_id (listing_id),
        INDEX idx_viewed_at (viewed_at),
        FOREIGN KEY (listing_id) REFERENCES listings(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
");

$db->query("
    CREATE TABLE IF NOT EXISTS offers (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        listing_id BIGINT UNSIGNED NOT NULL,
        user_id BIGINT UNSIGNED NOT NULL,
        discount_percent INT NOT NULL,
        status ENUM('active','accepted','expired') DEFAULT 'active',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY unique_offer (listing_id, user_id),
        FOREIGN KEY (listing_id) REFERENCES listings(id) ON DELETE CASCADE,
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
        INDEX idx_listing_status (listing_id, status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
");

$db->query("
    CREATE TABLE IF NOT EXISTS slots (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        listing_id BIGINT UNSIGNED NOT NULL,
        start_time TIMESTAMP NOT NULL,
        end_time TIMESTAMP NOT NULL,
        buyer_id BIGINT UNSIGNED,
        status ENUM('free','pending','confirmed','expired') DEFAULT 'free',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        confirmed_at TIMESTAMP NULL,
        FOREIGN KEY (listing_id) REFERENCES listings(id) ON DELETE CASCADE,
        INDEX idx_listing_status (listing_id, status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
");

$db->query("
    CREATE TABLE IF NOT EXISTS offer_notifications (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        listing_id BIGINT UNSIGNED NOT NULL,
        last_best_discount INT,
        last_notified_at TIMESTAMP NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY unique_listing (listing_id),
        FOREIGN KEY (listing_id) REFERENCES listings(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
");

// ==================== ВСПОМОГАТЕЛЬНЫЕ ФУНКЦИИ ====================

function invalidateListingCache($listingId) {
    $db = Database::getInstance();
    $db->cacheDelete("listing:{$listingId}");
    $db->cacheDelete("listings_count:*");
    $db->cacheDelete("listings_page:*");
    $db->cacheDelete("unified_search:*");
}

function getListingPhotos($pdo, $listingId) {
    $stmt = $pdo->prepare("SELECT id, url, sort_order FROM listing_photos WHERE listing_id = ? ORDER BY sort_order");
    $stmt->execute([$listingId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function getOffersSummary($pdo, $listingId) {
    $stmt = $pdo->prepare("
        SELECT discount_percent, COUNT(*) as cnt
        FROM offers
        WHERE listing_id = ? AND status = 'active'
        GROUP BY discount_percent
    ");
    $stmt->execute([$listingId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $summary = [5 => 0, 10 => 0, 15 => 0, 20 => 0];
    foreach ($rows as $row) {
        $summary[(int)$row['discount_percent']] = (int)$row['cnt'];
    }
    return $summary;
}

function notifyStatusChange($userId, $listingId, $status, $comment = null) {
    $notify = new NotificationService();
    if ($status === 'approved') {
        $notify->send($userId, 'listing_approved', [
            'listing_id' => $listingId,
            'link' => "/listing/$listingId"
        ]);
    } elseif ($status === 'rejected') {
        $notify->send($userId, 'listing_rejected', [
            'listing_id' => $listingId,
            'reason' => $comment ?: 'Объявление не прошло модерацию'
        ]);
    }
}

// ==================== ФУНКЦИЯ ДЛЯ ОБЪЕДИНЁННОГО ПОИСКА ====================

function buildUnifiedQuery($db, $pdo, $input, $userId, $action) {
    $page = max(1, (int)($input['page'] ?? 1));
    $limit = min(50, max(1, (int)($input['limit'] ?? 20)));
    $offset = ($page - 1) * $limit;
    $includeShops = isset($input['include_shops']) && $input['include_shops'] == 1;
    $searchQuery = trim($input['query'] ?? '');
    $type = $input['type'] ?? '';
    $categoryId = isset($input['category_id']) ? (int)$input['category_id'] : 0;
    $city = trim($input['city'] ?? '');
    $minPrice = isset($input['min_price']) ? (float)$input['min_price'] : 0;
    $maxPrice = isset($input['max_price']) ? (float)$input['max_price'] : 0;
    $condition = $input['condition'] ?? '';
    $hasWarranty = isset($input['has_warranty']) && $input['has_warranty'];
    $hasDelivery = isset($input['has_delivery']) && $input['has_delivery'];
    $isSealed = isset($input['is_sealed']) && $input['is_sealed'];
    $sortField = $input['sort'] ?? 'next_auto_boost_at';
    $sortDir = strtoupper($input['order'] ?? 'DESC') === 'ASC' ? 'ASC' : 'DESC';

    $allowedSort = ['created_at', 'price', 'views', 'next_auto_boost_at'];
    if (!in_array($sortField, $allowedSort)) $sortField = 'next_auto_boost_at';

    $results = [];
    $total = 0;

    // 1. Запрос из listings (частные объявления)
    $listingWhere = ["l.status IN ('approved', 'featured')"];
    $listingParams = [];

    if ($type && in_array($type, ['sell', 'wanted', 'resume', 'service'])) {
        $listingWhere[] = "l.type = ?";
        $listingParams[] = $type;
    }
    if ($categoryId) {
        $listingWhere[] = "l.category_id = ?";
        $listingParams[] = $categoryId;
    }
    if ($city) {
        $listingWhere[] = "l.city ILIKE ?";
        $listingParams[] = '%' . $city . '%';
    }
    if ($minPrice) {
        $listingWhere[] = "l.price >= ?";
        $listingParams[] = $minPrice;
    }
    if ($maxPrice) {
        $listingWhere[] = "l.price <= ?";
        $listingParams[] = $maxPrice;
    }
    if ($condition && in_array($condition, ['new', 'like_new', 'used'])) {
        $listingWhere[] = "l.condition = ?";
        $listingParams[] = $condition;
    }
    if ($hasWarranty) $listingWhere[] = "l.has_warranty = TRUE";
    if ($hasDelivery) $listingWhere[] = "l.has_delivery = TRUE";
    if ($isSealed) $listingWhere[] = "l.is_sealed = TRUE";

    if ($searchQuery) {
        $searchWords = explode(' ', $searchQuery);
        $searchCondition = [];
        foreach ($searchWords as $word) {
            if (strlen($word) > 2) {
                $searchCondition[] = "(MATCH(l.title, l.description) AGAINST(? IN BOOLEAN MODE))";
                $listingParams[] = '+' . $word . '*';
            }
        }
        if (!empty($searchCondition)) {
            $listingWhere[] = '(' . implode(' OR ', $searchCondition) . ')';
        }
    }

    $listingWhereClause = 'WHERE ' . implode(' AND ', $listingWhere);
    $listingCountSql = "SELECT COUNT(*) FROM listings l $listingWhereClause";
    $listingTotal = $db->fetchCount($listingCountSql, $listingParams);

    // Сортировка для listings
    $listingOrder = match($sortField) {
        'price' => "l.price $sortDir",
        'views' => "l.views $sortDir",
        'created_at' => "l.created_at $sortDir",
        default => "COALESCE(l.next_auto_boost_at, l.created_at) $sortDir"
    };

    $listingSql = "
        SELECT
            'listing' as source,
            l.id,
            l.user_id,
            l.type,
            l.category_id,
            l.title,
            l.description,
            l.price,
            l.condition,
            l.city,
            l.created_at,
            l.views,
            l.has_warranty,
            l.has_delivery,
            l.is_sealed,
            (SELECT url FROM listing_photos WHERE listing_id = l.id ORDER BY sort_order LIMIT 1) as image_url,
            NULL as shop_name,
            NULL as shop_slug,
            NULL as shop_logo,
            NULL as rating,
            NULL as review_count
        FROM listings l
        $listingWhereClause
        ORDER BY $listingOrder
    ";
    $listingStmt = $pdo->prepare($listingSql);
    $listingStmt->execute($listingParams);
    $listingResults = $listingStmt->fetchAll(PDO::FETCH_ASSOC);

    $results = array_merge($results, $listingResults);
    $total += $listingTotal;

    // 2. Запрос из shop_products (товары магазинов)
    if ($includeShops) {
        $productWhere = ["p.is_active = 1", "s.is_active = 1"];
        $productParams = [];

        if ($categoryId) {
            $productWhere[] = "p.category_id = ?";
            $productParams[] = $categoryId;
        }
        if ($city) {
            $productWhere[] = "s.city ILIKE ?";
            $productParams[] = '%' . $city . '%';
        }
        if ($minPrice) {
            $productWhere[] = "p.price >= ?";
            $productParams[] = $minPrice;
        }
        if ($maxPrice) {
            $productWhere[] = "p.price <= ?";
            $productParams[] = $maxPrice;
        }
        if ($condition && in_array($condition, ['new', 'like_new', 'used'])) {
            $productWhere[] = "p.condition = ?";
            $productParams[] = $condition;
        }
        if ($searchQuery) {
            $productWhere[] = "(p.title LIKE ? OR p.description LIKE ?)";
            $searchTerm = '%' . $searchQuery . '%';
            $productParams[] = $searchTerm;
            $productParams[] = $searchTerm;
        }

        $productWhereClause = 'WHERE ' . implode(' AND ', $productWhere);
        $productCountSql = "
            SELECT COUNT(*)
            FROM shop_products p
            JOIN shops s ON p.shop_id = s.id
            $productWhereClause
        ";
        $productTotal = $db->fetchCount($productCountSql, $productParams);

        // Сортировка для товаров магазинов (для next_auto_boost_at используем created_at как аналог)
        $productOrder = match($sortField) {
            'price' => "p.price $sortDir",
            'views' => "p.views $sortDir",
            'next_auto_boost_at' => "p.created_at $sortDir",
            default => "p.created_at $sortDir"
        };

        $productSql = "
            SELECT
                'shop_product' as source,
                p.id,
                s.user_id,
                'sell' as type,
                p.category_id,
                p.title,
                p.description,
                p.price,
                p.condition,
                s.city,
                p.created_at,
                p.views,
                NULL as has_warranty,
                NULL as has_delivery,
                NULL as is_sealed,
                (CASE
                    WHEN p.image_urls IS NOT NULL AND JSON_LENGTH(p.image_urls) > 0
                    THEN JSON_UNQUOTE(JSON_EXTRACT(p.image_urls, '$[0]'))
                    ELSE NULL
                END) as image_url,
                s.name as shop_name,
                s.slug as shop_slug,
                s.logo_url as shop_logo,
                COALESCE(AVG(r.rating), 0) as rating,
                COUNT(r.id) as review_count
            FROM shop_products p
            JOIN shops s ON p.shop_id = s.id
            LEFT JOIN shop_reviews r ON p.id = r.product_id AND r.is_approved = 1
            $productWhereClause
            GROUP BY p.id
            ORDER BY $productOrder
        ";
        $productStmt = $pdo->prepare($productSql);
        $productStmt->execute($productParams);
        $productResults = $productStmt->fetchAll(PDO::FETCH_ASSOC);

        $results = array_merge($results, $productResults);
        $total += $productTotal;
    }

    // Сортировка объединённых результатов
    usort($results, function($a, $b) use ($sortField, $sortDir) {
        $valA = $a[$sortField] ?? null;
        $valB = $b[$sortField] ?? null;
        if ($sortField === 'price') {
            $valA = (float)$valA;
            $valB = (float)$valB;
        }
        if ($sortDir === 'ASC') {
            return $valA <=> $valB;
        } else {
            return $valB <=> $valA;
        }
    });

    // Пагинация
    $paginated = array_slice($results, $offset, $limit);

    return [
        'success' => true,
        'data' => $paginated,
        'meta' => [
            'total' => $total,
            'page' => $page,
            'limit' => $limit,
            'pages' => ceil($total / $limit)
        ]
    ];
}

// ==================== ОБРАБОТКА ДЕЙСТВИЙ ====================

switch ($action) {
    // ---------- СОЗДАНИЕ (с идемпотентностью) ----------
    case 'create':
        $idempotencyKey = $input['idempotency_key'] ?? '';
        if ($idempotencyKey) {
            $cacheKey = "idempotent:create:{$userId}:{$idempotencyKey}";
            $cached = $db->cacheGet($cacheKey);
            if ($cached !== null) {
                echo $cached;
                exit;
            }
        }

        $type = $input['type'] ?? 'sell';
        if (!in_array($type, ['sell', 'wanted', 'resume', 'service'])) {
            http_response_code(400);
            echo json_encode(['success' => false, 'code' => 'INVALID_TYPE', 'error' => 'Неверный тип объявления']);
            exit;
        }

        $categoryId = isset($input['category_id']) ? (int)$input['category_id'] : null;
        $title = trim($input['title'] ?? '');
        $description = trim($input['description'] ?? '');
        $price = isset($input['price']) ? (float)$input['price'] : null;
        $priceType = $input['price_type'] ?? 'fixed';
        $condition = $input['condition'] ?? 'used';
        $address = trim($input['address'] ?? '');
        $city = trim($input['city'] ?? '');
        $customFields = $input['custom_fields'] ?? null;
        $lat = isset($input['lat']) ? (float)$input['lat'] : null;
        $lng = isset($input['lng']) ? (float)$input['lng'] : null;
        $hasWarranty = !empty($input['has_warranty']);
        $hasDelivery = !empty($input['has_delivery']);
        $bookingSettings = $input['booking_settings'] ?? null;
        $minOfferPercent = isset($input['min_offer_percent']) ? (int)$input['min_offer_percent'] : null;
        $isSealed = !empty($input['is_sealed']);
        $gift = $input['gift'] ?? null;

        // Валидация для продажи (сокращённо, но полная)
        if ($type === 'sell') {
            if (empty($title) || mb_strlen($title) < 5) {
                http_response_code(400);
                echo json_encode(['success' => false, 'code' => 'TITLE_TOO_SHORT', 'error' => 'Заголовок должен содержать минимум 5 символов']);
                exit;
            }
            if (empty($description) || mb_strlen($description) < 10) {
                http_response_code(400);
                echo json_encode(['success' => false, 'code' => 'DESCRIPTION_TOO_SHORT', 'error' => 'Описание должно содержать минимум 10 символов']);
                exit;
            }
            if (!$categoryId) {
                http_response_code(400);
                echo json_encode(['success' => false, 'code' => 'CATEGORY_REQUIRED', 'error' => 'Категория обязательна']);
                exit;
            }
            if ($price === null || $price <= 0) {
                http_response_code(400);
                echo json_encode(['success' => false, 'code' => 'INVALID_PRICE', 'error' => 'Цена обязательна и должна быть больше 0']);
                exit;
            }
        }
        // Остальные типы – аналогично (в финальной версии должны быть полные проверки)

        // Геокодинг с кэшем (GeoService уже кэширует)
        if (!empty($address) && ($lat === null || $lng === null)) {
            $coords = $geo->getAddressCoordinates($address);
            if ($coords) {
                $lat = $coords['lat'];
                $lng = $coords['lng'];
            }
        }

        // Вычисляем next_auto_boost_at: по умолчанию через 10 дней (неактивное)
        $nextBoost = date('Y-m-d H:i:s', strtotime('+10 days'));

        $stmt = $pdo->prepare("
            INSERT INTO listings (user_id, type, category_id, title, description, price, price_type, condition,
                                  address, lat, lng, city, custom_fields, has_warranty, has_delivery, booking_settings,
                                  min_offer_percent, is_sealed, gift, next_auto_boost_at, created_at, updated_at, expires_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW(), NOW() + INTERVAL 30 DAY)
            RETURNING id
        ");
        $stmt->execute([
            $userId,
            $type,
            $categoryId,
            $title,
            $description,
            $price,
            $priceType,
            $condition,
            $address ?: null,
            $lat,
            $lng,
            $city ?: null,
            $customFields ? json_encode($customFields) : null,
            $hasWarranty ? 1 : 0,
            $hasDelivery ? 1 : 0,
            $bookingSettings ? json_encode($bookingSettings) : null,
            $minOfferPercent,
            $isSealed ? 1 : 0,
            $gift ? json_encode($gift) : null,
            $nextBoost
        ]);
        $listingId = $stmt->fetchColumn();

        // Логирование
        $db->insert('security_logs', [
            'user_id' => $userId,
            'ip_address' => $ip,
            'event_type' => 'listing_created',
            'description' => "Создано объявление #$listingId типа $type",
            'severity' => 'low',
            'created_at' => date('Y-m-d H:i:s')
        ]);

        // Инвалидация кэша списков
        $db->cacheDelete("listings_count:*");
        $db->cacheDelete("listings_page:*");
        $db->cacheDelete("unified_search:*");

        $response = json_encode([
            'success' => true,
            'listing_id' => $listingId,
            'message' => 'Объявление создано и отправлено на модерацию'
        ]);
        if ($idempotencyKey) {
            $db->cacheSet("idempotent:create:{$userId}:{$idempotencyKey}", $response, 86400);
        }
        echo $response;
        break;

    // ---------- ОБНОВЛЕНИЕ ----------
    case 'update':
        $listingId = isset($input['listing_id']) ? (int)$input['listing_id'] : 0;
        if (!$listingId) {
            http_response_code(400);
            echo json_encode(['success' => false, 'code' => 'MISSING_ID', 'error' => 'Не указан ID объявления']);
            exit;
        }

        $listing = $db->fetchOne("SELECT * FROM listings WHERE id = ? AND user_id = ?", [$listingId, $userId]);
        if (!$listing) {
            http_response_code(404);
            echo json_encode(['success' => false, 'code' => 'NOT_FOUND', 'error' => 'Объявление не найдено или доступ запрещён']);
            exit;
        }

        $updateData = [];
        if (isset($input['title'])) $updateData['title'] = trim($input['title']);
        if (isset($input['description'])) $updateData['description'] = trim($input['description']);
        if (isset($input['price'])) $updateData['price'] = (float)$input['price'];
        if (isset($input['price_type'])) $updateData['price_type'] = $input['price_type'];
        if (isset($input['condition'])) $updateData['condition'] = $input['condition'];
        if (isset($input['address'])) $updateData['address'] = trim($input['address']);
        if (isset($input['city'])) $updateData['city'] = trim($input['city']);
        if (isset($input['category_id'])) $updateData['category_id'] = (int)$input['category_id'];
        if (isset($input['custom_fields'])) $updateData['custom_fields'] = json_encode($input['custom_fields']);
        if (isset($input['has_warranty'])) $updateData['has_warranty'] = $input['has_warranty'] ? 1 : 0;
        if (isset($input['has_delivery'])) $updateData['has_delivery'] = $input['has_delivery'] ? 1 : 0;
        if (isset($input['booking_settings'])) $updateData['booking_settings'] = json_encode($input['booking_settings']);
        if (isset($input['min_offer_percent'])) {
            $val = (int)$input['min_offer_percent'];
            if ($val !== null && ($val < 50 || $val > 100)) {
                http_response_code(400);
                echo json_encode(['success' => false, 'code' => 'INVALID_MIN_OFFER', 'error' => 'Минимальный процент предложения должен быть между 50 и 100']);
                exit;
            }
            $updateData['min_offer_percent'] = $val;
        }
        if (isset($input['is_sealed'])) $updateData['is_sealed'] = $input['is_sealed'] ? 1 : 0;
        if (isset($input['gift'])) {
            $gift = $input['gift'];
            if ($gift !== null) {
                if (!isset($gift['description']) || !isset($gift['limit']) || !isset($gift['expires_at'])) {
                    http_response_code(400);
                    echo json_encode(['success' => false, 'code' => 'INVALID_GIFT', 'error' => 'Подарок должен содержать description, limit и expires_at']);
                    exit;
                }
                $gift['limit'] = (int)$gift['limit'];
                $gift['claimed'] = 0;
            }
            $updateData['gift'] = $gift ? json_encode($gift) : null;
        }

        // Геокодинг при изменении адреса
        if (isset($input['address']) && !isset($input['lat']) && !isset($input['lng'])) {
            $coords = $geo->getAddressCoordinates($input['address']);
            if ($coords) {
                $updateData['lat'] = $coords['lat'];
                $updateData['lng'] = $coords['lng'];
            }
        } elseif (isset($input['lat']) && isset($input['lng'])) {
            $updateData['lat'] = (float)$input['lat'];
            $updateData['lng'] = (float)$input['lng'];
        }

        // При обновлении меняем статус на pending для модерации
        $updateData['status'] = 'pending';
        $updateData['moderation_comment'] = null;
        $updateData['updated_at'] = date('Y-m-d H:i:s');

        if (empty($updateData)) {
            echo json_encode(['success' => true, 'message' => 'Нет изменений']);
            exit;
        }

        $db->update('listings', $updateData, 'id = ?', [$listingId]);
        invalidateListingCache($listingId);
        $db->cacheDelete("listings_count:*");
        $db->cacheDelete("listings_page:*");
        $db->cacheDelete("unified_search:*");

        echo json_encode(['success' => true, 'message' => 'Объявление обновлено']);
        break;

    // ---------- УДАЛЕНИЕ ----------
    case 'delete':
        $listingId = isset($input['listing_id']) ? (int)$input['listing_id'] : 0;
        if (!$listingId) {
            http_response_code(400);
            echo json_encode(['success' => false, 'code' => 'MISSING_ID', 'error' => 'Не указан ID объявления']);
            exit;
        }

        $user = $db->fetchOne("SELECT role FROM users WHERE id = ?", [$userId]);
        $listing = $db->fetchOne("SELECT user_id, category_id, city FROM listings WHERE id = ?", [$listingId]);
        if (!$listing) {
            http_response_code(404);
            echo json_encode(['success' => false, 'code' => 'NOT_FOUND', 'error' => 'Объявление не найдено']);
            exit;
        }
        if ($listing['user_id'] != $userId && (!$user || $user['role'] !== 'admin')) {
            http_response_code(403);
            echo json_encode(['success' => false, 'code' => 'FORBIDDEN', 'error' => 'Доступ запрещён']);
            exit;
        }

        $db->delete('listings', 'id = ?', [$listingId]);
        invalidateListingCache($listingId);
        $db->cacheDelete("listings_count:*");
        $db->cacheDelete("listings_page:*");
        $db->cacheDelete("unified_search:*");

        echo json_encode(['success' => true, 'message' => 'Объявление удалено']);
        break;

    // ---------- ПОЛУЧЕНИЕ (с кэшем) ----------
    case 'get':
        $listingId = isset($input['listing_id']) ? (int)$input['listing_id'] : 0;
        if (!$listingId) {
            http_response_code(400);
            echo json_encode(['success' => false, 'code' => 'MISSING_ID', 'error' => 'Не указан ID объявления']);
            exit;
        }

        $cacheKey = "listing:{$listingId}";
        $listing = $db->cacheRemember($cacheKey, 300, function() use ($db, $pdo, $listingId) {
            $listing = $db->fetchOne("
                SELECT l.*, u.name as user_name, u.email as user_email, u.phone as user_phone,
                       c.name as category_name
                FROM listings l
                LEFT JOIN users u ON l.user_id = u.id
                LEFT JOIN listing_categories c ON l.category_id = c.id
                WHERE l.id = ? AND l.status IN ('approved', 'featured')
            ", [$listingId]);
            if (!$listing) return null;
            $listing['photos'] = getListingPhotos($pdo, $listingId);
            if ($listing['type'] === 'sell') {
                $listing['offers_summary'] = getOffersSummary($pdo, $listingId);
            }
            if ($listing['gift']) {
                $gift = json_decode($listing['gift'], true);
                if (strtotime($gift['expires_at']) > time()) {
                    $listing['gift'] = [
                        'description' => $gift['description'],
                        'remaining' => $gift['limit'] - ($gift['claimed'] ?? 0)
                    ];
                } else {
                    $listing['gift'] = null;
                }
            }
            return $listing;
        });

        if (!$listing) {
            http_response_code(404);
            echo json_encode(['success' => false, 'code' => 'NOT_FOUND', 'error' => 'Объявление не найдено или не опубликовано']);
            exit;
        }

        // Увеличиваем счётчик просмотров и логируем в listing_views
        $db->query("UPDATE listings SET views = views + 1 WHERE id = ?", [$listingId]);
        $viewUserId = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null;
        $db->query("INSERT INTO listing_views (listing_id, user_id, viewed_at) VALUES (?, ?, NOW())", [$listingId, $viewUserId]);

        echo json_encode(['success' => true, 'data' => $listing]);
        break;

    // ---------- СПИСОК (с поддержкой shop_products) ----------
    case 'list':
        $includeShops = isset($input['include_shops']) && $input['include_shops'] == 1;

        if ($includeShops) {
            $cacheKey = "unified_search:" . md5(json_encode($input));
            $cached = $db->cacheGet($cacheKey, 120);
            if ($cached !== null) {
                echo $cached;
                exit;
            }
            $result = buildUnifiedQuery($db, $pdo, $input, $userId, $action);
            $db->cacheSet($cacheKey, json_encode($result), 120);
            echo json_encode($result);
        } else {
            $page = max(1, (int)($input['page'] ?? 1));
            $limit = min(50, max(1, (int)($input['limit'] ?? 20)));
            $offset = ($page - 1) * $limit;

            $where = ["l.status IN ('approved', 'featured')"];
            $params = [];

            if (!empty($input['type']) && in_array($input['type'], ['sell', 'wanted', 'resume', 'service'])) {
                $where[] = "l.type = ?";
                $params[] = $input['type'];
            }
            if (!empty($input['category_id'])) {
                $where[] = "l.category_id = ?";
                $params[] = (int)$input['category_id'];
            }
            if (!empty($input['city'])) {
                $where[] = "l.city ILIKE ?";
                $params[] = '%' . $input['city'] . '%';
            }
            if (!empty($input['min_price'])) {
                $where[] = "l.price >= ?";
                $params[] = (float)$input['min_price'];
            }
            if (!empty($input['max_price'])) {
                $where[] = "l.price <= ?";
                $params[] = (float)$input['max_price'];
            }
            if (!empty($input['condition']) && in_array($input['condition'], ['new', 'like_new', 'used'])) {
                $where[] = "l.condition = ?";
                $params[] = $input['condition'];
            }
            if (isset($input['user_id'])) {
                $where[] = "l.user_id = ?";
                $params[] = (int)$input['user_id'];
            }
            if (isset($input['has_warranty']) && $input['has_warranty']) $where[] = "l.has_warranty = TRUE";
            if (isset($input['has_delivery']) && $input['has_delivery']) $where[] = "l.has_delivery = TRUE";
            if (isset($input['is_sealed']) && $input['is_sealed']) $where[] = "l.is_sealed = TRUE";

            $whereClause = 'WHERE ' . implode(' AND ', $where);
            $countCacheKey = "listings_count:" . md5($whereClause . json_encode($params));
            $total = $db->cacheRemember($countCacheKey, 300, function() use ($db, $whereClause, $params) {
                return $db->fetchCount("SELECT COUNT(*) FROM listings l $whereClause", $params);
            });

            $allowedSort = ['created_at', 'price', 'views', 'next_auto_boost_at'];
            $sortField = $input['sort'] ?? 'next_auto_boost_at';
            if (!in_array($sortField, $allowedSort)) $sortField = 'next_auto_boost_at';
            $sortDir = strtoupper($input['order'] ?? 'DESC') === 'ASC' ? 'ASC' : 'DESC';

            $sql = "
                SELECT l.id, l.type, l.title, l.price, l.price_type, l.condition, l.city, l.created_at, l.views,
                       l.has_warranty, l.has_delivery, l.is_sealed,
                       (SELECT url FROM listing_photos WHERE listing_id = l.id ORDER BY sort_order LIMIT 1) as photo
                FROM listings l
                $whereClause
                ORDER BY l.{$sortField} {$sortDir}
                LIMIT ? OFFSET ?
            ";
            $queryParams = array_merge($params, [$limit, $offset]);
            $listings = $db->fetchAll($sql, $queryParams);

            echo json_encode([
                'success' => true,
                'data' => $listings,
                'meta' => [
                    'total' => $total,
                    'page' => $page,
                    'limit' => $limit,
                    'pages' => ceil($total / $limit)
                ]
            ]);
        }
        break;

    // ---------- ПОИСК (с поддержкой shop_products) ----------
    case 'search':
        $includeShops = isset($input['include_shops']) && $input['include_shops'] == 1;
        $query = trim($input['query'] ?? '');

        if (empty($query) && $includeShops) {
            // Если нет запроса, но нужны товары магазинов – используем list с include_shops
            $input['action'] = 'list';
            $input['include_shops'] = 1;
            $result = buildUnifiedQuery($db, $pdo, $input, $userId, 'list');
            echo json_encode($result);
            break;
        }

        if ($includeShops) {
            $cacheKey = "unified_search:" . md5(json_encode($input));
            $cached = $db->cacheGet($cacheKey, 120);
            if ($cached !== null) {
                echo $cached;
                exit;
            }
            $result = buildUnifiedQuery($db, $pdo, $input, $userId, $action);
            $db->cacheSet($cacheKey, json_encode($result), 120);
            echo json_encode($result);
        } else {
            if (empty($query)) {
                echo json_encode(['success' => true, 'data' => [], 'meta' => ['total' => 0]]);
                exit;
            }

            $page = max(1, (int)($input['page'] ?? 1));
            $limit = min(50, max(1, (int)($input['limit'] ?? 20)));
            $offset = ($page - 1) * $limit;

            $where = ["l.status IN ('approved', 'featured')"];
            $params = [];
            if (!empty($input['type']) && in_array($input['type'], ['sell', 'wanted', 'resume', 'service'])) {
                $where[] = "l.type = ?";
                $params[] = $input['type'];
            }
            if (!empty($input['category_id'])) {
                $where[] = "l.category_id = ?";
                $params[] = (int)$input['category_id'];
            }
            if (!empty($input['city'])) {
                $where[] = "l.city ILIKE ?";
                $params[] = '%' . $input['city'] . '%';
            }
            if (isset($input['has_warranty']) && $input['has_warranty']) $where[] = "l.has_warranty = TRUE";
            if (isset($input['has_delivery']) && $input['has_delivery']) $where[] = "l.has_delivery = TRUE";
            if (isset($input['is_sealed']) && $input['is_sealed']) $where[] = "l.is_sealed = TRUE";

            $searchWords = explode(' ', $query);
            $searchCondition = [];
            foreach ($searchWords as $word) {
                if (strlen($word) > 2) {
                    $searchCondition[] = "(MATCH(l.title, l.description) AGAINST(? IN BOOLEAN MODE))";
                    $params[] = '+' . $word . '*';
                }
            }
            if (!empty($searchCondition)) {
                $where[] = '(' . implode(' OR ', $searchCondition) . ')';
            } else {
                echo json_encode(['success' => true, 'data' => [], 'meta' => ['total' => 0]]);
                exit;
            }

            $whereClause = 'WHERE ' . implode(' AND ', $where);
            $countCacheKey = "listings_count:" . md5($whereClause . json_encode($params));
            $total = $db->cacheRemember($countCacheKey, 300, function() use ($db, $whereClause, $params) {
                return $db->fetchCount("SELECT COUNT(*) FROM listings l $whereClause", $params);
            });

            $allowedSort = ['created_at', 'price', 'views', 'next_auto_boost_at'];
            $sortField = $input['sort'] ?? 'next_auto_boost_at';
            if (!in_array($sortField, $allowedSort)) $sortField = 'next_auto_boost_at';
            $sortDir = strtoupper($input['order'] ?? 'DESC') === 'ASC' ? 'ASC' : 'DESC';

            $sql = "
                SELECT l.id, l.type, l.title, l.price, l.price_type, l.condition, l.city, l.created_at, l.views,
                       l.has_warranty, l.has_delivery, l.is_sealed,
                       (SELECT url FROM listing_photos WHERE listing_id = l.id ORDER BY sort_order LIMIT 1) as photo
                FROM listings l
                $whereClause
                ORDER BY l.{$sortField} {$sortDir}
                LIMIT ? OFFSET ?
            ";
            $queryParams = array_merge($params, [$limit, $offset]);
            $listings = $db->fetchAll($sql, $queryParams);

            echo json_encode([
                'success' => true,
                'data' => $listings,
                'meta' => [
                    'total' => $total,
                    'page' => $page,
                    'limit' => $limit,
                    'pages' => ceil($total / $limit)
                ]
            ]);
        }
        break;

    // ---------- ОТМЕТКА О ПРОДАЖЕ ----------
    case 'mark_sold':
        $listingId = isset($input['listing_id']) ? (int)$input['listing_id'] : 0;
        if (!$listingId) {
            http_response_code(400);
            echo json_encode(['success' => false, 'code' => 'MISSING_ID', 'error' => 'Не указан ID объявления']);
            exit;
        }

        $user = $db->fetchOne("SELECT role FROM users WHERE id = ?", [$userId]);
        $listing = $db->fetchOne("SELECT user_id, status, category_id, city FROM listings WHERE id = ?", [$listingId]);
        if (!$listing) {
            http_response_code(404);
            echo json_encode(['success' => false, 'code' => 'NOT_FOUND', 'error' => 'Объявление не найдено']);
            exit;
        }
        if ($listing['user_id'] != $userId && (!$user || $user['role'] !== 'admin')) {
            http_response_code(403);
            echo json_encode(['success' => false, 'code' => 'FORBIDDEN', 'error' => 'Доступ запрещён']);
            exit;
        }
        if ($listing['status'] === 'archived') {
            echo json_encode(['success' => true, 'message' => 'Объявление уже отмечено как проданное']);
            exit;
        }

        $db->update('listings', ['status' => 'archived', 'updated_at' => date('Y-m-d H:i:s')], 'id = ?', [$listingId]);
        invalidateListingCache($listingId);
        $db->cacheDelete("listings_count:*");
        $db->cacheDelete("listings_page:*");
        $db->cacheDelete("unified_search:*");

        echo json_encode(['success' => true, 'message' => 'Объявление отмечено как проданное']);
        break;

    // ---------- ЗАБРАТЬ ПОДАРОК ----------
    case 'claim_gift':
        $listingId = isset($input['listing_id']) ? (int)$input['listing_id'] : 0;
        if (!$listingId) {
            http_response_code(400);
            echo json_encode(['success' => false, 'code' => 'MISSING_ID', 'error' => 'Не указан ID объявления']);
            exit;
        }

        $listing = $db->fetchOne("SELECT user_id, gift, status FROM listings WHERE id = ?", [$listingId]);
        if (!$listing) {
            http_response_code(404);
            echo json_encode(['success' => false, 'code' => 'NOT_FOUND', 'error' => 'Объявление не найдено']);
            exit;
        }
        if ($listing['user_id'] == $userId) {
            http_response_code(400);
            echo json_encode(['success' => false, 'code' => 'OWN_GIFT', 'error' => 'Вы не можете забрать свой собственный подарок']);
            exit;
        }
        if ($listing['status'] !== 'approved') {
            http_response_code(400);
            echo json_encode(['success' => false, 'code' => 'INACTIVE', 'error' => 'Объявление не активно']);
            exit;
        }
        if (!$listing['gift']) {
            http_response_code(404);
            echo json_encode(['success' => false, 'code' => 'GIFT_NOT_FOUND', 'error' => 'Подарок не найден']);
            exit;
        }
        $gift = json_decode($listing['gift'], true);
        if (strtotime($gift['expires_at']) < time()) {
            http_response_code(400);
            echo json_encode(['success' => false, 'code' => 'GIFT_EXPIRED', 'error' => 'Срок действия подарка истёк']);
            exit;
        }
        $claimed = $gift['claimed'] ?? 0;
        if ($claimed >= $gift['limit']) {
            http_response_code(400);
            echo json_encode(['success' => false, 'code' => 'GIFT_EXHAUSTED', 'error' => 'Подарок уже разобран']);
            exit;
        }

        $claimed++;
        $gift['claimed'] = $claimed;
        $db->update('listings', ['gift' => json_encode($gift)], 'id = ?', [$listingId]);

        echo json_encode(['success' => true, 'message' => 'Вы забрали подарок!', 'gift' => $gift['description']]);
        break;

    // ---------- СВОДКА ПРЕДЛОЖЕНИЙ (для покупателя) ----------
    case 'get_offers_summary':
        $listingId = isset($input['listing_id']) ? (int)$input['listing_id'] : 0;
        if (!$listingId) {
            http_response_code(400);
            echo json_encode(['success' => false, 'code' => 'MISSING_ID', 'error' => 'Не указан ID объявления']);
            exit;
        }
        $summary = getOffersSummary($pdo, $listingId);
        echo json_encode(['success' => true, 'summary' => $summary]);
        break;

    // ---------- ПРЕДЛОЖЕНИЯ ДЛЯ ПРОДАВЦА (с пагинацией) ----------
    case 'get_seller_offers':
        $listingId = isset($input['listing_id']) ? (int)$input['listing_id'] : 0;
        if (!$listingId) {
            http_response_code(400);
            echo json_encode(['success' => false, 'code' => 'MISSING_ID', 'error' => 'Не указан ID объявления']);
            exit;
        }
        $listing = $db->fetchOne("SELECT user_id FROM listings WHERE id = ?", [$listingId]);
        if (!$listing || $listing['user_id'] != $userId) {
            http_response_code(403);
            echo json_encode(['success' => false, 'code' => 'FORBIDDEN', 'error' => 'Доступ запрещён']);
            exit;
        }
        $page = max(1, (int)($input['page'] ?? 1));
        $limit = 20;
        $offset = ($page - 1) * $limit;
        $offers = $db->fetchAll("
            SELECT id, user_id, discount_percent, created_at
            FROM offers
            WHERE listing_id = ? AND status = 'active'
            ORDER BY discount_percent ASC
            LIMIT ? OFFSET ?
        ", [$listingId, $limit, $offset]);
        $total = $db->fetchCount("SELECT COUNT(*) FROM offers WHERE listing_id = ? AND status = 'active'", [$listingId]);
        echo json_encode([
            'success' => true,
            'data' => $offers,
            'meta' => [
                'page' => $page,
                'limit' => $limit,
                'total' => $total,
                'pages' => ceil($total / $limit)
            ]
        ]);
        break;

    // ---------- ПРИНЯТЬ ПРЕДЛОЖЕНИЕ ----------
    case 'accept_offer':
        $offerId = isset($input['offer_id']) ? (int)$input['offer_id'] : 0;
        $listingId = isset($input['listing_id']) ? (int)$input['listing_id'] : 0;
        if (!$offerId || !$listingId) {
            http_response_code(400);
            echo json_encode(['success' => false, 'code' => 'MISSING_ID', 'error' => 'Не указаны ID предложения и объявления']);
            exit;
        }
        $listing = $db->fetchOne("SELECT user_id, title FROM listings WHERE id = ?", [$listingId]);
        if (!$listing || $listing['user_id'] != $userId) {
            http_response_code(403);
            echo json_encode(['success' => false, 'code' => 'FORBIDDEN', 'error' => 'Доступ запрещён']);
            exit;
        }
        $offer = $db->fetchOne("SELECT * FROM offers WHERE id = ? AND listing_id = ? AND status = 'active'", [$offerId, $listingId]);
        if (!$offer) {
            http_response_code(404);
            echo json_encode(['success' => false, 'code' => 'OFFER_NOT_FOUND', 'error' => 'Предложение не найдено']);
            exit;
        }

        $pdo->beginTransaction();
        try {
            $db->update('offers', ['status' => 'accepted'], 'id = ?', [$offerId]);
            $db->update('listings', ['status' => 'archived'], 'id = ?', [$listingId]);

            $chatId = $listingId . '_' . $offer['user_id'] . '_' . time();
            $db->insert('messages', [
                'chat_id' => $chatId,
                'sender_id' => $userId,
                'receiver_id' => $offer['user_id'],
                'content' => 'Продавец принял ваше предложение!',
                'created_at' => date('Y-m-d H:i:s')
            ]);
            $pdo->commit();

            $notify = new NotificationService();
            $notify->send($offer['user_id'], 'offer_accepted', [
                'listing_id' => $listingId,
                'title' => $listing['title'] ?? '',
                'discount' => $offer['discount_percent']
            ]);

            // Инвалидируем кэш
            invalidateListingCache($listingId);
            $db->cacheDelete("listings_count:*");
            $db->cacheDelete("listings_page:*");
            $db->cacheDelete("unified_search:*");

            echo json_encode(['success' => true, 'message' => 'Предложение принято, покупатель уведомлён']);
        } catch (Exception $e) {
            $pdo->rollBack();
            error_log("Accept offer error: " . $e->getMessage());
            http_response_code(500);
            echo json_encode(['success' => false, 'code' => 'INTERNAL_ERROR', 'error' => 'Ошибка при принятии предложения']);
        }
        break;

    default:
        http_response_code(400);
        echo json_encode(['success' => false, 'code' => 'UNKNOWN_ACTION', 'error' => 'Неизвестное действие']);
        break;
}