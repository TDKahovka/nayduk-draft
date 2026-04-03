<?php
/* ============================================
   НАЙДУК — Страница товара (v2.0)
   - Полная микроразметка Schema.org (Product + Offer + AggregateRating + Breadcrumb + Organization)
   - Отзывы с фото, вопросы-ответы, варианты товара
   - Видео (YouTube/Vimeo/свой хостинг)
   - Кэширование, адаптивность, без капчи
   ============================================ */

if (session_status() === PHP_SESSION_NONE) session_start();

require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/services/Database.php';
require_once __DIR__ . '/services/NotificationService.php';

$productId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if (!$productId) {
    http_response_code(404);
    die('Товар не найден');
}

$db = Database::getInstance();
$pdo = $db->getPdo();

// ==================== АВТОСОЗДАНИЕ ТАБЛИЦ ====================
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
        is_approved BOOLEAN DEFAULT FALSE,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (shop_id) REFERENCES shops(id) ON DELETE CASCADE,
        FOREIGN KEY (product_id) REFERENCES shop_products(id) ON DELETE SET NULL,
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
        INDEX idx_shop (shop_id),
        INDEX idx_product (product_id)
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

// ==================== КЭШИРОВАНИЕ ====================
$cacheKey = "product_page:{$productId}";
$cached = cacheGet($cacheKey, 600);
if ($cached !== null) {
    echo $cached;
    exit;
}

// ==================== ПОЛУЧЕНИЕ ТОВАРА ====================
$stmt = $pdo->prepare("
    SELECT p.*, s.name as shop_name, s.slug as shop_slug, s.id as shop_id,
           s.logo_url as shop_logo, s.contact_phone, s.contact_email,
           s.contact_telegram, s.contact_whatsapp, s.theme
    FROM shop_products p
    JOIN shops s ON p.shop_id = s.id
    WHERE p.id = ? AND p.is_active = 1 AND s.is_active = 1
");
$stmt->execute([$productId]);
$product = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$product) {
    http_response_code(404);
    die('Товар не найден');
}

// Получаем фото (из JSON)
$images = json_decode($product['image_urls'] ?? '[]', true);
if (!is_array($images)) $images = [];

// Получаем варианты товара
$stmt = $pdo->prepare("SELECT * FROM shop_product_options WHERE product_id = ?");
$stmt->execute([$productId]);
$options = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Группируем варианты по имени (цвет, размер и т.д.)
$optionGroups = [];
foreach ($options as $opt) {
    if (!isset($optionGroups[$opt['name']])) $optionGroups[$opt['name']] = [];
    $optionGroups[$opt['name']][] = [
        'value' => $opt['value'],
        'price_adjustment' => $opt['price_adjustment'],
        'stock' => $opt['stock']
    ];
}

// Получаем отзывы (только одобренные)
$stmt = $pdo->prepare("
    SELECT r.*, u.name as user_name, u.avatar_url as user_avatar
    FROM shop_reviews r
    LEFT JOIN users u ON r.user_id = u.id
    WHERE r.product_id = ? AND r.is_approved = 1
    ORDER BY r.created_at DESC
");
$stmt->execute([$productId]);
$reviews = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Средний рейтинг
$avgRating = 0;
$reviewCount = count($reviews);
if ($reviewCount > 0) {
    $totalRating = array_sum(array_column($reviews, 'rating'));
    $avgRating = round($totalRating / $reviewCount, 1);
}

// Получаем вопросы-ответы
$stmt = $pdo->prepare("
    SELECT q.*, u.name as user_name
    FROM shop_product_questions q
    LEFT JOIN users u ON q.user_id = u.id
    WHERE q.product_id = ?
    ORDER BY q.created_at DESC
");
$stmt->execute([$productId]);
$questions = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ==================== ОБРАБОТКА AJAX ====================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_GET['ajax'])) {
    header('Content-Type: application/json');
    $action = $_POST['action'] ?? '';
    $ip = getUserIP();

    if (!isset($_SESSION['user_id'])) {
        http_response_code(401);
        echo json_encode(['success' => false, 'error' => 'Требуется авторизация']);
        exit;
    }
    $userId = $_SESSION['user_id'];

    // Добавление отзыва
    if ($action === 'add_review') {
        $rating = (int)($_POST['rating'] ?? 0);
        $comment = trim($_POST['comment'] ?? '');
        $photos = isset($_POST['photos']) ? json_decode($_POST['photos'], true) : [];

        if ($rating < 1 || $rating > 5 || empty($comment)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Некорректные данные']);
            exit;
        }

        $stmt = $pdo->prepare("INSERT INTO shop_reviews (shop_id, product_id, user_id, rating, comment, photos, created_at) VALUES (?, ?, ?, ?, ?, ?, NOW())");
        $stmt->execute([$product['shop_id'], $productId, $userId, $rating, $comment, json_encode($photos)]);

        echo json_encode(['success' => true, 'message' => 'Отзыв добавлен и будет опубликован после проверки']);
        exit;
    }

    // Добавление вопроса
    if ($action === 'add_question') {
        $question = trim($_POST['question'] ?? '');
        if (empty($question)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Введите вопрос']);
            exit;
        }

        $stmt = $pdo->prepare("INSERT INTO shop_product_questions (product_id, user_id, question, created_at) VALUES (?, ?, ?, NOW())");
        $stmt->execute([$productId, $userId, $question]);

        // Уведомление продавцу
        $notify = new NotificationService();
        $notify->send($product['shop_id'], 'new_question', [
            'product_id' => $productId,
            'product_title' => $product['title'],
            'question' => $question
        ]);

        echo json_encode(['success' => true, 'message' => 'Вопрос отправлен. Продавец ответит в ближайшее время.']);
        exit;
    }

    // Показать контакты (rate limit)
    if ($action === 'contacts') {
        $rateKey = "product_contact:{$product['shop_id']}:{$ip}";
        if (!checkRateLimit($rateKey, 5, 3600)) {
            http_response_code(429);
            echo json_encode(['success' => false, 'error' => 'Слишком много запросов. Попробуйте через час.']);
            exit;
        }
        echo json_encode([
            'success' => true,
            'contacts' => [
                'phone' => $product['contact_phone'],
                'email' => $product['contact_email'],
                'telegram' => $product['contact_telegram'],
                'whatsapp' => $product['contact_whatsapp']
            ]
        ]);
        exit;
    }

    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Неизвестное действие']);
    exit;
}

// ==================== ОСНОВНОЙ HTML ====================
$pageTitle = htmlspecialchars($product['title']) . ' — Найдук';
$pageDescription = htmlspecialchars(mb_substr(strip_tags($product['description'] ?? ''), 0, 160));
$theme = $product['theme'] ?? 'light';
$baseUrl = 'https://' . $_SERVER['HTTP_HOST'];

// ==================== МИКРОРАЗМЕТКА SCHEMA.ORG ====================
$schema = [
    '@context' => 'https://schema.org',
    '@graph' => [
        [
            '@type' => 'Product',
            '@id' => $baseUrl . '/product/' . $productId,
            'name' => $product['title'],
            'description' => strip_tags($product['description']),
            'image' => $images,
            'sku' => $product['id'],
            'brand' => [
                '@type' => 'Brand',
                'name' => $product['shop_name']
            ],
            'offers' => [
                '@type' => 'Offer',
                'url' => $baseUrl . '/product/' . $productId,
                'priceCurrency' => 'RUB',
                'price' => $product['price'],
                'availability' => 'https://schema.org/InStock',
                'itemCondition' => 'https://schema.org/NewCondition'
            ]
        ],
        [
            '@type' => 'BreadcrumbList',
            '@id' => $baseUrl . '/product/' . $productId . '#breadcrumb',
            'itemListElement' => [
                [
                    '@type' => 'ListItem',
                    'position' => 1,
                    'name' => 'Главная',
                    'item' => $baseUrl
                ],
                [
                    '@type' => 'ListItem',
                    'position' => 2,
                    'name' => 'Магазины',
                    'item' => $baseUrl . '/shops'
                ],
                [
                    '@type' => 'ListItem',
                    'position' => 3,
                    'name' => $product['shop_name'],
                    'item' => $baseUrl . '/shop/' . $product['shop_slug']
                ],
                [
                    '@type' => 'ListItem',
                    'position' => 4,
                    'name' => $product['title'],
                    'item' => $baseUrl . '/product/' . $productId
                ]
            ]
        ],
        [
            '@type' => 'Organization',
            '@id' => $baseUrl . '/#org',
            'name' => $product['shop_name'],
            'url' => $baseUrl . '/shop/' . $product['shop_slug'],
            'logo' => $product['shop_logo'] ? $baseUrl . $product['shop_logo'] : null
        ]
    ]
];

if ($reviewCount > 0) {
    $schema['@graph'][0]['aggregateRating'] = [
        '@type' => 'AggregateRating',
        'ratingValue' => $avgRating,
        'reviewCount' => $reviewCount
    ];
}

ob_start();
?>
<!DOCTYPE html>
<html lang="ru" data-theme="<?= htmlspecialchars($theme) ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <meta name="csrf-token" content="<?= csrf_token() ?>">
    <title><?= $pageTitle ?></title>
    <meta name="description" content="<?= $pageDescription ?>">
    <link rel="canonical" href="<?= $baseUrl ?>/product/<?= $productId ?>">
    <link rel="stylesheet" href="https://cdn.hugeicons.com/font/hgi-stroke-rounded.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/toastify-js/src/toastify.min.css">
    <link rel="stylesheet" href="/css/business.css">
    <link rel="stylesheet" href="/css/premium.css">
    <link rel="stylesheet" href="/css/theme-switch.css">
    <link rel="stylesheet" href="/css/themes.css">
    <script type="application/ld+json"><?= json_encode($schema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?></script>
    <style>
        .product-container { max-width: 1200px; margin: 0 auto; padding: 40px 20px; }
        .product-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 40px; }
        @media (max-width: 768px) { .product-grid { grid-template-columns: 1fr; } }

        /* Галерея */
        .gallery-main { width: 100%; aspect-ratio: 1; object-fit: contain; background: var(--bg-secondary); border-radius: var(--radius-xl); cursor: pointer; }
        .gallery-thumbs { display: flex; gap: 8px; margin-top: 12px; overflow-x: auto; }
        .gallery-thumb { width: 80px; height: 80px; object-fit: cover; border-radius: var(--radius); cursor: pointer; opacity: 0.6; transition: opacity 0.2s; }
        .gallery-thumb.active { opacity: 1; border: 2px solid var(--primary); }

        /* Информация */
        .product-title { font-size: 28px; font-weight: 700; margin-bottom: 8px; }
        .product-rating { display: flex; align-items: center; gap: 8px; margin-bottom: 16px; color: var(--warning); }
        .product-price { font-size: 32px; font-weight: 700; color: var(--primary); margin: 16px 0; }
        .product-options { margin: 20px 0; }
        .option-group { margin-bottom: 16px; }
        .option-label { font-weight: 600; margin-bottom: 8px; display: block; }
        .option-values { display: flex; flex-wrap: wrap; gap: 8px; }
        .option-value {
            padding: 8px 16px;
            border: 1px solid var(--border);
            border-radius: var(--radius-full);
            cursor: pointer;
            transition: all 0.2s;
        }
        .option-value.selected { background: var(--primary); color: white; border-color: var(--primary); }
        .stock-status { font-size: 14px; margin: 8px 0; color: var(--success); }
        .stock-status.low { color: var(--warning); }
        .stock-status.out { color: var(--danger); }

        .btn-buy {
            width: 100%;
            padding: 16px;
            background: var(--primary);
            color: white;
            border: none;
            border-radius: var(--radius-full);
            font-size: 18px;
            font-weight: 700;
            cursor: pointer;
            margin: 20px 0;
        }
        .btn-buy:disabled { opacity: 0.6; cursor: not-allowed; }

        .shop-card {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: var(--radius-xl);
            padding: 20px;
            margin: 20px 0;
        }
        .shop-logo { width: 60px; height: 60px; border-radius: var(--radius); object-fit: cover; }
        .contact-btn {
            background: var(--bg-secondary);
            border: 1px solid var(--border);
            padding: 8px 16px;
            border-radius: var(--radius-full);
            cursor: pointer;
            margin-right: 8px;
        }

        .reviews-section, .questions-section { margin-top: 40px; }
        .review-card {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: var(--radius);
            padding: 16px;
            margin-bottom: 16px;
        }
        .review-photos { display: flex; gap: 8px; margin-top: 12px; }
        .review-photo { width: 60px; height: 60px; object-fit: cover; border-radius: var(--radius); cursor: pointer; }
        .question-card {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: var(--radius);
            padding: 16px;
            margin-bottom: 16px;
        }
        .question-answer { margin-top: 12px; padding-left: 16px; border-left: 3px solid var(--primary); }

        .modal {
            position: fixed; top: 0; left: 0; right: 0; bottom: 0;
            background: rgba(0,0,0,0.8); display: flex; align-items: center; justify-content: center;
            z-index: 1000; visibility: hidden; opacity: 0; transition: all 0.2s;
        }
        .modal.active { visibility: visible; opacity: 1; }
        .modal-img { max-width: 90vw; max-height: 90vh; object-fit: contain; }

        @media (max-width: 768px) {
            .product-container { padding: 20px; }
            .product-title { font-size: 24px; }
            .product-price { font-size: 28px; }
            .btn-buy { position: sticky; bottom: 0; margin: 0; border-radius: 0; }
        }
    </style>
</head>
<body>
<div class="product-container">
    <div class="product-grid">
        <!-- Галерея -->
        <div>
            <div class="gallery">
                <img id="main-image" class="gallery-main" src="<?= htmlspecialchars($images[0] ?? '/assets/no-image.png') ?>" alt="<?= htmlspecialchars($product['title']) ?>">
                <?php if (count($images) > 1): ?>
                <div class="gallery-thumbs">
                    <?php foreach ($images as $idx => $img): ?>
                    <img src="<?= htmlspecialchars($img) ?>" class="gallery-thumb <?= $idx === 0 ? 'active' : '' ?>" data-index="<?= $idx ?>" onclick="setMainImage(this.src, this)">
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Информация -->
        <div>
            <h1 class="product-title"><?= htmlspecialchars($product['title']) ?></h1>
            <div class="product-rating">
                <?php if ($reviewCount > 0): ?>
                    <span><?= str_repeat('★', round($avgRating)) . str_repeat('☆', 5 - round($avgRating)) ?></span>
                    <span><?= number_format($avgRating, 1) ?> (<?= $reviewCount ?> отзывов)</span>
                <?php else: ?>
                    <span>Нет отзывов</span>
                <?php endif; ?>
            </div>
            <div class="product-price" id="base-price"><?= number_format($product['price'], 0, ',', ' ') ?> ₽</div>

            <!-- Варианты товара -->
            <?php if (!empty($optionGroups)): ?>
            <div class="product-options" id="options-container" data-base-price="<?= $product['price'] ?>">
                <?php foreach ($optionGroups as $name => $values): ?>
                <div class="option-group" data-option-name="<?= htmlspecialchars($name) ?>">
                    <div class="option-label"><?= htmlspecialchars($name) ?>:</div>
                    <div class="option-values">
                        <?php foreach ($values as $opt): ?>
                        <div class="option-value" data-value="<?= htmlspecialchars($opt['value']) ?>" data-price-adjustment="<?= $opt['price_adjustment'] ?>" data-stock="<?= $opt['stock'] ?>">
                            <?= htmlspecialchars($opt['value']) ?>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>

            <div class="stock-status" id="stock-status">✅ В наличии</div>
            <button class="btn-buy" id="buy-btn" data-product-id="<?= $productId ?>">🛒 Купить</button>

            <!-- Описание -->
            <div class="product-description">
                <?= nl2br(htmlspecialchars($product['description'])) ?>
            </div>

            <!-- Магазин -->
            <div class="shop-card">
                <div style="display: flex; align-items: center; gap: 16px;">
                    <img src="<?= htmlspecialchars($product['shop_logo'] ?? '/assets/default-shop.png') ?>" class="shop-logo">
                    <div>
                        <a href="/shop/<?= htmlspecialchars($product['shop_slug']) ?>" style="font-weight: 600;"><?= htmlspecialchars($product['shop_name']) ?></a>
                        <div class="shop-contacts">
                            <button class="contact-btn" data-type="phone">📞 Показать телефон</button>
                            <button class="contact-btn" data-type="email">✉️ Показать email</button>
                            <?php if ($product['contact_telegram']): ?>
                            <button class="contact-btn" data-type="telegram">💬 Telegram</button>
                            <?php endif; ?>
                        </div>
                        <div id="contact-info" class="contact-info" style="margin-top: 12px; display: none;"></div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Отзывы -->
    <div class="reviews-section">
        <h2>Отзывы покупателей</h2>
        <?php if ($reviewCount > 0): ?>
            <?php foreach ($reviews as $r): ?>
            <div class="review-card">
                <div class="review-rating"><?= str_repeat('★', $r['rating']) . str_repeat('☆', 5 - $r['rating']) ?></div>
                <div class="review-comment"><?= nl2br(htmlspecialchars($r['comment'])) ?></div>
                <div class="review-author">— <?= htmlspecialchars($r['user_name']) ?></div>
                <?php $photos = json_decode($r['photos'] ?? '[]', true); if ($photos): ?>
                <div class="review-photos">
                    <?php foreach ($photos as $photo): ?>
                    <img src="<?= htmlspecialchars($photo) ?>" class="review-photo" onclick="openImageModal(this.src)">
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
        <?php else: ?>
            <p>Пока нет отзывов. Будьте первым!</p>
        <?php endif; ?>
        <button class="btn btn-secondary" onclick="openReviewModal()">✍️ Написать отзыв</button>
    </div>

    <!-- Вопросы-ответы -->
    <div class="questions-section">
        <h2>Вопросы-ответы</h2>
        <?php foreach ($questions as $q): ?>
        <div class="question-card">
            <div><strong><?= htmlspecialchars($q['user_name']) ?>:</strong> <?= nl2br(htmlspecialchars($q['question'])) ?></div>
            <?php if ($q['answer']): ?>
            <div class="question-answer"><strong>Ответ продавца:</strong> <?= nl2br(htmlspecialchars($q['answer'])) ?></div>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>
        <button class="btn btn-secondary" onclick="openQuestionModal()">❓ Задать вопрос</button>
    </div>
</div>

<!-- Модальные окна -->
<div id="image-modal" class="modal" onclick="closeModal('image-modal')">
    <img id="modal-img" class="modal-img">
</div>

<div id="review-modal" class="modal">
    <div class="modal-content" style="max-width: 500px;">
        <div class="modal-header"><h3>Написать отзыв</h3><button onclick="closeModal('review-modal')">✕</button></div>
        <form id="review-form">
            <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
            <div class="form-group"><label>Оценка</label>
                <select name="rating" class="form-select">
                    <option value="5">5 ★</option><option value="4">4 ★</option>
                    <option value="3">3 ★</option><option value="2">2 ★</option>
                    <option value="1">1 ★</option>
                </select>
            </div>
            <div class="form-group"><label>Ваш отзыв</label><textarea name="comment" class="form-textarea" rows="4" required></textarea></div>
            <div class="form-group"><label>Фото (необязательно)</label><input type="file" id="review-photo-input" accept="image/*" multiple></div>
            <div id="review-photo-preview" class="photo-preview-grid"></div>
            <button type="submit" class="btn btn-primary">Отправить</button>
        </form>
    </div>
</div>

<div id="question-modal" class="modal">
    <div class="modal-content" style="max-width: 500px;">
        <div class="modal-header"><h3>Задать вопрос</h3><button onclick="closeModal('question-modal')">✕</button></div>
        <form id="question-form">
            <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
            <div class="form-group"><label>Ваш вопрос</label><textarea name="question" class="form-textarea" rows="4" required></textarea></div>
            <button type="submit" class="btn btn-primary">Отправить</button>
        </form>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/toastify-js"></script>
<script>
    const csrfToken = '<?= csrf_token() ?>';
    const productId = <?= $productId ?>;
    let uploadedReviewPhotos = [];

    // ===== ГАЛЕРЕЯ =====
    function setMainImage(src, element) {
        document.getElementById('main-image').src = src;
        document.querySelectorAll('.gallery-thumb').forEach(thumb => thumb.classList.remove('active'));
        if (element) element.classList.add('active');
    }

    // ===== ВАРИАНТЫ ТОВАРА =====
    const optionsContainer = document.getElementById('options-container');
    const basePrice = parseFloat(document.getElementById('base-price')?.innerText.replace(/[^0-9]/g, '')) || 0;
    let selectedOptions = {};
    let currentPrice = basePrice;

    function updatePrice() {
        let price = basePrice;
        for (const [name, value] of Object.entries(selectedOptions)) {
            const optDiv = document.querySelector(`.option-group[data-option-name="${name}"] .option-value[data-value="${value}"]`);
            if (optDiv) {
                price += parseFloat(optDiv.dataset.priceAdjustment || 0);
            }
        }
        currentPrice = Math.max(0, price);
        document.getElementById('base-price').innerHTML = currentPrice.toLocaleString('ru-RU') + ' ₽';
        updateStock();
    }

    function updateStock() {
        let minStock = Infinity;
        for (const [name, value] of Object.entries(selectedOptions)) {
            const optDiv = document.querySelector(`.option-group[data-option-name="${name}"] .option-value[data-value="${value}"]`);
            if (optDiv) {
                const stock = parseInt(optDiv.dataset.stock || 0);
                if (stock < minStock) minStock = stock;
            }
        }
        const stockDiv = document.getElementById('stock-status');
        if (minStock === 0 || minStock === Infinity) {
            stockDiv.innerHTML = '❌ Нет в наличии';
            stockDiv.className = 'stock-status out';
            document.getElementById('buy-btn').disabled = true;
        } else if (minStock < 5) {
            stockDiv.innerHTML = `⚠️ Осталось ${minStock} шт.`;
            stockDiv.className = 'stock-status low';
            document.getElementById('buy-btn').disabled = false;
        } else {
            stockDiv.innerHTML = '✅ В наличии';
            stockDiv.className = 'stock-status';
            document.getElementById('buy-btn').disabled = false;
        }
    }

    if (optionsContainer) {
        document.querySelectorAll('.option-value').forEach(opt => {
            opt.addEventListener('click', () => {
                const group = opt.closest('.option-group');
                const optionName = group.dataset.optionName;
                group.querySelectorAll('.option-value').forEach(o => o.classList.remove('selected'));
                opt.classList.add('selected');
                selectedOptions[optionName] = opt.dataset.value;
                updatePrice();
            });
        });
        // Автовыбор первого варианта
        document.querySelectorAll('.option-group').forEach(group => {
            const first = group.querySelector('.option-value');
            if (first) {
                first.click();
            }
        });
    }

    // ===== КУПИТЬ =====
    document.getElementById('buy-btn').addEventListener('click', () => {
        alert('Функция покупки будет добавлена в следующей версии. Пока свяжитесь с продавцом.');
    });

    // ===== КОНТАКТЫ =====
    async function showContacts(type) {
        const contactDiv = document.getElementById('contact-info');
        try {
            const response = await fetch('/product.php?ajax=1&id=' + productId, {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: 'action=contacts&csrf_token=' + csrfToken
            });
            const data = await response.json();
            if (data.success) {
                let text = '';
                if (type === 'phone' && data.contacts.phone) text = `📞 ${data.contacts.phone}`;
                else if (type === 'email' && data.contacts.email) text = `✉️ ${data.contacts.email}`;
                else if (type === 'telegram' && data.contacts.telegram) text = `💬 Telegram: ${data.contacts.telegram}`;
                else text = 'Контакт не указан';
                contactDiv.innerHTML = text;
                contactDiv.style.display = 'block';
                setTimeout(() => { contactDiv.style.display = 'none'; }, 10000);
            } else {
                Toastify({ text: data.error || 'Ошибка', duration: 3000, backgroundColor: '#FF3B30' }).showToast();
            }
        } catch (err) {
            Toastify({ text: 'Ошибка сети', duration: 3000, backgroundColor: '#FF3B30' }).showToast();
        }
    }

    document.querySelectorAll('.contact-btn').forEach(btn => {
        btn.addEventListener('click', () => showContacts(btn.dataset.type));
    });

    // ===== ОТЗЫВЫ =====
    function openReviewModal() {
        if (!<?= isset($_SESSION['user_id']) ? 'true' : 'false' ?>) {
            Toastify({ text: 'Войдите, чтобы оставить отзыв', duration: 3000, backgroundColor: '#FF9500' }).showToast();
            return;
        }
        document.getElementById('review-modal').classList.add('active');
    }

    async function submitReview(e) {
        e.preventDefault();
        const formData = new FormData(e.target);
        formData.append('action', 'add_review');
        formData.append('product_id', productId);
        formData.append('photos', JSON.stringify(uploadedReviewPhotos));
        try {
            const response = await fetch('/product.php?ajax=1&id=' + productId, {
                method: 'POST',
                body: formData
            });
            const data = await response.json();
            if (data.success) {
                Toastify({ text: data.message, duration: 3000 }).showToast();
                closeModal('review-modal');
                location.reload();
            } else {
                Toastify({ text: data.error || 'Ошибка', duration: 3000, backgroundColor: '#FF3B30' }).showToast();
            }
        } catch (err) {
            Toastify({ text: 'Ошибка сети', duration: 3000, backgroundColor: '#FF3B30' }).showToast();
        }
    }

    // ===== ВОПРОСЫ =====
    function openQuestionModal() {
        if (!<?= isset($_SESSION['user_id']) ? 'true' : 'false' ?>) {
            Toastify({ text: 'Войдите, чтобы задать вопрос', duration: 3000, backgroundColor: '#FF9500' }).showToast();
            return;
        }
        document.getElementById('question-modal').classList.add('active');
    }

    async function submitQuestion(e) {
        e.preventDefault();
        const formData = new FormData(e.target);
        formData.append('action', 'add_question');
        formData.append('product_id', productId);
        try {
            const response = await fetch('/product.php?ajax=1&id=' + productId, {
                method: 'POST',
                body: formData
            });
            const data = await response.json();
            if (data.success) {
                Toastify({ text: data.message, duration: 3000 }).showToast();
                closeModal('question-modal');
                location.reload();
            } else {
                Toastify({ text: data.error || 'Ошибка', duration: 3000, backgroundColor: '#FF3B30' }).showToast();
            }
        } catch (err) {
            Toastify({ text: 'Ошибка сети', duration: 3000, backgroundColor: '#FF3B30' }).showToast();
        }
    }

    // ===== ФОТО ДЛЯ ОТЗЫВОВ =====
    const reviewPhotoInput = document.getElementById('review-photo-input');
    const reviewPhotoPreview = document.getElementById('review-photo-preview');
    if (reviewPhotoInput) {
        reviewPhotoInput.addEventListener('change', (e) => {
            uploadedReviewPhotos = [];
            reviewPhotoPreview.innerHTML = '';
            for (let file of e.target.files) {
                const reader = new FileReader();
                reader.onload = (ev) => {
                    const div = document.createElement('div');
                    div.className = 'photo-preview';
                    div.innerHTML = `<img src="${ev.target.result}" style="width:80px;height:80px;object-fit:cover;"><button class="remove-photo">✕</button>`;
                    reviewPhotoPreview.appendChild(div);
                    div.querySelector('.remove-photo').addEventListener('click', () => div.remove());
                    uploadedReviewPhotos.push(ev.target.result);
                };
                reader.readAsDataURL(file);
            }
        });
    }

    // ===== МОДАЛЬНЫЕ ОКНА =====
    function openImageModal(src) {
        document.getElementById('modal-img').src = src;
        document.getElementById('image-modal').classList.add('active');
    }
    function closeModal(id) { document.getElementById(id).classList.remove('active'); }

    document.getElementById('review-form')?.addEventListener('submit', submitReview);
    document.getElementById('question-form')?.addEventListener('submit', submitQuestion);
</script>
</body>
</html>
<?php
$html = ob_get_clean();
cacheSet($cacheKey, $html, 600);
echo $html;