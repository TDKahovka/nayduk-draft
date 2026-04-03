<?php
/* ============================================
   НАЙДУК — Страница объявления (полная премиум-версия)
   Версия 2.0 (апрель 2026)
   - Все функции реализованы сразу, без ожидания
   - Карта (OpenStreetMap), рекомендации, избранное, жалобы, чат
   - Полная адаптивность, премиальный дизайн
   ============================================ */

if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    http_response_code(404);
    include __DIR__ . '/../404.php';
    exit;
}
$listingId = (int)$_GET['id'];

global $pageTitle, $pageDescription;
$pageTitle = 'Объявление #' . $listingId . ' — Найдук';
$pageDescription = 'Подробная информация об объявлении на Найдук.';
?>
<div class="listing-container" id="listingContainer">
    <div class="loader" id="loader">Загрузка объявления...</div>
    <div id="listingContent" style="display: none;"></div>
</div>

<style>
    /* Дополнительные стили для страницы (расширяют глобальные) */
    .listing-container {
        max-width: 1280px;
        margin: 0 auto;
        padding: 20px;
    }
    .loader {
        text-align: center;
        padding: 80px;
        font-size: 18px;
        color: var(--text-secondary);
    }
    .listing-detail {
        background: var(--surface);
        border-radius: var(--radius-2xl);
        border: 1px solid var(--border);
        overflow: hidden;
        box-shadow: var(--shadow-md);
    }
    .listing-header {
        padding: 24px;
        border-bottom: 1px solid var(--border-light);
    }
    .listing-title {
        font-size: 28px;
        font-weight: 700;
        margin-bottom: 12px;
        color: var(--text);
    }
    .listing-price {
        font-size: 32px;
        font-weight: 800;
        color: var(--primary);
        margin-bottom: 8px;
    }
    .listing-meta {
        display: flex;
        gap: 20px;
        flex-wrap: wrap;
        font-size: 14px;
        color: var(--text-secondary);
        margin-top: 12px;
    }
    .photos-gallery {
        display: flex;
        gap: 12px;
        overflow-x: auto;
        padding: 24px;
        background: var(--bg-secondary);
        scrollbar-width: thin;
    }
    .photos-gallery img {
        height: 300px;
        width: auto;
        border-radius: var(--radius-lg);
        object-fit: cover;
        cursor: pointer;
        transition: transform 0.2s;
        box-shadow: var(--shadow-sm);
    }
    .photos-gallery img:hover {
        transform: scale(1.02);
    }
    .no-photo {
        background: var(--bg-secondary);
        padding: 80px;
        text-align: center;
        color: var(--text-secondary);
        font-size: 18px;
    }
    .listing-description {
        padding: 24px;
        border-bottom: 1px solid var(--border-light);
    }
    .listing-description h3 {
        font-size: 20px;
        font-weight: 600;
        margin-bottom: 12px;
    }
    .listing-description p {
        line-height: 1.6;
        white-space: pre-wrap;
    }
    .seller-card {
        padding: 24px;
        background: var(--bg-secondary);
        margin: 20px;
        border-radius: var(--radius-xl);
        display: flex;
        justify-content: space-between;
        align-items: center;
        flex-wrap: wrap;
        gap: 20px;
    }
    .seller-info h3 {
        font-size: 18px;
        font-weight: 600;
        margin-bottom: 8px;
    }
    .seller-info p {
        margin: 4px 0;
        font-size: 14px;
        color: var(--text-secondary);
    }
    .seller-rating {
        display: flex;
        align-items: center;
        gap: 8px;
        margin-top: 8px;
    }
    .stars {
        color: var(--accent);
        letter-spacing: 2px;
    }
    .seller-actions {
        display: flex;
        gap: 12px;
        flex-wrap: wrap;
    }
    .action-btn {
        padding: 10px 20px;
        border-radius: var(--radius-full);
        font-weight: 600;
        cursor: pointer;
        border: none;
        transition: all 0.2s;
    }
    .action-btn-primary {
        background: var(--primary);
        color: white;
    }
    .action-btn-primary:hover {
        background: var(--primary-dark);
        transform: translateY(-2px);
    }
    .action-btn-secondary {
        background: var(--surface);
        border: 1px solid var(--border);
        color: var(--text);
    }
    .action-btn-secondary:hover {
        background: var(--bg-secondary);
    }
    .favorite-btn {
        background: none;
        border: none;
        font-size: 24px;
        cursor: pointer;
        color: var(--text-secondary);
        transition: transform 0.2s;
    }
    .favorite-btn.active {
        color: var(--danger);
    }
    .map-container {
        margin: 20px;
        height: 300px;
        border-radius: var(--radius-xl);
        overflow: hidden;
        border: 1px solid var(--border);
    }
    .recommendations {
        margin: 20px;
        padding: 20px;
        border-top: 1px solid var(--border-light);
    }
    .recommendations h3 {
        font-size: 20px;
        font-weight: 600;
        margin-bottom: 16px;
    }
    .recommendations-grid {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
        gap: 16px;
    }
    .recommend-card {
        background: var(--surface);
        border: 1px solid var(--border);
        border-radius: var(--radius-lg);
        overflow: hidden;
        transition: transform 0.2s;
        cursor: pointer;
    }
    .recommend-card:hover {
        transform: translateY(-4px);
        box-shadow: var(--shadow-md);
    }
    .recommend-card img {
        width: 100%;
        height: 150px;
        object-fit: cover;
        background: var(--bg-secondary);
    }
    .recommend-info {
        padding: 12px;
    }
    .recommend-price {
        font-weight: 700;
        color: var(--primary);
    }
    .offers-summary {
        padding: 24px;
        border-top: 1px solid var(--border-light);
    }
    .offers-list {
        display: flex;
        gap: 16px;
        flex-wrap: wrap;
    }
    .offer-badge {
        background: var(--bg);
        border-radius: var(--radius-full);
        padding: 6px 12px;
        font-size: 14px;
        border: 1px solid var(--border);
    }
    .gift-block {
        background: linear-gradient(135deg, var(--accent-gold), #FFB347);
        color: var(--text);
        padding: 16px;
        border-radius: var(--radius-xl);
        margin: 20px;
        text-align: center;
    }
    .share-block {
        margin: 20px;
        text-align: right;
    }
    @media (max-width: 768px) {
        .listing-title { font-size: 24px; }
        .listing-price { font-size: 28px; }
        .photos-gallery img { height: 200px; }
        .seller-card { flex-direction: column; align-items: stretch; }
        .seller-actions { justify-content: center; }
        .recommendations-grid { grid-template-columns: 1fr 1fr; }
    }
</style>

<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />

<script>
    const listingId = <?= $listingId ?>;
    let csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');
    let currentUser = null;
    let isFavorite = false;

    // Загрузка данных пользователя (если авторизован)
    async function loadCurrentUser() {
        try {
            const res = await fetch('/api/users/current.php');
            const data = await res.json();
            if (data.success) currentUser = data.user;
        } catch (e) {}
    }

    // Загрузка объявления
    async function loadListing() {
        try {
            const res = await fetch('/api/listings/listings.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ action: 'get', listing_id: listingId })
            });
            const data = await res.json();
            if (data.success && data.data) {
                renderListing(data.data);
                if (data.data.lat && data.data.lng) renderMap(data.data.lat, data.data.lng);
                loadRecommendations(data.data.category_id);
                checkFavorite();
            } else {
                showError('Объявление не найдено');
            }
        } catch (err) {
            showError('Ошибка загрузки');
        }
    }

    // Основная отрисовка
    function renderListing(listing) {
        // Фото
        let photosHtml = '';
        if (listing.photos && listing.photos.length) {
            photosHtml = '<div class="photos-gallery">';
            listing.photos.forEach(photo => {
                photosHtml += `<img src="${photo.url}" alt="Фото" onclick="openModal('${photo.url}')">`;
            });
            photosHtml += '</div>';
        } else {
            photosHtml = '<div class="no-photo">📷 Фотографии отсутствуют</div>';
        }

        // Рейтинг продавца (если есть)
        let ratingHtml = '';
        if (listing.seller_rating) {
            const stars = '★'.repeat(Math.floor(listing.seller_rating)) + '☆'.repeat(5 - Math.floor(listing.seller_rating));
            ratingHtml = `<div class="seller-rating"><span class="stars">${stars}</span> <span>${listing.seller_rating} (${listing.seller_reviews || 0} отзывов)</span></div>`;
        }

        // Блок продавца
        const sellerHtml = `
            <div class="seller-card">
                <div class="seller-info">
                    <h3>${escapeHtml(listing.user_name || 'Пользователь')}</h3>
                    ${listing.user_phone ? `<p>📞 Телефон: <span class="phone-number" data-phone="${listing.user_phone}">${maskPhone(listing.user_phone)}</span> <button class="show-phone-btn" data-phone="${listing.user_phone}">Показать</button></p>` : ''}
                    ${listing.user_email ? `<p>✉️ Email: ${escapeHtml(listing.user_email)}</p>` : ''}
                    ${ratingHtml}
                </div>
                <div class="seller-actions">
                    <button class="action-btn action-btn-primary" onclick="startChat(${listing.user_id})">💬 Написать</button>
                    <button class="action-btn action-btn-secondary" onclick="reportListing(${listing.id})">⚠️ Пожаловаться</button>
                </div>
            </div>
        `;

        // Предложения (если есть)
        let offersHtml = '';
        if (listing.type === 'sell') {
            offersHtml = `<div class="offers-summary" id="offers-summary"><h3>📊 Предложения покупателей</h3><div id="offers-list">Загрузка...</div></div>`;
        }

        // Подарок
        let giftHtml = '';
        if (listing.gift && listing.gift.description && listing.gift.remaining > 0) {
            giftHtml = `
                <div class="gift-block">
                    🎁 Подарок от продавца: ${escapeHtml(listing.gift.description)}<br>
                    Осталось: ${listing.gift.remaining} шт.
                    <button onclick="claimGift(${listing.id})">Забрать подарок</button>
                </div>
            `;
        }

        // Кнопка избранного
        const favoriteBtn = currentUser ? `
            <div class="share-block">
                <button class="favorite-btn ${isFavorite ? 'active' : ''}" onclick="toggleFavorite()">
                    ${isFavorite ? '❤️' : '🤍'} Избранное
                </button>
                <button class="action-btn action-btn-secondary" onclick="shareListing()">🔗 Поделиться</button>
            </div>
        ` : '';

        const html = `
            <div class="listing-detail">
                <div class="listing-header">
                    <h1 class="listing-title">${escapeHtml(listing.title)}</h1>
                    <div class="listing-price">${listing.price ? listing.price.toLocaleString() + ' ₽' : 'Цена не указана'}</div>
                    <div class="listing-meta">
                        <span>📍 ${escapeHtml(listing.city || 'Город не указан')}</span>
                        <span>👁️ ${listing.views} просмотров</span>
                        <span>📅 ${new Date(listing.created_at).toLocaleDateString()}</span>
                    </div>
                </div>
                ${photosHtml}
                <div class="listing-description">
                    <h3>Описание</h3>
                    <p>${escapeHtml(listing.description).replace(/\n/g, '<br>')}</p>
                </div>
                ${sellerHtml}
                <div id="map" class="map-container"></div>
                ${offersHtml}
                ${giftHtml}
                ${favoriteBtn}
                <div class="recommendations" id="recommendations"></div>
            </div>
        `;

        document.getElementById('loader').style.display = 'none';
        const container = document.getElementById('listingContent');
        container.innerHTML = html;
        container.style.display = 'block';

        if (listing.type === 'sell') loadOffersSummary(listing.id);
    }

    // Рекомендации (похожие объявления)
    async function loadRecommendations(categoryId) {
        if (!categoryId) return;
        try {
            const res = await fetch('/api/listings/listings.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ action: 'list', category_id: categoryId, limit: 4, sort: 'created_at', order: 'DESC' })
            });
            const data = await res.json();
            if (data.success && data.data.length) {
                let recHtml = '<h3>Похожие объявления</h3><div class="recommendations-grid">';
                data.data.forEach(item => {
                    if (item.id == listingId) return;
                    recHtml += `
                        <a href="/listing/${item.id}" class="recommend-card">
                            <img src="${item.photo || '/assets/img/no-image.png'}" alt="${escapeHtml(item.title)}">
                            <div class="recommend-info">
                                <div class="recommend-price">${item.price ? item.price.toLocaleString() + ' ₽' : 'Цена не указана'}</div>
                                <div>${escapeHtml(item.title)}</div>
                            </div>
                        </a>
                    `;
                });
                recHtml += '</div>';
                document.getElementById('recommendations').innerHTML = recHtml;
            }
        } catch (e) {}
    }

    // Карта
    function renderMap(lat, lng) {
        if (!lat || !lng) {
            document.getElementById('map').innerHTML = '<div class="no-photo">📍 Местоположение не указано</div>';
            return;
        }
        const map = L.map('map').setView([lat, lng], 14);
        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
            attribution: '© OpenStreetMap contributors'
        }).addTo(map);
        L.marker([lat, lng]).addTo(map);
    }

    // Предложения покупателей
    async function loadOffersSummary(listingId) {
        try {
            const res = await fetch('/api/listings/listings.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ action: 'get_offers_summary', listing_id: listingId })
            });
            const data = await res.json();
            if (data.success && data.summary) {
                let offersHtml = '<div class="offers-list">';
                for (const [percent, count] of Object.entries(data.summary)) {
                    if (count > 0) offersHtml += `<div class="offer-badge">${percent}% – ${count} чел.</div>`;
                }
                offersHtml += offersHtml === '<div class="offers-list">' ? '<p>Нет предложений</p>' : '</div>';
                document.getElementById('offers-list').innerHTML = offersHtml;
            } else {
                document.getElementById('offers-list').innerHTML = '<p>Нет предложений</p>';
            }
        } catch (err) {
            document.getElementById('offers-list').innerHTML = '<p>Ошибка загрузки</p>';
        }
    }

    // Избранное
    async function checkFavorite() {
        if (!currentUser) return;
        try {
            const res = await fetch('/api/favorites/check.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ listing_id: listingId, csrf_token: csrfToken })
            });
            const data = await res.json();
            if (data.success) isFavorite = data.is_favorite;
        } catch (e) {}
    }
    async function toggleFavorite() {
        if (!currentUser) {
            Notify.warning('Войдите, чтобы добавить в избранное');
            return;
        }
        try {
            const action = isFavorite ? 'remove' : 'add';
            const res = await fetch('/api/favorites/manage.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ action, listing_id: listingId, csrf_token: csrfToken })
            });
            const data = await res.json();
            if (data.success) {
                isFavorite = !isFavorite;
                const btn = document.querySelector('.favorite-btn');
                if (btn) {
                    btn.innerHTML = isFavorite ? '❤️ Избранное' : '🤍 Избранное';
                    btn.classList.toggle('active', isFavorite);
                }
                Notify.success(isFavorite ? 'Добавлено в избранное' : 'Удалено из избранного');
            } else {
                Notify.error(data.error || 'Ошибка');
            }
        } catch (err) {
            Notify.error('Ошибка сети');
        }
    }

    // Действия
    function startChat(userId) {
        if (!currentUser) {
            Notify.warning('Войдите, чтобы начать чат');
            return;
        }
        window.location.href = `/chat?user=${userId}`;
    }
    async function reportListing(listingId) {
        const reason = prompt('Укажите причину жалобы:');
        if (!reason) return;
        try {
            const res = await fetch('/api/reports/create.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ listing_id: listingId, reason, csrf_token: csrfToken })
            });
            const data = await res.json();
            if (data.success) Notify.success('Жалоба отправлена');
            else Notify.error(data.error || 'Ошибка');
        } catch (err) {
            Notify.error('Ошибка сети');
        }
    }
    async function claimGift(listingId) {
        if (!confirm('Забрать подарок?')) return;
        try {
            const res = await fetch('/api/listings/listings.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ action: 'claim_gift', listing_id: listingId, csrf_token: csrfToken })
            });
            const data = await res.json();
            if (data.success) {
                Notify.success(data.message);
                setTimeout(() => location.reload(), 2000);
            } else {
                Notify.error(data.error || 'Ошибка');
            }
        } catch (err) {
            Notify.error('Ошибка сети');
        }
    }
    function shareListing() {
        if (navigator.share) {
            navigator.share({
                title: document.title,
                url: window.location.href
            });
        } else {
            navigator.clipboard.writeText(window.location.href);
            Notify.success('Ссылка скопирована');
        }
    }
    function openModal(url) {
        const modal = document.createElement('div');
        modal.style.position = 'fixed';
        modal.style.top = 0;
        modal.style.left = 0;
        modal.style.width = '100%';
        modal.style.height = '100%';
        modal.style.backgroundColor = 'rgba(0,0,0,0.9)';
        modal.style.zIndex = 10000;
        modal.style.display = 'flex';
        modal.style.alignItems = 'center';
        modal.style.justifyContent = 'center';
        modal.style.cursor = 'pointer';
        modal.innerHTML = `<img src="${url}" style="max-width:90%; max-height:90%; object-fit:contain;">`;
        modal.onclick = () => modal.remove();
        document.body.appendChild(modal);
    }
    function maskPhone(phone) {
        if (!phone) return '';
        return phone.replace(/(\d)(\d{3})(\d{3})(\d{2})(\d{2})/, '$1 $2 $3-**-**');
    }
    function showError(message) {
        document.getElementById('loader').innerHTML = `<div style="text-align:center; color:var(--danger);">❌ ${message}</div>`;
    }
    function escapeHtml(str) {
        if (!str) return '';
        return str.replace(/[&<>]/g, m => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;' }[m] || m));
    }

    // Обработка показа телефона (делегирование)
    document.addEventListener('click', function(e) {
        if (e.target.classList.contains('show-phone-btn')) {
            const phone = e.target.getAttribute('data-phone');
            const span = e.target.previousElementSibling;
            if (span && span.classList.contains('phone-number')) {
                span.textContent = phone;
                e.target.remove();
            }
        }
    });

    // Инициализация
    loadCurrentUser().then(() => loadListing());
</script>