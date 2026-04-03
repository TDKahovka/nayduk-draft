<?php
/* ============================================
   НАЙДУК — Единая точка входа (фронт-контроллер)
   Версия 4.3 (апрель 2026)
   - Добавлены маршруты для аукционов
   ============================================ */

require_once __DIR__ . '/../vendor/autoload.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../includes/functions.php';

$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$uri = rtrim($uri, '/');
$uri = $uri ?: '/';

// ===== 1. API-запросы – не оборачиваем =====
if (strpos($uri, '/api/') === 0) {
    $file = __DIR__ . '/..' . $uri . '.php';
    if (!file_exists($file)) {
        $file = __DIR__ . '/..' . $uri;
    }
    if (file_exists($file)) {
        require $file;
        exit;
    }
    http_response_code(404);
    echo json_encode(['error' => 'Not found']);
    exit;
}

// ===== 2. Статические файлы – отдаём напрямую =====
$staticExtensions = ['css', 'js', 'jpg', 'jpeg', 'png', 'gif', 'svg', 'ico', 'webp', 'woff2', 'woff', 'ttf'];
$ext = pathinfo($uri, PATHINFO_EXTENSION);
if (in_array($ext, $staticExtensions)) {
    $file = __DIR__ . '/..' . $uri;
    if (file_exists($file)) {
        $modified = filemtime($file);
        $etag = md5_file($file);
        header('Last-Modified: ' . gmdate('D, d M Y H:i:s', $modified) . ' GMT');
        header('ETag: "' . $etag . '"');
        header('Cache-Control: public, max-age=86400');
        if (isset($_SERVER['HTTP_IF_MODIFIED_SINCE']) || isset($_SERVER['HTTP_IF_NONE_MATCH'])) {
            $ifModifiedSince = $_SERVER['HTTP_IF_MODIFIED_SINCE'] ?? '';
            $ifNoneMatch = $_SERVER['HTTP_IF_NONE_MATCH'] ?? '';
            if (strtotime($ifModifiedSince) >= $modified || $ifNoneMatch === '"' . $etag . '"') {
                http_response_code(304);
                exit;
            }
        }
        header('Content-Type: ' . mime_content_type($file));
        readfile($file);
        exit;
    }
    http_response_code(404);
    exit;
}

// ===== 3. Маршрутизация =====
$routes = [
    // Главная
    '/' => '/pages/home.php',
    
    // Категории
    '/categories' => '/pages/categories.php',
    '/category/([^/]+)' => '/pages/category.php?slug=$1',
    '/category/([^/]+)/([^/]+)' => '/pages/category.php?slug=$1&sub=$2',
    
    // Объявления
    '/listing' => '/pages/listing_create.php',
    '/listing/create' => '/pages/listing_create.php',
    '/listing/(\d+)' => '/pages/listing.php?id=$1',
    '/search' => '/pages/search.php',
    
    // Магазины
    '/shop/([^/]+)' => '/shop/index.php?slug=$1',
    '/shop/order/(\d+)' => '/shop/order.php?id=$1',
    
    // Аукционы
    '/auctions' => '/auctions/index.php',
    '/auctions/create' => '/auctions/create.php',
    '/auctions/my' => '/auctions/my.php',
    '/auctions/accept/(\d+)' => '/auctions/accept_offer.php?offer_id=$1',
    '/auctions/(\d+)' => '/auctions/view.php?id=$1',
    
    // Профиль и авторизация
    '/profile' => '/pages/profile.php',
    '/auth/login' => '/pages/auth/login.php',
    '/auth/register' => '/pages/auth/register.php',
    '/auth/logout' => '/pages/auth/logout.php',
    
    // Бизнес-кабинет
    '/business' => '/pages/business/dashboard.php',
    '/business/offers' => '/pages/business/offers.php',
    '/business/analytics' => '/pages/business/analytics.php',
    '/business/customers' => '/pages/business/customers.php',
    '/business/pricelist' => '/pages/business/pricelist.php',
    '/business/settings' => '/pages/business/settings.php',
    
    // Админка
    '/admin' => '/pages/admin/dashboard.php',
    '/admin/offers' => '/pages/admin/offers.php',
    '/admin/users' => '/pages/admin/users.php',
    '/admin/system' => '/pages/admin/system.php',
    '/admin/auctions' => '/admin/auctions.php',
    
    // Офферы (партнёрские предложения)
    '/offers' => '/pages/offers.php',
    '/offers/(\d+)' => '/pages/offer.php?id=$1',
    '/go/(\d+)' => '/pages/go.php?offer_id=$1',
];

$found = false;
$contentFile = null;

foreach ($routes as $pattern => $target) {
    if (preg_match('#^' . $pattern . '$#', $uri, $matches)) {
        $file = $target;
        foreach ($matches as $i => $match) {
            if ($i > 0) {
                $file = str_replace('$' . $i, $match, $file);
            }
        }
        $fullPath = __DIR__ . $file;
        if (file_exists($fullPath)) {
            $found = true;
            $contentFile = $fullPath;
            break;
        }
    }
}

// Если по маршруту не нашли, пробуем искать файл напрямую в /pages
if (!$found) {
    $directFile = __DIR__ . '/pages' . $uri . '.php';
    if (file_exists($directFile)) {
        $contentFile = $directFile;
        $found = true;
    }
}

if (!$found) {
    http_response_code(404);
    include __DIR__ . '/../404.php';
    exit;
}

// ===== 4. CSRF-проверка =====
if ($_SERVER['REQUEST_METHOD'] !== 'GET' && $_SERVER['REQUEST_METHOD'] !== 'HEAD') {
    $input = json_decode(file_get_contents('php://input'), true);
    $token = $_POST['csrf_token'] ?? ($input['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    if (!verify_csrf_token($token)) {
        http_response_code(419);
        if ($_SERVER['HTTP_X_REQUESTED_WITH'] === 'XMLHttpRequest') {
            header('Content-Type: application/json');
            echo json_encode(['error' => 'CSRF token mismatch']);
        } else {
            set_flash('error', 'Недействительный токен безопасности. Попробуйте обновить страницу.');
            header('Location: ' . ($_SERVER['HTTP_REFERER'] ?? '/'));
        }
        exit;
    }
}

// ===== 5. Выполняем страницу и обёртываем в layout =====
ob_start();
require $contentFile;
$pageContent = ob_get_clean();

if (headers_sent()) {
    echo $pageContent;
    exit;
}

global $pageTitle, $pageDescription;
$title = $pageTitle ?? 'Найдук — партнёрская платформа';
$description = $pageDescription ?? 'Зарабатывайте на партнёрских программах, размещайте объявления, находите клиентов.';

$user = null;
if (isset($_SESSION['user_id'])) {
    $db = Database::getInstance();
    $user = $db->getUserById($_SESSION['user_id']);
    if (!$user) {
        session_destroy();
    }
}

$layoutData = [
    'title' => $title,
    'description' => $description,
    'content' => $pageContent,
    'user' => $user
];

require __DIR__ . '/../views/layouts/main.php';