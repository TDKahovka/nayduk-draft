<?php
/* ============================================
   НАЙДУК — Страница магазина (премиум‑версия)
   Версия 1.0 (март 2026)
   - Динамическая загрузка товаров (бесконечный скролл/кнопка)
   - Корзина (localStorage для гостей, БД для авторизованных)
   - Блоки конструктора (layout)
   - Отзывы с рейтингом и фото
   - Карта (Leaflet), FAQ, контакты
   - SEO‑разметка Schema.org (LocalBusiness, AggregateRating)
   - Мобильная адаптация
   ============================================ */

session_start();
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../services/Database.php';

$db = Database::getInstance();
$pdo = $db->getPdo();

// ==================== ПОЛУЧАЕМ МАГАЗИН ПО SLUG ====================
$slug = $_GET['slug'] ?? '';
if (empty($slug)) {
    http_response_code(404);
    include __DIR__ . '/../404.php';
    exit;
}

$shop = $db->fetchOne("SELECT * FROM shops WHERE slug = ? AND is_active = 1", [$slug]);
if (!$shop) {
    http_response_code(404);
    include __DIR__ . '/../404.php';
    exit;
}
$shopId = $shop['id'];
$userId = $_SESSION['user_id'] ?? 0;

// ==================== КЭШИРОВАНИЕ (Redis/файлы) ====================
$cacheKey = "shop_{$shopId}";
$shopData = $db->cacheRemember($cacheKey, 600, function() use ($db, $shopId, $shop) {
    // Получаем товары (первые 6 для предзагрузки, остальные через AJAX)
    $products = $db->fetchAll("
        SELECT id, name, description, price, old_price, images, quantity, category
        FROM shop_products
        WHERE shop_id = ? AND is_active = 1
        ORDER BY created_at DESC
        LIMIT 6
    ", [$shopId]);
    // Сериализуем изображения
    foreach ($products as &$p) {
        $p['images'] = $p['images'] ? json_decode($p['images'], true) : [];
    }
    // Получаем услуги (аналогично)
    $services = $db->fetchAll("
        SELECT id, name, description, price, duration
        FROM shop_services
        WHERE shop_id = ? AND is_active = 1
        ORDER BY created_at DESC
        LIMIT 6
    ", [$shopId]);

    // Отзывы (только одобренные)
    $reviews = $db->fetchAll("
        SELECT r.*, u.name as user_name
        FROM shop_reviews r
        JOIN users u ON r.user_id = u.id
        WHERE r.shop_id = ? AND r.status = 'approved'
        ORDER BY r.created_at DESC
        LIMIT 10
    ", [$shopId]);

    // Средний рейтинг и количество отзывов
    $ratingData = $db->fetchOne("
        SELECT AVG(rating) as avg_rating, COUNT(*) as total_reviews
        FROM shop_reviews
        WHERE shop_id = ? AND status = 'approved'
    ", [$shopId]);

    // FAQ
    $faq = json_decode($shop['faq'] ?? '[]', true);

    // Контакты и карта
    $hasAddress = !empty($shop['address']);
    $lat = $shop['lat'] ?? null;
    $lng = $shop['lng'] ?? null;

    // Блоки layout (если есть)
    $layout = json_decode($shop['layout'] ?? '[]', true);

    return [
        'shop' => $shop,
        'products' => $products,
        'services' => $services,
        'reviews' => $reviews,
        'avg_rating' => round($ratingData['avg_rating'] ?? 0, 1),
        'total_reviews' => (int)($ratingData['total_reviews'] ?? 0),
        'faq' => $faq,
        'hasAddress' => $hasAddress,
        'lat' => $lat,
        'lng' => $lng,
        'layout' => $layout
    ];
});

$shop = $shopData['shop'];
$products = $shopData['products'];
$services = $shopData['services'];
$reviews = $shopData['reviews'];
$avgRating = $shopData['avg_rating'];
$totalReviews = $shopData['total_reviews'];
$faq = $shopData['faq'];
$hasAddress = $shopData['hasAddress'];
$lat = $shopData['lat'];
$lng = $shopData['lng'];
$layout = $shopData['layout'];

$csrfToken = generateCsrfToken();

// ==================== СТРАНИЦА ====================
global $pageTitle, $pageDescription;
$pageTitle = htmlspecialchars($shop['name']) . ' — магазин на Найдук';
$pageDescription = htmlspecialchars(mb_substr(strip_tags($shop['description'] ?? ''), 0, 160));

// Микроразметка Schema.org (LocalBusiness + AggregateRating)
$schema = [
    '@context' => 'https://schema.org',
    '@type' => 'LocalBusiness',
    'name' => $shop['name'],
    'description' => $shop['description'] ?? '',
    'image' => $shop['logo_url'] ?? '',
    'url' => 'https://' . $_SERVER['HTTP_HOST'] . '/shop/' . $slug,
    'address' => $shop['address'] ?? '',
    'telephone' => $shop['contact_phone'] ?? '',
    'email' => $shop['contact_email'] ?? '',
    'aggregateRating' => $totalReviews ? [
        '@type' => 'AggregateRating',
        'ratingValue' => $avgRating,
        'reviewCount' => $totalReviews
    ] : null
];
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <meta name="csrf-token" content="<?= $csrfToken ?>">
    <title><?= $pageTitle ?></title>
    <meta name="description" content="<?= $pageDescription ?>">
    <link rel="canonical" href="https://<?= $_SERVER['HTTP_HOST'] ?>/shop/<?= htmlspecialchars($slug) ?>">
    <script type="application/ld+json"><?= json_encode($schema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?></script>
    <link rel="stylesheet" href="https://cdn.hugeicons.com/font/hgi-stroke-rounded.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/toastify-js/src/toastify.min.css">
    <link rel="stylesheet" href="/css/business.css">
    <link rel="stylesheet" href="/css/premium.css">
    <link rel="stylesheet" href="/css/theme-switch.css">
    <link rel="stylesheet" href="/css/themes.css">
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
    <style>
        .shop-container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 20px;
        }
        .shop-header {
            background: var(--surface);
            border-radius: var(--radius-xl);
            padding: 30px;
            margin-bottom: 30px;
            border: 1px solid var(--border);
            text-align: center;
        }
        .shop-logo {
            max-width: 150px;
            margin-bottom: 20px;
        }
        .shop-name {
            font-size: 32px;
            font-weight: 700;
            margin-bottom: 8px;
        }
        .shop-rating {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            background: var(--bg-secondary);
            padding: 4px 12px;
            border-radius: var(--radius-full);
            font-size: 14px;
            margin-top: 10px;
        }
        .shop-description {
            margin-top: 20px;
            color: var(--text-secondary);
            max-width: 600px;
            margin-left: auto;
            margin-right: auto;
        }
        .shop-contacts {
            display: flex;
            justify-content: center;
            gap: 20px;
            flex-wrap: wrap;
            margin-top: 20px;
        }
        .shop-contact a {
            color: var(--text);
            text-decoration: none;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .products-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
            gap: 20px;
            margin: 20px 0;
        }
        .product-card {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: var(--radius-lg);
            overflow: hidden;
            transition: transform 0.2s, box-shadow 0.2s;
            cursor: pointer;
        }
        .product-card:hover {
            transform: translateY(-4px);
            box-shadow: var(--shadow-md);
        }
        .product-image {
            width: 100%;
            height: 200px;
            object-fit: cover;
            background: var(--bg-secondary);
        }
        .product-info {
            padding: 16px;
        }
        .product-title {
            font-size: 18px;
            font-weight: 600;
            margin-bottom: 8px;
        }
        .product-price {
            font-size: 20px;
            font-weight: 700;
            color: var(--primary);
            margin-bottom: 8px;
        }
        .product-old-price {
            text-decoration: line-through;
            font-size: 14px;
            color: var(--text-secondary);
            margin-left: 8px;
        }
        .product-actions {
            margin-top: 12px;
            display: flex;
            gap: 8px;
        }
        .btn-sm {
            padding: 6px 12px;
            font-size: 13px;
        }
        .btn-primary {
            background: var(--primary);
            color: white;
            border: none;
            border-radius: var(--radius-full);
            cursor: pointer;
        }
        .btn-secondary {
            background: var(--bg-secondary);
            border: 1px solid var(--border);
            color: var(--text);
            border-radius: var(--radius-full);
            cursor: pointer;
        }
        .reviews-section {
            margin-top: 40px;
        }
        .review-card {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: var(--radius);
            padding: 16px;
            margin-bottom: 16px;
        }
        .review-header {
            display: flex;
            justify-content: space-between;
            margin-bottom: 8px;
        }
        .review-stars {
            color: var(--warning);
        }
        .review-text {
            margin: 8px 0;
        }
        .review-photos {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
            margin-top: 8px;
        }
        .review-photo {
            width: 60px;
            height: 60px;
            object-fit: cover;
            border-radius: var(--radius);
            cursor: pointer;
        }
        .faq-item {
            border-bottom: 1px solid var(--border);
            padding: 12px 0;
        }
        .faq-question {
            font-weight: 600;
            cursor: pointer;
            display: flex;
            justify-content: space-between;
        }
        .faq-answer {
            display: none;
            padding-top: 8px;
            color: var(--text-secondary);
        }
        .faq-answer.open {
            display: block;
        }
        .map-container {
            height: 300px;
            border-radius: var(--radius-lg);
            overflow: hidden;
            margin-top: 20px;
        }
        .cart-modal {
            position: fixed;
            top: 0;
            right: -400px;
            width: 380px;
            height: 100%;
            background: var(--surface);
            box-shadow: var(--shadow-lg);
            z-index: 1000;
            transition: right 0.3s;
            padding: 20px;
            overflow-y: auto;
        }
        .cart-modal.open {
            right: 0;
        }
        .cart-item {
            display: flex;
            gap: 12px;
            margin-bottom: 16px;
            padding-bottom: 12px;
            border-bottom: 1px solid var(--border);
        }
        .cart-item img {
            width: 60px;
            height: 60px;
            object-fit: cover;
            border-radius: var(--radius);
        }
        .cart-item-info {
            flex: 1;
        }
        .cart-item-title {
            font-weight: 600;
        }
        .cart-item-price {
            color: var(--primary);
        }
        .cart-item-quantity {
            display: flex;
            align-items: center;
            gap: 8px;
            margin-top: 8px;
        }
        .cart-total {
            margin-top: 20px;
            text-align: right;
            font-weight: 700;
            font-size: 18px;
        }
        .cart-actions {
            display: flex;
            gap: 12px;
            margin-top: 20px;
        }
        .cart-overlay {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.5);
            z-index: 999;
            display: none;
        }
        .cart-overlay.open {
            display: block;
        }
        @media (max-width: 768px) {
            .cart-modal {
                width: 100%;
                right: -100%;
            }
            .products-grid {
                grid-template-columns: 1fr;
            }
            .shop-header {
                padding: 20px;
            }
            .shop-name {
                font-size: 24px;
            }
        }
    </style>
</head>
<body>
<div class="shop-container">
    <!-- ШАПКА МАГАЗИНА -->
    <div class="shop-header">
        <?php if ($shop['logo_url']): ?>
            <img src="<?= htmlspecialchars($shop['logo_url']) ?>" class="shop-logo" alt="<?= htmlspecialchars($shop['name']) ?>">
        <?php endif; ?>
        <h1 class="shop-name"><?= htmlspecialchars($shop['name']) ?></h1>
        <?php if ($totalReviews): ?>
            <div class="shop-rating">
                <span>⭐ <?= $avgRating ?></span>
                <span>(<?= $totalReviews ?> отзывов)</span>
            </div>
        <?php endif; ?>
        <?php if ($shop['description']): ?>
            <div class="shop-description"><?= nl2br(htmlspecialchars($shop['description'])) ?></div>
        <?php endif; ?>
        <div class="shop-contacts">
            <?php if ($shop['contact_phone']): ?>
                <div class="shop-contact"><a href="tel:<?= htmlspecialchars($shop['contact_phone']) ?>">📞 <?= htmlspecialchars($shop['contact_phone']) ?></a></div>
            <?php endif; ?>
            <?php if ($shop['contact_email']): ?>
                <div class="shop-contact"><a href="mailto:<?= htmlspecialchars($shop['contact_email']) ?>">✉️ <?= htmlspecialchars($shop['contact_email']) ?></a></div>
            <?php endif; ?>
            <?php if ($shop['contact_telegram']): ?>
                <div class="shop-contact"><a href="https://t.me/<?= urlencode($shop['contact_telegram']) ?>" target="_blank">💬 Telegram</a></div>
            <?php endif; ?>
            <?php if ($shop['contact_whatsapp']): ?>
                <div class="shop-contact"><a href="https://wa.me/<?= preg_replace('/[^0-9]/', '', $shop['contact_whatsapp']) ?>" target="_blank">📱 WhatsApp</a></div>
            <?php endif; ?>
        </div>
        <div style="margin-top: 20px;">
            <button id="cart-icon" class="btn btn-primary">🛒 Корзина (<span id="cart-count">0</span>)</button>
        </div>
    </div>

    <!-- БЛОКИ КОНСТРУКТОРА (layout) -->
    <?php
    $blockRenderers = [
        'hero' => function($block) use ($shop) {
            echo '<div class="hero-block">' . htmlspecialchars($block['title'] ?? $shop['name']) . '</div>';
        },
        'products' => function($block) {
            echo '<div id="products-section"><h2>' . htmlspecialchars($block['title'] ?? 'Товары') . '</h2><div class="products-grid" id="products-grid"></div><div id="products-load-more" class="text-center" style="display:none;"><button class="btn btn-secondary" onclick="loadMoreProducts()">Показать ещё</button></div></div>';
        },
        'services' => function($block) {
            echo '<div id="services-section"><h2>' . htmlspecialchars($block['title'] ?? 'Услуги') . '</h2><div class="products-grid" id="services-grid"></div></div>';
        },
        'reviews' => function($block) {
            echo '<div class="reviews-section"><h2>' . htmlspecialchars($block['title'] ?? 'Отзывы') . '</h2><div id="reviews-container"></div><div class="text-center" id="reviews-load-more" style="display:none;"><button class="btn btn-secondary" onclick="loadMoreReviews()">Загрузить ещё</button></div></div>';
        },
        'faq' => function($block) use ($faq) {
            echo '<div class="faq-section"><h2>' . htmlspecialchars($block['title'] ?? 'Вопросы и ответы') . '</h2><div id="faq-list">';
            foreach ($faq as $item) {
                echo '<div class="faq-item"><div class="faq-question">' . htmlspecialchars($item['question']) . ' <span>▼</span></div><div class="faq-answer">' . nl2br(htmlspecialchars($item['answer'])) . '</div></div>';
            }
            echo '</div></div>';
        },
        'map' => function($block) use ($hasAddress, $lat, $lng) {
            if ($hasAddress && $lat && $lng) {
                echo '<div class="map-section"><h2>' . htmlspecialchars($block['title'] ?? 'Карта') . '</h2><div id="map" class="map-container"></div></div>';
            }
        },
        'contacts' => function($block) use ($shop) {
            echo '<div class="contacts-section"><h2>' . htmlspecialchars($block['title'] ?? 'Контакты') . '</h2><div>' . nl2br(htmlspecialchars($shop['address'] ?? '')) . '</div></div>';
        }
    ];

    if (empty($layout)) {
        // Стандартный порядок
        echo '<div id="products-section"><h2>Товары</h2><div class="products-grid" id="products-grid"></div><div id="products-load-more" class="text-center" style="display:none;"><button class="btn btn-secondary" onclick="loadMoreProducts()">Показать ещё</button></div></div>';
        echo '<div id="services-section"><h2>Услуги</h2><div class="products-grid" id="services-grid"></div></div>';
        echo '<div class="reviews-section"><h2>Отзывы</h2><div id="reviews-container"></div><div class="text-center" id="reviews-load-more" style="display:none;"><button class="btn btn-secondary" onclick="loadMoreReviews()">Загрузить ещё</button></div></div>';
        echo '<div class="faq-section"><h2>Вопросы и ответы</h2><div id="faq-list">';
        foreach ($faq as $item) {
            echo '<div class="faq-item"><div class="faq-question">' . htmlspecialchars($item['question']) . ' <span>▼</span></div><div class="faq-answer">' . nl2br(htmlspecialchars($item['answer'])) . '</div></div>';
        }
        echo '</div></div>';
        if ($hasAddress && $lat && $lng) {
            echo '<div class="map-section"><h2>Карта</h2><div id="map" class="map-container"></div></div>';
        }
    } else {
        foreach ($layout as $block) {
            $type = $block['type'];
            if (isset($blockRenderers[$type])) {
                $blockRenderers[$type]($block);
            }
        }
    }
    ?>
</div>

<!-- Модалка корзины -->
<div class="cart-overlay" id="cart-overlay"></div>
<div class="cart-modal" id="cart-modal">
    <div style="display:flex; justify-content:space-between; margin-bottom:20px;">
        <h3>Корзина</h3>
        <button id="close-cart" style="background:none; border:none; font-size:24px; cursor:pointer;">✕</button>
    </div>
    <div id="cart-items"></div>
    <div class="cart-total" id="cart-total">Итого: 0 ₽</div>
    <div class="cart-actions">
        <button id="checkout-btn" class="btn btn-primary">Оформить заказ</button>
        <button id="clear-cart-btn" class="btn btn-secondary">Очистить</button>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/toastify-js"></script>
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<script>
    const csrfToken = '<?= $csrfToken ?>';
    const shopId = <?= $shopId ?>;
    const shopSlug = '<?= $slug ?>';
    let productsPage = 1;
    let reviewsPage = 1;
    let loading = false;
    let hasMoreProducts = true;
    let hasMoreReviews = true;
    let productsList = <?= json_encode($products) ?>;
    let servicesList = <?= json_encode($services) ?>;
    let reviewsList = <?= json_encode($reviews) ?>;
    let avgRating = <?= $avgRating ?>;
    let totalReviews = <?= $totalReviews ?>;

    // ==================== КОРЗИНА ====================
    let cart = {
        items: [],
        total: 0,
        load: function() {
            const saved = localStorage.getItem('cart');
            if (saved) {
                try {
                    this.items = JSON.parse(saved);
                    this.updateTotal();
                } catch(e) {}
            }
            this.render();
        },
        save: function() {
            localStorage.setItem('cart', JSON.stringify(this.items));
        },
        add: function(productId, name, price, image) {
            const existing = this.items.find(i => i.id == productId);
            if (existing) {
                existing.quantity++;
            } else {
                this.items.push({ id: productId, name, price, image, quantity: 1 });
            }
            if (this.items.length > 20) {
                this.items.pop();
                alert('В корзине не может быть более 20 товаров');
            }
            this.updateTotal();
            this.save();
            this.render();
            showToast('Товар добавлен в корзину', 'success');
        },
        remove: function(productId) {
            this.items = this.items.filter(i => i.id != productId);
            this.updateTotal();
            this.save();
            this.render();
        },
        updateQuantity: function(productId, delta) {
            const item = this.items.find(i => i.id == productId);
            if (item) {
                item.quantity += delta;
                if (item.quantity <= 0) {
                    this.remove(productId);
                } else {
                    this.updateTotal();
                    this.save();
                    this.render();
                }
            }
        },
        updateTotal: function() {
            this.total = this.items.reduce((sum, i) => sum + i.price * i.quantity, 0);
        },
        clear: function() {
            this.items = [];
            this.updateTotal();
            this.save();
            this.render();
        },
        render: function() {
            const container = document.getElementById('cart-items');
            const totalSpan = document.getElementById('cart-total');
            const countSpan = document.getElementById('cart-count');
            if (!container) return;
            if (this.items.length === 0) {
                container.innerHTML = '<div class="empty-state">Корзина пуста</div>';
                totalSpan.innerText = 'Итого: 0 ₽';
                countSpan.innerText = '0';
                return;
            }
            let html = '';
            this.items.forEach(item => {
                html += `
                    <div class="cart-item">
                        <img src="${item.image || '/assets/img/no-image.png'}" alt="${escapeHtml(item.name)}">
                        <div class="cart-item-info">
                            <div class="cart-item-title">${escapeHtml(item.name)}</div>
                            <div class="cart-item-price">${formatPrice(item.price)} ₽</div>
                            <div class="cart-item-quantity">
                                <button class="btn btn-secondary btn-sm" onclick="cart.updateQuantity(${item.id}, -1)">-</button>
                                <span>${item.quantity}</span>
                                <button class="btn btn-secondary btn-sm" onclick="cart.updateQuantity(${item.id}, 1)">+</button>
                            </div>
                        </div>
                        <button class="btn btn-danger btn-sm" onclick="cart.remove(${item.id})">✕</button>
                    </div>
                `;
            });
            container.innerHTML = html;
            totalSpan.innerText = `Итого: ${formatPrice(this.total)} ₽`;
            countSpan.innerText = this.items.reduce((s,i)=>s+i.quantity,0);
        }
    };

    // ==================== ТОВАРЫ И УСЛУГИ ====================
    function renderProducts() {
        const container = document.getElementById('products-grid');
        if (!container) return;
        let html = '';
        productsList.forEach(p => {
            const image = p.images && p.images[0] ? p.images[0] : '/assets/img/no-image.png';
            html += `
                <div class="product-card">
                    <img src="${image}" class="product-image" alt="${escapeHtml(p.name)}">
                    <div class="product-info">
                        <div class="product-title">${escapeHtml(p.name)}</div>
                        <div class="product-price">${formatPrice(p.price)} ₽
                            ${p.old_price ? `<span class="product-old-price">${formatPrice(p.old_price)} ₽</span>` : ''}
                        </div>
                        <div class="product-actions">
                            <button class="btn btn-primary btn-sm" onclick="addToCart(${p.id}, '${escapeHtml(p.name)}', ${p.price}, '${image}')">В корзину</button>
                            <button class="btn btn-secondary btn-sm" onclick="quickView(${p.id})">Быстрый просмотр</button>
                        </div>
                    </div>
                </div>
            `;
        });
        container.innerHTML = html;
    }

    function renderServices() {
        const container = document.getElementById('services-grid');
        if (!container) return;
        let html = '';
        servicesList.forEach(s => {
            html += `
                <div class="product-card">
                    <div class="product-info">
                        <div class="product-title">${escapeHtml(s.name)}</div>
                        <div class="product-price">${formatPrice(s.price)} ₽</div>
                        <div>Длительность: ${s.duration} мин</div>
                        <div class="product-actions">
                            <button class="btn btn-primary btn-sm" onclick="addToCartService(${s.id}, '${escapeHtml(s.name)}', ${s.price})">Записаться</button>
                        </div>
                    </div>
                </div>
            `;
        });
        container.innerHTML = html;
    }

    async function loadMoreProducts() {
        if (loading || !hasMoreProducts) return;
        loading = true;
        productsPage++;
        try {
            const res = await fetch(`/api/shop/manage.php?action=get_products&shop_id=${shopId}&page=${productsPage}&limit=12&csrf_token=${csrfToken}`);
            const data = await res.json();
            if (data.success && data.products.length) {
                productsList = productsList.concat(data.products);
                renderProducts();
                if (data.products.length < 12) hasMoreProducts = false;
            } else {
                hasMoreProducts = false;
            }
        } catch(e) { console.error(e); }
        loading = false;
        const loadMoreBtn = document.getElementById('products-load-more');
        if (loadMoreBtn) loadMoreBtn.style.display = hasMoreProducts ? 'block' : 'none';
    }

    // ==================== ОТЗЫВЫ ====================
    function renderReviews() {
        const container = document.getElementById('reviews-container');
        if (!container) return;
        if (!reviewsList.length) {
            container.innerHTML = '<div class="empty-state">Пока нет отзывов. Станьте первым!</div>';
            return;
        }
        let html = '';
        reviewsList.forEach(r => {
            const stars = '★'.repeat(r.rating) + '☆'.repeat(5 - r.rating);
            let photos = '';
            if (r.photos) {
                const photosArr = JSON.parse(r.photos);
                if (photosArr.length) {
                    photos = '<div class="review-photos">';
                    photosArr.forEach(photo => {
                        photos += `<img src="${photo}" class="review-photo" onclick="window.open(this.src)">`;
                    });
                    photos += '</div>';
                }
            }
            html += `
                <div class="review-card">
                    <div class="review-header">
                        <div><strong>${escapeHtml(r.user_name)}</strong> <span class="review-stars">${stars}</span></div>
                        <div>${new Date(r.created_at).toLocaleDateString()}</div>
                    </div>
                    <div class="review-text">${escapeHtml(r.comment)}</div>
                    ${photos}
                </div>
            `;
        });
        container.innerHTML = html;
    }

    async function loadMoreReviews() {
        if (loading || !hasMoreReviews) return;
        loading = true;
        reviewsPage++;
        try {
            const res = await fetch(`/api/shop/manage.php?action=get_reviews&shop_id=${shopId}&page=${reviewsPage}&limit=10&csrf_token=${csrfToken}`);
            const data = await res.json();
            if (data.success && data.reviews.length) {
                reviewsList = reviewsList.concat(data.reviews);
                renderReviews();
                if (data.reviews.length < 10) hasMoreReviews = false;
            } else {
                hasMoreReviews = false;
            }
        } catch(e) { console.error(e); }
        loading = false;
        const loadMoreBtn = document.getElementById('reviews-load-more');
        if (loadMoreBtn) loadMoreBtn.style.display = hasMoreReviews ? 'block' : 'none';
    }

    // ==================== ДОБАВЛЕНИЕ В КОРЗИНУ ====================
    function addToCart(id, name, price, image) {
        cart.add(id, name, price, image);
    }

    function addToCartService(id, name, price) {
        cart.add(id, name, price, '/assets/img/service-icon.png');
    }

    // ==================== БЫСТРЫЙ ПРОСМОТР ====================
    function quickView(productId) {
        alert('Функция быстрого просмотра будет доступна в следующей версии');
    }

    // ==================== ОФОРМЛЕНИЕ ЗАКАЗА ====================
    async function checkout() {
        if (cart.items.length === 0) {
            showToast('Корзина пуста', 'warning');
            return;
        }
        if (!confirm('Оформить заказ?')) return;
        // Здесь должен быть вызов API создания заказа
        try {
            const response = await fetch('/api/orders/create.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    shop_id: shopId,
                    items: cart.items,
                    total: cart.total,
                    csrf_token: csrfToken
                })
            });
            const data = await response.json();
            if (data.success) {
                showToast('Заказ оформлен!', 'success');
                cart.clear();
                window.location.href = `/shop/${shopSlug}/order/${data.order_id}`;
            } else {
                showToast(data.error || 'Ошибка', 'error');
            }
        } catch(e) {
            showToast('Ошибка соединения', 'error');
        }
    }

    // ==================== КАРТА ====================
    function initMap() {
        if (typeof L === 'undefined' || !document.getElementById('map')) return;
        const lat = <?= $lat ?: 'null' ?>;
        const lng = <?= $lng ?: 'null' ?>;
        if (lat && lng) {
            const map = L.map('map').setView([lat, lng], 15);
            L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                attribution: '© OpenStreetMap contributors'
            }).addTo(map);
            L.marker([lat, lng]).addTo(map).bindPopup('<?= addslashes($shop['address']) ?>');
        }
    }

    // ==================== ОБЩИЕ ====================
    function showToast(msg, type = 'success') {
        const colors = { success: '#34C759', error: '#FF3B30', warning: '#FF9500', info: '#5A67D8' };
        Toastify({ text: msg, duration: 3000, backgroundColor: colors[type] }).showToast();
    }
    function formatPrice(price) { return new Intl.NumberFormat('ru-RU').format(price); }
    function escapeHtml(str) { return str ? str.replace(/[&<>]/g, m => ({ '&':'&amp;', '<':'&lt;', '>':'&gt;' }[m] || m)) : ''; }

    // ==================== ИНИЦИАЛИЗАЦИЯ ====================
    document.addEventListener('DOMContentLoaded', () => {
        cart.load();
        renderProducts();
        renderServices();
        renderReviews();
        initMap();
        if (hasMoreProducts) document.getElementById('products-load-more').style.display = 'block';
        if (hasMoreReviews) document.getElementById('reviews-load-more').style.display = 'block';

        // Обработчики корзины
        document.getElementById('cart-icon').addEventListener('click', () => {
            document.getElementById('cart-modal').classList.add('open');
            document.getElementById('cart-overlay').classList.add('open');
        });
        document.getElementById('close-cart').addEventListener('click', () => {
            document.getElementById('cart-modal').classList.remove('open');
            document.getElementById('cart-overlay').classList.remove('open');
        });
        document.getElementById('cart-overlay').addEventListener('click', () => {
            document.getElementById('cart-modal').classList.remove('open');
            document.getElementById('cart-overlay').classList.remove('open');
        });
        document.getElementById('clear-cart-btn').addEventListener('click', () => {
            if (confirm('Очистить корзину?')) cart.clear();
        });
        document.getElementById('checkout-btn').addEventListener('click', checkout);

        // FAQ аккордеон
        document.querySelectorAll('.faq-question').forEach(el => {
            el.addEventListener('click', () => {
                el.nextElementSibling.classList.toggle('open');
                el.querySelector('span').innerText = el.nextElementSibling.classList.contains('open') ? '▲' : '▼';
            });
        });
    });
</script>
</body>
</html>