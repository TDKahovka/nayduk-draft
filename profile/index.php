<?php
/* ============================================
   НАЙДУК — Профиль пользователя (финальная бизнес-версия)
   Версия 10.0 (апрель 2026)
   - Разделение на обычного пользователя и бизнес-аккаунт
   - Вкладка «Мой бизнес» для предпринимателей (замена старого партнёрского центра)
   - Платные услуги: закрепление, выделение, аукцион, баннеры
   - Управление товарами/услугами (из shop_products)
   - Статистика бизнеса, история платежей
   - Полная интеграция с ЮKassa (интерфейс, вызовы)
   - Безопасность, автосоздание таблиц, адаптивность
   ============================================ */

// ===== БЕЗОПАСНАЯ НАСТРОЙКА СЕССИИ =====
if (session_status() === PHP_SESSION_NONE) {
    $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || $_SERVER['SERVER_PORT'] == 443;
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'domain' => '',
        'secure' => $secure,
        'httponly' => true,
        'samesite' => 'Lax'
    ]);
    session_start();
}

require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../services/Database.php';

if (!isset($_SESSION['user_id'])) {
    $_SESSION['redirect_after_auth'] = '/profile';
    header('Location: /auth/login');
    exit;
}

$db = Database::getInstance();
$userId = (int)$_SESSION['user_id'];
$user = $db->getUserById($userId);
if (!$user || !empty($user['deleted_at'])) {
    session_destroy();
    header('Location: /auth/login');
    exit;
}

// ===== АВТОСОЗДАНИЕ НЕДОСТАЮЩИХ ПОЛЕЙ И ТАБЛИЦ =====
$pdo = $db->getPdo();

// Поля users
$columns = $pdo->query("SHOW COLUMNS FROM users")->fetchAll(PDO::FETCH_COLUMN);
$requiredFields = [
    'phone', 'avatar_url', 'is_partner', 'trust_score', 'notify_email', 'notify_sms',
    'deleted_at', 'delete_token', 'phone_visible', 'telegram', 'whatsapp', 'city',
    'is_business'  // новое поле для бизнес-аккаунта
];
foreach ($requiredFields as $field) {
    if (!in_array($field, $columns)) {
        $type = match($field) {
            'is_partner' => 'BOOLEAN DEFAULT FALSE',
            'trust_score' => 'INT DEFAULT 0',
            'notify_email' => 'BOOLEAN DEFAULT TRUE',
            'notify_sms' => 'BOOLEAN DEFAULT FALSE',
            'deleted_at' => 'TIMESTAMP NULL',
            'delete_token' => 'VARCHAR(255)',
            'phone_visible' => 'BOOLEAN DEFAULT FALSE',
            'telegram' => 'VARCHAR(100)',
            'whatsapp' => 'VARCHAR(100)',
            'city' => 'VARCHAR(255)',
            'is_business' => 'BOOLEAN DEFAULT FALSE',
            default => 'TEXT'
        };
        $pdo->exec("ALTER TABLE users ADD COLUMN $field $type");
    }
}
$isBusiness = !empty($user['is_business']);

// Таблица shop_products (товары/услуги бизнеса)
$pdo->exec("
    CREATE TABLE IF NOT EXISTS shop_products (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        business_user_id BIGINT UNSIGNED NOT NULL,
        title VARCHAR(255) NOT NULL,
        description TEXT,
        price DECIMAL(12,2),
        category_id BIGINT UNSIGNED,
        condition VARCHAR(20) DEFAULT 'new',
        image_urls JSON,
        views INT DEFAULT 0,
        is_active BOOLEAN DEFAULT TRUE,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        FOREIGN KEY (business_user_id) REFERENCES users(id) ON DELETE CASCADE,
        INDEX idx_business (business_user_id),
        INDEX idx_active (is_active)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
");

// Таблица business_subscriptions (подписки бизнес-аккаунтов)
$pdo->exec("
    CREATE TABLE IF NOT EXISTS business_subscriptions (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        user_id BIGINT UNSIGNED NOT NULL,
        plan VARCHAR(50) NOT NULL DEFAULT 'monthly',
        price DECIMAL(12,2) NOT NULL,
        start_date TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        end_date TIMESTAMP NOT NULL,
        is_active BOOLEAN DEFAULT TRUE,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
        INDEX idx_user (user_id),
        INDEX idx_active (is_active)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
");

// Таблица payment_history (история платежей)
$pdo->exec("
    CREATE TABLE IF NOT EXISTS payment_history (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        user_id BIGINT UNSIGNED NOT NULL,
        amount DECIMAL(12,2) NOT NULL,
        currency VARCHAR(3) DEFAULT 'RUB',
        description VARCHAR(255),
        payment_method VARCHAR(50),
        status ENUM('pending','paid','failed','refunded') DEFAULT 'pending',
        external_id VARCHAR(255),
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        paid_at TIMESTAMP NULL,
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
        INDEX idx_user (user_id),
        INDEX idx_status (status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
");

// Таблица business_banners (заявки на баннеры)
$pdo->exec("
    CREATE TABLE IF NOT EXISTS business_banners (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        business_user_id BIGINT UNSIGNED NOT NULL,
        image_url VARCHAR(500) NOT NULL,
        target_url VARCHAR(500),
        city VARCHAR(255),
        start_date TIMESTAMP,
        end_date TIMESTAMP,
        status ENUM('pending','approved','rejected') DEFAULT 'pending',
        admin_comment TEXT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (business_user_id) REFERENCES users(id) ON DELETE CASCADE,
        INDEX idx_user (business_user_id),
        INDEX idx_status (status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
");

// Таблица paid_services (записи о купленных услугах для объявлений)
$pdo->exec("
    CREATE TABLE IF NOT EXISTS paid_services (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        user_id BIGINT UNSIGNED NOT NULL,
        listing_id BIGINT UNSIGNED NOT NULL,
        service_type VARCHAR(50) NOT NULL,
        expires_at TIMESTAMP NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
        FOREIGN KEY (listing_id) REFERENCES listings(id) ON DELETE CASCADE,
        INDEX idx_user (user_id),
        INDEX idx_listing (listing_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
");

// Получаем данные бизнеса, если есть
$business = null;
$subscription = null;
$products = [];
$banners = [];
$payments = [];
if ($isBusiness) {
    // Текущая подписка
    $subscription = $db->fetchOne("
        SELECT * FROM business_subscriptions
        WHERE user_id = ? AND is_active = 1 AND end_date > NOW()
        ORDER BY end_date DESC LIMIT 1
    ", [$userId]);

    // Товары бизнеса
    $products = $db->fetchAll("
        SELECT * FROM shop_products
        WHERE business_user_id = ? AND is_active = 1
        ORDER BY created_at DESC
    ", [$userId]);

    // Заявки на баннеры
    $banners = $db->fetchAll("
        SELECT * FROM business_banners
        WHERE business_user_id = ?
        ORDER BY created_at DESC
    ", [$userId]);

    // История платежей
    $payments = $db->fetchAll("
        SELECT * FROM payment_history
        WHERE user_id = ?
        ORDER BY created_at DESC
        LIMIT 20
    ", [$userId]);
}

// Статистика для сайдбара (общая)
$listingsCount   = $db->fetchCount("SELECT COUNT(*) FROM listings WHERE user_id = ?", [$userId]);
$favoritesCount  = $db->fetchCount("SELECT COUNT(*) FROM favorites WHERE user_id = ?", [$userId]);
$messagesUnread  = $db->fetchCount("SELECT COUNT(*) FROM messages WHERE receiver_id = ? AND is_read = 0", [$userId]);

// Рейтинг пользователя (краткий)
$userRating = $db->getUserRating($userId);
$avgRating = $userRating ? $userRating['avg_rating'] : 0;
$totalReviews = $userRating ? $userRating['total_reviews'] : 0;

// Вкладки
$tabs = [
    'overview'  => ['title' => 'Обзор', 'icon' => 'dashboard'],
    'listings'  => ['title' => 'Мои объявления', 'icon' => 'tags'],
    'favorites' => ['title' => 'Избранное', 'icon' => 'heart'],
    'messages'  => ['title' => 'Сообщения', 'icon' => 'chatting-01'],
    'auctions'  => ['title' => 'Аукционы', 'icon' => 'auction'],
    'reviews'   => ['title' => 'Отзывы', 'icon' => 'star'],
    'settings'  => ['title' => 'Настройки', 'icon' => 'settings'],
];
if ($isBusiness) {
    $tabs['business'] = ['title' => 'Мой бизнес', 'icon' => 'briefcase-01'];
}
$currentTab = isset($_GET['tab']) && isset($tabs[$_GET['tab']]) ? $_GET['tab'] : 'overview';

$csrfToken = generateCsrfToken();

$pageTitle = 'Профиль — Найдук';
$pageDescription = 'Личный кабинет пользователя';

// ===== МИКРОРАЗМЕТКА =====
$schema = [
    '@context' => 'https://schema.org',
    '@graph' => [
        [
            '@type' => 'ProfilePage',
            '@id' => 'https://' . $_SERVER['HTTP_HOST'] . '/profile/#profile',
            'url' => 'https://' . $_SERVER['HTTP_HOST'] . '/profile',
            'mainEntity' => ['@id' => 'https://' . $_SERVER['HTTP_HOST'] . '/profile/#person']
        ],
        [
            '@type' => 'Person',
            '@id' => 'https://' . $_SERVER['HTTP_HOST'] . '/profile/#person',
            'name' => htmlspecialchars($user['name'] ?? 'Пользователь'),
            'url' => 'https://' . $_SERVER['HTTP_HOST'] . '/profile',
            'image' => $user['avatar_url'] ?? 'https://' . $_SERVER['HTTP_HOST'] . '/assets/default-avatar.png',
            'description' => 'Пользователь платформы Найдук',
            'sameAs' => []
        ]
    ]
];
if (!empty($user['email'])) {
    $schema['@graph'][1]['email'] = $user['email'];
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <meta name="csrf-token" content="<?= $csrfToken ?>">
    <meta name="description" content="<?= htmlspecialchars($pageDescription) ?>">
    <meta name="robots" content="noindex, follow">
    <link rel="canonical" href="https://<?= $_SERVER['HTTP_HOST'] ?>/profile">
    <link rel="preconnect" href="https://cdn.hugeicons.com">
    <link rel="preconnect" href="https://cdn.jsdelivr.net">
    <title><?= htmlspecialchars($pageTitle) ?></title>
    <script type="application/ld+json"><?= json_encode($schema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?></script>
    <link rel="stylesheet" href="https://cdn.hugeicons.com/font/hgi-stroke-rounded.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/toastify-js/src/toastify.min.css">
    <link rel="stylesheet" href="/css/business.css">
    <link rel="stylesheet" href="/css/premium.css">
    <link rel="stylesheet" href="/css/theme-switch.css">
    <style>
        /* (Стили сохранены из предыдущей версии) */
        .profile-container { max-width: 1200px; margin: 0 auto; padding: 0 20px; }
        .profile-grid { display: grid; grid-template-columns: 280px 1fr; gap: 30px; }
        @media (max-width: 768px) { .profile-grid { grid-template-columns: 1fr; } }
        .profile-sidebar { background: var(--surface); border-radius: var(--radius-xl); padding: 24px; border: 1px solid var(--border-light); }
        .profile-avatar-section { text-align: center; margin-bottom: 20px; }
        .profile-avatar { width: 100px; height: 100px; border-radius: 50%; object-fit: cover; border: 3px solid var(--border-light); }
        .profile-name { font-size: 20px; font-weight: 700; text-align: center; margin-bottom: 8px; }
        .profile-trust { margin: 16px 0; }
        .trust-bar { height: 8px; background: var(--bg-secondary); border-radius: var(--radius-full); overflow: hidden; }
        .trust-fill { height: 100%; background: linear-gradient(90deg, var(--success), var(--primary)); width: <?= min($user['trust_score'] ?? 0, 100) ?>%; }
        .rating-badge { display: inline-flex; align-items: center; gap: 8px; background: var(--bg-secondary); padding: 8px 12px; border-radius: var(--radius-full); font-size: 14px; margin: 16px 0; }
        .stats-grid { display: grid; grid-template-columns: repeat(2, 1fr); gap: 12px; margin-top: 20px; }
        .stat-card { background: var(--bg-secondary); border-radius: var(--radius); padding: 12px; text-align: center; }
        .stat-value { font-size: 24px; font-weight: 700; color: var(--primary); }
        .profile-nav { margin-top: 24px; display: flex; flex-direction: column; gap: 8px; }
        .nav-item { display: flex; align-items: center; gap: 12px; padding: 12px 16px; border-radius: var(--radius); color: var(--text-secondary); transition: all var(--transition); text-decoration: none; font-weight: 500; }
        .nav-item i { font-size: 20px; }
        .nav-item:hover, .nav-item.active { background: var(--bg-secondary); color: var(--primary); }
        .tab-content { display: none; }
        .tab-content.active { display: block; }
        .listing-card, .offer-card, .message-card, .auction-card, .review-card, .product-card, .banner-card { background: var(--surface); border: 1px solid var(--border-light); border-radius: var(--radius); padding: 16px; margin-bottom: 16px; }
        .btn-small { padding: 6px 12px; font-size: 13px; }
        .badge { display: inline-block; padding: 2px 8px; border-radius: var(--radius-full); font-size: 11px; font-weight: 600; }
        .badge-warning { background: rgba(255,149,0,0.1); color: var(--warning); }
        .badge-success { background: rgba(52,199,89,0.1); color: var(--success); }
        .empty-state { text-align: center; padding: 40px; color: var(--text-secondary); }
        .form-group { margin-bottom: 20px; }
        .form-input { width: 100%; padding: 12px; border: 1px solid var(--border); border-radius: var(--radius); background: var(--bg); color: var(--text); font-size: 16px; }
        .form-actions { display: flex; gap: 12px; margin-top: 20px; flex-wrap: wrap; }
        .btn { display: inline-flex; align-items: center; gap: 8px; padding: 12px 24px; border-radius: var(--radius-full); font-weight: 600; cursor: pointer; transition: all var(--transition); border: none; font-size: 14px; }
        .btn-primary { background: var(--primary); color: white; }
        .btn-secondary { background: var(--bg-secondary); color: var(--text); border: 1px solid var(--border); }
        .btn-danger { background: rgba(255,59,48,0.1); color: var(--danger); }
        .spinner { display: inline-block; width: 16px; height: 16px; border: 2px solid rgba(255,255,255,0.3); border-radius: 50%; border-top-color: #fff; animation: spin 0.6s linear infinite; }
        @keyframes spin { to { transform: rotate(360deg); } }
        .load-more-btn { text-align: center; margin: 20px 0; }
        .skeleton { background: linear-gradient(90deg, var(--border-light) 25%, var(--bg-secondary) 50%, var(--border-light) 75%); background-size: 200% 100%; animation: skeleton-loading 1.5s infinite; border-radius: var(--radius); }
        @keyframes skeleton-loading { 0% { background-position: 200% 0; } 100% { background-position: -200% 0; } }
        .skeleton-card { height: 100px; margin-bottom: 16px; }
        .qr-code { max-width: 200px; margin: 20px auto; display: block; }
        .backup-codes { font-family: monospace; background: var(--bg-secondary); padding: 12px; border-radius: var(--radius); margin: 16px 0; display: flex; flex-wrap: wrap; gap: 8px; justify-content: center; }
        .backup-code { background: var(--surface); padding: 4px 8px; border-radius: var(--radius); font-size: 14px; }
        .modal { position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0,0,0,0.5); display: flex; align-items: center; justify-content: center; z-index: 1000; visibility: hidden; opacity: 0; transition: all 0.2s; }
        .modal.active { visibility: visible; opacity: 1; }
        .modal-content { background: var(--surface); border-radius: var(--radius-xl); padding: 24px; max-width: 500px; width: 90%; }
        .modal-header { display: flex; justify-content: space-between; margin-bottom: 16px; }
        .offer-list { max-height: 400px; overflow-y: auto; margin: 16px 0; }
        .offer-item { display: flex; justify-content: space-between; align-items: center; padding: 12px; border-bottom: 1px solid var(--border-light); }
        .accept-offer-btn { background: var(--success); color: white; border: none; padding: 4px 12px; border-radius: var(--radius-full); cursor: pointer; }
        .offer-count { margin-left: 8px; font-weight: 600; color: var(--primary); }
        .reviews-subtabs { display: flex; gap: 12px; margin-bottom: 20px; border-bottom: 1px solid var(--border-light); }
        .reviews-subtab { padding: 8px 16px; cursor: pointer; color: var(--text-secondary); }
        .reviews-subtab.active { color: var(--primary); border-bottom: 2px solid var(--primary); }
        .reviews-filters { display: flex; gap: 12px; margin-bottom: 20px; flex-wrap: wrap; align-items: flex-end; }
        .review-rating { color: var(--warning); font-size: 14px; }
        .review-photos { display: flex; gap: 8px; flex-wrap: wrap; margin-top: 8px; }
        .review-photo { width: 60px; height: 60px; object-fit: cover; border-radius: var(--radius); cursor: pointer; }
        .review-seller-reply { margin-top: 12px; padding: 12px; background: var(--bg-secondary); border-radius: var(--radius); font-size: 13px; }
        .review-actions { margin-top: 12px; display: flex; gap: 12px; }
        .btn-helpful { background: none; border: none; color: var(--text-secondary); cursor: pointer; font-size: 13px; display: flex; align-items: center; gap: 4px; }
        .btn-helpful.active { color: var(--primary); }
        .quick-actions { display: flex; flex-wrap: wrap; gap: 12px; margin-top: 20px; padding-top: 20px; border-top: 1px solid var(--border-light); }
        .quick-action { flex: 1; text-align: center; background: var(--bg-secondary); border-radius: var(--radius); padding: 12px; font-size: 13px; text-decoration: none; color: var(--text); transition: all 0.2s; }
        .quick-action:hover { background: var(--primary); color: white; }
        .city-suggestions { position: absolute; background: var(--surface); border: 1px solid var(--border); border-radius: var(--radius); max-height: 200px; overflow-y: auto; z-index: 1000; width: calc(100% - 2px); }
        .city-suggestion { padding: 10px 14px; cursor: pointer; }
        .city-suggestion:hover { background: var(--bg-secondary); }
        .product-image { width: 80px; height: 80px; object-fit: cover; border-radius: var(--radius); }
        .product-item { display: flex; gap: 16px; align-items: center; }
        @media (max-width: 768px) { .product-item { flex-direction: column; align-items: flex-start; } }
        .subscription-status { background: var(--bg-secondary); border-radius: var(--radius); padding: 16px; margin-bottom: 20px; }
        .payment-row { display: flex; justify-content: space-between; padding: 8px 0; border-bottom: 1px solid var(--border-light); }
    </style>
</head>
<body>
<div class="profile-container">
    <div class="profile-grid">
        <!-- САЙДБАР (общий) -->
        <aside class="profile-sidebar">
            <div class="profile-avatar-section">
                <img id="profile-avatar-img" src="<?= htmlspecialchars($user['avatar_url'] ?? '') ?>" class="profile-avatar" alt="Аватар" style="<?= empty($user['avatar_url']) ? 'display: none;' : '' ?>">
                <div id="profile-avatar-placeholder" class="profile-avatar" style="<?= !empty($user['avatar_url']) ? 'display: none;' : '' ?>; background: var(--bg-secondary); display: flex; align-items: center; justify-content: center; font-size: 48px;">👤</div>
                <button class="btn btn-secondary btn-small" onclick="document.getElementById('avatar-upload').click()" style="margin-top: 12px;">Изменить</button>
                <input type="file" id="avatar-upload" style="display: none;" accept="image/jpeg,image/png,image/webp">
            </div>
            <h2 class="profile-name"><?= htmlspecialchars($user['name'] ?? 'Пользователь') ?></h2>
            <div class="profile-trust">
                <div class="trust-bar"><div class="trust-fill"></div></div>
                <div style="font-size: 12px; margin-top: 4px;">Доверие: <?= $user['trust_score'] ?? 0 ?>/100</div>
            </div>
            <div class="rating-badge">
                <span>⭐ <?= number_format($avgRating, 1) ?></span>
                <span>(<?= $totalReviews ?> отзывов)</span>
            </div>
            <div class="stats-grid">
                <div class="stat-card"><div class="stat-value"><?= $listingsCount ?></div><div>Объявлений</div></div>
                <div class="stat-card"><div class="stat-value"><?= $favoritesCount ?></div><div>Избранное</div></div>
                <div class="stat-card"><div class="stat-value"><?= $messagesUnread ?></div><div>Сообщения</div></div>
                <?php if ($isBusiness): ?>
                <div class="stat-card"><div class="stat-value" id="business-products-count"><?= count($products) ?></div><div>Товаров/услуг</div></div>
                <?php endif; ?>
            </div>
            <nav class="profile-nav">
                <?php foreach ($tabs as $key => $tab): ?>
                <a href="?tab=<?= $key ?>" class="nav-item <?= $currentTab === $key ? 'active' : '' ?>" data-tab="<?= $key ?>">
                    <i class="hgi hgi-stroke-<?= $tab['icon'] ?>"></i> <?= $tab['title'] ?>
                </a>
                <?php endforeach; ?>
                <a href="/auth/logout" class="nav-item"><i class="hgi hgi-stroke-logout-01"></i> Выйти</a>
            </nav>
            <div class="quick-actions">
                <a href="/listing/create" class="quick-action">➕ Новое объявление</a>
                <a href="/search" class="quick-action">🔍 Поиск</a>
            </div>
        </aside>

        <main class="profile-content">
            <!-- Обзор (лента) -->
            <div class="tab-content <?= $currentTab === 'overview' ? 'active' : '' ?>" data-tab="overview">
                <h2>Добро пожаловать, <?= htmlspecialchars($user['name'] ?? 'Пользователь') ?>!</h2>
                <div id="overview-feed" class="feed">
                    <div class="skeleton skeleton-card"></div>
                </div>
            </div>

            <!-- Мои объявления (с платными услугами) -->
            <div class="tab-content <?= $currentTab === 'listings' ? 'active' : '' ?>" data-tab="listings">
                <h2>Мои объявления</h2>
                <div class="listings-filters" style="margin-bottom: 16px;">
                    <select id="listings-status-filter" class="form-input" style="width: auto; display: inline-block;">
                        <option value="all">Все</option>
                        <option value="active">Активные</option>
                        <option value="archived">Архивные</option>
                        <option value="pending">На модерации</option>
                    </select>
                </div>
                <div id="listings-container"></div>
                <div class="load-more-btn" id="listings-load-more" style="display: none;"><button class="btn btn-secondary" onclick="loadMore('listings')">Загрузить ещё</button></div>
            </div>

            <!-- Избранное -->
            <div class="tab-content <?= $currentTab === 'favorites' ? 'active' : '' ?>" data-tab="favorites">
                <h2>Избранное</h2>
                <div id="favorites-container"></div>
                <div class="load-more-btn" id="favorites-load-more" style="display: none;"><button class="btn btn-secondary" onclick="loadMore('favorites')">Загрузить ещё</button></div>
            </div>

            <!-- Сообщения -->
            <div class="tab-content <?= $currentTab === 'messages' ? 'active' : '' ?>" data-tab="messages">
                <h2>Сообщения</h2>
                <div id="messages-container"></div>
                <div class="load-more-btn" id="messages-load-more" style="display: none;"><button class="btn btn-secondary" onclick="loadMore('messages')">Загрузить ещё</button></div>
            </div>

            <!-- Аукционы -->
            <div class="tab-content <?= $currentTab === 'auctions' ? 'active' : '' ?>" data-tab="auctions">
                <h2>Мои аукционы</h2>
                <div id="auctions-container"></div>
                <div class="load-more-btn" id="auctions-load-more" style="display: none;"><button class="btn btn-secondary" onclick="loadMore('auctions')">Загрузить ещё</button></div>
            </div>

            <!-- Отзывы -->
            <div class="tab-content <?= $currentTab === 'reviews' ? 'active' : '' ?>" data-tab="reviews">
                <h2>Отзывы</h2>
                <div class="reviews-subtabs">
                    <div class="reviews-subtab active" data-subtab="about-me">Отзывы обо мне</div>
                    <div class="reviews-subtab" data-subtab="my-reviews">Мои отзывы</div>
                </div>
                <div class="reviews-filters" id="reviews-filters" style="display: none;">
                    <div class="filter-group">
                        <label class="filter-label">Рейтинг</label>
                        <select id="review-rating-filter" class="filter-select">
                            <option value="0">Все</option>
                            <option value="5">5★</option>
                            <option value="4">4★</option>
                            <option value="3">3★</option>
                            <option value="2">2★</option>
                            <option value="1">1★</option>
                        </select>
                    </div>
                    <div class="filter-group">
                        <label class="filter-label"><input type="checkbox" id="review-photos-filter"> Только с фото</label>
                    </div>
                    <div class="filter-group">
                        <button id="apply-review-filters" class="btn btn-primary btn-small">Применить</button>
                    </div>
                </div>
                <div id="reviews-container"></div>
                <div class="load-more-btn" id="reviews-load-more" style="display: none;"><button class="btn btn-secondary" onclick="loadMoreReviews()">Загрузить ещё</button></div>
            </div>

            <!-- Настройки (с городом, 2FA, и т.д.) -->
            <div class="tab-content <?= $currentTab === 'settings' ? 'active' : '' ?>" data-tab="settings">
                <h2>Настройки профиля</h2>
                <form id="settings-form">
                    <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
                    <div class="form-group"><label>Имя</label><input type="text" name="name" class="form-input" value="<?= htmlspecialchars($user['name'] ?? '') ?>" required></div>
                    <div class="form-group"><label>Email</label><input type="email" name="email" class="form-input" value="<?= htmlspecialchars($user['email']) ?>" required></div>
                    <div class="form-group"><label>Телефон</label><input type="tel" name="phone" class="form-input" value="<?= htmlspecialchars($user['phone'] ?? '') ?>"></div>
                    <div class="form-group"><label><input type="checkbox" name="phone_visible" value="1" <?= !empty($user['phone_visible']) ? 'checked' : '' ?>> Показывать телефон в объявлениях</label></div>
                    <div class="form-group"><label>Город</label>
                        <input type="text" id="city-input" name="city" class="form-input" autocomplete="off" value="<?= htmlspecialchars($user['city'] ?? '') ?>">
                        <div id="city-suggestions" class="city-suggestions" style="display: none;"></div>
                    </div>
                    <div class="form-group"><label>Telegram</label><input type="text" name="telegram" class="form-input" value="<?= htmlspecialchars($user['telegram'] ?? '') ?>"></div>
                    <div class="form-group"><label>WhatsApp</label><input type="text" name="whatsapp" class="form-input" value="<?= htmlspecialchars($user['whatsapp'] ?? '') ?>"></div>
                    <div class="form-group"><label>Текущий пароль (обязателен для смены данных)</label><input type="password" name="current_password" class="form-input" autocomplete="off"></div>
                    <div class="form-group"><label>Новый пароль (оставьте пустым, если не меняете)</label><input type="password" name="new_password" class="form-input" minlength="8" autocomplete="off"></div>
                    <div class="form-group">
                        <label>Уведомления</label>
                        <div>
                            <label><input type="checkbox" name="notify_email" value="1" <?= !empty($user['notify_email']) ? 'checked' : '' ?>> Email</label>
                            <label><input type="checkbox" name="notify_sms" value="1" <?= !empty($user['notify_sms']) ? 'checked' : '' ?>> SMS</label>
                        </div>
                    </div>
                    <div class="form-actions">
                        <button type="submit" class="btn btn-primary">Сохранить изменения</button>
                        <button type="button" class="btn btn-danger" onclick="showDeleteConfirm()">Удалить аккаунт</button>
                    </div>
                </form>
                <hr style="margin: 30px 0;">
                <h3>🔐 Двухфакторная аутентификация</h3>
                <div id="2fa-status">
                    <div class="form-actions" style="margin-top: 10px;">
                        <button type="button" class="btn btn-primary" onclick="enable2FA()" id="2fa-enable-btn">Включить 2FA</button>
                        <button type="button" class="btn btn-danger" onclick="disable2FA()" id="2fa-disable-btn" style="display: none;">Отключить 2FA</button>
                    </div>
                    <div id="2fa-setup" style="display: none;"></div>
                </div>
                <hr style="margin: 30px 0;">
                <h3>🔒 Безопасность</h3>
                <button type="button" class="btn btn-secondary" onclick="logoutAllDevices()">Выйти из всех устройств</button>
            </div>

            <!-- Мой бизнес (только для предпринимателей) -->
            <?php if ($isBusiness): ?>
            <div class="tab-content <?= $currentTab === 'business' ? 'active' : '' ?>" data-tab="business">
                <h2>Мой бизнес</h2>

                <!-- Состояние подписки -->
                <div class="subscription-status">
                    <?php if ($subscription): ?>
                        <div>✅ Активна до <strong><?= date('d.m.Y', strtotime($subscription['end_date'])) ?></strong></div>
                        <div>Тариф: <?= $subscription['plan'] === 'monthly' ? 'Ежемесячный' : 'Годовой' ?></div>
                        <button class="btn btn-primary btn-small" onclick="renewSubscription()">Продлить подписку</button>
                    <?php else: ?>
                        <div>⚠️ У вас нет активной подписки. Подключите бизнес-аккаунт, чтобы получить преимущества.</div>
                        <button class="btn btn-primary btn-small" onclick="activateSubscription()">Активировать подписку</button>
                    <?php endif; ?>
                </div>

                <!-- Настройки бизнеса -->
                <h3>Настройки бизнеса</h3>
                <form id="business-settings-form">
                    <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
                    <div class="form-group"><label>Название компании</label><input type="text" name="company_name" class="form-input" value="<?= htmlspecialchars($user['company_name'] ?? '') ?>"></div>
                    <div class="form-group"><label>Логотип (URL)</label><input type="text" name="logo_url" class="form-input" value="<?= htmlspecialchars($user['logo_url'] ?? '') ?>"></div>
                    <div class="form-group"><label>Адрес</label><input type="text" name="address" class="form-input" value="<?= htmlspecialchars($user['address'] ?? '') ?>"></div>
                    <div class="form-group"><label>Контактный телефон</label><input type="text" name="business_phone" class="form-input" value="<?= htmlspecialchars($user['business_phone'] ?? '') ?>"></div>
                    <div class="form-group"><label>Сайт</label><input type="url" name="website" class="form-input" value="<?= htmlspecialchars($user['website'] ?? '') ?>"></div>
                    <div class="form-group"><label>Описание</label><textarea name="description" class="form-textarea" rows="3"><?= htmlspecialchars($user['business_description'] ?? '') ?></textarea></div>
                    <div class="form-actions"><button type="submit" class="btn btn-primary">Сохранить настройки бизнеса</button></div>
                </form>

                <!-- Товары / услуги -->
                <h3>Мои товары и услуги</h3>
                <button class="btn btn-secondary btn-small" onclick="openAddProductModal()">➕ Добавить товар/услугу</button>
                <div id="products-container">
                    <?php if (empty($products)): ?>
                        <div class="empty-state">Нет добавленных товаров или услуг.</div>
                    <?php else: ?>
                        <?php foreach ($products as $p): ?>
                            <div class="product-card" data-id="<?= $p['id'] ?>">
                                <div class="product-item">
                                    <?php if ($p['image_urls']): $images = json_decode($p['image_urls'], true); ?>
                                        <img src="<?= htmlspecialchars($images[0] ?? '') ?>" class="product-image" alt="">
                                    <?php endif; ?>
                                    <div style="flex:1">
                                        <div><strong><?= htmlspecialchars($p['title']) ?></strong> — <?= number_format($p['price'], 0, ',', ' ') ?> ₽</div>
                                        <div><?= htmlspecialchars(substr($p['description'], 0, 100)) ?></div>
                                        <div class="form-actions" style="margin-top: 8px;">
                                            <button class="btn btn-secondary btn-small" onclick="editProduct(<?= $p['id'] ?>)">Редактировать</button>
                                            <button class="btn btn-danger btn-small" onclick="deleteProduct(<?= $p['id'] ?>)">Удалить</button>
                                            <button class="btn btn-primary btn-small" onclick="promoteProduct(<?= $p['id'] ?>)">Продвинуть</button>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>

                <!-- Платные услуги (одноразовые) -->
                <h3>Платные услуги для объявлений</h3>
                <div class="services-grid" style="display: flex; gap: 16px; flex-wrap: wrap; margin-bottom: 20px;">
                    <div class="service-card" style="background: var(--bg-secondary); border-radius: var(--radius); padding: 16px; flex:1; text-align:center;">
                        <div>📌 Закрепить объявление (7 дней)</div>
                        <div class="price" style="font-weight:700; margin:8px 0;">100 ₽</div>
                        <button class="btn btn-primary btn-small" onclick="buyService('pin')">Купить</button>
                    </div>
                    <div class="service-card" style="background: var(--bg-secondary); border-radius: var(--radius); padding: 16px; flex:1; text-align:center;">
                        <div>✨ Выделение цветом (7 дней)</div>
                        <div class="price" style="font-weight:700; margin:8px 0;">150 ₽</div>
                        <button class="btn btn-primary btn-small" onclick="buyService('highlight')">Купить</button>
                    </div>
                    <div class="service-card" style="background: var(--bg-secondary); border-radius: var(--radius); padding: 16px; flex:1; text-align:center;">
                        <div>🔨 Создать аукцион (платный лот)</div>
                        <div class="price" style="font-weight:700; margin:8px 0;">200 ₽</div>
                        <button class="btn btn-primary btn-small" onclick="buyService('auction')">Купить</button>
                    </div>
                </div>

                <!-- Баннеры на главной -->
                <h3>Реклама на главной</h3>
                <button class="btn btn-secondary btn-small" onclick="openBannerRequestModal()">Заказать баннер</button>
                <div id="banners-container">
                    <?php if (empty($banners)): ?>
                        <div class="empty-state">Нет заявок на баннеры.</div>
                    <?php else: ?>
                        <?php foreach ($banners as $b): ?>
                            <div class="banner-card">
                                <div>Статус: <?= $b['status'] === 'pending' ? 'На модерации' : ($b['status'] === 'approved' ? 'Одобрен' : 'Отклонён') ?></div>
                                <div>Город: <?= htmlspecialchars($b['city'] ?? 'Все') ?></div>
                                <div>Период: <?= date('d.m.Y', strtotime($b['start_date'])) ?> — <?= date('d.m.Y', strtotime($b['end_date'])) ?></div>
                                <img src="<?= htmlspecialchars($b['image_url']) ?>" style="max-width:100%; max-height:100px;">
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>

                <!-- Статистика бизнеса -->
                <h3>Статистика бизнеса</h3>
                <div id="business-stats">
                    <div class="stats-grid" style="margin-bottom: 20px;">
                        <div class="stat-card"><div class="stat-value" id="stat-views">—</div><div>Просмотров товаров</div></div>
                        <div class="stat-card"><div class="stat-value" id="stat-clicks">—</div><div>Переходов на сайт</div></div>
                        <div class="stat-card"><div class="stat-value" id="stat-orders">—</div><div>Заказов / заявок</div></div>
                    </div>
                </div>

                <!-- История платежей -->
                <h3>История платежей</h3>
                <div id="payments-history">
                    <?php if (empty($payments)): ?>
                        <div class="empty-state">Нет платежей.</div>
                    <?php else: ?>
                        <?php foreach ($payments as $p): ?>
                            <div class="payment-row">
                                <span><?= date('d.m.Y', strtotime($p['created_at'])) ?></span>
                                <span><?= number_format($p['amount'], 0, ',', ' ') ?> ₽</span>
                                <span><?= htmlspecialchars($p['description']) ?></span>
                                <span class="badge <?= $p['status'] === 'paid' ? 'badge-success' : 'badge-warning' ?>">
                                    <?= $p['status'] === 'paid' ? 'Оплачено' : ($p['status'] === 'pending' ? 'Ожидает' : 'Ошибка') ?>
                                </span>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
            <?php endif; ?>
        </main>
    </div>
</div>

<!-- Модальные окна -->
<div id="delete-modal" class="modal"><div class="modal-content"><div class="modal-header"><h3>Удаление аккаунта</h3><button onclick="closeModal('delete-modal')">✕</button></div><p>Введите пароль для подтверждения:</p><input type="password" id="delete-password" class="form-input"><div class="form-actions"><button class="btn btn-danger" onclick="confirmDelete()">Удалить</button><button class="btn btn-secondary" onclick="closeModal('delete-modal')">Отмена</button></div></div></div>
<div id="offers-modal" class="modal"><div class="modal-content"><div class="modal-header"><h3>Предложения</h3><button onclick="closeModal('offers-modal')">✕</button></div><div id="offers-modal-content"></div><div class="form-actions"><button class="btn btn-secondary" onclick="closeModal('offers-modal')">Закрыть</button></div></div></div>
<div id="payment-modal" class="modal"><div class="modal-content"><div class="modal-header"><h3>Оплата</h3><button onclick="closeModal('payment-modal')">✕</button></div><div id="payment-info"></div><div id="payment-form"></div><div class="form-actions"><button class="btn btn-primary" onclick="submitPayment()">Оплатить</button><button class="btn btn-secondary" onclick="closeModal('payment-modal')">Отмена</button></div></div></div>

<script src="https://cdn.jsdelivr.net/npm/toastify-js"></script>
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
    const csrfToken = '<?= $csrfToken ?>';
    const userId = <?= $userId ?>;
    const isBusiness = <?= $isBusiness ? 'true' : 'false' ?>;
    const loadedTabs = new Set();
    let currentReviewSubtab = 'about-me';
    let currentReviewPage = 1;
    let currentReviewRating = 0;
    let currentReviewHasPhotos = false;
    let currentListingsStatus = 'all';
    let paymentData = null;

    // ===== ВСПОМОГАТЕЛЬНЫЕ ФУНКЦИИ =====
    async function apiRequest(action, data = {}, method = 'POST', retries = 2) {
        const formData = new URLSearchParams();
        formData.append('action', action);
        formData.append('csrf_token', csrfToken);
        for (const [key, val] of Object.entries(data)) formData.append(key, val);
        for (let i = 0; i <= retries; i++) {
            try {
                const controller = new AbortController();
                const timeoutId = setTimeout(() => controller.abort(), 8000);
                const response = await fetch('/api/profile/manage.php', { method, body: formData, signal: controller.signal });
                clearTimeout(timeoutId);
                if (!response.ok) throw new Error(`HTTP ${response.status}`);
                return await response.json();
            } catch (err) {
                if (i === retries) throw err;
                await new Promise(r => setTimeout(r, 1000 * (i + 1)));
            }
        }
    }

    async function listingsApiRequest(action, data = {}, retries = 2) {
        const formData = new URLSearchParams();
        formData.append('action', action);
        formData.append('csrf_token', csrfToken);
        for (const [key, val] of Object.entries(data)) formData.append(key, val);
        for (let i = 0; i <= retries; i++) {
            try {
                const controller = new AbortController();
                const timeoutId = setTimeout(() => controller.abort(), 8000);
                const response = await fetch('/api/listings.php', { method: 'POST', body: formData, signal: controller.signal });
                clearTimeout(timeoutId);
                if (!response.ok) throw new Error(`HTTP ${response.status}`);
                return await response.json();
            } catch (err) {
                if (i === retries) throw err;
                await new Promise(r => setTimeout(r, 1000 * (i + 1)));
            }
        }
    }

    function showToast(message, type = 'success') {
        const colors = {
            success: 'linear-gradient(135deg, #34C759, #2C9B4E)',
            error: 'linear-gradient(135deg, #FF3B30, #C72A2A)',
            warning: 'linear-gradient(135deg, #FF9500, #E68600)',
            info: 'linear-gradient(135deg, #5A67D8, #4C51BF)'
        };
        Toastify({ text: message, duration: 4000, gravity: 'top', position: 'right', backgroundColor: colors[type] || colors.info }).showToast();
    }

    function escapeHtml(str) { return str ? str.replace(/[&<>]/g, m => ({ '&':'&amp;', '<':'&lt;', '>':'&gt;' }[m] || m)) : ''; }
    function formatPrice(price) { return new Intl.NumberFormat('ru-RU').format(price); }
    function closeModal(id) { document.getElementById(id).classList.remove('active'); }

    // ===== АВАТАР =====
    document.getElementById('avatar-upload')?.addEventListener('change', async function(e) {
        const file = e.target.files[0];
        if (!file) return;
        const formData = new FormData();
        formData.append('action', 'avatar');
        formData.append('csrf_token', csrfToken);
        formData.append('avatar', file);
        try {
            const response = await fetch('/api/profile/manage.php', { method: 'POST', body: formData });
            const data = await response.json();
            if (data.success) {
                const img = document.getElementById('profile-avatar-img');
                const placeholder = document.getElementById('profile-avatar-placeholder');
                img.src = data.avatar_url + '?t=' + Date.now();
                img.style.display = 'block';
                placeholder.style.display = 'none';
                showToast('Аватар обновлён');
            } else {
                showToast(data.error || 'Ошибка загрузки', 'error');
            }
        } catch (err) {
            showToast('Ошибка сети', 'error');
        }
    });

    // ===== НАСТРОЙКИ (город) =====
    document.getElementById('settings-form')?.addEventListener('submit', async function(e) {
        e.preventDefault();
        const formData = new FormData(this);
        const data = {};
        for (let [key, val] of formData.entries()) data[key] = val;
        try {
            const result = await apiRequest('update', data);
            if (result.success) {
                showToast(result.message || 'Профиль обновлён');
                if (result.email_pending) showToast('Письмо для подтверждения email отправлено', 'info');
                if (data.name) document.querySelector('.profile-name').innerText = data.name;
            } else {
                showToast(result.error || 'Ошибка обновления', 'error');
            }
        } catch (err) {
            showToast('Ошибка сети', 'error');
        }
    });

    // Автодополнение города
    const cityInput = document.getElementById('city-input');
    const citySuggestions = document.getElementById('city-suggestions');
    if (cityInput) {
        cityInput.addEventListener('input', () => {
            clearTimeout(window.cityDebounceTimer);
            const query = cityInput.value.trim();
            if (query.length < 2) { citySuggestions.style.display = 'none'; return; }
            window.cityDebounceTimer = setTimeout(async () => {
                try {
                    const response = await fetch('/api/geo/city.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ action: 'suggest', query: query, limit: 10, csrf_token: csrfToken })
                    });
                    const data = await response.json();
                    if (data.success && data.data && data.data.length) {
                        citySuggestions.innerHTML = data.data.map(city => `<div class="city-suggestion" data-name="${city.city_name}">${city.city_name} ${city.region_name ? `(${city.region_name})` : ''}</div>`).join('');
                        citySuggestions.style.display = 'block';
                        document.querySelectorAll('.city-suggestion').forEach(el => {
                            el.addEventListener('click', () => {
                                cityInput.value = el.dataset.name;
                                citySuggestions.style.display = 'none';
                            });
                        });
                    } else { citySuggestions.style.display = 'none'; }
                } catch (e) { citySuggestions.style.display = 'none'; }
            }, 300);
        });
        document.addEventListener('click', (e) => {
            if (!cityInput.contains(e.target) && !citySuggestions.contains(e.target)) citySuggestions.style.display = 'none';
        });
    }

    // ===== УДАЛЕНИЕ АККАУНТА, 2FA ===== (функции сохранены)
    function showDeleteConfirm() { document.getElementById('delete-modal').classList.add('active'); }
    async function confirmDelete() {
        const password = document.getElementById('delete-password').value;
        if (!password) { showToast('Введите пароль', 'warning'); return; }
        try {
            const result = await apiRequest('delete', { password: password });
            if (result.success) {
                showToast(result.message);
                setTimeout(() => { location.href = '/'; }, 3000);
            } else {
                showToast(result.error || 'Ошибка удаления', 'error');
            }
        } catch (err) { showToast('Ошибка сети', 'error'); }
        closeModal('delete-modal');
    }
    async function enable2FA() {
        try {
            const result = await apiRequest('enable-2fa', { step: 1 });
            if (result.success) {
                const setupDiv = document.getElementById('2fa-setup');
                setupDiv.innerHTML = `<p>Отсканируйте QR-код в приложении аутентификатора</p><img src="${result.qr_url}" class="qr-code"><p>Или введите код: <code>${result.secret}</code></p><div class="form-group"><label>Код</label><input type="text" id="2fa-code" class="form-input" placeholder="6 цифр"></div><button class="btn btn-primary" onclick="verify2FA('${result.secret}')">Подтвердить</button>`;
                setupDiv.style.display = 'block';
                document.getElementById('2fa-enable-btn').style.display = 'none';
            } else { showToast(result.error || 'Ошибка включения 2FA', 'error'); }
        } catch (err) { showToast('Ошибка сети', 'error'); }
    }
    async function verify2FA(secret) {
        const code = document.getElementById('2fa-code').value;
        if (!code || code.length !== 6) { showToast('Введите 6-значный код', 'warning'); return; }
        try {
            const result = await apiRequest('enable-2fa', { step: 2, secret: secret, code: code });
            if (result.success) {
                showToast('2FA успешно включена');
                let backupHtml = '<div class="backup-codes">';
                result.backup_codes.forEach(code => { backupHtml += `<span class="backup-code">${code}</span>`; });
                backupHtml += '</div><p>Сохраните резервные коды!</p>';
                document.getElementById('2fa-setup').innerHTML = backupHtml;
                document.getElementById('2fa-disable-btn').style.display = 'inline-block';
            } else { showToast(result.error || 'Неверный код', 'error'); }
        } catch (err) { showToast('Ошибка сети', 'error'); }
    }
    async function disable2FA() {
        const password = prompt('Введите пароль для отключения 2FA');
        if (!password) return;
        try {
            const result = await apiRequest('disable-2fa', { password: password });
            if (result.success) {
                showToast('2FA отключена');
                document.getElementById('2fa-disable-btn').style.display = 'none';
                document.getElementById('2fa-enable-btn').style.display = 'inline-block';
                document.getElementById('2fa-setup').style.display = 'none';
            } else { showToast(result.error || 'Ошибка отключения', 'error'); }
        } catch (err) { showToast('Ошибка сети', 'error'); }
    }
    async function logoutAllDevices() {
        if (!confirm('Выйти из всех устройств? Все сеансы будут завершены.')) return;
        try {
            const result = await apiRequest('logout-all', {});
            if (result.success) {
                showToast('Вы вышли из всех устройств. Пожалуйста, войдите снова.');
                setTimeout(() => { location.href = '/auth/login'; }, 2000);
            } else { showToast(result.error || 'Ошибка', 'error'); }
        } catch (err) { showToast('Ошибка сети', 'error'); }
    }

    // ===== ЗАГРУЗКА ЛЕНТЫ ОБЗОРА =====
    async function loadOverviewFeed() {
        const container = document.getElementById('overview-feed');
        container.innerHTML = '<div class="skeleton skeleton-card"></div>';
        try {
            const result = await apiRequest('feed', {});
            if (result.success) {
                let html = '';
                for (const item of result.data) {
                    html += `<div class="listing-card">${escapeHtml(item.text)} <small>${new Date(item.created_at).toLocaleString()}</small></div>`;
                }
                if (html === '') html = '<div class="empty-state">Пока нет активности</div>';
                container.innerHTML = html;
            } else { container.innerHTML = '<div class="empty-state">Не удалось загрузить ленту</div>'; }
        } catch (err) { container.innerHTML = '<div class="empty-state">Ошибка загрузки</div>'; }
    }

    // ===== МОИ ОБЪЯВЛЕНИЯ (с платными услугами) =====
    async function loadListings(page = 1, append = false) {
        const container = document.getElementById('listings-container');
        if (!container) return;
        const key = `listings_${page}_${append}_${currentListingsStatus}`;
        if (window.loadingTabs?.get(key)) return;
        if (!window.loadingTabs) window.loadingTabs = new Map();
        window.loadingTabs.set(key, true);
        if (!append) container.innerHTML = '<div class="skeleton skeleton-card"></div><div class="skeleton skeleton-card"></div>';
        try {
            const url = `/api/profile/manage.php?action=listings&page=${page}&limit=20&status=${currentListingsStatus}&csrf_token=${csrfToken}`;
            const response = await fetch(url);
            const result = await response.json();
            if (!result.success) throw new Error(result.error);
            const items = result.data || [];
            if (items.length === 0 && !append) {
                container.innerHTML = '<div class="empty-state">Нет объявлений</div>';
                return;
            }
            let html = '';
            for (const item of items) {
                let offersInfo = '';
                if (item.is_sealed) {
                    try {
                        const offersRes = await fetch(`/api/listings.php?action=get_offers_summary&listing_id=${item.id}&csrf_token=${csrfToken}`);
                        const offersData = await offersRes.json();
                        const totalOffers = offersData.summary ? Object.values(offersData.summary).reduce((a,b)=>a+b,0) : 0;
                        offersInfo = `<span class="offer-count">💰 ${totalOffers} предложений</span>`;
                        if (totalOffers > 0) offersInfo += `<button class="btn btn-secondary btn-small view-offers" data-listing-id="${item.id}" data-listing-title="${escapeHtml(item.title)}">Просмотр</button>`;
                    } catch(e) { console.warn(e); }
                }
                html += `
                    <div class="listing-card" data-id="${item.id}">
                        <h3>${escapeHtml(item.title)}</h3>
                        <p>Цена: ${formatPrice(item.price)} ₽</p>
                        <p>Статус: <span class="badge status-${item.status}">${item.status === 'approved' ? 'Активно' : (item.status === 'archived' ? 'Архивно' : 'На модерации')}</span></p>
                        <div class="form-actions" style="margin-top: 12px;">
                            <a href="/listing?id=${item.id}" class="btn btn-secondary btn-small">Просмотр</a>
                            <a href="/listings/edit.php?id=${item.id}" class="btn btn-primary btn-small">Редактировать</a>
                            ${item.status !== 'archived' ? `<button onclick="archiveListing(${item.id})" class="btn btn-secondary btn-small">Снять с публикации</button>` : ''}
                            <button onclick="deleteListing(${item.id})" class="btn btn-danger btn-small">Удалить</button>
                            <button onclick="buyServiceForListing(${item.id}, 'pin')" class="btn btn-secondary btn-small">📌 Закрепить</button>
                            <button onclick="buyServiceForListing(${item.id}, 'highlight')" class="btn btn-secondary btn-small">✨ Выделить</button>
                            ${offersInfo}
                        </div>
                    </div>
                `;
            }
            if (append) container.insertAdjacentHTML('beforeend', html);
            else container.innerHTML = html;
            document.querySelectorAll('.view-offers').forEach(btn => btn.addEventListener('click', () => showOffersModal(btn.dataset.listingId, btn.dataset.listingTitle)));
            const loadMoreBtn = document.getElementById('listings-load-more');
            if (loadMoreBtn && result.meta && result.meta.page < result.meta.pages) {
                loadMoreBtn.style.display = 'block';
                loadMoreBtn.dataset.page = result.meta.page;
                loadMoreBtn.dataset.tab = 'listings';
            } else if (loadMoreBtn) loadMoreBtn.style.display = 'none';
        } catch (err) {
            container.innerHTML = '<div class="empty-state">Ошибка загрузки. <button class="btn btn-secondary btn-small" onclick="loadListings(1,false)">Повторить</button></div>';
        } finally { window.loadingTabs.delete(key); }
    }

    async function archiveListing(id) {
        if (!confirm('Снять с публикации? Объявление станет недоступным.')) return;
        try {
            const result = await apiRequest('archive-listing', { listing_id: id });
            if (result.success) { showToast('Объявление снято с публикации'); loadListings(1, false); }
            else showToast(result.error || 'Ошибка', 'error');
        } catch (err) { showToast('Ошибка сети', 'error'); }
    }

    window.deleteListing = async (id) => {
        if (!confirm('Удалить объявление без возможности восстановления?')) return;
        try {
            const result = await apiRequest('delete-listing', { listing_id: id });
            if (result.success) {
                showToast('Объявление удалено');
                if (document.querySelector('.tab-content.active').dataset.tab === 'listings') loadListings(1, false);
                else if (document.querySelector('.tab-content.active').dataset.tab === 'auctions') loadAuctions(1, false);
            } else { showToast(result.error || 'Ошибка', 'error'); }
        } catch (err) { showToast('Ошибка сети', 'error'); }
    };

    async function buyServiceForListing(listingId, serviceType) {
        if (!confirm(`Купить услугу "${serviceType === 'pin' ? 'Закрепление' : 'Выделение'}" для этого объявления?`)) return;
        try {
            const result = await apiRequest('prepare-payment', { service: serviceType, listing_id: listingId });
            if (result.success && result.payment_url) {
                window.location.href = result.payment_url;
            } else {
                showToast(result.error || 'Ошибка подготовки оплаты', 'error');
            }
        } catch (err) { showToast('Ошибка сети', 'error'); }
    }

    // ===== ОСТАЛЬНЫЕ ВКЛАДКИ (избранное, сообщения, аукционы, отзывы) =====
    async function loadFavorites(page = 1, append = false) {
        const container = document.getElementById('favorites-container');
        if (!container) return;
        const key = `favorites_${page}_${append}`;
        if (window.loadingTabs?.get(key)) return;
        if (!window.loadingTabs) window.loadingTabs = new Map();
        window.loadingTabs.set(key, true);
        if (!append) container.innerHTML = '<div class="skeleton skeleton-card"></div><div class="skeleton skeleton-card"></div>';
        try {
            const url = `/api/profile/manage.php?action=favorites&page=${page}&limit=20&csrf_token=${csrfToken}`;
            const response = await fetch(url);
            const result = await response.json();
            if (!result.success) throw new Error(result.error);
            const items = result.data || [];
            if (items.length === 0 && !append) { container.innerHTML = '<div class="empty-state">Нет избранных объявлений</div>'; return; }
            let html = '';
            items.forEach(i => {
                html += `<div class="listing-card"><h3>${escapeHtml(i.title)}</h3><p>Цена: ${formatPrice(i.price)} ₽</p><div class="form-actions"><a href="/listing?id=${i.id}" class="btn btn-secondary btn-small">Просмотр</a><button onclick="removeFavorite(${i.id})" class="btn btn-danger btn-small">Удалить</button></div></div>`;
            });
            if (append) container.insertAdjacentHTML('beforeend', html);
            else container.innerHTML = html;
            const loadMoreBtn = document.getElementById('favorites-load-more');
            if (loadMoreBtn && result.meta && result.meta.page < result.meta.pages) {
                loadMoreBtn.style.display = 'block';
                loadMoreBtn.dataset.page = result.meta.page;
                loadMoreBtn.dataset.tab = 'favorites';
            } else if (loadMoreBtn) loadMoreBtn.style.display = 'none';
        } catch (err) { container.innerHTML = '<div class="empty-state">Ошибка загрузки</div>'; }
        finally { window.loadingTabs.delete(key); }
    }
    async function removeFavorite(listingId) {
        try {
            const result = await apiRequest('remove-favorite', { listing_id: listingId });
            if (result.success) { showToast('Удалено из избранного'); loadFavorites(1, false); }
            else showToast(result.error || 'Ошибка', 'error');
        } catch (err) { showToast('Ошибка сети', 'error'); }
    }

    async function loadMessages(page = 1, append = false) {
        const container = document.getElementById('messages-container');
        if (!container) return;
        const key = `messages_${page}_${append}`;
        if (window.loadingTabs?.get(key)) return;
        window.loadingTabs.set(key, true);
        if (!append) container.innerHTML = '<div class="skeleton skeleton-card"></div><div class="skeleton skeleton-card"></div>';
        try {
            const url = `/api/profile/manage.php?action=messages&page=${page}&limit=20&csrf_token=${csrfToken}`;
            const response = await fetch(url);
            const result = await response.json();
            if (!result.success) throw new Error(result.error);
            const items = result.data || [];
            if (items.length === 0 && !append) { container.innerHTML = '<div class="empty-state">Нет сообщений</div>'; return; }
            let html = '';
            items.forEach(m => {
                html += `<div class="message-card"><strong>${escapeHtml(m.sender_name || m.receiver_name)}</strong><div>${escapeHtml(m.content.substring(0,150))}${m.content.length>150?'...':''}</div><small>${new Date(m.created_at).toLocaleString()}</small><div class="form-actions"><a href="/chat?user=${m.sender_id == userId ? m.receiver_id : m.sender_id}" class="btn btn-secondary btn-small">Ответить</a></div></div>`;
            });
            if (append) container.insertAdjacentHTML('beforeend', html);
            else container.innerHTML = html;
            const loadMoreBtn = document.getElementById('messages-load-more');
            if (loadMoreBtn && result.meta && result.meta.page < result.meta.pages) {
                loadMoreBtn.style.display = 'block';
                loadMoreBtn.dataset.page = result.meta.page;
                loadMoreBtn.dataset.tab = 'messages';
            } else if (loadMoreBtn) loadMoreBtn.style.display = 'none';
        } catch (err) { container.innerHTML = '<div class="empty-state">Ошибка загрузки</div>'; }
        finally { window.loadingTabs.delete(key); }
    }

    async function loadAuctions(page = 1, append = false) {
        const container = document.getElementById('auctions-container');
        if (!container) return;
        const key = `auctions_${page}_${append}`;
        if (window.loadingTabs?.get(key)) return;
        window.loadingTabs.set(key, true);
        if (!append) container.innerHTML = '<div class="skeleton skeleton-card"></div><div class="skeleton skeleton-card"></div>';
        try {
            const url = `/api/profile/manage.php?action=auctions&page=${page}&limit=20&csrf_token=${csrfToken}`;
            const response = await fetch(url);
            const result = await response.json();
            if (!result.success) throw new Error(result.error);
            const items = result.data || [];
            if (items.length === 0 && !append) { container.innerHTML = '<div class="empty-state">Нет активных аукционов</div>'; return; }
            let html = '';
            for (const item of items) {
                html += `<div class="auction-card"><h3>${escapeHtml(item.title)}</h3><p>Цена: ${formatPrice(item.price)} ₽</p><p>Предложений: ${item.offer_count}</p><div class="form-actions"><button class="btn btn-primary btn-small view-auction-offers" data-listing-id="${item.id}" data-listing-title="${escapeHtml(item.title)}">Просмотреть предложения</button><button onclick="endAuction(${item.id})" class="btn btn-secondary btn-small">Завершить аукцион</button></div></div>`;
            }
            if (append) container.insertAdjacentHTML('beforeend', html);
            else container.innerHTML = html;
            document.querySelectorAll('.view-auction-offers').forEach(btn => btn.addEventListener('click', () => showOffersModal(btn.dataset.listingId, btn.dataset.listingTitle)));
            const loadMoreBtn = document.getElementById('auctions-load-more');
            if (loadMoreBtn && result.meta && result.meta.page < result.meta.pages) {
                loadMoreBtn.style.display = 'block';
                loadMoreBtn.dataset.page = result.meta.page;
                loadMoreBtn.dataset.tab = 'auctions';
            } else if (loadMoreBtn) loadMoreBtn.style.display = 'none';
        } catch (err) { container.innerHTML = '<div class="empty-state">Ошибка загрузки</div>'; }
        finally { window.loadingTabs.delete(key); }
    }
    async function endAuction(listingId) {
        if (!confirm('Завершить аукцион? Приём предложений будет остановлен.')) return;
        try {
            const result = await apiRequest('end-auction', { listing_id: listingId });
            if (result.success) { showToast('Аукцион завершён'); loadAuctions(1, false); }
            else showToast(result.error || 'Ошибка', 'error');
        } catch (err) { showToast('Ошибка сети', 'error'); }
    }
    async function showOffersModal(listingId, title) {
        const modal = document.getElementById('offers-modal');
        const contentDiv = document.getElementById('offers-modal-content');
        contentDiv.innerHTML = '<div class="skeleton skeleton-card"></div>';
        modal.classList.add('active');
        try {
            const result = await listingsApiRequest('get_seller_offers', { listing_id: listingId });
            if (!result.success) throw new Error(result.error);
            if (result.data.length === 0) { contentDiv.innerHTML = '<p>Нет активных предложений</p>'; return; }
            let html = `<h4>Объявление: ${escapeHtml(title)}</h4><div class="offer-list">`;
            for (const offer of result.data) {
                html += `<div class="offer-item"><span>Скидка -${offer.discount_percent}%</span><button class="accept-offer-btn" data-offer-id="${offer.id}" data-listing-id="${listingId}">Принять</button></div>`;
            }
            html += '</div>';
            contentDiv.innerHTML = html;
            document.querySelectorAll('.accept-offer-btn').forEach(btn => btn.addEventListener('click', async () => {
                const offerId = btn.dataset.offerId;
                const lid = btn.dataset.listingId;
                try {
                    const acceptResult = await listingsApiRequest('accept_offer', { offer_id: offerId, listing_id: lid });
                    if (acceptResult.success) {
                        showToast(acceptResult.message);
                        closeModal('offers-modal');
                        if (document.querySelector('.tab-content.active').dataset.tab === 'listings') loadListings(1, false);
                        else if (document.querySelector('.tab-content.active').dataset.tab === 'auctions') loadAuctions(1, false);
                    } else { showToast(acceptResult.error || 'Ошибка', 'error'); }
                } catch (err) { showToast('Ошибка сети', 'error'); }
            }));
        } catch (err) { contentDiv.innerHTML = '<p>Ошибка загрузки предложений</p>'; }
    }

    // ===== ОТЗЫВЫ =====
    async function loadReviews(page = 1, append = false) {
        const container = document.getElementById('reviews-container');
        if (!container) return;
        const key = `reviews_${currentReviewSubtab}_${page}_${append}`;
        if (window.loadingTabs?.get(key)) return;
        window.loadingTabs.set(key, true);
        if (!append) container.innerHTML = '<div class="skeleton skeleton-card"></div><div class="skeleton skeleton-card"></div>';
        let url = '';
        if (currentReviewSubtab === 'about-me') url = `/api/reviews.php?action=list&user_id=${userId}&page=${page}&limit=10`;
        else url = `/api/reviews.php?action=my&page=${page}&limit=10&csrf_token=${csrfToken}`;
        if (currentReviewRating) url += `&rating=${currentReviewRating}`;
        if (currentReviewHasPhotos) url += `&has_photos=1`;
        try {
            const response = await fetch(url);
            const data = await response.json();
            if (!data.success) throw new Error(data.error);
            const reviews = data.data || [];
            const meta = data.meta;
            if (reviews.length === 0 && !append) { container.innerHTML = '<div class="empty-state">Нет отзывов</div>'; return; }
            let html = '';
            for (const r of reviews) {
                const ratingStars = '★'.repeat(r.rating) + '☆'.repeat(5 - r.rating);
                let photosHtml = '';
                if (r.photos && r.photos.length) {
                    photosHtml = '<div class="review-photos">';
                    for (const photo of r.photos) photosHtml += `<img src="${escapeHtml(photo)}" class="review-photo" onclick="window.open(this.src)">`;
                    photosHtml += '</div>';
                }
                let replyHtml = r.seller_reply ? `<div class="review-seller-reply"><strong>Ответ продавца:</strong> ${escapeHtml(r.seller_reply)}</div>` : '';
                let helpfulHtml = '';
                if (currentReviewSubtab === 'about-me' && userId !== r.reviewer_id) {
                    helpfulHtml = `<div class="review-actions"><button class="btn-helpful" data-id="${r.id}" data-helpful="1">👍 Полезно (${r.helpful_count || 0})</button><button class="btn-helpful" data-id="${r.id}" data-helpful="0">👎 Не полезно (${r.not_helpful_count || 0})</button></div>`;
                }
                html += `<div class="review-card"><div class="review-header"><div class="review-rating">${ratingStars}</div><div class="review-date">${new Date(r.created_at).toLocaleDateString('ru-RU')}</div></div><div class="review-author"><strong>${escapeHtml(r.reviewer_name || 'Пользователь')}</strong></div><div class="review-text">${escapeHtml(r.comment)}</div>${photosHtml}${replyHtml}${helpfulHtml}</div>`;
            }
            if (append) container.insertAdjacentHTML('beforeend', html);
            else container.innerHTML = html;
            const loadMoreBtn = document.getElementById('reviews-load-more');
            if (loadMoreBtn && meta && meta.page < meta.pages) {
                loadMoreBtn.style.display = 'block';
                loadMoreBtn.dataset.page = meta.page;
            } else if (loadMoreBtn) loadMoreBtn.style.display = 'none';
            attachReviewHelpfulButtons();
        } catch (err) { if (!append) container.innerHTML = '<div class="empty-state">Ошибка загрузки отзывов</div>'; }
        finally { window.loadingTabs.delete(key); }
    }
    function attachReviewHelpfulButtons() {
        document.querySelectorAll('.btn-helpful').forEach(btn => {
            btn.removeEventListener('click', reviewHelpfulHandler);
            btn.addEventListener('click', reviewHelpfulHandler);
        });
    }
    async function reviewHelpfulHandler(e) {
        const btn = e.currentTarget;
        const reviewId = btn.dataset.id;
        const isHelpful = btn.dataset.helpful === '1';
        try {
            const response = await fetch('/api/reviews.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: new URLSearchParams({ action: 'helpful', review_id: reviewId, is_helpful: isHelpful ? 1 : 0, csrf_token: csrfToken })
            });
            const data = await response.json();
            if (data.success) { showToast('Ваш голос учтён', 'info'); loadReviews(1, false); }
            else { showToast(data.error || 'Ошибка', 'error'); }
        } catch (err) { showToast('Ошибка сети', 'error'); }
    }
    function loadMoreReviews() {
        const btn = document.getElementById('reviews-load-more');
        if (!btn) return;
        const currentPage = parseInt(btn.dataset.page) || 1;
        const nextPage = currentPage + 1;
        loadReviews(nextPage, true);
        btn.dataset.page = nextPage;
    }
    function initReviewsTab() {
        document.querySelectorAll('.reviews-subtab').forEach(tab => {
            tab.addEventListener('click', () => {
                document.querySelectorAll('.reviews-subtab').forEach(t => t.classList.remove('active'));
                tab.classList.add('active');
                currentReviewSubtab = tab.dataset.subtab;
                currentReviewPage = 1;
                document.getElementById('reviews-filters').style.display = currentReviewSubtab === 'about-me' ? 'flex' : 'none';
                loadReviews(1, false);
            });
        });
        document.getElementById('apply-review-filters')?.addEventListener('click', () => {
            currentReviewRating = parseInt(document.getElementById('review-rating-filter').value) || 0;
            currentReviewHasPhotos = document.getElementById('review-photos-filter').checked;
            loadReviews(1, false);
        });
        loadReviews(1, false);
    }

    // ===== БИЗНЕС-ФУНКЦИИ =====
    function activateSubscription() {
        buyService('business_subscription');
    }
    function renewSubscription() {
        buyService('business_subscription_renew');
    }
    function buyService(service, listingId = null) {
        let payload = { service: service };
        if (listingId) payload.listing_id = listingId;
        openPaymentModal(payload);
    }
    function buyServiceForListing(listingId, service) {
        buyService(service, listingId);
    }
    function openPaymentModal(data) {
        paymentData = data;
        const modal = document.getElementById('payment-modal');
        const infoDiv = document.getElementById('payment-info');
        infoDiv.innerHTML = `<p>Сумма: ...</p><p>Услуга: ${data.service}</p>`;
        modal.classList.add('active');
    }
    async function submitPayment() {
        if (!paymentData) return;
        try {
            const result = await apiRequest('prepare-payment', paymentData);
            if (result.success && result.payment_url) {
                window.location.href = result.payment_url;
            } else {
                showToast(result.error || 'Ошибка подготовки оплаты', 'error');
                closeModal('payment-modal');
            }
        } catch (err) {
            showToast('Ошибка сети', 'error');
            closeModal('payment-modal');
        }
    }
    function openAddProductModal() { alert('Форма добавления товара (будет реализована)'); }
    function editProduct(id) { alert('Редактирование товара ' + id); }
    async function deleteProduct(id) {
        if (!confirm('Удалить товар?')) return;
        try {
            const result = await apiRequest('delete-product', { product_id: id });
            if (result.success) { showToast('Товар удалён'); location.reload(); }
            else showToast(result.error || 'Ошибка', 'error');
        } catch (err) { showToast('Ошибка сети', 'error'); }
    }
    function promoteProduct(id) { buyService('product_promotion', id); }
    function openBannerRequestModal() { alert('Заявка на баннер (будет реализована)'); }
    async function loadBusinessStats() {
        try {
            const result = await apiRequest('business-stats', {});
            if (result.success) {
                document.getElementById('stat-views').innerText = result.data.views || 0;
                document.getElementById('stat-clicks').innerText = result.data.clicks || 0;
                document.getElementById('stat-orders').innerText = result.data.orders || 0;
            }
        } catch (err) {}
    }

    // ===== АКТИВАЦИЯ ВКЛАДОК =====
    function activateTab(tabName) {
        if (!loadedTabs.has(tabName)) {
            if (tabName === 'overview') loadOverviewFeed();
            else if (tabName === 'listings') loadListings(1, false);
            else if (tabName === 'favorites') loadFavorites(1, false);
            else if (tabName === 'messages') loadMessages(1, false);
            else if (tabName === 'auctions') loadAuctions(1, false);
            else if (tabName === 'reviews') initReviewsTab();
            else if (tabName === 'business' && isBusiness) {
                loadBusinessStats();
            }
            loadedTabs.add(tabName);
        }
    }
    document.querySelectorAll('.nav-item[data-tab]').forEach(link => {
        link.addEventListener('click', (e) => {
            const tab = link.dataset.tab;
            if (tab && !loadedTabs.has(tab)) activateTab(tab);
        });
    });
    document.getElementById('listings-status-filter')?.addEventListener('change', function() {
        currentListingsStatus = this.value;
        loadListings(1, false);
    });
    async function loadMore(tabName) {
        const btn = document.getElementById(`${tabName}-load-more`);
        if (!btn) return;
        const currentPage = parseInt(btn.dataset.page) || 1;
        const nextPage = currentPage + 1;
        if (tabName === 'listings') await loadListings(nextPage, true);
        else if (tabName === 'favorites') await loadFavorites(nextPage, true);
        else if (tabName === 'messages') await loadMessages(nextPage, true);
        else if (tabName === 'auctions') await loadAuctions(nextPage, true);
        btn.dataset.page = nextPage;
    }
    document.addEventListener('DOMContentLoaded', () => {
        activateTab('<?= $currentTab ?>');
    });
</script>
</body>
</html>