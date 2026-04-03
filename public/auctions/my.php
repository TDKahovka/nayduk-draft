<?php
/**
 * НАЙДУК — Личный кабинет участника аукционов
 * Версия 2.0 (март 2026)
 */
session_start();
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../services/Database.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: /auth/login');
    exit;
}

$db = Database::getInstance();
$userId = (int)$_SESSION['user_id'];
$user = $db->getUserById($userId);

// Получаем список созданных продавцом аукционов
$myAuctions = $db->fetchAll("
    SELECT l.*,
           (SELECT COUNT(*) FROM auction_bids WHERE listing_id = l.id) as bids_count,
           (SELECT MAX(bid_price) FROM auction_bids WHERE listing_id = l.id) as current_bid,
           (SELECT photo_url FROM listing_photos WHERE listing_id = l.id ORDER BY sort_order LIMIT 1) as photo
    FROM listings l
    WHERE l.user_id = ? AND l.auction_type = 1
    ORDER BY l.created_at DESC
", [$userId]);

// Получаем список активных ставок пользователя (где он участвует, аукцион ещё активен)
$myBids = $db->fetchAll("
    SELECT DISTINCT l.*,
           (SELECT MAX(bid_price) FROM auction_bids WHERE listing_id = l.id) as current_bid,
           (SELECT bid_price FROM auction_bids WHERE listing_id = l.id AND user_id = ? ORDER BY created_at DESC LIMIT 1) as my_last_bid,
           (SELECT photo_url FROM listing_photos WHERE listing_id = l.id ORDER BY sort_order LIMIT 1) as photo
    FROM listings l
    INNER JOIN auction_bids b ON l.id = b.listing_id
    WHERE b.user_id = ? AND l.auction_status = 'active'
    GROUP BY l.id
    ORDER BY l.auction_end_at ASC
", [$userId, $userId]);

// Получаем список неподтверждённых сделок (где пользователь победил)
$pendingDeals = $db->fetchAll("
    SELECT d.*, l.title, l.user_id as seller_id
    FROM deals d
    JOIN listings l ON d.listing_id = l.id
    WHERE d.buyer_id = ? AND d.status = 'awaiting_confirmation' AND d.expires_at > NOW()
", [$userId]);

// Получаем список неподтверждённых продаж (где пользователь продавец)
$pendingSales = $db->fetchAll("
    SELECT d.*, l.title, u.name as buyer_name
    FROM deals d
    JOIN listings l ON d.listing_id = l.id
    JOIN users u ON d.buyer_id = u.id
    WHERE d.seller_id = ? AND d.status = 'awaiting_confirmation' AND d.expires_at > NOW()
", [$userId]);

// Проверяем наличие непогашенных комиссий
$unpaidCommissions = $db->fetchOne("
    SELECT SUM(amount) as total FROM seller_commissions
    WHERE seller_id = ? AND status = 'pending' AND due_date > NOW()
", [$userId]);

$csrfToken = generateCsrfToken();

$pageTitle = 'Мои аукционы — Найдук';
$pageDescription = 'Управление созданными аукционами, ставками и сделками.';
require_once __DIR__ . '/../includes/header.php';
?>

<style>
    .dashboard-tabs {
        display: flex;
        gap: 8px;
        border-bottom: 1px solid var(--border);
        margin-bottom: 24px;
        flex-wrap: wrap;
    }
    .tab-btn {
        padding: 12px 24px;
        background: none;
        border: none;
        font-weight: 600;
        cursor: pointer;
        color: var(--text-secondary);
        border-bottom: 2px solid transparent;
        transition: all 0.2s;
    }
    .tab-btn.active {
        color: var(--primary);
        border-bottom-color: var(--primary);
    }
    .tab-pane {
        display: none;
    }
    .tab-pane.active {
        display: block;
    }
    .auction-card {
        background: var(--surface);
        border: 1px solid var(--border);
        border-radius: var(--radius-lg);
        margin-bottom: 16px;
        overflow: hidden;
        display: flex;
        flex-wrap: wrap;
    }
    .auction-card-img {
        width: 120px;
        height: 120px;
        object-fit: cover;
        background: var(--bg-secondary);
    }
    .auction-card-info {
        flex: 1;
        padding: 16px;
    }
    .auction-card-actions {
        padding: 16px;
        min-width: 200px;
        border-left: 1px solid var(--border);
        display: flex;
        flex-direction: column;
        gap: 8px;
        justify-content: center;
    }
    .status-badge {
        display: inline-block;
        padding: 4px 12px;
        border-radius: var(--radius-full);
        font-size: 12px;
        font-weight: 600;
    }
    .status-active { background: #E8F5E9; color: #2E7D32; }
    .status-pending { background: #FFF3E0; color: #ED6C02; }
    .status-completed { background: #E3F2FD; color: #1565C0; }
    .status-closed { background: #FFEBEE; color: #C62828; }
    .btn-sm { padding: 6px 12px; font-size: 13px; }
    .empty-state { text-align: center; padding: 60px; color: var(--text-secondary); }
    .alert-warning { background: rgba(255,149,0,0.1); color: #FF9500; padding: 12px; border-radius: var(--radius); margin-bottom: 20px; }
</style>

<div class="auctions-container">
    <h1>Мои аукционы</h1>

    <?php if ($unpaidCommissions && $unpaidCommissions['total'] > 0): ?>
        <div class="alert-warning" style="margin-bottom: 20px;">
            ⚠️ У вас есть непогашенная комиссия на сумму <strong><?= number_format($unpaidCommissions['total'], 0) ?> ₽</strong>.
            <a href="/payments/commissions.php">Оплатить сейчас</a>
        </div>
    <?php endif; ?>

    <div class="dashboard-tabs">
        <button class="tab-btn active" data-tab="my-auctions">Мои аукционы</button>
        <button class="tab-btn" data-tab="my-bids">Мои ставки</button>
        <button class="tab-btn" data-tab="pending-deals">Ожидают подтверждения (покупки)</button>
        <button class="tab-btn" data-tab="pending-sales">Ожидают подтверждения (продажи)</button>
    </div>

    <!-- Вкладка: Мои аукционы -->
    <div class="tab-pane active" id="my-auctions">
        <?php if (empty($myAuctions)): ?>
            <div class="empty-state">Вы ещё не создали ни одного аукциона. <a href="/auctions/create.php">Создать</a></div>
        <?php else: ?>
            <?php foreach ($myAuctions as $auction):
                $isActive = $auction['auction_status'] == 'active';
                $isPending = $auction['auction_status'] == 'payment_pending';
                $isCompleted = $auction['auction_status'] == 'completed';
                $isClosed = in_array($auction['auction_status'], ['closed_no_bids', 'reserve_not_met', 'cancelled']);
                $timeLeft = strtotime($auction['auction_end_at']) - time();
                $currentPrice = $auction['current_bid'] ?? $auction['start_bid'];
                ?>
                <div class="auction-card">
                    <img src="<?= $auction['photo'] ?: '/uploads/placeholder/naiduk_smile.png' ?>" class="auction-card-img">
                    <div class="auction-card-info">
                        <h3><?= htmlspecialchars($auction['title']) ?></h3>
                        <div>
                            <span class="status-badge <?= $isActive ? 'status-active' : ($isPending ? 'status-pending' : ($isCompleted ? 'status-completed' : 'status-closed')) ?>">
                                <?php
                                if ($isActive) echo 'Активен';
                                elseif ($isPending) echo 'Ожидает оплаты';
                                elseif ($isCompleted) echo 'Завершён';
                                elseif ($auction['auction_status'] == 'closed_no_bids') echo 'Нет ставок';
                                elseif ($auction['auction_status'] == 'reserve_not_met') echo 'Резерв не достигнут';
                                else echo 'Закрыт';
                                ?>
                            </span>
                        </div>
                        <div>💰 Текущая ставка: <?= number_format($currentPrice, 0) ?> ₽</div>
                        <div>📊 Ставок: <?= $auction['bids_count'] ?></div>
                        <?php if ($isActive && $timeLeft > 0): ?>
                            <div>⏰ Завершается: <?= date('d.m.Y H:i', strtotime($auction['auction_end_at'])) ?></div>
                        <?php endif; ?>
                    </div>
                    <div class="auction-card-actions">
                        <?php if ($isActive): ?>
                            <a href="/auctions/view.php?id=<?= $auction['id'] ?>" class="btn btn-primary btn-sm">Перейти к аукциону</a>
                        <?php elseif ($auction['auction_status'] == 'closed_no_bids'): ?>
                            <button class="btn btn-secondary btn-sm" onclick="relistAuction(<?= $auction['id'] ?>)">🔄 Перевыставить бесплатно</button>
                        <?php elseif ($auction['auction_status'] == 'reserve_not_met' && $auction['bids_count'] > 0): ?>
                            <button class="btn btn-primary btn-sm" onclick="acceptWinner(<?= $auction['id'] ?>, <?= $auction['current_bid'] ?>)">✅ Продать победителю за <?= number_format($auction['current_bid'], 0) ?> ₽</button>
                        <?php elseif ($isPending): ?>
                            <a href="/payments/confirm_auction.php?listing_id=<?= $auction['id'] ?>" class="btn btn-primary btn-sm">💰 Оплатить размещение</a>
                        <?php endif; ?>
                        <?php if (!$isCompleted && !$isPending): ?>
                            <button class="btn btn-secondary btn-sm" onclick="deleteAuction(<?= $auction['id'] ?>)">🗑️ Удалить</button>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <!-- Вкладка: Мои ставки -->
    <div class="tab-pane" id="my-bids">
        <?php if (empty($myBids)): ?>
            <div class="empty-state">Вы ещё не участвовали в аукционах.</div>
        <?php else: ?>
            <?php foreach ($myBids as $auction):
                $isLeader = ($auction['current_bid'] == $auction['my_last_bid']);
                $timeLeft = strtotime($auction['auction_end_at']) - time();
                ?>
                <div class="auction-card">
                    <img src="<?= $auction['photo'] ?: '/uploads/placeholder/naiduk_smile.png' ?>" class="auction-card-img">
                    <div class="auction-card-info">
                        <h3><?= htmlspecialchars($auction['title']) ?></h3>
                        <div>💰 Ваша последняя ставка: <?= number_format($auction['my_last_bid'], 0) ?> ₽</div>
                        <div>🔥 Текущая ставка: <?= number_format($auction['current_bid'], 0) ?> ₽</div>
                        <?php if ($isLeader): ?>
                            <div class="status-badge status-active">🏆 Вы лидируете!</div>
                        <?php else: ?>
                            <div class="status-badge status-closed">📉 Вас перебили</div>
                        <?php endif; ?>
                    </div>
                    <div class="auction-card-actions">
                        <a href="/auctions/view.php?id=<?= $auction['id'] ?>" class="btn btn-primary btn-sm">Перейти к аукциону</a>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <!-- Вкладка: Ожидают подтверждения (покупки) -->
    <div class="tab-pane" id="pending-deals">
        <?php if (empty($pendingDeals)): ?>
            <div class="empty-state">Нет ожидающих подтверждения сделок.</div>
        <?php else: ?>
            <?php foreach ($pendingDeals as $deal): ?>
                <div class="auction-card">
                    <div class="auction-card-info">
                        <h3><?= htmlspecialchars($deal['title']) ?></h3>
                        <div>💰 Цена: <?= number_format($deal['price'], 0) ?> ₽</div>
                        <div>⏰ Подтвердить до: <?= date('d.m.Y H:i', strtotime($deal['expires_at'])) ?></div>
                    </div>
                    <div class="auction-card-actions">
                        <button class="btn btn-primary btn-sm" onclick="confirmDeal(<?= $deal['id'] ?>, 'buyer')">✅ Подтвердить получение</button>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <!-- Вкладка: Ожидают подтверждения (продажи) -->
    <div class="tab-pane" id="pending-sales">
        <?php if (empty($pendingSales)): ?>
            <div class="empty-state">Нет ожидающих подтверждения продаж.</div>
        <?php else: ?>
            <?php foreach ($pendingSales as $deal): ?>
                <div class="auction-card">
                    <div class="auction-card-info">
                        <h3><?= htmlspecialchars($deal['title']) ?></h3>
                        <div>💰 Цена: <?= number_format($deal['price'], 0) ?> ₽</div>
                        <div>👤 Покупатель: <?= htmlspecialchars($deal['buyer_name']) ?></div>
                        <div>⏰ Подтвердить до: <?= date('d.m.Y H:i', strtotime($deal['expires_at'])) ?></div>
                    </div>
                    <div class="auction-card-actions">
                        <button class="btn btn-primary btn-sm" onclick="confirmDeal(<?= $deal['id'] ?>, 'seller')">✅ Подтвердить отправку</button>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>

<script>
    const csrfToken = '<?= $csrfToken ?>';

    document.querySelectorAll('.tab-btn').forEach(btn => {
        btn.addEventListener('click', () => {
            document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
            document.querySelectorAll('.tab-pane').forEach(p => p.classList.remove('active'));
            btn.classList.add('active');
            document.getElementById(btn.dataset.tab).classList.add('active');
        });
    });

    async function confirmDeal(dealId, role) {
        try {
            const response = await fetch('/api/deals/confirm.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ deal_id: dealId, role: role, csrf_token: csrfToken })
            });
            const data = await response.json();
            if (data.success) {
                Notify.success('Сделка подтверждена!');
                setTimeout(() => location.reload(), 1500);
            } else {
                Notify.error(data.error || 'Ошибка');
            }
        } catch (err) {
            Notify.error('Ошибка сети');
        }
    }

    async function relistAuction(listingId) {
        try {
            const response = await fetch('/api/auctions/relist.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ listing_id: listingId, csrf_token: csrfToken })
            });
            const data = await response.json();
            if (data.success) {
                Notify.success('Аукцион перевыставлен!');
                setTimeout(() => location.reload(), 1500);
            } else {
                Notify.error(data.error || 'Ошибка');
            }
        } catch (err) {
            Notify.error('Ошибка сети');
        }
    }

    async function acceptWinner(listingId, price) {
        if (!confirm(`Продать лот победителю за ${price.toLocaleString()} ₽?`)) return;
        try {
            const response = await fetch('/api/auctions/accept_winner.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ listing_id: listingId, csrf_token: csrfToken })
            });
            const data = await response.json();
            if (data.success) {
                Notify.success('Сделка создана!');
                setTimeout(() => location.reload(), 1500);
            } else {
                Notify.error(data.error || 'Ошибка');
            }
        } catch (err) {
            Notify.error('Ошибка сети');
        }
    }

    async function deleteAuction(listingId) {
        if (!confirm('Удалить аукцион? Это действие необратимо.')) return;
        try {
            const response = await fetch('/api/auctions/delete.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ listing_id: listingId, csrf_token: csrfToken })
            });
            const data = await response.json();
            if (data.success) {
                Notify.success('Аукцион удалён');
                setTimeout(() => location.reload(), 1500);
            } else {
                Notify.error(data.error || 'Ошибка');
            }
        } catch (err) {
            Notify.error('Ошибка сети');
        }
    }
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>