<?php
global $pageTitle, $pageDescription;
$pageTitle = 'Найдук — главная страница';
$pageDescription = 'Популярные категории, реферальные товары, бизнес-кабинеты и свежие объявления.';
?>
<div class="container home-page">
    <!-- Поиск -->
    <div class="search-section">
        <form action="/search" method="GET" class="search-form">
            <input type="text" name="q" class="search-input" placeholder="Что вы ищете?" value="<?= htmlspecialchars($_GET['q'] ?? '') ?>">
            <button type="submit" class="search-btn">Найти</button>
        </form>
    </div>

    <!-- Популярные категории (6 шт, 2 колонки) -->
    <div class="categories-section">
        <div class="section-header">
            <h2 class="section-title">Популярные категории</h2>
        </div>
        <div class="categories-grid" id="popular-categories"></div>
        <div class="text-center">
            <a href="/categories" class="btn btn-secondary">Все категории →</a>
        </div>
    </div>

    <!-- Найдук рекомендует (горизонтальный скролл) -->
    <div class="recommended-section">
        <div class="section-header">
            <h2 class="section-title">🤝 Найдук рекомендует</h2>
            <a href="/recommended" class="section-link">Все предложения →</a>
        </div>
        <div class="horizontal-scroll" id="recommended-products">
            <!-- будет заполнено JS -->
        </div>
    </div>

    <!-- Ваши Предприниматели (бизнес-кабинеты, гео) -->
    <div class="business-section">
        <div class="section-header">
            <h2 class="section-title">💼 Ваши Предприниматели</h2>
            <span class="section-subtitle">Продажа и услуги рядом</span>
            <a href="/business" class="section-link">Все предприниматели →</a>
        </div>
        <div class="horizontal-scroll" id="business-list">
            <!-- будет заполнено JS -->
        </div>
    </div>

    <!-- Аукцион (форма + активные заявки) -->
    <div class="auction-section">
        <div class="section-header">
            <h2 class="section-title">🔨 Аукцион</h2>
            <span class="section-subtitle">Вы называете цену – продавцы предлагают свою</span>
        </div>
        <div class="auction-form">
            <form id="auction-form">
                <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
                <div class="auction-input-group">
                    <input type="number" name="price" class="auction-price-input" placeholder="Ваша цена в рублях" required>
                    <button type="submit" class="btn btn-primary">Опубликовать заявку</button>
                </div>
            </form>
        </div>
        <div id="active-auctions" class="active-auctions">
            <!-- активные заявки будут загружены JS -->
        </div>
    </div>

    <!-- Свежие объявления (сетка) -->
    <div class="fresh-listings-section">
        <div class="section-header">
            <h2 class="section-title">🆕 Свежие объявления</h2>
            <a href="/listings" class="section-link">Все объявления →</a>
        </div>
        <div class="listings-grid" id="fresh-listings"></div>
        <div class="text-center">
            <button id="load-more-btn" class="btn btn-secondary">Показать ещё</button>
        </div>
    </div>
</div>

<style>
    /* Дополнительные стили для страницы (уже есть в основном CSS, но добавим специфические) */
    .home-page {
        padding: 20px 16px;
        max-width: 1200px;
        margin: 0 auto;
    }
    .search-section {
        margin-bottom: 30px;
    }
    .search-form {
        display: flex;
        gap: 8px;
    }
    .search-input {
        flex: 1;
        padding: 14px 16px;
        border: 1px solid var(--border);
        border-radius: var(--radius-full);
        font-size: 16px;
        background: var(--surface);
        color: var(--text);
    }
    .search-btn {
        padding: 0 24px;
        background: var(--primary);
        color: white;
        border: none;
        border-radius: var(--radius-full);
        font-weight: 600;
        cursor: pointer;
    }
    .section-header {
        display: flex;
        flex-wrap: wrap;
        align-items: baseline;
        justify-content: space-between;
        margin-bottom: 16px;
    }
    .section-title {
        font-size: 20px;
        font-weight: 700;
        margin: 0;
    }
    .section-subtitle {
        font-size: 14px;
        color: var(--text-secondary);
        margin-left: 12px;
    }
    .section-link {
        font-size: 14px;
        color: var(--primary);
        text-decoration: none;
    }
    .categories-grid {
        display: grid;
        grid-template-columns: repeat(2, 1fr);
        gap: 16px;
        margin-bottom: 20px;
    }
    .category-card {
        background: var(--surface);
        border: 1px solid var(--border);
        border-radius: var(--radius-lg);
        padding: 20px 12px;
        text-align: center;
        transition: all var(--transition);
        cursor: pointer;
        display: flex;
        flex-direction: column;
        align-items: center;
        gap: 12px;
    }
    .category-card:hover {
        transform: translateY(-4px);
        box-shadow: var(--shadow-md);
        border-color: var(--primary);
    }
    .category-icon {
        font-size: 40px;
    }
    .category-name {
        font-size: 14px;
        font-weight: 600;
        color: var(--text);
    }
    .horizontal-scroll {
        display: flex;
        overflow-x: auto;
        gap: 16px;
        padding-bottom: 8px;
        scrollbar-width: thin;
        -webkit-overflow-scrolling: touch;
    }
    .horizontal-scroll::-webkit-scrollbar {
        height: 4px;
    }
    .product-card, .business-card {
        flex: 0 0 140px;
        background: var(--surface);
        border: 1px solid var(--border);
        border-radius: var(--radius-lg);
        padding: 12px;
        text-align: center;
        transition: all var(--transition);
        cursor: pointer;
    }
    .product-card:hover, .business-card:hover {
        transform: translateY(-4px);
        box-shadow: var(--shadow-md);
    }
    .product-img, .business-logo {
        width: 80px;
        height: 80px;
        margin: 0 auto 8px;
        background: var(--bg-secondary);
        border-radius: var(--radius);
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 40px;
    }
    .product-title, .business-name {
        font-size: 14px;
        font-weight: 600;
        margin: 8px 0 4px;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }
    .product-price {
        font-size: 16px;
        font-weight: 700;
        color: var(--primary);
    }
    .business-rating {
        display: flex;
        justify-content: center;
        align-items: center;
        gap: 4px;
        font-size: 12px;
        color: var(--accent);
        margin: 4px 0;
    }
    .business-distance {
        font-size: 11px;
        color: var(--text-secondary);
    }
    .auction-section {
        background: var(--bg-secondary);
        border-radius: var(--radius-xl);
        padding: 20px;
        margin: 30px 0;
    }
    .auction-input-group {
        display: flex;
        gap: 12px;
        margin: 16px 0;
    }
    .auction-price-input {
        flex: 1;
        padding: 14px 16px;
        border: 1px solid var(--border);
        border-radius: var(--radius-full);
        background: var(--surface);
        font-size: 16px;
    }
    .active-auctions {
        margin-top: 20px;
    }
    .auction-item {
        background: var(--surface);
        border-radius: var(--radius);
        padding: 12px;
        margin-bottom: 10px;
        display: flex;
        justify-content: space-between;
        align-items: center;
    }
    .auction-price {
        font-weight: 700;
        color: var(--primary);
    }
    .listings-grid {
        display: grid;
        grid-template-columns: repeat(2, 1fr);
        gap: 16px;
        margin: 20px 0;
    }
    .listing-card {
        background: var(--surface);
        border: 1px solid var(--border);
        border-radius: var(--radius-lg);
        overflow: hidden;
        transition: all var(--transition);
        cursor: pointer;
    }
    .listing-card:hover {
        transform: translateY(-4px);
        box-shadow: var(--shadow-md);
    }
    .listing-image {
        height: 150px;
        background: var(--bg-secondary);
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 48px;
        color: var(--text-secondary);
    }
    .listing-info {
        padding: 12px;
    }
    .listing-price {
        font-size: 18px;
        font-weight: 700;
        color: var(--primary);
    }
    .listing-title {
        font-size: 14px;
        font-weight: 600;
        margin: 4px 0;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }
    .listing-meta {
        font-size: 12px;
        color: var(--text-secondary);
        display: flex;
        justify-content: space-between;
        margin-top: 8px;
    }
    .text-center {
        text-align: center;
        margin-top: 20px;
    }
    @media (min-width: 768px) {
        .categories-grid {
            grid-template-columns: repeat(3, 1fr);
        }
        .listings-grid {
            grid-template-columns: repeat(3, 1fr);
        }
        .product-card, .business-card {
            flex: 0 0 180px;
        }
        .product-img, .business-logo {
            width: 100px;
            height: 100px;
        }
    }
    @media (min-width: 1024px) {
        .listings-grid {
            grid-template-columns: repeat(4, 1fr);
        }
    }
</style>

<script>
    // ===== МОК-ДАННЫЕ (заменить на реальные API позже) =====
    // Популярные категории (6 штук)
    const popularCategories = [
        { id: 1, name: 'Недвижимость', icon: '🏠', slug: 'nedvizhimost' },
        { id: 2, name: 'Транспорт', icon: '🚗', slug: 'transport' },
        { id: 3, name: 'Электроника', icon: '📱', slug: 'elektronika' },
        { id: 4, name: 'Личные вещи', icon: '👕', slug: 'lichnye-veshchi' },
        { id: 5, name: 'Для дома и дачи', icon: '🏡', slug: 'dlya-doma' },
        { id: 6, name: 'Услуги', icon: '🔧', slug: 'uslugi' }
    ];

    // Рекомендуемые товары (реферальные)
    const recommendedProducts = [
        { id: 1, title: 'Смартфон XYZ', price: 29990, image: '📱', url: '#' },
        { id: 2, title: 'Ноутбук ABC', price: 54990, image: '💻', url: '#' },
        { id: 3, title: 'Наушники Pro', price: 4990, image: '🎧', url: '#' },
        { id: 4, title: 'Умные часы', price: 12990, image: '⌚', url: '#' },
        { id: 5, title: 'Планшет Lite', price: 19990, image: '📟', url: '#' },
        { id: 6, title: 'Фитнес-браслет', price: 3990, image: '🏃', url: '#' }
    ];

    // Предприниматели (бизнес-кабинеты)
    const businesses = [
        { id: 1, name: 'РемСервис', logo: '🔧', rating: 4.8, distance: 0.5, url: '/business/1' },
        { id: 2, name: 'СтройМастер', logo: '🔨', rating: 4.9, distance: 1.2, url: '/business/2' },
        { id: 3, name: 'Юрист24', logo: '⚖️', rating: 4.7, distance: 2.0, url: '/business/3' },
        { id: 4, name: 'КлинингПро', logo: '🧹', rating: 4.5, distance: 0.8, url: '/business/4' },
        { id: 5, name: 'Фотостудия', logo: '📸', rating: 4.9, distance: 1.5, url: '/business/5' }
    ];

    // Активные заявки аукциона
    const activeAuctions = [
        { id: 1, price: 5000, created_at: '2026-03-24' },
        { id: 2, price: 12000, created_at: '2026-03-23' },
        { id: 3, price: 800, created_at: '2026-03-22' }
    ];

    // Свежие объявления
    let freshListings = [
        { id: 1, title: 'iPhone 13 Pro', price: 45000, city: 'Москва', image: '📱', created_at: '2026-03-24' },
        { id: 2, title: 'Велосипед Stels', price: 12000, city: 'СПб', image: '🚲', created_at: '2026-03-24' },
        { id: 3, title: 'Диван угловой', price: 15000, city: 'Казань', image: '🛋️', created_at: '2026-03-23' },
        { id: 4, title: 'Ноутбук Asus', price: 32000, city: 'Екатеринбург', image: '💻', created_at: '2026-03-23' },
        { id: 5, title: 'Коляска детская', price: 5000, city: 'Новосибирск', image: '👶', created_at: '2026-03-22' },
        { id: 6, title: 'Холодильник LG', price: 25000, city: 'Москва', image: '❄️', created_at: '2026-03-22' }
    ];
    let listingsPage = 1;
    const listingsPerPage = 6;

    // ===== РЕНДЕРИНГ =====
    function renderCategories() {
        const container = document.getElementById('popular-categories');
        container.innerHTML = popularCategories.map(cat => `
            <a href="/category/${cat.slug}" class="category-card">
                <div class="category-icon">${cat.icon}</div>
                <div class="category-name">${cat.name}</div>
            </a>
        `).join('');
    }

    function renderRecommended() {
        const container = document.getElementById('recommended-products');
        container.innerHTML = recommendedProducts.map(product => `
            <a href="${product.url}" class="product-card" target="_blank" rel="noopener">
                <div class="product-img">${product.image}</div>
                <div class="product-title">${product.title}</div>
                <div class="product-price">${product.price.toLocaleString()} ₽</div>
            </a>
        `).join('');
    }

    function renderBusinesses() {
        const container = document.getElementById('business-list');
        container.innerHTML = businesses.map(b => `
            <a href="${b.url}" class="business-card">
                <div class="business-logo">${b.logo}</div>
                <div class="business-name">${b.name}</div>
                <div class="business-rating">★ ${b.rating}</div>
                <div class="business-distance">${b.distance.toFixed(1)} км</div>
            </a>
        `).join('');
    }

    function renderActiveAuctions() {
        const container = document.getElementById('active-auctions');
        if (activeAuctions.length === 0) {
            container.innerHTML = '<p class="text-center">Пока нет активных заявок. Станьте первым!</p>';
            return;
        }
        container.innerHTML = activeAuctions.map(auction => `
            <div class="auction-item">
                <span>Заявка от ${auction.created_at}</span>
                <span class="auction-price">${auction.price.toLocaleString()} ₽</span>
            </div>
        `).join('');
    }

    function renderListings(reset = true) {
        if (reset) listingsPage = 1;
        const start = 0;
        const end = listingsPage * listingsPerPage;
        const displayed = freshListings.slice(0, end);
        const container = document.getElementById('fresh-listings');
        container.innerHTML = displayed.map(listing => `
            <a href="/listing/${listing.id}" class="listing-card">
                <div class="listing-image">${listing.image}</div>
                <div class="listing-info">
                    <div class="listing-price">${listing.price.toLocaleString()} ₽</div>
                    <div class="listing-title">${listing.title}</div>
                    <div class="listing-meta">
                        <span>${listing.city}</span>
                        <span>${new Date(listing.created_at).toLocaleDateString()}</span>
                    </div>
                </div>
            </a>
        `).join('');
        const loadMoreBtn = document.getElementById('load-more-btn');
        if (end >= freshListings.length) {
            loadMoreBtn.style.display = 'none';
        } else {
            loadMoreBtn.style.display = 'inline-block';
        }
    }

    function loadMoreListings() {
        listingsPage++;
        renderListings(false);
    }

    // ===== АУКЦИОН: ОТПРАВКА ФОРМЫ =====
    document.addEventListener('DOMContentLoaded', () => {
        renderCategories();
        renderRecommended();
        renderBusinesses();
        renderActiveAuctions();
        renderListings();

        const auctionForm = document.getElementById('auction-form');
        auctionForm.addEventListener('submit', async (e) => {
            e.preventDefault();
            const formData = new FormData(auctionForm);
            const price = formData.get('price');
            const csrfToken = formData.get('csrf_token');

            // Имитация отправки (заменить на реальный endpoint)
            try {
                // const response = await fetch('/api/auction/create', {
                //     method: 'POST',
                //     headers: { 'Content-Type': 'application/json' },
                //     body: JSON.stringify({ price, csrf_token: csrfToken })
                // });
                // const result = await response.json();
                // if (result.success) {
                //     Notify.success('Заявка опубликована!');
                //     // добавить заявку в список
                // } else {
                //     Notify.error(result.error || 'Ошибка');
                // }
                console.log('Auction request:', { price, csrfToken });
                alert('Функция аукциона временно недоступна. Заявка не отправлена.');
            } catch (err) {
                console.error(err);
                alert('Ошибка соединения');
            }
        });

        document.getElementById('load-more-btn').addEventListener('click', loadMoreListings);
    });
</script>