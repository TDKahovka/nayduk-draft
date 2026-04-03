<?php
/* ============================================
   НАЙДУК — Страница поиска объявлений (v5.0)
   - Полная самодостаточность (все функции внутри)
   - Карта (Leaflet)
   - Сравнение товаров (localStorage)
   - Индикатор активности продавца (сегодня/неделя)
   - Автодополнение запросов
   - Переключатель "Показывать магазины"
   - Голосовой поиск, сохранение поиска, кэширование
   - ФИЛЬТР ПО РЕЙТИНГУ ПРОДАВЦА
   ============================================ */

if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../services/Database.php';
require_once __DIR__ . '/../includes/header.php';

$db = Database::getInstance();

// ===== ВАЛИДАЦИЯ GET-ПАРАМЕТРОВ =====
$allowedTypes = ['sell', 'wanted', 'resume', 'service'];
$allowedBlocks = ['housing', 'job', 'goods', 'services', 'community'];
$allowedConditions = ['new', 'used'];
$allowedSorts = ['date_desc', 'date_asc', 'price_asc', 'price_desc'];
$allowedMinRating = [0, 2, 3, 4]; // 0 = любой, 2 = 2★+, 3 = 3★+, 4 = 4★+

$defaultCity = isset($_GET['city']) ? trim($_GET['city']) : ($_SESSION['user_city'] ?? '');
$defaultType = in_array($_GET['type'] ?? '', $allowedTypes) ? $_GET['type'] : '';
$defaultBlock = in_array($_GET['block'] ?? '', $allowedBlocks) ? $_GET['block'] : '';
$defaultMinPrice = isset($_GET['min_price']) ? (int)$_GET['min_price'] : '';
$defaultMaxPrice = isset($_GET['max_price']) ? (int)$_GET['max_price'] : '';
$defaultCondition = in_array($_GET['condition'] ?? '', $allowedConditions) ? $_GET['condition'] : '';
$defaultHasWarranty = isset($_GET['has_warranty']) ? 1 : 0;
$defaultHasDelivery = isset($_GET['has_delivery']) ? 1 : 0;
$defaultSort = in_array($_GET['sort'] ?? '', $allowedSorts) ? $_GET['sort'] : 'date_desc';
$defaultIncludeShops = isset($_GET['include_shops']) ? (int)$_GET['include_shops'] : 1;
$defaultMinRating = isset($_GET['min_rating']) ? (int)$_GET['min_rating'] : 0;
if (!in_array($defaultMinRating, $allowedMinRating)) $defaultMinRating = 0;

$csrfToken = generateCsrfToken();
$pageTitle = 'Поиск объявлений — Найдук';
$pageDescription = 'Найдите всё, что нужно, в своём городе.';

// ===== КЭШ ПОПУЛЯРНЫХ ЗАПРОСОВ ДЛЯ АВТОДОПОЛНЕНИЯ =====
$suggestions = cacheGet('search_suggestions', 86400);
if ($suggestions === null) {
    $suggestions = $db->fetchAll("
        SELECT title, COUNT(*) as cnt
        FROM listings
        WHERE status = 'approved'
        GROUP BY title
        ORDER BY cnt DESC
        LIMIT 50
    ");
    cacheSet('search_suggestions', $suggestions, 86400);
}

$popularCategories = cacheGet('popular_categories', 86400);
if ($popularCategories === null) {
    $popularCategories = $db->fetchAll(
        "SELECT id, name FROM listing_categories WHERE is_active = 1 ORDER BY sort_order LIMIT 8"
    );
    cacheSet('popular_categories', $popularCategories, 86400);
}

$schema = [
    '@context' => 'https://schema.org',
    '@type' => 'WebPage',
    'name' => 'Поиск объявлений',
    'description' => $pageDescription,
    'url' => 'https://' . $_SERVER['HTTP_HOST'] . '/listings'
];
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <meta name="csrf-token" content="<?= $csrfToken ?>">
    <title><?= htmlspecialchars($pageTitle) ?></title>
    <meta name="description" content="<?= htmlspecialchars($pageDescription) ?>">
    <link rel="canonical" href="https://<?= $_SERVER['HTTP_HOST'] ?>/listings">
    <link rel="stylesheet" href="https://cdn.hugeicons.com/font/hgi-stroke-rounded.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/toastify-js/src/toastify.min.css">
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
    <link rel="stylesheet" href="/css/business.css">
    <link rel="stylesheet" href="/css/premium.css">
    <link rel="stylesheet" href="/css/theme-switch.css">
    <link rel="stylesheet" href="/css/themes.css">
    <style>
        .search-container { max-width: 1200px; margin: 0 auto; padding: 20px; }
        .search-header { margin-bottom: 30px; }
        .search-wrapper { display: flex; gap: 8px; align-items: center; }
        .search-input {
            flex: 1;
            padding: 14px 20px;
            border: 1px solid var(--border);
            border-radius: var(--radius-full);
            background: var(--bg);
            color: var(--text);
            font-size: 16px;
        }
        .voice-btn {
            background: var(--bg-secondary);
            border: 1px solid var(--border);
            border-radius: var(--radius-full);
            width: 48px; height: 48px;
            cursor: pointer;
            font-size: 20px;
        }
        .voice-btn.listening { background: var(--primary); color: white; animation: pulse 1s infinite; }
        @keyframes pulse { 0% { transform: scale(1); } 50% { transform: scale(1.05); } 100% { transform: scale(1); } }

        .category-suggestions, .autocomplete-suggestions {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            margin: 12px 0;
        }
        .category-chip, .suggestion-chip {
            background: var(--bg-secondary);
            border: 1px solid var(--border);
            border-radius: var(--radius-full);
            padding: 8px 16px;
            font-size: 13px;
            cursor: pointer;
            transition: all 0.2s;
        }
        .category-chip:hover, .suggestion-chip:hover {
            background: var(--primary);
            color: white;
        }

        .view-switch { display: flex; gap: 8px; margin-bottom: 20px; }
        .view-btn {
            padding: 8px 16px;
            background: var(--bg-secondary);
            border: 1px solid var(--border);
            border-radius: var(--radius-full);
            cursor: pointer;
        }
        .view-btn.active { background: var(--primary); color: white; }

        .results-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); gap: 20px; }
        .map-container { height: 500px; margin-top: 20px; border-radius: var(--radius-xl); overflow: hidden; display: none; }
        .map-container.active { display: block; }

        .listing-card, .promo-card {
            background: var(--surface);
            border-radius: var(--radius-lg);
            overflow: hidden;
            border: 1px solid var(--border-light);
            transition: 0.2s;
        }
        .listing-card:hover { transform: translateY(-4px); box-shadow: var(--shadow-md); }
        .card-img { width: 100%; aspect-ratio: 1; object-fit: cover; background: var(--bg-secondary); }
        .card-content { padding: 12px; }
        .card-title { font-size: 16px; font-weight: 600; margin-bottom: 6px; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; }
        .card-price { font-size: 18px; font-weight: 700; color: var(--primary); margin-bottom: 4px; }
        .card-city { font-size: 13px; color: var(--text-secondary); margin-bottom: 8px; }
        .seller-status {
            display: inline-block;
            font-size: 11px;
            padding: 2px 8px;
            border-radius: var(--radius-full);
            margin-bottom: 8px;
        }
        .status-today { background: rgba(52,199,89,0.1); color: var(--success); }
        .status-week { background: rgba(255,149,0,0.1); color: var(--warning); }
        .seller-rating {
            display: inline-block;
            font-size: 11px;
            padding: 2px 8px;
            border-radius: var(--radius-full);
            background: rgba(74,144,226,0.1);
            color: var(--primary);
            margin-left: 8px;
        }
        .compare-checkbox { margin-right: 8px; transform: scale(1.2); cursor: pointer; }
        .compare-bar {
            position: fixed;
            bottom: 0;
            left: 0;
            right: 0;
            background: var(--surface);
            border-top: 1px solid var(--border);
            padding: 12px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            z-index: 1000;
            transform: translateY(100%);
            transition: 0.2s;
        }
        .compare-bar.show { transform: translateY(0); }
        .compare-items { display: flex; gap: 8px; }
        .compare-item {
            background: var(--bg-secondary);
            border-radius: var(--radius-full);
            padding: 4px 8px;
            font-size: 12px;
        }

        @media (max-width: 768px) {
            .search-wrapper { flex-direction: column; }
            .voice-btn { width: 100%; margin-top: 8px; }
            .view-btn { padding: 12px 20px; }
            .listing-card .compare-checkbox { transform: scale(1.4); }
        }
    </style>
</head>
<body>
<div class="search-container">
    <div class="search-header">
        <h1>🔍 Поиск объявлений</h1>
    </div>

    <div class="search-form">
        <div class="search-wrapper">
            <input type="text" id="search-query" class="search-input" placeholder="Введите запрос, например: iPhone, квартира, юрист..." value="<?= htmlspecialchars($_GET['q'] ?? '') ?>" autocomplete="off">
            <button type="button" id="voice-search" class="voice-btn" title="Голосовой поиск">🎤</button>
        </div>

        <div id="autocomplete-suggestions" class="autocomplete-suggestions"></div>

        <div class="category-suggestions" id="category-suggestions">
            <?php foreach ($popularCategories as $cat): ?>
                <span class="category-chip" data-category="<?= htmlspecialchars($cat['name']) ?>"><?= htmlspecialchars($cat['name']) ?></span>
            <?php endforeach; ?>
        </div>

        <div class="filters-bar">
            <div class="filter-group"><label class="filter-label">Тип</label><select id="type-filter" class="filter-select">
                <option value="">Все</option><option value="sell" <?= $defaultType === 'sell' ? 'selected' : '' ?>>Продажа</option>
                <option value="wanted" <?= $defaultType === 'wanted' ? 'selected' : '' ?>>Спрос</option>
                <option value="resume" <?= $defaultType === 'resume' ? 'selected' : '' ?>>Резюме</option>
                <option value="service" <?= $defaultType === 'service' ? 'selected' : '' ?>>Услуги</option>
            </select></div>
            <div class="filter-group"><label class="filter-label">Категория</label><select id="block-filter" class="filter-select">
                <option value="">Любая</option>
                <option value="housing" <?= $defaultBlock === 'housing' ? 'selected' : '' ?>>Жильё</option>
                <option value="job" <?= $defaultBlock === 'job' ? 'selected' : '' ?>>Работа</option>
                <option value="goods" <?= $defaultBlock === 'goods' ? 'selected' : '' ?>>Товары</option>
                <option value="services" <?= $defaultBlock === 'services' ? 'selected' : '' ?>>Услуги</option>
                <option value="community" <?= $defaultBlock === 'community' ? 'selected' : '' ?>>Помощь</option>
            </select></div>
            <div class="filter-group"><label class="filter-label">Город</label><input type="text" id="city-filter" class="filter-input" value="<?= htmlspecialchars($defaultCity) ?>" placeholder="Ваш город"><button type="button" id="use-geo" class="btn-location btn btn-secondary btn-small" style="margin-top:4px;">📍 Моё местоположение</button></div>
            <div class="filter-group"><label class="filter-label">Цена</label><div style="display:flex;gap:8px;"><input type="number" id="price-min" class="filter-input" placeholder="от" value="<?= htmlspecialchars($defaultMinPrice) ?>"><input type="number" id="price-max" class="filter-input" placeholder="до" value="<?= htmlspecialchars($defaultMaxPrice) ?>"></div></div>
            <div class="filter-group"><label class="filter-label">Состояние</label><select id="condition-filter" class="filter-select"><option value="">Не важно</option><option value="new" <?= $defaultCondition === 'new' ? 'selected' : '' ?>>Новое</option><option value="used" <?= $defaultCondition === 'used' ? 'selected' : '' ?>>Б/у</option></select></div>
            <div class="filter-group"><label class="filter-label">Рейтинг продавца</label><select id="min-rating-filter" class="filter-select">
                <option value="0" <?= $defaultMinRating == 0 ? 'selected' : '' ?>>Любой</option>
                <option value="4" <?= $defaultMinRating == 4 ? 'selected' : '' ?>>4★ и выше</option>
                <option value="3" <?= $defaultMinRating == 3 ? 'selected' : '' ?>>3★ и выше</option>
                <option value="2" <?= $defaultMinRating == 2 ? 'selected' : '' ?>>2★ и выше</option>
            </select></div>
            <div class="filter-group"><label class="filter-label">Сортировка</label><select id="sort-filter" class="filter-select">
                <option value="date_desc" <?= $defaultSort === 'date_desc' ? 'selected' : '' ?>>Сначала новые</option>
                <option value="date_asc" <?= $defaultSort === 'date_asc' ? 'selected' : '' ?>>Сначала старые</option>
                <option value="price_asc" <?= $defaultSort === 'price_asc' ? 'selected' : '' ?>>Сначала дешёвые</option>
                <option value="price_desc" <?= $defaultSort === 'price_desc' ? 'selected' : '' ?>>Сначала дорогие</option>
            </select></div>
        </div>
        <div class="filter-checkbox" style="margin:10px 0;">
            <input type="checkbox" id="include-shops" <?= $defaultIncludeShops ? 'checked' : '' ?>>
            <label for="include-shops">Показывать товары из магазинов</label>
        </div>
        <button type="button" id="toggle-extra" class="toggle-extra">🔧 Дополнительные фильтры</button>
        <div id="extra-filters" class="extra-filters">
            <div class="filter-checkbox"><input type="checkbox" id="warranty" <?= $defaultHasWarranty ? 'checked' : '' ?>><label>С гарантией</label></div>
            <div class="filter-checkbox"><input type="checkbox" id="delivery" <?= $defaultHasDelivery ? 'checked' : '' ?>><label>С доставкой</label></div>
        </div>
        <div class="filter-actions" style="margin-top:16px;display:flex;gap:12px;align-items:center;">
            <button id="apply-filters" class="btn btn-primary">Применить</button>
            <button id="reset-filters" class="btn btn-secondary">Сбросить</button>
            <button id="save-search-btn" class="save-search-btn">🔔 Сохранить этот поиск</button>
        </div>
    </div>

    <div class="view-switch">
        <button id="list-view-btn" class="view-btn active">📋 Список</button>
        <button id="map-view-btn" class="view-btn">🗺️ Карта</button>
    </div>

    <div id="results-container" class="results-grid"></div>
    <div id="map-container" class="map-container"></div>
    <div id="load-more-container" class="load-more-btn" style="display: none;"><button id="load-more" class="btn-load-more">Загрузить ещё</button></div>

    <div id="compare-bar" class="compare-bar">
        <div class="compare-items" id="compare-items"></div>
        <button id="compare-button" class="btn btn-primary">Сравнить</button>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/toastify-js"></script>
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<script>
    const csrfToken = '<?= $csrfToken ?>';
    let currentPage = 1, totalPages = 1, isLoading = false, hasMore = true;
    let currentPromotions = [], promoIndex = 0;
    let lastFetchTime = 0;
    let currentAbortController = null;
    let searchDebounceTimer = null;
    let currentView = 'list';
    let map = null, mapMarkers = [];
    let compareList = JSON.parse(localStorage.getItem('compareList') || '[]');
    let currentUserId = <?= $_SESSION['user_id'] ?? 0 ?>;

    const MIN_INTERVAL_MS = 500, FETCH_TIMEOUT_MS = 10000, CACHE_TTL_MS = 300000, PROMO_CACHE_TTL_MS = 600000, GEO_CACHE_TTL_MS = 86400000;

    // ===== ВСПОМОГАТЕЛЬНЫЕ ФУНКЦИИ (все внутри) =====
    function showToast(msg, type = 'success') {
        const colors = { success: '#34C759', error: '#FF3B30', warning: '#FF9500', info: '#5A67D8' };
        Toastify({ text: msg, duration: 3000, backgroundColor: colors[type] || colors.info }).showToast();
    }

    function escapeHtml(str) {
        if (!str) return '';
        return str.replace(/[&<>]/g, m => ({ '&':'&amp;', '<':'&lt;', '>':'&gt;' }[m] || m));
    }

    function getCache(key, ttl = CACHE_TTL_MS) {
        const item = localStorage.getItem(key);
        if (!item) return null;
        try {
            const data = JSON.parse(item);
            if (Date.now() - data.timestamp < ttl) return data.value;
            localStorage.removeItem(key);
        } catch(e) {}
        return null;
    }

    function setCache(key, value, ttl = CACHE_TTL_MS) {
        localStorage.setItem(key, JSON.stringify({ timestamp: Date.now(), value }));
    }

    // ===== ОТРИСОВКА КАРТОЧЕК (с отображением рейтинга) =====
    function renderListingCard(l) {
        const photo = l.photo ? `<img src="${escapeHtml(l.photo)}" class="card-img" alt="Фото" loading="lazy">` : '<div class="card-img" style="display: flex; align-items: center; justify-content: center; background: var(--bg-secondary);">📷</div>';
        const price = l.price ? new Intl.NumberFormat('ru-RU').format(l.price) + ' ₽' : 'Цена не указана';
        let statusHtml = '';
        if (l.last_visit_at) {
            const last = new Date(l.last_visit_at);
            const now = new Date();
            const diffDays = Math.floor((now - last) / (1000 * 60 * 60 * 24));
            if (diffDays === 0) statusHtml = '<div class="seller-status status-today">🟢 Сегодня</div>';
            else if (diffDays < 7) statusHtml = '<div class="seller-status status-week">🟡 На этой неделе</div>';
        }
        let ratingHtml = '';
        if (l.seller_avg_rating && l.seller_avg_rating > 0) {
            const stars = '★'.repeat(Math.round(l.seller_avg_rating)) + '☆'.repeat(5 - Math.round(l.seller_avg_rating));
            ratingHtml = `<span class="seller-rating" title="Рейтинг продавца ${l.seller_avg_rating}">${stars}</span>`;
        }
        let icons = '';
        if (l.is_sealed) icons += '<span>🔄</span>';
        if (l.has_warranty) icons += '<span>🛡️</span>';
        if (l.has_delivery) icons += '<span>🚚</span>';
        return `
            <div class="listing-card" data-id="${l.id}" data-lat="${l.lat || ''}" data-lng="${l.lng || ''}">
                <input type="checkbox" class="compare-checkbox" value="${l.id}" ${compareList.includes(l.id) ? 'checked' : ''}>
                ${photo}
                <div class="card-content">
                    <div class="card-title">${escapeHtml(l.title)}</div>
                    <div class="card-price">${price}</div>
                    <div class="card-city">${escapeHtml(l.city || 'Город не указан')}</div>
                    ${statusHtml}
                    ${ratingHtml}
                    ${icons ? `<div class="card-icons">${icons}</div>` : ''}
                    <div style="margin-top:12px; display:flex; justify-content:space-between; align-items:center;">
                        <a href="${l.source === 'shop_product' ? '/product/' + l.id : '/listing?id=' + l.id}" class="btn btn-secondary btn-small">Просмотр</a>
                        <button class="favorite-btn" data-id="${l.id}">❤️</button>
                    </div>
                </div>
            </div>
        `;
    }

    function renderPromoCard(p) {
        const img = p.image_url ? `<img src="${escapeHtml(p.image_url)}" class="card-img" alt="Реклама" loading="lazy">` : '<div class="card-img" style="display: flex; align-items: center; justify-content: center; background: var(--bg-secondary);">⭐</div>';
        return `
            <div class="promo-card" data-id="${p.id}" data-url="${escapeHtml(p.link_url)}">
                ${img}
                <div class="card-content">
                    <div class="card-title">${escapeHtml(p.title)}</div>
                    <div class="card-price">⭐ Реклама</div>
                    <div class="card-city">${escapeHtml(p.description || '')}</div>
                    <div style="margin-top:12px;">
                        <button class="btn btn-primary btn-small promo-click">Перейти</button>
                    </div>
                </div>
            </div>
        `;
    }

    // ===== ИЗБРАННОЕ =====
    function attachFavoriteListeners() {
        document.querySelectorAll('.favorite-btn').forEach(btn => {
            btn.removeEventListener('click', favoriteHandler);
            btn.addEventListener('click', favoriteHandler);
        });
    }

    async function favoriteHandler(e) {
        e.preventDefault();
        const btn = e.currentTarget;
        const listingId = btn.dataset.id;
        if (!listingId) return;
        if (!currentUserId) {
            showToast('Войдите, чтобы добавить в избранное', 'warning');
            return;
        }
        const action = btn.classList.contains('active') ? 'remove' : 'add';
        try {
            const res = await fetch('/api/listings/favorites.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ action: action, listing_id: listingId, csrf_token: csrfToken })
            });
            const data = await res.json();
            if (data.success) {
                if (action === 'add') btn.classList.add('active');
                else btn.classList.remove('active');
                showToast(data.message);
            } else {
                showToast(data.error, 'error');
            }
        } catch (err) {
            showToast('Ошибка сети', 'error');
        }
    }

    // ===== РЕФЕРАЛКИ =====
    function attachPromoClickListeners() {
        document.querySelectorAll('.promo-click').forEach(btn => {
            btn.removeEventListener('click', promoHandler);
            btn.addEventListener('click', promoHandler);
        });
    }

    async function promoHandler(e) {
        const card = e.currentTarget.closest('.promo-card');
        const id = card.dataset.id;
        const url = card.dataset.url;
        if (id) {
            await fetch('/api/promotions.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: `action=click&id=${id}&csrf_token=${csrfToken}`
            });
        }
        window.open(url, '_blank');
    }

    async function logImpression(promoId) {
        try {
            await fetch('/api/promotions.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: `action=impression&id=${promoId}&csrf_token=${csrfToken}`
            });
        } catch (e) {}
    }

    // ===== СРАВНЕНИЕ =====
    function attachCompareCheckboxes() {
        document.querySelectorAll('.compare-checkbox').forEach(cb => {
            cb.addEventListener('change', e => {
                const id = parseInt(cb.value);
                if (cb.checked) { if (!compareList.includes(id)) compareList.push(id); }
                else { const idx = compareList.indexOf(id); if (idx !== -1) compareList.splice(idx,1); }
                localStorage.setItem('compareList', JSON.stringify(compareList));
                updateCompareUI();
            });
        });
    }

    function updateCompareUI() {
        const container = document.getElementById('compare-items');
        if (compareList.length === 0) {
            document.getElementById('compare-bar').classList.remove('show');
            return;
        }
        document.getElementById('compare-bar').classList.add('show');
        container.innerHTML = compareList.map(id => `<div class="compare-item">ID ${id}</div>`).join('');
    }

    // ===== КАРТА =====
    function renderMapResults(results) {
        if (!map) {
            map = L.map('map-container').setView([55.76, 37.64], 10);
            L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', { attribution: '© OpenStreetMap' }).addTo(map);
        }
        mapMarkers.forEach(m => map.removeLayer(m));
        mapMarkers = [];
        results.forEach(r => {
            if (r.lat && r.lng) {
                const marker = L.marker([parseFloat(r.lat), parseFloat(r.lng)]).addTo(map);
                marker.bindPopup(`<b>${escapeHtml(r.title)}</b><br>${r.price ? r.price + ' ₽' : ''}<br><a href="${r.source === 'shop_product' ? '/product/' + r.id : '/listing?id=' + r.id}">Открыть</a>`);
                mapMarkers.push(marker);
            }
        });
        if (mapMarkers.length > 0) map.fitBounds(L.featureGroup(mapMarkers).getBounds());
    }

    // ===== ОСНОВНАЯ ЗАГРУЗКА (с параметром min_rating) =====
    async function fetchListings(page = 1, append = false) {
        if (isLoading) return;
        const now = Date.now();
        if (page === 1 && now - lastFetchTime < MIN_INTERVAL_MS) return;
        lastFetchTime = now;
        isLoading = true;
        if (currentAbortController) currentAbortController.abort();
        currentAbortController = new AbortController();
        if (!append) {
            document.getElementById('results-container').innerHTML = '<div class="skeleton-card"></div><div class="skeleton-card"></div><div class="skeleton-card"></div><div class="skeleton-card"></div>';
            document.getElementById('load-more-container').style.display = 'none';
        }
        const rawQ = document.getElementById('search-query').value.trim();
        const rawType = document.getElementById('type-filter').value;
        const rawBlock = document.getElementById('block-filter').value;
        const city = document.getElementById('city-filter').value.trim();
        const minPrice = document.getElementById('price-min').value;
        const maxPrice = document.getElementById('price-max').value;
        const rawCondition = document.getElementById('condition-filter').value;
        const hasWarranty = document.getElementById('warranty').checked ? 1 : 0;
        const hasDelivery = document.getElementById('delivery').checked ? 1 : 0;
        const sort = document.getElementById('sort-filter').value;
        const includeShops = document.getElementById('include-shops').checked ? 1 : 0;
        const minRating = document.getElementById('min-rating-filter').value;
        const type = ['sell','wanted','resume','service'].includes(rawType) ? rawType : '';
        const block = ['housing','job','goods','services','community'].includes(rawBlock) ? rawBlock : '';
        const condition = ['new','used'].includes(rawCondition) ? rawCondition : '';

        let url = `/api/listings.php?action=list&page=${page}&limit=20&sort=${sort}&include_shops=${includeShops}`;
        if (rawQ) url += `&search=${encodeURIComponent(rawQ)}`;
        if (type) url += `&type=${type}`;
        if (block) url += `&block=${block}`;
        if (city) url += `&city=${encodeURIComponent(city)}`;
        if (minPrice) url += `&min_price=${minPrice}`;
        if (maxPrice) url += `&max_price=${maxPrice}`;
        if (condition) url += `&condition=${condition}`;
        if (hasWarranty) url += `&has_warranty=1`;
        if (hasDelivery) url += `&has_delivery=1`;
        if (minRating && minRating != 0) url += `&min_rating=${minRating}`;

        const cacheKey = `listings:${url}`;
        let listings = null, meta = null;
        if (page === 1 && !append) {
            const cached = getCache(cacheKey, CACHE_TTL_MS);
            if (cached) { listings = cached.data; meta = cached.meta; }
        }
        if (!listings) {
            try {
                const timeoutId = setTimeout(() => currentAbortController.abort(), FETCH_TIMEOUT_MS);
                const res = await fetch(url, { signal: currentAbortController.signal });
                clearTimeout(timeoutId);
                if (!res.ok) throw new Error(`HTTP ${res.status}`);
                const data = await res.json();
                if (!data.success) throw new Error(data.error);
                listings = data.data || [];
                meta = data.meta;
                if (page === 1 && !append) setCache(cacheKey, { data: listings, meta }, CACHE_TTL_MS);
            } catch (err) {
                console.error(err);
                if (err.name !== 'AbortError') showToast('Ошибка загрузки', 'error');
                if (!append) document.getElementById('results-container').innerHTML = `<div class="empty-state">Ошибка загрузки. <button class="retry-btn" onclick="fetchListings(1,false)">Повторить</button></div>`;
                isLoading = false; currentAbortController = null; return;
            }
        }
        totalPages = meta.pages;
        hasMore = page < totalPages;

        let promotions = [];
        const promoCacheKey = `promotions:${city || 'all'}`;
        const cachedPromos = getCache(promoCacheKey, PROMO_CACHE_TTL_MS);
        if (cachedPromos) promotions = cachedPromos;
        else {
            try {
                const promoRes = await fetch(`/api/promotions.php?action=list${city ? `&city=${encodeURIComponent(city)}` : ''}`);
                const promoData = await promoRes.json();
                if (promoData.success) { promotions = promoData.data; setCache(promoCacheKey, promotions, PROMO_CACHE_TTL_MS); }
            } catch(e) {}
        }
        if (page === 1) { currentPromotions = promotions; promoIndex = 0; }
        let allCards = [];
        if (listings.length === 0 && page === 1 && promotions.length > 0) {
            allCards = promotions.map(p => renderPromoCard(p));
            promotions.forEach(p => logImpression(p.id));
        } else {
            for (let i = 0; i < listings.length; i++) {
                allCards.push(renderListingCard(listings[i]));
                if ((i+1) % 20 === 0 && currentPromotions.length > 0) {
                    const promo = currentPromotions[promoIndex % currentPromotions.length];
                    allCards.push(renderPromoCard(promo));
                    logImpression(promo.id);
                    promoIndex++;
                }
            }
        }
        const container = document.getElementById('results-container');
        if (append) container.insertAdjacentHTML('beforeend', allCards.join(''));
        else container.innerHTML = allCards.join('');
        document.getElementById('load-more-container').style.display = hasMore ? 'block' : 'none';
        attachFavoriteListeners();
        attachPromoClickListeners();
        attachCompareCheckboxes();
        if (currentView === 'map' && listings.length) renderMapResults(listings);
        isLoading = false;
        currentAbortController = null;
    }

    // ===== LIVE SEARCH =====
    function debounceSearch() {
        if (searchDebounceTimer) clearTimeout(searchDebounceTimer);
        searchDebounceTimer = setTimeout(() => { fetchListings(1, false); }, 300);
    }

    // ===== ФИЛЬТРЫ =====
    document.getElementById('search-query').addEventListener('input', debounceSearch);
    document.getElementById('apply-filters').addEventListener('click', () => { currentPage = 1; fetchListings(1, false); });
    document.getElementById('reset-filters').addEventListener('click', () => {
        document.getElementById('search-query').value = '';
        document.getElementById('type-filter').value = '';
        document.getElementById('block-filter').value = '';
        document.getElementById('city-filter').value = '';
        document.getElementById('price-min').value = '';
        document.getElementById('price-max').value = '';
        document.getElementById('condition-filter').value = '';
        document.getElementById('warranty').checked = false;
        document.getElementById('delivery').checked = false;
        document.getElementById('sort-filter').value = 'date_desc';
        document.getElementById('include-shops').checked = true;
        document.getElementById('min-rating-filter').value = '0';
        fetchListings(1, false);
    });
    document.getElementById('load-more').addEventListener('click', () => { if (hasMore && !isLoading) { currentPage++; fetchListings(currentPage, true); } });
    document.getElementById('toggle-extra').addEventListener('click', () => document.getElementById('extra-filters').classList.toggle('open'));

    // ===== ПЕРЕКЛЮЧЕНИЕ ВИДА =====
    document.getElementById('list-view-btn').addEventListener('click', () => {
        currentView = 'list';
        document.getElementById('results-container').style.display = 'grid';
        document.getElementById('map-container').classList.remove('active');
        document.getElementById('list-view-btn').classList.add('active');
        document.getElementById('map-view-btn').classList.remove('active');
    });
    document.getElementById('map-view-btn').addEventListener('click', () => {
        currentView = 'map';
        document.getElementById('results-container').style.display = 'none';
        document.getElementById('map-container').classList.add('active');
        document.getElementById('list-view-btn').classList.remove('active');
        document.getElementById('map-view-btn').classList.add('active');
        fetchListings(1, false);
    });

    // ===== ГОЛОСОВОЙ ПОИСК =====
    const voiceBtn = document.getElementById('voice-search');
    if ('webkitSpeechRecognition' in window || 'SpeechRecognition' in window) {
        const SpeechRecognition = window.SpeechRecognition || window.webkitSpeechRecognition;
        const recognition = new SpeechRecognition();
        recognition.lang = 'ru-RU';
        recognition.interimResults = false;
        voiceBtn.addEventListener('click', () => {
            voiceBtn.classList.add('listening');
            recognition.start();
            recognition.onresult = (event) => {
                const transcript = event.results[0][0].transcript;
                document.getElementById('search-query').value = transcript;
                voiceBtn.classList.remove('listening');
                fetchListings(1, false);
            };
            recognition.onerror = () => { voiceBtn.classList.remove('listening'); showToast('Не удалось распознать речь', 'error'); };
            recognition.onend = () => voiceBtn.classList.remove('listening');
        });
    } else voiceBtn.style.display = 'none';

    // ===== ПОДСКАЗКИ КАТЕГОРИЙ =====
    document.querySelectorAll('.category-chip').forEach(chip => {
        chip.addEventListener('click', () => {
            document.getElementById('search-query').value = chip.dataset.category;
            fetchListings(1, false);
        });
    });

    // ===== АВТОДОПОЛНЕНИЕ =====
    const autocompleteDiv = document.getElementById('autocomplete-suggestions');
    const searchInput = document.getElementById('search-query');
    searchInput.addEventListener('input', (e) => {
        const val = e.target.value.trim();
        if (val.length < 2) { autocompleteDiv.innerHTML = ''; return; }
        const suggestions = <?= json_encode($suggestions) ?>;
        const filtered = suggestions.filter(s => s.title.toLowerCase().includes(val.toLowerCase())).slice(0,5);
        if (filtered.length) {
            autocompleteDiv.innerHTML = filtered.map(s => `<span class="suggestion-chip" data-query="${escapeHtml(s.title)}">${escapeHtml(s.title)}</span>`).join('');
            document.querySelectorAll('.suggestion-chip').forEach(el => {
                el.addEventListener('click', () => {
                    searchInput.value = el.dataset.query;
                    autocompleteDiv.innerHTML = '';
                    fetchListings(1, false);
                });
            });
        } else autocompleteDiv.innerHTML = '';
    });

    // ===== ГЕОЛОКАЦИЯ =====
    async function getCityFromCoords(lat, lng) {
        const cacheKey = `geo:${lat}:${lng}`;
        const cached = getCache(cacheKey, GEO_CACHE_TTL_MS);
        if (cached) return cached;
        try {
            const res = await fetch(`https://nominatim.openstreetmap.org/reverse?lat=${lat}&lon=${lng}&format=json`);
            const data = await res.json();
            const city = data.address?.city || data.address?.town || data.address?.village;
            if (city) { setCache(cacheKey, city, GEO_CACHE_TTL_MS); return city; }
            return null;
        } catch(e) { return null; }
    }

    document.getElementById('use-geo').addEventListener('click', async () => {
        if (!navigator.geolocation) { showToast('Геолокация не поддерживается', 'warning'); return; }
        try {
            const position = await new Promise((resolve, reject) => {
                navigator.geolocation.getCurrentPosition(resolve, reject, { timeout: 5000 });
            });
            const city = await getCityFromCoords(position.coords.latitude, position.coords.longitude);
            if (city) {
                document.getElementById('city-filter').value = city;
                fetchListings(1, false);
            } else showToast('Не удалось определить город', 'error');
        } catch(err) { showToast('Не удалось определить местоположение', 'error'); }
    });

    // ===== СОХРАНЕНИЕ ПОИСКА =====
    document.getElementById('save-search-btn')?.addEventListener('click', async () => {
        if (!currentUserId) { showToast('Войдите, чтобы сохранить поиск', 'warning'); return; }
        const filters = {
            q: document.getElementById('search-query').value,
            type: document.getElementById('type-filter').value,
            block: document.getElementById('block-filter').value,
            city: document.getElementById('city-filter').value,
            min_price: document.getElementById('price-min').value,
            max_price: document.getElementById('price-max').value,
            condition: document.getElementById('condition-filter').value,
            has_warranty: document.getElementById('warranty').checked ? 1 : 0,
            has_delivery: document.getElementById('delivery').checked ? 1 : 0,
            include_shops: document.getElementById('include-shops').checked ? 1 : 0,
            min_rating: document.getElementById('min-rating-filter').value
        };
        try {
            const res = await fetch('/api/subscriptions.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ action: 'create', filters: filters, csrf_token: csrfToken })
            });
            const data = await res.json();
            if (data.success) showToast('Поиск сохранён. Уведомления будут приходить на почту.', 'success');
            else showToast(data.error || 'Ошибка сохранения', 'error');
        } catch(e) { showToast('Ошибка сети', 'error'); }
    });

    // ===== СРАВНЕНИЕ (кнопка) =====
    document.getElementById('compare-button')?.addEventListener('click', () => {
        if (compareList.length < 2) { showToast('Выберите хотя бы 2 объявления для сравнения', 'warning'); return; }
        const ids = compareList.join(',');
        window.open(`/compare?ids=${ids}`, '_blank');
    });

    // ===== СТАРТ =====
    fetchListings(1, false);
    updateCompareUI();
</script>
</body>
</html>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>