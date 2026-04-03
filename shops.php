<?php
/* ============================================
   НАЙДУК — Универсальная страница магазина (v3.0)
   - Поддержка блоков (hero, products, reviews, faq, map, contacts)
   - Кэширование
   - Микроразметка LocalBusiness + Product
   ============================================ */

if (session_status() === PHP_SESSION_NONE) session_start();

require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/services/Database.php';
require_once __DIR__ . '/services/GeoService.php';

$slug = trim($_GET['slug'] ?? '');
if (empty($slug)) {
    http_response_code(404);
    die('Магазин не найден');
}

$db = Database::getInstance();
$pdo = $db->getPdo();

// ==================== КЭШИРОВАНИЕ ====================
$cacheKey = "shop_page:{$slug}";
$cached = cacheGet($cacheKey, 300);
if ($cached !== null) {
    echo $cached;
    exit;
}

// ==================== ПОЛУЧЕНИЕ ДАННЫХ МАГАЗИНА ====================
$stmt = $pdo->prepare("
    SELECT s.*, u.name as owner_name, u.avatar_url as owner_avatar
    FROM shops s
    LEFT JOIN users u ON s.user_id = u.id
    WHERE s.slug = ? AND s.is_active = 1
");
$stmt->execute([$slug]);
$shop = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$shop) {
    http_response_code(404);
    die('Магазин не найден');
}

// ==================== ПОЛУЧЕНИЕ МАКЕТА ====================
$layout = json_decode($shop['layout'] ?? '[]', true);
if (empty($layout)) {
    // Макет по умолчанию
    $layout = [
        ['type' => 'hero', 'title' => $shop['name'], 'subtitle' => $shop['description'] ?? ''],
        ['type' => 'products', 'limit' => 12, 'sort' => 'created_desc'],
        ['type' => 'reviews', 'limit' => 6],
        ['type' => 'faq'],
        ['type' => 'map'],
        ['type' => 'contacts']
    ];
}

// ==================== ОБРАБОТКА AJAX ====================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_GET['ajax'])) {
    header('Content-Type: application/json');
    $action = $_POST['action'] ?? '';
    $ip = getUserIP();

    // Показ контактов (rate limit)
    if ($action === 'contacts') {
        $rateKey = "shop_contact:{$shop['id']}:{$ip}";
        if (!checkRateLimit($rateKey, 3, 3600)) {
            http_response_code(429);
            echo json_encode(['success' => false, 'error' => 'Слишком много запросов. Попробуйте через час.']);
            exit;
        }
        echo json_encode([
            'success' => true,
            'contacts' => [
                'phone' => $shop['contact_phone'],
                'email' => $shop['contact_email'],
                'telegram' => $shop['contact_telegram'],
                'whatsapp' => $shop['contact_whatsapp']
            ]
        ]);
        exit;
    }

    // Добавление отзыва
    if ($action === 'add_review') {
        if (!isset($_SESSION['user_id'])) {
            http_response_code(401);
            echo json_encode(['success' => false, 'error' => 'Требуется авторизация']);
            exit;
        }
        $userId = $_SESSION['user_id'];
        $rating = (int)($_POST['rating'] ?? 0);
        $comment = trim($_POST['comment'] ?? '');
        $productId = (int)($_POST['product_id'] ?? 0);
        if ($rating < 1 || $rating > 5 || empty($comment)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Некорректные данные']);
            exit;
        }
        $stmt = $pdo->prepare("INSERT INTO shop_reviews (shop_id, product_id, user_id, rating, comment) VALUES (?, ?, ?, ?, ?)");
        $stmt->execute([$shop['id'], $productId ?: null, $userId, $rating, $comment]);
        echo json_encode(['success' => true, 'message' => 'Отзыв добавлен и будет опубликован после проверки']);
        exit;
    }

    // Запись на услугу
    if ($action === 'book') {
        if (!isset($_SESSION['user_id'])) {
            http_response_code(401);
            echo json_encode(['success' => false, 'error' => 'Требуется авторизация']);
            exit;
        }
        $userId = $_SESSION['user_id'];
        $productId = (int)($_POST['product_id'] ?? 0);
        $bookingDate = $_POST['date'] ?? '';
        $bookingTime = $_POST['time'] ?? '';
        $duration = (int)($_POST['duration'] ?? 60);
        if (!$productId || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $bookingDate) || !preg_match('/^\d{2}:\d{2}$/', $bookingTime)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Некорректные данные']);
            exit;
        }
        // Проверка, что слот свободен (упрощённо)
        $stmt = $pdo->prepare("SELECT id FROM shop_bookings WHERE shop_id = ? AND booking_date = ? AND booking_time = ? AND status IN ('pending','confirmed')");
        $stmt->execute([$shop['id'], $bookingDate, $bookingTime]);
        if ($stmt->fetch()) {
            http_response_code(409);
            echo json_encode(['success' => false, 'error' => 'Это время уже занято']);
            exit;
        }
        $stmt = $pdo->prepare("INSERT INTO shop_bookings (shop_id, product_id, user_id, booking_date, booking_time, duration) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->execute([$shop['id'], $productId, $userId, $bookingDate, $bookingTime, $duration]);
        echo json_encode(['success' => true, 'message' => 'Запись создана, ожидайте подтверждения']);
        exit;
    }

    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Неизвестное действие']);
    exit;
}

// ==================== ОСНОВНОЙ HTML ====================
$pageTitle = htmlspecialchars($shop['name']) . ' — Найдук';
$pageDescription = htmlspecialchars(mb_substr(strip_tags($shop['description'] ?? ''), 0, 160));

// Микроразметка LocalBusiness
$schema = [
    '@context' => 'https://schema.org',
    '@type' => 'LocalBusiness',
    'name' => $shop['name'],
    'description' => $shop['description'],
    'image' => $shop['logo_url'] ?? '',
    'url' => 'https://' . $_SERVER['HTTP_HOST'] . '/shop/' . $slug,
    'address' => ['@type' => 'PostalAddress', 'streetAddress' => $shop['address']],
    'geo' => ['@type' => 'GeoCoordinates', 'latitude' => $shop['lat'], 'longitude' => $shop['lng']],
    'telephone' => $shop['contact_phone'] ?? '',
    'email' => $shop['contact_email'] ?? '',
    'priceRange' => '₽'
];

$theme = $shop['theme'] ?? 'light';
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
    <link rel="canonical" href="https://<?= $_SERVER['HTTP_HOST'] ?>/shop/<?= urlencode($slug) ?>">
    <link rel="stylesheet" href="https://cdn.hugeicons.com/font/hgi-stroke-rounded.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/toastify-js/src/toastify.min.css">
    <link rel="stylesheet" href="/css/business.css">
    <link rel="stylesheet" href="/css/premium.css">
    <link rel="stylesheet" href="/css/theme-switch.css">
    <link rel="stylesheet" href="/css/themes.css">
    <script type="application/ld+json"><?= json_encode($schema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?></script>
    <style>
        .shop-container { max-width: 1200px; margin: 0 auto; padding: 40px 20px; }
        .shop-block { margin-bottom: 60px; }
        .products-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
            gap: 24px;
        }
        .product-card {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: var(--radius-xl);
            overflow: hidden;
            transition: transform 0.2s, box-shadow 0.2s;
        }
        .product-card:hover { transform: translateY(-4px); box-shadow: var(--shadow-md); }
        .product-image {
            aspect-ratio: 1;
            object-fit: cover;
            width: 100%;
            background: var(--bg-secondary);
        }
        .product-info { padding: 16px; }
        .product-title { font-size: 16px; font-weight: 600; margin-bottom: 8px; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; }
        .product-price { font-size: 20px; font-weight: 700; color: var(--primary); margin-bottom: 12px; }
        .btn-product {
            display: block;
            text-align: center;
            background: var(--primary);
            color: white;
            padding: 8px;
            border-radius: var(--radius-full);
            text-decoration: none;
            font-weight: 600;
        }
        .reviews-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
            gap: 24px;
        }
        .review-card {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: var(--radius);
            padding: 16px;
        }
        .review-rating { color: var(--warning); margin-bottom: 8px; }
        .review-photos { display: flex; gap: 8px; margin-top: 12px; }
        .review-photo { width: 60px; height: 60px; object-fit: cover; border-radius: var(--radius); cursor: pointer; }
        .contact-btn {
            background: var(--bg-secondary);
            border: 1px solid var(--border);
            padding: 8px 20px;
            border-radius: var(--radius-full);
            cursor: pointer;
            margin-right: 8px;
        }
        .map { height: 300px; background: var(--bg-secondary); border-radius: var(--radius); margin-top: 16px; }
        .faq-item { border-bottom: 1px solid var(--border-light); padding: 16px 0; }
        .faq-question { font-weight: 600; cursor: pointer; }
        .faq-answer { display: none; margin-top: 8px; color: var(--text-secondary); }
        .faq-answer.show { display: block; }
        @media (max-width: 768px) {
            .shop-container { padding: 20px; }
            .products-grid, .reviews-grid { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>
<div class="shop-container">
    <?php foreach ($layout as $block): ?>
        <?php
        $type = $block['type'];
        $params = $block;
        include __DIR__ . '/blocks/' . $type . '.php';
        ?>
    <?php endforeach; ?>
</div>

<script src="https://cdn.jsdelivr.net/npm/toastify-js"></script>
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
<script>
    const csrfToken = '<?= csrf_token() ?>';
    const shopId = <?= $shop['id'] ?>;
    const shopSlug = '<?= addslashes($slug) ?>';

    // Показ контактов
    document.querySelectorAll('.contact-btn').forEach(btn => {
        btn.addEventListener('click', async (e) => {
            const type = btn.dataset.type;
            const contactDiv = document.getElementById('contact-info');
            try {
                const response = await fetch('/shop.php?ajax=1&slug=' + encodeURIComponent(shopSlug), {
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
                    else if (type === 'whatsapp' && data.contacts.whatsapp) text = `💬 WhatsApp: ${data.contacts.whatsapp}`;
                    else text = 'Контакт не указан';
                    contactDiv.innerHTML = text;
                    contactDiv.classList.add('show');
                } else {
                    Toastify({ text: data.error || 'Ошибка', duration: 3000, backgroundColor: '#FF3B30' }).showToast();
                }
            } catch (err) {
                Toastify({ text: 'Ошибка сети', duration: 3000, backgroundColor: '#FF3B30' }).showToast();
            }
        });
    });

    // FAQ аккордеон
    document.querySelectorAll('.faq-question').forEach(q => {
        q.addEventListener('click', () => {
            const answer = q.nextElementSibling;
            answer.classList.toggle('show');
        });
    });

    // Карта
    const mapDiv = document.getElementById('map');
    if (mapDiv && mapDiv.dataset.lat && mapDiv.dataset.lng) {
        const lat = parseFloat(mapDiv.dataset.lat);
        const lng = parseFloat(mapDiv.dataset.lng);
        if (!isNaN(lat) && !isNaN(lng)) {
            const map = L.map(mapDiv).setView([lat, lng], 15);
            L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', { attribution: '&copy; OSM' }).addTo(map);
            L.marker([lat, lng]).addTo(map);
        }
    }
</script>
</body>
</html>
<?php
$html = ob_get_clean();
cacheSet($cacheKey, $html, 300);
echo $html;