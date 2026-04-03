<?php
/* ============================================
   НАЙДУК — Общая шапка сайта
   Версия 3.2 (март 2026)
   - Добавлена ссылка на геонастройки (/owner/geo-settings.php)
   - Добавлена подсветка активной ссылки для раздела owner
   ============================================ */

session_start();

require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/../services/GeoService.php';

$currentUri = $_SERVER['REQUEST_URI'];
$isAdmin = strpos($currentUri, '/admin/') === 0;
$isOwner = strpos($currentUri, '/owner/') === 0;
$isBusiness = strpos($currentUri, '/business/') === 0;
$isOfferCatalog = strpos($currentUri, '/offers/') === 0 || $currentUri === '/offers';
$isShops = strpos($currentUri, '/shops') === 0;

$currentUser = null;
if (isset($_SESSION['user_id'])) {
    $db = Database::getInstance();
    $currentUser = $db->fetchOne("SELECT id, name, email, role, is_partner FROM users WHERE id = ?", [$_SESSION['user_id']]);
}

$csrfToken = generateCsrfToken();

// ===== ГЕОСЕРВИС: определение текущего города =====
$geo = new GeoService();
$userId = $_SESSION['user_id'] ?? null;
$currentCity = $geo->getUserCity($userId);

// Для JS передаём город в data-атрибуте
$cityDataJson = $currentCity ? json_encode($currentCity, JSON_UNESCAPED_UNICODE) : 'null';
?>
<!DOCTYPE html>
<html lang="ru" data-theme="<?= htmlspecialchars($_COOKIE['site-theme'] ?? 'light') ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <meta name="csrf-token" content="<?= $csrfToken ?>">
    <title><?= $pageTitle ?? 'Найдук — доска объявлений и партнёрская платформа' ?></title>
    <meta name="description" content="<?= $pageDescription ?? 'Зарабатывайте на партнёрских программах, размещайте объявления, находите клиентов.' ?>">
    <link rel="canonical" href="https://<?= $_SERVER['HTTP_HOST'] . $currentUri ?>">
    
    <link rel="stylesheet" href="https://cdn.hugeicons.com/font/hgi-stroke-rounded.css" />
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/toastify-js/src/toastify.min.css" />
    <link rel="stylesheet" href="/css/business.css" />
    <link rel="stylesheet" href="/css/premium.css" />
    <link rel="stylesheet" href="/css/theme-switch.css" />
    <link rel="stylesheet" href="/css/themes.css" />
    
    <?php if (isset($extraStyles)): ?>
        <style><?= $extraStyles ?></style>
    <?php endif; ?>
</head>
<body>
    <header class="header">
        <div class="logo">Найдук</div>
        <nav class="nav">
            <a href="/" class="<?= $currentUri === '/' ? 'active' : '' ?>">Главная</a>
            <a href="/offers/" class="<?= $isOfferCatalog ? 'active' : '' ?>">Предложения</a>
            <a href="/shops" class="<?= $isShops ? 'active' : '' ?>">Магазины</a>
            <?php if ($currentUser): ?>
                <?php if ($currentUser['is_partner'] || $currentUser['role'] === 'admin'): ?>
                    <a href="/business/dashboard" class="<?= $isBusiness ? 'active' : '' ?>">Бизнес-кабинет</a>
                <?php endif; ?>
                <?php if ($currentUser['role'] === 'admin'): ?>
                    <a href="/admin/offers" class="<?= $isAdmin ? 'active' : '' ?>">Админка</a>
                    <a href="/owner/geo-settings.php" class="<?= $isOwner ? 'active' : '' ?>">🌍 Геонастройки</a>
                <?php endif; ?>
                <div class="user-menu">
                    <span class="plan-badge"><?= $currentUser['is_partner'] ? 'Бизнес' : 'Пользователь' ?></span>
                    <div class="theme-switch">
                        <button class="theme-option" data-theme="light" title="Светлая">☀️</button>
                        <button class="theme-option" data-theme="dark" title="Тёмная">🌙</button>
                        <button class="theme-option" data-theme="neutral" title="Нейтральная">🏢</button>
                        <button class="theme-option" data-theme="contrast" title="Контрастная">🎯</button>
                        <button class="theme-option" data-theme="nature" title="Природная">🌿</button>
                    </div>
                    <a href="/business/profile" class="btn btn-secondary">
                        <i class="hgi hgi-stroke-user"></i> <?= htmlspecialchars($currentUser['name'] ?? 'Профиль') ?>
                    </a>
                    <a href="/auth/logout" class="btn btn-secondary">Выйти</a>
                </div>
            <?php else: ?>
                <div class="theme-switch">
                    <button class="theme-option" data-theme="light" title="Светлая">☀️</button>
                    <button class="theme-option" data-theme="dark" title="Тёмная">🌙</button>
                    <button class="theme-option" data-theme="neutral" title="Нейтральная">🏢</button>
                    <button class="theme-option" data-theme="contrast" title="Контрастная">🎯</button>
                    <button class="theme-option" data-theme="nature" title="Природная">🌿</button>
                </div>
                <a href="/auth/login" class="btn btn-secondary">Войти</a>
                <a href="/auth/register" class="btn btn-primary">Регистрация</a>
            <?php endif; ?>
        </nav>
        
        <!-- БЛОК ВЫБОРА ГОРОДА -->
        <div class="city-selector" id="city-selector">
            <i class="hgi hgi-stroke-location-01"></i>
            <?php if ($currentCity && !empty($currentCity['city'])): ?>
                <span><?= htmlspecialchars($currentCity['city']) ?></span>
            <?php else: ?>
                <span>Выбрать город</span>
            <?php endif; ?>
            <i class="hgi hgi-stroke-chevron-down" style="font-size: 12px; margin-left: 4px;"></i>
        </div>
    </header>

    <!-- Скрытые данные для JS -->
    <div id="city-data" data-city='<?= htmlspecialchars($cityDataJson, ENT_QUOTES, 'UTF-8') ?>' style="display: none;"></div>

    <!-- Модальное окно выбора города -->
    <div id="city-modal" class="modal" style="display: none;">
        <div class="modal-content">
            <div class="modal-header">
                <h3>📍 Выберите ваш город</h3>
                <button class="close-btn" id="close-city-modal">✕</button>
            </div>
            <div class="city-search">
                <input type="text" id="city-search-input" class="form-input" placeholder="Начните вводить название города..." autocomplete="off">
                <div id="city-suggestions" class="city-suggestions-list"></div>
            </div>
            <div class="modal-footer">
                <button class="btn btn-secondary" id="cancel-city-modal">Закрыть</button>
            </div>
        </div>
    </div>

    <main class="main-content">