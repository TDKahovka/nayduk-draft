<?php
/* ============================================
   НАЙДУК — Страница объявления (финальная версия)
   Версия 6.0 (апрель 2026)
   - Полностью согласованный функционал
   - Телефон скрыт, кнопка показа для авторизованных (rate limit 10/час)
   - Деловое предложение: 1%,3%,5%,8%,11% (горизонтальный скролл)
   - Кнопки для автора: Редактировать, Снять с публикации, Пиарить
   - Рейтинг продавца (звёзды)
   - Избранное: авторизованные (БД, 100/мес), гости (localStorage)
   - Сообщение продавцу → переход на страницу чата
   - Пожаловаться (модальное окно)
   - 3 похожих объявления + 1 реферальное (из партнёрского кабинета)
   - Отзывы: после подтверждённой сделки, фото для оценки 1–3
   - Подтверждение сделки (защита от накрутки отзывов)
   - Удалены слоты встреч, видеозвонок, сохранение поиска
   ============================================ */

if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    http_response_code(404);
    include __DIR__ . '/../404.php';
    exit;
}
$listingId = (int)$_GET['id'];

global $pageTitle, $pageDescription;
$pageTitle = 'Объявление #' . $listingId . ' — Найдук';
$pageDescription = 'Подробная информация об объявлении.';
?>
<div class="listing-container" id="listingContainer">
    <div class="loader" id="loader">Загрузка объявления...</div>
    <div id="listingContent" style="display: none;"></div>
</div>

<style>
    /* ===== ГЛОБАЛЬНЫЕ СТИЛИ (премиум, адаптив) ===== */
    .listing-container {
        max-width: 1200px;
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
        display: flex;
        align-items: center;
        gap: 8px;
        flex-wrap: wrap;
    }
    .seller-rating {
        font-size: 14px;
        color: var(--warning);
        margin-left: 8px;
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
    .offer-summary {
        padding: 24px;
        border-top: 1px solid var(--border-light);
    }
    .offer-list {
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
    .business-offer-section {
        margin: 20px;
        padding: 20px;
        background: var(--bg-secondary);
        border-radius: var(--radius-xl);
    }
    .business-offer-section h3 {
        margin-bottom: 12px;
    }
    .offer-scroll {
        display: flex;
        overflow-x: auto;
        gap: 12px;
        padding: 8px 0;
    }
    .offer-card {
        flex: 0 0 100px;
        background: var(--surface);
        border: 1px solid var(--border);
        border-radius: var(--radius-lg);
        padding: 12px;
        text-align: center;
        cursor: pointer;
        transition: all 0.2s;
    }
    .offer-card.selected {
        border-color: var(--primary);
        background: rgba(74,144,226,0.1);
    }
    .offer-percent {
        font-size: 20px;
        font-weight: 700;
        color: var(--primary);
    }
    .offer-label {
        font-size: 12px;
        color: var(--text-secondary);
    }
    .send-offer-btn {
        margin-top: 16px;
        width: 100%;
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
    .similar-section {
        margin: 20px;
        padding: 20px;
        border-top: 1px solid var(--border-light);
    }
    .similar-section h3 {
        font-size: 20px;
        margin-bottom: 16px;
    }
    .similar-grid {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
        gap: 16px;
    }
    .similar-card {
        background: var(--surface);
        border: 1px solid var(--border);
        border-radius: var(--radius-lg);
        overflow: hidden;
        transition: transform 0.2s;
        cursor: pointer;
    }
    .similar-card:hover {
        transform: translateY(-4px);
        box-shadow: var(--shadow-md);
    }
    .similar-card img {
        width: 100%;
        height: 150px;
        object-fit: cover;
        background: var(--bg-secondary);
    }
    .similar-info {
        padding: 12px;
    }
    .similar-price {
        font-weight: 700;
        color: var(--primary);
    }
    .modal {
        position: fixed;
        top: 0;
        left: 0;
        right: 0;
        bottom: 0;
        background: rgba(0,0,0,0.5);
        display: flex;
        align-items: center;
        justify-content: center;
        z-index: 1000;
        visibility: hidden;
        opacity: 0;
        transition: all 0.2s;
    }
    .modal.active {
        visibility: visible;
        opacity: 1;
    }
    .modal-content {
        background: var(--surface);
        border-radius: var(--radius-xl);
        padding: 24px;
        max-width: 500px;
        width: 90%;
        max-height: 90vh;
        overflow-y: auto;
    }
    .modal-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 16px;
    }
    .modal-close {
        background: none;
        border: none;
        font-size: 24px;
        cursor: pointer;
        color: var(--text-secondary);
    }
    .rating-summary {
        margin: 20px;
        padding: 20px;
        background: var(--bg-secondary);
        border-radius: var(--radius-xl);
    }
    .reviews-section {
        margin: 20px;
        border-top: 1px solid var(--border-light);
        padding-top: 20px;
    }
    .reviews-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        flex-wrap: wrap;
        margin-bottom: 20px;
    }
    .rating-distribution {
        margin-top: 12px;
    }
    .distr-bar {
        display: flex;
        align-items: center;
        gap: 8px;
        margin-bottom: 4px;
        font-size: 12px;
    }
    .distr-bar .bar {
        flex: 1;
        height: 8px;
        background: var(--bg-secondary);
        border-radius: var(--radius-full);
        overflow: hidden;
    }
    .distr-bar .fill {
        height: 100%;
        background: var(--primary);
        width: 0;
    }
    .review-card {
        background: var(--surface);
        border: 1px solid var(--border-light);
        border-radius: var(--radius);
        padding: 16px;
        margin-bottom: 16px;
    }
    .review-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 8px;
        flex-wrap: wrap;
    }
    .review-rating {
        color: var(--warning);
        font-size: 14px;
    }
    .review-date {
        font-size: 12px;
        color: var(--text-secondary);
    }
    .review-text {
        margin: 12px 0;
        line-height: 1.5;
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
    .review-seller-reply {
        margin-top: 12px;
        padding: 12px;
        background: var(--bg-secondary);
        border-radius: var(--radius);
        font-size: 13px;
    }
    .review-actions {
        margin-top: 12px;
        display: flex;
        gap: 12px;
    }
    .btn-helpful {
        background: none;
        border: none;
        color: var(--text-secondary);
        cursor: pointer;
        font-size: 13px;
        display: flex;
        align-items: center;
        gap: 4px;
    }
    .btn-helpful.active {
        color: var(--primary);
    }
    .btn-leave-review {
        background: var(--primary);
        color: white;
        border: none;
        border-radius: var(--radius-full);
        padding: 10px 20px;
        cursor: pointer;
        font-weight: 600;
    }
    .empty-state {
        text-align: center;
        padding: 40px;
        color: var(--text-secondary);
    }
    @media (max-width: 768px) {
        .listing-title { font-size: 24px; }
        .listing-price { font-size: 28px; }
        .photos-gallery img { height: 200px; }
        .seller-card { flex-direction: column; align-items: stretch; }
        .seller-actions { justify-content: center; }
        .similar-grid { grid-template-columns: 1fr 1fr; }
        .action-btn { padding: 8px 16px; font-size: 14px; }
        .offer-card { flex: 0 0 80px; }
        .offer-percent { font-size: 16px; }
    }
</style>

<script src="https://cdn.jsdelivr.net/npm/toastify-js"></script>
<script>
    const listingId = <?= $listingId ?>;
    let csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
    let currentUser = null;
    let isFavorite = false;
    let listingData = null;
    let selectedDiscount = null;
    let currentReviewPage = 1;
    let totalReviewPages = 1;
    let hasDeal = false;

    // Helper: показать уведомление
    function showToast(message, type = 'success') {
        const colors = {
            success: 'linear-gradient(135deg, #34C759, #2C9B4E)',
            error: 'linear-gradient(135deg, #FF3B30, #C72A2A)',
            warning: 'linear-gradient(135deg, #FF9500, #E68600)',
            info: 'linear-gradient(135deg, #5A67D8, #4C51BF)'
        };
        Toastify({
            text: message,
            duration: 4000,
            gravity: 'top',
            position: 'right',
            backgroundColor: colors[type] || colors.info
        }).showToast();
    }

    // Получить текущего пользователя
    async function loadCurrentUser() {
        try {
            const res = await fetch('/api/auth/current.php', {
                method: 'GET',
                headers: { 'X-CSRF-Token': csrfToken }
            });
            const data = await res.json();
            if (data.success) currentUser = data.user;
        } catch (e) {}
    }

    // Загрузка объявления
    async function loadListing() {
        try {
            const res = await fetch('/api/listings.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ action: 'get', listing_id: listingId })
            });
            const data = await res.json();
            if (data.success && data.data) {
                listingData = data.data;
                renderListing(listingData);
                loadSellerRating(listingData.user_id);
                checkFavorite();
                loadSimilarListings(listingData.category_id);
                loadReferralProduct(listingData.city);
                loadReviews(1);
                loadDealStatus();
            } else {
                showError('Объявление не найдено');
            }
        } catch (err) {
            showError('Ошибка загрузки');
        }
    }

    // Загрузка рейтинга продавца
    async function loadSellerRating(userId) {
        try {
            const res = await fetch('/api/reviews.php?action=user&user_id=' + userId);
            const data = await res.json();
            if (data.success && data.rating) {
                const ratingDiv = document.getElementById('seller-rating');
                if (ratingDiv) {
                    const stars = '★'.repeat(Math.round(data.rating)) + '☆'.repeat(5 - Math.round(data.rating));
                    ratingDiv.innerHTML = `${stars} ${data.rating.toFixed(1)}`;
                }
            }
        } catch (e) {}
    }

    // Отрисовка объявления
    function renderListing(listing) {
        // Фото
        let photosHtml = '';
        if (listing.photos && listing.photos.length) {
            photosHtml = '<div class="photos-gallery">';
            listing.photos.forEach(photo => {
                photosHtml += `<img src="${photo.url}" alt="Фото" onclick="openModalImage('${photo.url}')">`;
            });
            photosHtml += '</div>';
        } else {
            photosHtml = '<div class="no-photo">📷 Фотографии отсутствуют</div>';
        }

        // Кнопки для автора
        let authorButtonsHtml = '';
        if (currentUser && currentUser.id == listing.user_id) {
            authorButtonsHtml = `
                <div class="seller-actions" style="margin-top: 12px;">
                    <a href="/listings/edit.php?id=${listing.id}" class="action-btn action-btn-secondary">✏️ Редактировать</a>
                    <button onclick="unpublishListing(${listing.id})" class="action-btn action-btn-secondary">📌 Снять с публикации</button>
                    <button onclick="promoteListing(${listing.id})" class="action-btn action-btn-primary">📢 Пиарить</button>
                </div>
            `;
        }

        // Блок продавца
        const sellerHtml = `
            <div class="seller-card">
                <div class="seller-info">
                    <h3>${escapeHtml(listing.user_name || 'Пользователь')}
                        <span id="seller-rating" class="seller-rating"></span>
                    </h3>
                    ${listing.user_phone && currentUser ? `
                        <div style="margin-top: 8px;">
                            <button class="action-btn action-btn-secondary" onclick="showPhone(${listing.user_id})">📞 Показать телефон</button>
                            <span class="form-hint" style="display: block; margin-top: 4px;">Номер виден только вам после нажатия, мы не передаём его третьим лицам.</span>
                        </div>
                    ` : ''}
                </div>
                <div class="seller-actions">
                    <button class="action-btn action-btn-primary" onclick="startChat(${listing.user_id})">💬 Написать</button>
                    <button class="action-btn action-btn-secondary" onclick="reportListing(${listing.id})">⚠️ Пожаловаться</button>
                </div>
            </div>
            ${authorButtonsHtml}
        `;

        // Деловое предложение (если включено)
        let businessOfferHtml = '';
        if (listing.is_sealed) {
            const discounts = [1, 3, 5, 8, 11];
            businessOfferHtml = `
                <div class="business-offer-section">
                    <h3>🤝 Деловое предложение</h3>
                    <p>Вы можете предложить свою цену. Продавец выберет лучшее предложение.</p>
                    <div class="offer-scroll" id="offer-scroll">
                        ${discounts.map(d => `
                            <div class="offer-card" data-discount="${d}">
                                <div class="offer-percent">-${d}%</div>
                                <div class="offer-label">скидка</div>
                            </div>
                        `).join('')}
                    </div>
                    <button id="send-offer-btn" class="action-btn action-btn-primary send-offer-btn" disabled>Отправить предложение</button>
                    <div class="form-hint" style="margin-top: 8px;">Продавец не обязан принимать предложение. Предложение остаётся анонимным до принятия.</div>
                </div>
            `;
        }

        // Подарок
        let giftHtml = '';
        if (listing.gift && listing.gift.description && listing.gift.remaining > 0) {
            giftHtml = `
                <div class="gift-block">
                    🎁 Подарок от продавца: ${escapeHtml(listing.gift.description)}<br>
                    Осталось: ${listing.gift.remaining} шт.
                    <button onclick="claimGift(${listing.id})" class="action-btn action-btn-secondary" style="margin-top: 8px;">Забрать подарок</button>
                </div>
            `;
        }

        // Кнопка избранного
        const favoriteBtn = `
            <div class="share-block">
                <button class="favorite-btn ${isFavorite ? 'active' : ''}" onclick="toggleFavorite()">
                    ${isFavorite ? '❤️' : '🤍'} Избранное
                </button>
                <button class="action-btn action-btn-secondary" onclick="shareListing()">🔗 Поделиться</button>
            </div>
        `;

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
                ${businessOfferHtml}
                ${giftHtml}
                ${favoriteBtn}
                <div id="similar-section" class="similar-section">
                    <h3>📌 Возможно заинтересует</h3>
                    <div id="similar-grid" class="similar-grid"></div>
                </div>
                <div class="reviews-section" id="reviews-section">
                    <div class="reviews-header">
                        <h3>📝 Отзывы покупателей</h3>
                        <button id="leave-review-btn" class="btn-leave-review" style="display: none;">Оставить отзыв</button>
                    </div>
                    <div id="reviews-container">
                        <div class="skeleton skeleton-card" style="height: 100px;"></div>
                    </div>
                    <div id="reviews-load-more" class="load-more-btn" style="display: none; text-align: center; margin-top: 20px;">
                        <button class="btn btn-secondary" onclick="loadMoreReviews()">Загрузить ещё</button>
                    </div>
                </div>
            </div>
        `;

        document.getElementById('loader').style.display = 'none';
        const container = document.getElementById('listingContent');
        container.innerHTML = html;
        container.style.display = 'block';

        if (businessOfferHtml) {
            document.querySelectorAll('.offer-card').forEach(card => {
                card.addEventListener('click', () => {
                    document.querySelectorAll('.offer-card').forEach(c => c.classList.remove('selected'));
                    card.classList.add('selected');
                    selectedDiscount = card.dataset.discount;
                    document.getElementById('send-offer-btn').disabled = false;
                });
            });
            document.getElementById('send-offer-btn').addEventListener('click', () => {
                if (selectedDiscount) sendOffer(listing.id, selectedDiscount);
            });
        }
    }

    // Показать телефон
    async function showPhone(sellerId) {
        if (!currentUser) {
            showToast('Войдите, чтобы увидеть телефон', 'warning');
            return;
        }
        try {
            const res = await fetch('/api/listings/contacts.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ action: 'get_phone', listing_id: listingId, seller_id: sellerId, csrf_token: csrfToken })
            });
            const data = await res.json();
            if (data.success && data.phone) {
                showToast(`Телефон: ${data.phone}`, 'info');
            } else {
                showToast(data.error || 'Не удалось получить телефон', 'error');
            }
        } catch (err) {
            showToast('Ошибка сети', 'error');
        }
    }

    // Отправить деловое предложение
    async function sendOffer(listingId, discount) {
        if (!currentUser) {
            showToast('Войдите, чтобы отправить предложение', 'warning');
            return;
        }
        try {
            const res = await fetch('/api/listings.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ action: 'make_offer', listing_id: listingId, discount: discount, csrf_token: csrfToken })
            });
            const data = await res.json();
            if (data.success) {
                showToast('Предложение отправлено!', 'success');
                document.getElementById('send-offer-btn').disabled = true;
                document.querySelectorAll('.offer-card').forEach(c => c.classList.remove('selected'));
                selectedDiscount = null;
            } else {
                showToast(data.error || 'Ошибка отправки', 'error');
            }
        } catch (err) {
            showToast('Ошибка сети', 'error');
        }
    }

    // Забрать подарок
    async function claimGift(listingId) {
        if (!currentUser) {
            showToast('Войдите, чтобы забрать подарок', 'warning');
            return;
        }
        try {
            const res = await fetch('/api/listings.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ action: 'claim_gift', listing_id: listingId, csrf_token: csrfToken })
            });
            const data = await res.json();
            if (data.success) {
                showToast(data.message, 'success');
                setTimeout(() => location.reload(), 2000);
            } else {
                showToast(data.error, 'error');
            }
        } catch (err) {
            showToast('Ошибка сети', 'error');
        }
    }

    // Похожие объявления (3 из той же категории)
    async function loadSimilarListings(categoryId) {
        if (!categoryId) return;
        try {
            const res = await fetch('/api/listings.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ action: 'list', category_id: categoryId, limit: 10, sort: 'created_at', order: 'DESC' })
            });
            const data = await res.json();
            if (data.success && data.data.length) {
                let similar = data.data.filter(item => item.id != listingId);
                if (similar.length > 3) similar = similar.slice(0, 3);
                renderSimilarListings(similar);
            }
        } catch (e) {}
    }

    // Реферальное предложение (1 товар из партнёрского кабинета)
    async function loadReferralProduct(city) {
        try {
            const res = await fetch('/api/recommended/random.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ city: city, csrf_token: csrfToken })
            });
            const data = await res.json();
            if (data.success && data.product) {
                const container = document.getElementById('similar-grid');
                if (container) {
                    const productHtml = `
                        <a href="${data.product.url}" class="similar-card" target="_blank" rel="noopener">
                            <img src="${data.product.image || '/assets/img/no-image.png'}" alt="${escapeHtml(data.product.title)}">
                            <div class="similar-info">
                                <div class="similar-price">${data.product.price ? data.product.price.toLocaleString() + ' ₽' : 'Цена не указана'}</div>
                                <div>${escapeHtml(data.product.title)}</div>
                                <div class="form-hint">🤝 Рекомендуем</div>
                            </div>
                        </a>
                    `;
                    container.insertAdjacentHTML('beforeend', productHtml);
                }
            }
        } catch (e) {}
    }

    function renderSimilarListings(listings) {
        const container = document.getElementById('similar-grid');
        if (!container) return;
        let html = '';
        listings.forEach(item => {
            html += `
                <a href="/listing/${item.id}" class="similar-card">
                    <img src="${item.photo || '/assets/img/no-image.png'}" alt="${escapeHtml(item.title)}">
                    <div class="similar-info">
                        <div class="similar-price">${item.price ? item.price.toLocaleString() + ' ₽' : 'Цена не указана'}</div>
                        <div>${escapeHtml(item.title)}</div>
                    </div>
                </a>
            `;
        });
        container.innerHTML = html;
    }

    // Избранное
    async function checkFavorite() {
        if (!currentUser) {
            const favs = JSON.parse(localStorage.getItem('favorites') || '[]');
            isFavorite = favs.includes(listingId);
            updateFavoriteButton();
            return;
        }
        try {
            const res = await fetch('/api/listings/favorites.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ action: 'check', listing_id: listingId, csrf_token: csrfToken })
            });
            const data = await res.json();
            if (data.success) isFavorite = data.is_favorite;
            updateFavoriteButton();
        } catch (e) {}
    }
    async function toggleFavorite() {
        if (!currentUser) {
            let favs = JSON.parse(localStorage.getItem('favorites') || '[]');
            if (isFavorite) {
                favs = favs.filter(id => id != listingId);
                showToast('Удалено из избранного', 'info');
            } else {
                favs.push(listingId);
                showToast('Добавлено в избранное', 'success');
            }
            localStorage.setItem('favorites', JSON.stringify(favs));
            isFavorite = !isFavorite;
            updateFavoriteButton();
            return;
        }
        const action = isFavorite ? 'remove' : 'add';
        try {
            const res = await fetch('/api/listings/favorites.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ action: action, listing_id: listingId, csrf_token: csrfToken })
            });
            const data = await res.json();
            if (data.success) {
                isFavorite = !isFavorite;
                updateFavoriteButton();
                showToast(isFavorite ? 'Добавлено в избранное' : 'Удалено из избранного', 'info');
            } else {
                showToast(data.error || 'Ошибка', 'error');
            }
        } catch (err) {
            showToast('Ошибка сети', 'error');
        }
    }
    function updateFavoriteButton() {
        const btn = document.querySelector('.favorite-btn');
        if (btn) {
            btn.innerHTML = isFavorite ? '❤️ Избранное' : '🤍 Избранное';
            btn.classList.toggle('active', isFavorite);
        }
    }

    // Действия
    function startChat(userId) {
        if (!currentUser) {
            showToast('Войдите, чтобы начать чат', 'warning');
            return;
        }
        window.location.href = `/chat?user=${userId}`;
    }
    function shareListing() {
        if (navigator.share) {
            navigator.share({
                title: document.title,
                url: window.location.href
            });
        } else {
            navigator.clipboard.writeText(window.location.href);
            showToast('Ссылка скопирована', 'success');
        }
    }
    function reportListing(listingId) {
        const modal = document.getElementById('report-modal');
        if (!modal) return;
        modal.classList.add('active');
        document.getElementById('report-listing-id').value = listingId;
    }
    async function submitReport() {
        const listingId = document.getElementById('report-listing-id').value;
        const reason = document.getElementById('report-reason').value;
        const comment = document.getElementById('report-comment').value;
        if (!reason) {
            showToast('Выберите причину', 'warning');
            return;
        }
        try {
            const res = await fetch('/api/listings/reports.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ action: 'create', listing_id: listingId, reason: reason, comment: comment, csrf_token: csrfToken })
            });
            const data = await res.json();
            if (data.success) {
                showToast('Жалоба отправлена', 'success');
                closeModal('report-modal');
            } else {
                showToast(data.error || 'Ошибка', 'error');
            }
        } catch (err) {
            showToast('Ошибка сети', 'error');
        }
    }
    function unpublishListing(listingId) {
        if (!confirm('Снять объявление с публикации? Оно станет недоступно для других.')) return;
        fetch('/api/listings.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: 'mark_sold', listing_id: listingId, csrf_token: csrfToken })
        })
        .then(res => res.json())
        .then(data => {
            if (data.success) {
                showToast('Объявление снято с публикации', 'success');
                setTimeout(() => location.reload(), 1500);
            } else {
                showToast(data.error || 'Ошибка', 'error');
            }
        })
        .catch(err => showToast('Ошибка сети', 'error'));
    }
    function promoteListing(listingId) {
        window.location.href = `/listings/promote.php?id=${listingId}`;
    }

    // Отзывы
    async function loadReviews(page = 1, append = false) {
        const container = document.getElementById('reviews-container');
        if (!container) return;
        if (!append) {
            container.innerHTML = '<div class="skeleton skeleton-card" style="height: 100px;"></div>';
        }
        try {
            const response = await fetch(`/api/reviews.php?action=list&user_id=${listingData.user_id}&page=${page}&limit=5&csrf_token=${csrfToken}`);
            const data = await response.json();
            if (!data.success) throw new Error(data.error);
            const reviews = data.data || [];
            const meta = data.meta;
            totalReviewPages = meta.pages;
            if (reviews.length === 0 && !append) {
                container.innerHTML = '<div class="empty-state">Пока нет отзывов. Будьте первым!</div>';
                return;
            }
            let html = '';
            for (const r of reviews) {
                const ratingStars = '★'.repeat(r.rating) + '☆'.repeat(5 - r.rating);
                let photosHtml = '';
                if (r.photos && r.photos.length) {
                    photosHtml = '<div class="review-photos">';
                    for (const photo of r.photos) {
                        photosHtml += `<img src="${escapeHtml(photo)}" class="review-photo" onclick="window.open(this.src)">`;
                    }
                    photosHtml += '</div>';
                }
                let replyHtml = '';
                if (r.seller_reply) {
                    replyHtml = `<div class="review-seller-reply"><strong>Ответ продавца:</strong> ${escapeHtml(r.seller_reply)}</div>`;
                }
                let helpfulHtml = '';
                if (currentUser && currentUser.id !== r.reviewer_id) {
                    helpfulHtml = `
                        <div class="review-actions">
                            <button class="btn-helpful" data-id="${r.id}" data-helpful="1">👍 Полезно (${r.helpful_count || 0})</button>
                            <button class="btn-helpful" data-id="${r.id}" data-helpful="0">👎 Не полезно (${r.not_helpful_count || 0})</button>
                        </div>
                    `;
                }
                html += `
                    <div class="review-card">
                        <div class="review-header">
                            <div class="review-rating">${ratingStars}</div>
                            <div class="review-date">${new Date(r.created_at).toLocaleDateString('ru-RU')}</div>
                        </div>
                        <div class="review-author"><strong>${escapeHtml(r.reviewer_name || 'Покупатель')}</strong></div>
                        <div class="review-text">${escapeHtml(r.comment)}</div>
                        ${photosHtml}
                        ${replyHtml}
                        ${helpfulHtml}
                    </div>
                `;
            }
            if (append) {
                container.insertAdjacentHTML('beforeend', html);
            } else {
                container.innerHTML = html;
            }
            const loadMoreBtn = document.getElementById('reviews-load-more');
            if (loadMoreBtn && meta && meta.page < meta.pages) {
                loadMoreBtn.style.display = 'block';
                loadMoreBtn.dataset.page = meta.page;
            } else if (loadMoreBtn) {
                loadMoreBtn.style.display = 'none';
            }
            attachHelpfulButtons();
        } catch (err) {
            console.error(err);
            container.innerHTML = '<div class="empty-state">Ошибка загрузки отзывов</div>';
        }
    }

    function attachHelpfulButtons() {
        document.querySelectorAll('.btn-helpful').forEach(btn => {
            btn.removeEventListener('click', helpfulHandler);
            btn.addEventListener('click', helpfulHandler);
        });
    }

    async function helpfulHandler(e) {
        const btn = e.currentTarget;
        const reviewId = btn.dataset.id;
        const isHelpful = btn.dataset.helpful === '1';
        try {
            const response = await fetch('/api/reviews.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: new URLSearchParams({
                    action: 'helpful',
                    review_id: reviewId,
                    is_helpful: isHelpful ? 1 : 0,
                    csrf_token: csrfToken
                })
            });
            const data = await response.json();
            if (data.success) {
                showToast('Ваш голос учтён', 'info');
                loadReviews(1, false);
            } else {
                showToast(data.error || 'Ошибка', 'error');
            }
        } catch (err) {
            showToast('Ошибка сети', 'error');
        }
    }

    async function loadMoreReviews() {
        const btn = document.getElementById('reviews-load-more');
        if (!btn) return;
        const currentPage = parseInt(btn.dataset.page) || 1;
        const nextPage = currentPage + 1;
        await loadReviews(nextPage, true);
        btn.dataset.page = nextPage;
    }

    // Подтверждение сделки
    async function loadDealStatus() {
        if (!currentUser) return;
        try {
            const res = await fetch('/api/deals.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ action: 'status', listing_id: listingId, csrf_token: csrfToken })
            });
            const data = await res.json();
            if (data.success && data.has_deal) {
                hasDeal = true;
                document.getElementById('leave-review-btn').style.display = 'inline-block';
            } else {
                hasDeal = false;
                document.getElementById('leave-review-btn').style.display = 'none';
            }
        } catch (e) {}
    }

    async function confirmDeal(role) {
        try {
            const res = await fetch('/api/deals.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ action: 'confirm', listing_id: listingId, role: role, csrf_token: csrfToken })
            });
            const data = await res.json();
            if (data.success) {
                showToast(data.message);
                loadDealStatus();
            } else {
                showToast(data.error || 'Ошибка', 'error');
            }
        } catch (err) {
            showToast('Ошибка сети', 'error');
        }
    }

    // Модальное окно для жалобы
    function closeModal(id) {
        document.getElementById(id).classList.remove('active');
    }

    // Общие утилиты
    function openModalImage(url) {
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
    function showError(message) {
        document.getElementById('loader').innerHTML = `<div style="text-align:center; color:var(--danger);">❌ ${message}</div>`;
    }
    function escapeHtml(str) {
        if (!str) return '';
        return str.replace(/[&<>]/g, m => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;' }[m] || m));
    }

    // Инициализация
    loadCurrentUser().then(() => loadListing());
</script>

<!-- Модальное окно для жалобы -->
<div id="report-modal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h3>Пожаловаться на объявление</h3>
            <button class="modal-close" onclick="closeModal('report-modal')">✕</button>
        </div>
        <input type="hidden" id="report-listing-id">
        <div class="form-group">
            <label>Причина</label>
            <select id="report-reason" class="form-select">
                <option value="">Выберите причину</option>
                <option value="spam">Спам</option>
                <option value="fraud">Мошенничество</option>
                <option value="illegal">Незаконный контент</option>
                <option value="rules">Нарушение правил</option>
                <option value="other">Другое</option>
            </select>
        </div>
        <div class="form-group">
            <label>Комментарий (необязательно)</label>
            <textarea id="report-comment" class="form-textarea" rows="3"></textarea>
        </div>
        <div class="form-actions">
            <button onclick="submitReport()" class="btn btn-primary">Отправить жалобу</button>
            <button onclick="closeModal('report-modal')" class="btn btn-secondary">Отмена</button>
        </div>
    </div>
</div>