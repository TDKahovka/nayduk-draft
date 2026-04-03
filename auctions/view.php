<?php
/**
 * НАЙДУК — Страница просмотра аукциона
 * Версия 2.0 (март 2026)
 * - SSE через Redis
 * - 3D-эффект для фото
 * - Кнопка «Купить сейчас»
 * - Лимиты: 3 бесплатные ставки, платные 10 за 50₽
 * - Согласие с правилами, блокировки
 */
session_start();
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../services/Database.php';

$listingId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if (!$listingId) {
    http_response_code(404);
    include __DIR__ . '/../404.php';
    exit;
}

$db = Database::getInstance();
$listing = $db->fetchOne("SELECT * FROM listings WHERE id = ? AND auction_type = 1", [$listingId]);
if (!$listing || $listing['auction_status'] != 'active') {
    http_response_code(404);
    include __DIR__ . '/../404.php';
    exit;
}

$userId = $_SESSION['user_id'] ?? null;
$user = $userId ? $db->getUserById($userId) : null;
$isOwner = ($userId && $userId == $listing['user_id']);

$currentMaxBid = $db->fetchOne("SELECT MAX(bid_price) as max FROM auction_bids WHERE listing_id = ?", [$listingId])['max'] ?? $listing['start_bid'];
$bidsCount = $db->fetchCount("SELECT COUNT(*) FROM auction_bids WHERE listing_id = ?", [$listingId]);

$history = $db->fetchAll("SELECT anonymous_id, color_code, bid_price, created_at FROM auction_bids WHERE listing_id = ? ORDER BY created_at DESC LIMIT 20", [$listingId]);

$participant = $userId ? $db->fetchOne("SELECT free_bids_used, extra_bids_used FROM auction_participants WHERE listing_id = ? AND user_id = ?", [$listingId, $userId]) : null;
$freeUsed = $participant['free_bids_used'] ?? 0;
$extraUsed = $participant['extra_bids_used'] ?? 0;
$freeRemaining = max(0, 3 - $freeUsed);
$extraRemaining = $user ? max(0, $user['extra_bids_balance'] - $extraUsed) : 0;
$totalRemaining = $freeRemaining + $extraRemaining;

$hasConsent = false;
$isBlocked = false;
if ($userId) {
    $latestVersion = $db->fetchOne("SELECT version FROM auction_consent_versions ORDER BY id DESC LIMIT 1");
    if ($latestVersion) {
        $hasConsent = (bool)$db->fetchOne("SELECT 1 FROM user_auction_consents WHERE user_id = ? AND consent_version = ?", [$userId, $latestVersion['version']]);
    }
    $block = $db->fetchOne("SELECT expires_at FROM user_blocks WHERE user_id = ? AND block_type = 'auction' AND expires_at > NOW()", [$userId]);
    $isBlocked = !empty($block);
}

$csrfToken = generateCsrfToken();
$endTimestamp = strtotime($listing['auction_end_at']);
$timeLeft = max(0, $endTimestamp - time());

$photo = $db->fetchOne("SELECT photo_url FROM listing_photos WHERE listing_id = ? ORDER BY sort_order LIMIT 1", [$listingId]);
$photoUrl = $photo ? $photo['photo_url'] : '/uploads/placeholder/naiduk_smile.png';

$pageTitle = "Аукцион: {$listing['title']} — Найдук";
$pageDescription = mb_substr(strip_tags($listing['description']), 0, 160);

require_once __DIR__ . '/../includes/header.php';
?>

<style>
    .auction-view { max-width: 1280px; margin: 0 auto; padding: 20px; }
    .auction-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 30px; }
    @media (max-width: 768px) { .auction-grid { grid-template-columns: 1fr; } }
    .photo-3d {
        position: relative;
        width: 100%;
        border-radius: 24px;
        overflow: hidden;
        transform-style: preserve-3d;
        transition: transform 0.15s ease-out;
        background: #1e293b;
        cursor: default;
    }
    .photo-3d .photo-img {
        position: relative;
        width: 100%;
        padding-top: 75%;
        background-size: cover;
        background-position: center;
        will-change: transform, background-position;
        transition: transform 0.15s ease-out;
    }
    .photo-3d .photo-glow {
        position: absolute;
        inset: -40%;
        background: radial-gradient(circle at 50% 0%, rgba(255,255,255,0.25), transparent 60%);
        mix-blend-mode: screen;
        opacity: 0;
        transition: opacity 0.15s ease-out, transform 0.15s ease-out;
        pointer-events: none;
    }
    .auction-info {
        background: var(--surface);
        border-radius: 24px;
        padding: 20px;
        border: 1px solid var(--border);
    }
    .auction-title { font-size: 28px; font-weight: 700; margin-bottom: 12px; }
    .auction-meta {
        display: flex;
        gap: 20px;
        flex-wrap: wrap;
        font-size: 14px;
        color: var(--text-secondary);
        margin-bottom: 20px;
    }
    .current-bid { font-size: 32px; font-weight: 800; color: var(--primary); margin-bottom: 16px; }
    .timer {
        font-size: 24px;
        font-weight: 600;
        background: var(--bg-secondary);
        padding: 12px;
        border-radius: 16px;
        text-align: center;
        margin-bottom: 20px;
    }
    .timer-urgent { color: var(--danger); animation: pulse 1s infinite; }
    @keyframes pulse { 0% { opacity: 1; } 50% { opacity: 0.6; } 100% { opacity: 1; } }
    .bid-form { margin: 20px 0; }
    .remaining-bids { font-size: 14px; margin-bottom: 12px; color: var(--text-secondary); }
    .bid-input-group { display: flex; gap: 12px; margin-bottom: 12px; }
    .bid-input { flex: 1; padding: 12px; border: 1px solid var(--border); border-radius: 12px; background: var(--bg); font-size: 16px; }
    .quick-bids { display: flex; gap: 8px; }
    .quick-bids button { padding: 8px 12px; background: var(--bg-secondary); border: 1px solid var(--border); border-radius: 12px; cursor: pointer; }
    .place-bid-btn {
        width: 100%;
        padding: 14px;
        background: var(--primary);
        color: white;
        border: none;
        border-radius: 9999px;
        font-weight: 700;
        cursor: pointer;
        transition: all 0.2s;
    }
    .place-bid-btn:disabled { opacity: 0.6; cursor: not-allowed; }
    .buy-now-btn {
        width: 100%;
        padding: 14px;
        background: #22c55e;
        color: white;
        border: none;
        border-radius: 9999px;
        font-weight: 700;
        cursor: pointer;
        margin-bottom: 12px;
    }
    .history { margin-top: 30px; max-height: 400px; overflow-y: auto; }
    .history-item {
        display: flex;
        align-items: center;
        gap: 12px;
        padding: 8px 0;
        border-bottom: 1px solid var(--border-light);
        font-size: 14px;
    }
    .bidder-color { width: 24px; height: 24px; border-radius: 50%; flex-shrink: 0; }
    .bidder-id { font-weight: 600; min-width: 80px; }
    .bid-price { flex: 1; font-weight: 700; }
    .bid-time { color: var(--text-secondary); }
    .alert { padding: 12px; border-radius: 12px; margin: 12px 0; }
    .alert-warning { background: rgba(255,149,0,0.1); color: #FF9500; border: 1px solid #FF9500; }
    .buy-bids-btn { background: var(--accent); color: white; padding: 8px 16px; border: none; border-radius: 9999px; cursor: pointer; font-size: 14px; }
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
    .modal.active { visibility: visible; opacity: 1; }
    .modal-content {
        background: var(--surface);
        border-radius: 24px;
        max-width: 500px;
        width: 90%;
        padding: 24px;
        max-height: 80vh;
        overflow-y: auto;
    }
    .modal-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 16px;
    }
    .modal-close { background: none; border: none; font-size: 24px; cursor: pointer; color: var(--text-secondary); }
    .consent-text { white-space: pre-wrap; font-size: 14px; line-height: 1.5; margin-bottom: 20px; }
</style>

<div class="auction-view">
    <div class="auction-grid">
        <div class="photo-3d" id="photo3d" data-photo="<?= htmlspecialchars($photoUrl) ?>">
            <div class="photo-img" style="background-image: url('<?= htmlspecialchars($photoUrl) ?>');"></div>
            <div class="photo-glow"></div>
        </div>

        <div class="auction-info">
            <h1 class="auction-title"><?= htmlspecialchars($listing['title']) ?></h1>
            <div class="auction-meta">
                <span>📅 Начало: <?= date('d.m.Y H:i', strtotime($listing['created_at'])) ?></span>
                <span>👁️ <?= $listing['views'] ?> просмотров</span>
                <span>📊 <?= $bidsCount ?> ставок</span>
            </div>

            <div class="current-bid" id="currentBid">
                <?php if ($listing['hidden_bids']): ?>
                    <?= $currentMaxBid > $listing['start_bid'] ? 'Есть ставка выше' : 'Пока нет ставок' ?>
                <?php else: ?>
                    Текущая ставка: <span id="currentBidValue"><?= number_format($currentMaxBid, 0) ?></span> ₽
                <?php endif; ?>
            </div>

            <div class="timer" id="timer" data-end="<?= $endTimestamp ?>">
                <?= formatTimeLeft($timeLeft) ?>
            </div>

            <?php if ($listing['buy_now_price'] && $listing['auction_status'] == 'active' && !$isOwner && $userId && !$isBlocked): ?>
                <div class="buy-now-block" style="margin: 16px 0;">
                    <button id="buyNowBtn" class="buy-now-btn">
                        🚀 Купить сейчас за <?= number_format($listing['buy_now_price'], 0) ?> ₽
                    </button>
                    <div class="form-hint">Мгновенная покупка по фиксированной цене. Аукцион завершится.</div>
                </div>
            <?php endif; ?>

            <?php if ($isOwner): ?>
                <div class="alert alert-warning">Вы — продавец. Вы не можете делать ставки на свой лот.</div>
            <?php elseif ($isBlocked): ?>
                <div class="alert alert-warning">⛔ Вы заблокированы за неоплату предыдущего выигрыша. Блокировка до <?= date('d.m.Y', strtotime($block['expires_at'])) ?>.</div>
            <?php elseif ($userId): ?>
                <div class="bid-form" id="bidForm">
                    <div class="remaining-bids">
                        У вас осталось: <strong id="freeRemaining"><?= $freeRemaining ?></strong> бесплатных ставок,
                        <strong id="extraRemaining"><?= $extraRemaining ?></strong> платных.
                    </div>
                    <div class="bid-input-group">
                        <input type="number" id="bidAmount" class="bid-input" placeholder="Ваша ставка" step="<?= $listing['min_bid_increment'] ?>" value="<?= $currentMaxBid + $listing['min_bid_increment'] ?>">
                        <div class="quick-bids">
                            <button data-add="500">+500</button>
                            <button data-add="1000">+1000</button>
                            <button data-add="5000">+5000</button>
                        </div>
                    </div>
                    <button id="placeBidBtn" class="place-bid-btn" <?= ($totalRemaining <= 0 || !$hasConsent) ? 'disabled' : '' ?>>
                        Сделать ставку
                    </button>
                    <?php if ($totalRemaining <= 0): ?>
                        <div class="alert alert-warning" style="margin-top: 12px;">
                            У вас закончились ставки. <button id="buyBidsBtn" class="buy-bids-btn">Купить 10 ставок за 50 ₽</button>
                        </div>
                    <?php endif; ?>
                    <?php if (!$hasConsent): ?>
                        <div class="alert alert-warning" style="margin-top: 12px;">
                            Для участия необходимо согласиться с правилами. <button id="showConsentBtn" class="buy-bids-btn">Ознакомиться</button>
                        </div>
                    <?php endif; ?>
                </div>
            <?php else: ?>
                <div class="alert alert-warning">🔐 Для участия в аукционе <a href="/auth/login">войдите</a> или <a href="/auth/register">зарегистрируйтесь</a>.</div>
            <?php endif; ?>

            <div class="history">
                <h3>История ставок</h3>
                <div id="historyList">
                    <?php foreach ($history as $bid): ?>
                        <div class="history-item">
                            <div class="bidder-color" style="background: <?= $bid['color_code'] ?>"></div>
                            <div class="bidder-id"><?= htmlspecialchars($bid['anonymous_id']) ?></div>
                            <div class="bid-price"><?= $listing['hidden_bids'] ? '***' : number_format($bid['bid_price'], 0) . ' ₽' ?></div>
                            <div class="bid-time"><?= timeAgo($bid['created_at']) ?></div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </div>
    <div class="listing-description" style="margin-top: 30px;">
        <h3>Описание</h3>
        <p><?= nl2br(htmlspecialchars($listing['description'])) ?></p>
    </div>
</div>

<div id="consentModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h3>Правила участия в аукционах</h3>
            <button class="modal-close" id="closeConsentModal">✕</button>
        </div>
        <div class="consent-text" id="consentText"></div>
        <div class="form-group">
            <label><input type="checkbox" id="consentCheckbox"> Я ознакомлен и согласен с правилами</label>
        </div>
        <button id="acceptConsentBtn" class="place-bid-btn">Подтвердить</button>
    </div>
</div>

<script>
    const listingId = <?= $listingId ?>;
    const csrfToken = '<?= $csrfToken ?>';
    const hiddenMode = <?= $listing['hidden_bids'] ? 'true' : 'false' ?>;
    const minIncrement = <?= $listing['min_bid_increment'] ?>;
    let currentMax = <?= $currentMaxBid ?>;
    let remainingFree = <?= $freeRemaining ?>;
    let remainingExtra = <?= $extraRemaining ?>;
    let isConsented = <?= $hasConsent ? 'true' : 'false' ?>;
    let isBlocked = <?= $isBlocked ? 'true' : 'false' ?>;
    let isOwner = <?= $isOwner ? 'true' : 'false' ?>;
    let isSubmitting = false;

    const timerEl = document.getElementById('timer');
    const currentBidValueSpan = document.getElementById('currentBidValue');
    const bidAmountInput = document.getElementById('bidAmount');
    const placeBidBtn = document.getElementById('placeBidBtn');
    const freeRemainingSpan = document.getElementById('freeRemaining');
    const extraRemainingSpan = document.getElementById('extraRemaining');
    const historyList = document.getElementById('historyList');

    function updateTimer() {
        const end = parseInt(timerEl.dataset.end) * 1000;
        const now = Date.now();
        const diff = end - now;
        if (diff <= 0) {
            timerEl.textContent = 'Аукцион завершён';
            timerEl.classList.add('timer-urgent');
            if (placeBidBtn) placeBidBtn.disabled = true;
            return;
        }
        const hours = Math.floor(diff / 3600000);
        const minutes = Math.floor((diff % 3600000) / 60000);
        const seconds = Math.floor((diff % 60000) / 1000);
        timerEl.textContent = `${hours}ч ${minutes}м ${seconds}с`;
        if (diff < 3600000) timerEl.classList.add('timer-urgent');
        else timerEl.classList.remove('timer-urgent');
    }
    setInterval(updateTimer, 1000);
    updateTimer();

    document.querySelectorAll('.quick-bids button').forEach(btn => {
        btn.addEventListener('click', () => {
            let add = parseInt(btn.dataset.add);
            let current = parseFloat(bidAmountInput.value) || currentMax;
            bidAmountInput.value = current + add;
        });
    });

    async function placeBid() {
        if (isSubmitting) return;
        let amount = parseFloat(bidAmountInput.value);
        if (isNaN(amount) || amount <= 0) { Notify.error('Введите корректную сумму'); return; }
        if (amount <= currentMax + minIncrement - 0.01) {
            Notify.error('Ставка должна быть выше текущей на ' + minIncrement + ' ₽');
            return;
        }
        isSubmitting = true;
        placeBidBtn.disabled = true;
        try {
            const response = await fetch('/api/auctions/bid.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ listing_id: listingId, amount, csrf_token: csrfToken })
            });
            const data = await response.json();
            if (data.success) {
                Notify.success('Ставка принята!');
                if (!hiddenMode) {
                    currentMax = amount;
                    if (currentBidValueSpan) currentBidValueSpan.textContent = amount.toLocaleString();
                    bidAmountInput.value = amount + minIncrement;
                } else {
                    document.getElementById('currentBid').textContent = 'Есть ставка выше';
                }
                remainingFree = data.free_remaining;
                remainingExtra = data.extra_remaining;
                freeRemainingSpan.textContent = remainingFree;
                extraRemainingSpan.textContent = remainingExtra;
                if (data.new_end_time) {
                    timerEl.dataset.end = data.new_end_time;
                    updateTimer();
                }
                if (remainingFree + remainingExtra <= 0) {
                    placeBidBtn.disabled = true;
                }
            } else if (data.error === 'consent_required') {
                showConsentModal(data.consent_text);
            } else if (data.error === 'no_bids_left') {
                Notify.warning('У вас закончились ставки. Купите дополнительные.');
                if (!document.querySelector('#buyBidsBtn')) {
                    const div = document.createElement('div');
                    div.className = 'alert alert-warning';
                    div.innerHTML = 'У вас закончились ставки. <button id="buyBidsBtn" class="buy-bids-btn">Купить 10 ставок за 50 ₽</button>';
                    document.querySelector('.bid-form').appendChild(div);
                    document.getElementById('buyBidsBtn').addEventListener('click', buyBids);
                }
            } else {
                Notify.error(data.message || 'Ошибка при ставке');
            }
        } catch (err) {
            Notify.error('Ошибка сети');
        } finally {
            isSubmitting = false;
            placeBidBtn.disabled = false;
        }
    }

    async function buyBids() {
        try {
            const response = await fetch('/api/payments/buy_bids.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ csrf_token: csrfToken })
            });
            const data = await response.json();
            if (data.success && data.payment_url) {
                window.location.href = data.payment_url;
            } else {
                Notify.error(data.error || 'Ошибка создания платежа');
            }
        } catch (err) {
            Notify.error('Ошибка сети');
        }
    }

    async function buyNow() {
        if (!confirm('Подтвердите мгновенную покупку. Аукцион завершится, и вы станете владельцем лота.')) return;
        try {
            const response = await fetch('/api/auctions/buy_now.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ listing_id: listingId, csrf_token: csrfToken })
            });
            const data = await response.json();
            if (data.success) {
                Notify.success(data.message);
                setTimeout(() => window.location.href = '/auctions/my.php', 2000);
            } else {
                Notify.error(data.message || 'Ошибка');
            }
        } catch (err) {
            Notify.error('Ошибка сети');
        }
    }

    async function showConsentModal(consentText) {
        const modal = document.getElementById('consentModal');
        const textDiv = document.getElementById('consentText');
        textDiv.textContent = consentText;
        modal.classList.add('active');
        document.getElementById('closeConsentModal').onclick = () => modal.classList.remove('active');
        document.getElementById('acceptConsentBtn').onclick = async () => {
            const checkbox = document.getElementById('consentCheckbox');
            if (!checkbox.checked) { Notify.warning('Необходимо согласиться с правилами'); return; }
            try {
                const resp = await fetch('/api/auctions/consent.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ csrf_token: csrfToken })
                });
                const data = await resp.json();
                if (data.success) {
                    isConsented = true;
                    modal.classList.remove('active');
                    placeBidBtn.disabled = false;
                    Notify.success('Спасибо! Теперь вы можете делать ставки.');
                } else {
                    Notify.error(data.error || 'Ошибка');
                }
            } catch (err) {
                Notify.error('Ошибка сети');
            }
        };
    }

    if (placeBidBtn) placeBidBtn.addEventListener('click', placeBid);
    const buyBidsBtn = document.getElementById('buyBidsBtn');
    if (buyBidsBtn) buyBidsBtn.addEventListener('click', buyBids);
    const buyNowBtn = document.getElementById('buyNowBtn');
    if (buyNowBtn) buyNowBtn.addEventListener('click', buyNow);
    const showConsentBtn = document.getElementById('showConsentBtn');
    if (showConsentBtn) {
        showConsentBtn.addEventListener('click', async () => {
            const resp = await fetch('/api/auctions/consent_text.php');
            const data = await resp.json();
            if (data.success) showConsentModal(data.text);
        });
    }

    const evtSource = new EventSource(`/sse/auction.php?listing_id=${listingId}`);
    evtSource.addEventListener('update', function(e) {
        const data = JSON.parse(e.data);
        if (data.type === 'new_bid') {
            if (data.hidden) {
                document.getElementById('currentBid').textContent = 'Есть ставка выше';
            } else {
                currentMax = data.bid_price;
                if (currentBidValueSpan) currentBidValueSpan.textContent = data.bid_price.toLocaleString();
            }
            const newItem = document.createElement('div');
            newItem.className = 'history-item';
            newItem.innerHTML = `
                <div class="bidder-color" style="background: ${data.color_code}"></div>
                <div class="bidder-id">${data.anonymous_id}</div>
                <div class="bid-price">${data.hidden ? '***' : data.bid_price.toLocaleString() + ' ₽'}</div>
                <div class="bid-time">только что</div>
            `;
            historyList.prepend(newItem);
            if (historyList.children.length > 20) historyList.removeChild(historyList.lastChild);
            if (data.new_end_time) {
                timerEl.dataset.end = data.new_end_time;
                updateTimer();
            }
        } else if (data.type === 'auction_ended') {
            Notify.info('Аукцион завершён');
            timerEl.textContent = 'Завершён';
            placeBidBtn.disabled = true;
        }
    });
    evtSource.addEventListener('error', function(e) {
        evtSource.close();
        setTimeout(() => {
            new EventSource(`/sse/auction.php?listing_id=${listingId}`);
        }, 5000);
    });

    const photo3d = document.getElementById('photo3d');
    const photoImg = photo3d?.querySelector('.photo-img');
    const photoGlow = photo3d?.querySelector('.photo-glow');
    if (photo3d && <?= $listing['enable_3d_effect'] ?? 0 ?>) {
        let rect = null;
        function updateRect() { rect = photo3d.getBoundingClientRect(); }
        updateRect();
        window.addEventListener('resize', updateRect);
        photo3d.addEventListener('mousemove', e => {
            const x = (e.clientX - rect.left) / rect.width;
            const y = (e.clientY - rect.top) / rect.height;
            const rotY = (x - 0.5) * 20;
            const rotX = (0.5 - y) * 14;
            photo3d.style.transform = `perspective(900px) rotateX(${rotX}deg) rotateY(${rotY}deg)`;
            photoImg.style.transform = `translate3d(${(x-0.5)*20}px, ${(y-0.5)*20}px, 0)`;
            photoImg.style.backgroundPosition = `${50 + (x-0.5)*8}% ${50 + (y-0.5)*8}%`;
            if (photoGlow) {
                photoGlow.style.opacity = 0.5;
                photoGlow.style.transform = `translate(${(x-0.5)*40}px, ${(y-0.5)*40}px)`;
            }
        });
        photo3d.addEventListener('mouseleave', () => {
            photo3d.style.transform = '';
            photoImg.style.transform = '';
            photoImg.style.backgroundPosition = '50% 50%';
            if (photoGlow) photoGlow.style.opacity = 0;
        });
        if (window.DeviceOrientationEvent) {
            window.addEventListener('deviceorientation', e => {
                const beta = e.beta || 0;
                const gamma = e.gamma || 0;
                const rotX = beta / 6;
                const rotY = gamma / 6;
                photo3d.style.transform = `perspective(900px) rotateX(${rotX}deg) rotateY(${rotY}deg)`;
                photoImg.style.transform = `translate3d(${gamma/4}px, ${beta/4}px, 0)`;
                if (photoGlow) photoGlow.style.opacity = 0.4;
            });
        }
    }
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>