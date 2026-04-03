<?php
/**
 * Административный интерфейс управления аукционами
 * Доступ только для администраторов (role = 'admin')
 * Позволяет:
 * - Просматривать все аукционы с фильтрацией (статус, тип, категория, город, дата)
 * - Просматривать детали аукциона (ставки, сделки)
 * - Принудительно завершать аукцион, менять статус
 * - Блокировать / разблокировать пользователей
 * - Отменять аукцион
 * - Просматривать логи
 * Все действия логируются в audit_log
 */
session_start();
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../services/Database.php';
require_once __DIR__ . '/../services/NotificationService.php';

// Проверка авторизации и роли
if (!isset($_SESSION['user_id'])) {
    header('Location: /auth/login');
    exit;
}
$db = Database::getInstance();
$user = $db->getUserById($_SESSION['user_id']);
if (!$user || $user['role'] !== 'admin') {
    http_response_code(403);
    die('Доступ запрещён');
}

$csrfToken = generateCsrfToken();

// Обработка действий
$action = $_POST['action'] ?? $_GET['action'] ?? '';
$listingId = isset($_POST['listing_id']) ? (int)$_POST['listing_id'] : (isset($_GET['listing_id']) ? (int)$_GET['listing_id'] : 0);
$userId = isset($_POST['user_id']) ? (int)$_POST['user_id'] : (isset($_GET['user_id']) ? (int)$_GET['user_id'] : 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf = $_POST['csrf_token'] ?? '';
    if (!verify_csrf_token($csrf)) {
        die('Неверный CSRF-токен');
    }

    switch ($action) {
        case 'force_complete':
            // Принудительное завершение аукциона (победителем становится текущий лидер)
            if ($listingId) {
                $listing = $db->fetchOne("SELECT * FROM listings WHERE id = ? AND auction_type = 1", [$listingId]);
                if ($listing) {
                    $winnerBid = $db->fetchOne("SELECT * FROM auction_bids WHERE listing_id = ? ORDER BY bid_price DESC LIMIT 1", [$listingId]);
                    if ($winnerBid) {
                        $db->update('listings', [
                            'auction_status' => 'payment_pending',
                            'winner_id' => $winnerBid['user_id'],
                            'final_price' => $winnerBid['bid_price']
                        ], 'id = ?', [$listingId]);
                        $expires = date('Y-m-d H:i:s', strtotime('+24 hours'));
                        $dealId = $db->insert('deals', [
                            'listing_id' => $listingId,
                            'seller_id' => $listing['user_id'],
                            'buyer_id' => $winnerBid['user_id'],
                            'price' => $winnerBid['bid_price'],
                            'expires_at' => $expires
                        ]);
                        $db->update('listings', ['deal_id' => $dealId], 'id = ?', [$listingId]);
                        $db->insert('audit_log', [
                            'event_type' => 'admin_force_complete',
                            'listing_id' => $listingId,
                            'user_id' => $_SESSION['user_id'],
                            'details' => json_encode(['winner' => $winnerBid['user_id']])
                        ]);
                        $success = 'Аукцион принудительно завершён, создана сделка.';
                    } else {
                        $error = 'Нет ставок, нельзя завершить.';
                    }
                } else {
                    $error = 'Аукцион не найден.';
                }
            }
            break;

        case 'cancel_auction':
            // Отмена аукциона (статус cancelled, уведомление продавцу и участникам)
            if ($listingId) {
                $listing = $db->fetchOne("SELECT * FROM listings WHERE id = ? AND auction_type = 1", [$listingId]);
                if ($listing && in_array($listing['auction_status'], ['active', 'payment_pending', 'reserve_not_met'])) {
                    $db->update('listings', ['auction_status' => 'cancelled'], 'id = ?', [$listingId]);
                    $db->insert('audit_log', [
                        'event_type' => 'admin_cancel_auction',
                        'listing_id' => $listingId,
                        'user_id' => $_SESSION['user_id']
                    ]);
                    // Уведомление продавцу
                    $notify = new NotificationService();
                    $notify->send($listing['user_id'], 'auction_cancelled', ['listing_id' => $listingId]);
                    $success = 'Аукцион отменён.';
                } else {
                    $error = 'Нельзя отменить этот аукцион.';
                }
            }
            break;

        case 'block_user':
            if ($userId) {
                $days = (int)($_POST['block_days'] ?? 30);
                $reason = trim($_POST['block_reason'] ?? 'По решению администрации');
                $expires = date('Y-m-d H:i:s', strtotime("+{$days} days"));
                $db->insert('user_blocks', [
                    'user_id' => $userId,
                    'block_type' => 'auction',
                    'expires_at' => $expires,
                    'reason' => $reason
                ]);
                $db->insert('audit_log', [
                    'event_type' => 'admin_block_user',
                    'user_id' => $userId,
                    'details' => json_encode(['days' => $days, 'reason' => $reason])
                ]);
                $success = "Пользователь заблокирован на {$days} дней.";
            }
            break;

        case 'unblock_user':
            if ($userId) {
                $db->query("DELETE FROM user_blocks WHERE user_id = ? AND block_type = 'auction'", [$userId]);
                $db->insert('audit_log', [
                    'event_type' => 'admin_unblock_user',
                    'user_id' => $userId
                ]);
                $success = 'Пользователь разблокирован.';
            }
            break;

        case 'delete_auction':
            // Физическое удаление (только для черновиков или без ставок)
            if ($listingId) {
                $listing = $db->fetchOne("SELECT auction_status, bids_count FROM listings WHERE id = ?", [$listingId]);
                if ($listing && in_array($listing['auction_status'], ['draft', 'closed_no_bids', 'cancelled'])) {
                    $db->query("DELETE FROM listings WHERE id = ?", [$listingId]);
                    $db->insert('audit_log', [
                        'event_type' => 'admin_delete_auction',
                        'listing_id' => $listingId,
                        'user_id' => $_SESSION['user_id']
                    ]);
                    $success = 'Аукцион удалён.';
                } else {
                    $error = 'Нельзя удалить активный аукцион.';
                }
            }
            break;
    }
}

// Параметры фильтрации
$status = isset($_GET['status']) ? $_GET['status'] : '';
$type = isset($_GET['type']) ? (int)$_GET['type'] : 0;
$category = isset($_GET['category']) ? (int)$_GET['category'] : 0;
$city = isset($_GET['city']) ? trim($_GET['city']) : '';
$userName = isset($_GET['user']) ? trim($_GET['user']) : '';
$dateFrom = isset($_GET['date_from']) ? $_GET['date_from'] : '';
$dateTo = isset($_GET['date_to']) ? $_GET['date_to'] : '';
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$limit = 20;
$offset = ($page - 1) * $limit;

// Базовый запрос
$where = ["l.auction_type = 1"];
$params = [];

if ($status) {
    $where[] = "l.auction_status = ?";
    $params[] = $status;
}
if ($type) {
    $where[] = "l.auction_type = ?";
    $params[] = $type;
}
if ($category) {
    $where[] = "l.category_id = ?";
    $params[] = $category;
}
if ($city) {
    $where[] = "l.city LIKE ?";
    $params[] = "%$city%";
}
if ($userName) {
    $where[] = "u.name LIKE ?";
    $params[] = "%$userName%";
}
if ($dateFrom) {
    $where[] = "l.created_at >= ?";
    $params[] = $dateFrom . ' 00:00:00';
}
if ($dateTo) {
    $where[] = "l.created_at <= ?";
    $params[] = $dateTo . ' 23:59:59';
}

$whereSql = "WHERE " . implode(" AND ", $where);
$order = "ORDER BY l.created_at DESC";

$countSql = "SELECT COUNT(*) FROM listings l JOIN users u ON l.user_id = u.id $whereSql";
$total = $db->fetchCount($countSql, $params);
$totalPages = ceil($total / $limit);

$sql = "SELECT l.id, l.title, l.start_bid, l.price, l.auction_type, l.auction_status, l.auction_end_at,
               l.created_at, l.user_id, l.winner_id, l.final_price,
               u.name as seller_name,
               (SELECT COUNT(*) FROM auction_bids WHERE listing_id = l.id) AS bids_count,
               (SELECT MAX(bid_price) FROM auction_bids WHERE listing_id = l.id) AS current_bid
        FROM listings l
        JOIN users u ON l.user_id = u.id
        $whereSql
        $order
        LIMIT $offset, $limit";
$auctions = $db->fetchAll($sql, $params);

// Получаем категории для фильтра
$categories = $db->fetchAll("SELECT id, name FROM listing_categories WHERE is_active = 1 ORDER BY name");

$pageTitle = 'Управление аукционами — Найдук';
require_once __DIR__ . '/../includes/header.php';
?>

<style>
    .admin-container { max-width: 1400px; margin: 0 auto; padding: 20px; }
    .admin-header { margin-bottom: 20px; display: flex; justify-content: space-between; align-items: center; }
    .filters { background: var(--surface); padding: 20px; border-radius: var(--radius-xl); margin-bottom: 20px; display: flex; flex-wrap: wrap; gap: 15px; align-items: flex-end; }
    .filter-group { flex: 1; min-width: 150px; }
    .filter-group label { display: block; font-size: 12px; margin-bottom: 4px; color: var(--text-secondary); }
    .filter-group input, .filter-group select { width: 100%; padding: 8px 12px; border: 1px solid var(--border); border-radius: var(--radius); background: var(--bg); color: var(--text); }
    .filter-group button { width: 100%; padding: 8px; background: var(--primary); color: white; border: none; border-radius: var(--radius); cursor: pointer; }
    .auctions-table { width: 100%; border-collapse: collapse; background: var(--surface); border-radius: var(--radius-xl); overflow: hidden; }
    .auctions-table th, .auctions-table td { padding: 12px; text-align: left; border-bottom: 1px solid var(--border-light); }
    .auctions-table th { background: var(--bg-secondary); font-weight: 600; }
    .status-badge { display: inline-block; padding: 4px 8px; border-radius: var(--radius-full); font-size: 12px; font-weight: 600; }
    .status-active { background: #E8F5E9; color: #2E7D32; }
    .status-pending { background: #FFF3E0; color: #ED6C02; }
    .status-completed { background: #E3F2FD; color: #1565C0; }
    .status-closed { background: #FFEBEE; color: #C62828; }
    .actions { display: flex; gap: 8px; flex-wrap: wrap; }
    .btn-icon { padding: 4px 8px; font-size: 12px; cursor: pointer; border-radius: var(--radius); border: none; }
    .btn-danger { background: #FFEBEE; color: #C62828; }
    .btn-warning { background: #FFF3E0; color: #ED6C02; }
    .btn-success { background: #E8F5E9; color: #2E7D32; }
    .pagination { display: flex; justify-content: center; gap: 8px; margin-top: 20px; }
    .page-item { padding: 8px 12px; border: 1px solid var(--border); border-radius: var(--radius); background: var(--surface); color: var(--text); text-decoration: none; }
    .page-item.active { background: var(--primary); color: white; border-color: var(--primary); }
    .modal { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); justify-content: center; align-items: center; z-index: 1000; }
    .modal-content { background: var(--surface); border-radius: var(--radius-xl); padding: 24px; max-width: 500px; width: 90%; }
    .modal-header { display: flex; justify-content: space-between; margin-bottom: 16px; }
    .modal-close { cursor: pointer; font-size: 24px; }
    .form-group { margin-bottom: 16px; }
    .form-group label { display: block; margin-bottom: 4px; font-weight: 600; }
    .form-group input, .form-group textarea, .form-group select { width: 100%; padding: 8px 12px; border: 1px solid var(--border); border-radius: var(--radius); }
</style>

<div class="admin-container">
    <div class="admin-header">
        <h1>Управление аукционами</h1>
        <a href="/admin/index.php" class="btn btn-secondary">Назад в админку</a>
    </div>

    <?php if (isset($success)): ?>
        <div class="alert alert-success" style="background: #E8F5E9; color: #2E7D32; padding: 12px; border-radius: var(--radius); margin-bottom: 20px;">✅ <?= htmlspecialchars($success) ?></div>
    <?php endif; ?>
    <?php if (isset($error)): ?>
        <div class="alert alert-danger" style="background: #FFEBEE; color: #C62828; padding: 12px; border-radius: var(--radius); margin-bottom: 20px;">❌ <?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <form method="GET" class="filters">
        <div class="filter-group">
            <label>Статус</label>
            <select name="status">
                <option value="">Все</option>
                <option value="active" <?= $status == 'active' ? 'selected' : '' ?>>Активен</option>
                <option value="payment_pending" <?= $status == 'payment_pending' ? 'selected' : '' ?>>Ожидает оплаты</option>
                <option value="completed" <?= $status == 'completed' ? 'selected' : '' ?>>Завершён</option>
                <option value="closed_no_bids" <?= $status == 'closed_no_bids' ? 'selected' : '' ?>>Без ставок</option>
                <option value="reserve_not_met" <?= $status == 'reserve_not_met' ? 'selected' : '' ?>>Резерв не достигнут</option>
                <option value="cancelled" <?= $status == 'cancelled' ? 'selected' : '' ?>>Отменён</option>
                <option value="draft" <?= $status == 'draft' ? 'selected' : '' ?>>Черновик</option>
            </select>
        </div>
        <div class="filter-group">
            <label>Тип</label>
            <select name="type">
                <option value="">Все</option>
                <option value="1" <?= $type == 1 ? 'selected' : '' ?>>Прямой</option>
                <option value="2" <?= $type == 2 ? 'selected' : '' ?>>Обратный</option>
            </select>
        </div>
        <div class="filter-group">
            <label>Категория</label>
            <select name="category">
                <option value="">Все</option>
                <?php foreach ($categories as $cat): ?>
                    <option value="<?= $cat['id'] ?>" <?= $category == $cat['id'] ? 'selected' : '' ?>><?= htmlspecialchars($cat['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="filter-group">
            <label>Город</label>
            <input type="text" name="city" value="<?= htmlspecialchars($city) ?>" placeholder="Город">
        </div>
        <div class="filter-group">
            <label>Продавец</label>
            <input type="text" name="user" value="<?= htmlspecialchars($userName) ?>" placeholder="Имя продавца">
        </div>
        <div class="filter-group">
            <label>Дата от</label>
            <input type="date" name="date_from" value="<?= htmlspecialchars($dateFrom) ?>">
        </div>
        <div class="filter-group">
            <label>Дата до</label>
            <input type="date" name="date_to" value="<?= htmlspecialchars($dateTo) ?>">
        </div>
        <div class="filter-group">
            <button type="submit">Применить</button>
        </div>
    </form>

    <div style="overflow-x: auto;">
        <table class="auctions-table">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Название</th>
                    <th>Продавец</th>
                    <th>Тип</th>
                    <th>Текущая ставка</th>
                    <th>Ставок</th>
                    <th>Статус</th>
                    <th>Окончание</th>
                    <th>Действия</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($auctions)): ?>
                    <tr><td colspan="9" class="empty-state">Нет аукционов</td></tr>
                <?php else: ?>
                    <?php foreach ($auctions as $a): ?>
                        <?php
                        $statusClass = '';
                        $statusText = '';
                        switch ($a['auction_status']) {
                            case 'active': $statusClass = 'status-active'; $statusText = 'Активен'; break;
                            case 'payment_pending': $statusClass = 'status-pending'; $statusText = 'Ожидает оплаты'; break;
                            case 'completed': $statusClass = 'status-completed'; $statusText = 'Завершён'; break;
                            case 'closed_no_bids': $statusClass = 'status-closed'; $statusText = 'Без ставок'; break;
                            case 'reserve_not_met': $statusClass = 'status-closed'; $statusText = 'Резерв не достигнут'; break;
                            case 'cancelled': $statusClass = 'status-closed'; $statusText = 'Отменён'; break;
                            case 'draft': $statusClass = 'status-pending'; $statusText = 'Черновик'; break;
                            default: $statusClass = ''; $statusText = $a['auction_status'];
                        }
                        $currentBid = $a['current_bid'] ?? $a['start_bid'];
                        ?>
                        <tr>
                            <td><?= $a['id'] ?></td>
                            <td><a href="/auctions/view.php?id=<?= $a['id'] ?>" target="_blank"><?= htmlspecialchars(mb_substr($a['title'], 0, 50)) ?></a></td>
                            <td><?= htmlspecialchars($a['seller_name']) ?> (ID: <?= $a['user_id'] ?>)</td>
                            <td><?= $a['auction_type'] == 1 ? 'Прямой' : 'Обратный' ?></td>
                            <td><?= number_format($currentBid, 0) ?> ₽</td>
                            <td><?= $a['bids_count'] ?></td>
                            <td><span class="status-badge <?= $statusClass ?>"><?= $statusText ?></span></td>
                            <td><?= $a['auction_end_at'] ? date('d.m.Y H:i', strtotime($a['auction_end_at'])) : '-' ?></td>
                            <td class="actions">
                                <?php if ($a['auction_status'] == 'active'): ?>
                                    <button class="btn-icon btn-success" onclick="forceComplete(<?= $a['id'] ?>)">Завершить</button>
                                    <button class="btn-icon btn-warning" onclick="cancelAuction(<?= $a['id'] ?>)">Отменить</button>
                                <?php endif; ?>
                                <?php if (in_array($a['auction_status'], ['draft', 'closed_no_bids', 'cancelled'])): ?>
                                    <button class="btn-icon btn-danger" onclick="deleteAuction(<?= $a['id'] ?>)">Удалить</button>
                                <?php endif; ?>
                                <button class="btn-icon" onclick="viewBids(<?= $a['id'] ?>)">Ставки</button>
                                <button class="btn-icon" onclick="blockUser(<?= $a['user_id'] ?>, '<?= addslashes($a['seller_name']) ?>')">Блокировать</button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <?php if ($totalPages > 1): ?>
        <div class="pagination">
            <?php
            $queryParams = $_GET;
            for ($i = 1; $i <= $totalPages; $i++):
                $queryParams['page'] = $i;
                $url = '?' . http_build_query($queryParams);
            ?>
                <a href="<?= $url ?>" class="page-item <?= $i == $page ? 'active' : '' ?>"><?= $i ?></a>
            <?php endfor; ?>
        </div>
    <?php endif; ?>
</div>

<!-- Модальное окно для принудительного завершения -->
<div id="forceCompleteModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h3>Принудительное завершение аукциона</h3>
            <span class="modal-close" onclick="closeModal('forceCompleteModal')">&times;</span>
        </div>
        <p>Вы уверены? Аукцион будет завершён, победителем станет текущий лидер. Сделка будет создана.</p>
        <form method="post">
            <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
            <input type="hidden" name="action" value="force_complete">
            <input type="hidden" name="listing_id" id="force_listing_id">
            <div class="form-actions" style="display: flex; justify-content: flex-end; gap: 12px; margin-top: 20px;">
                <button type="button" onclick="closeModal('forceCompleteModal')" class="btn btn-secondary">Отмена</button>
                <button type="submit" class="btn btn-primary">Подтвердить</button>
            </div>
        </form>
    </div>
</div>

<!-- Модальное окно для отмены аукциона -->
<div id="cancelAuctionModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h3>Отмена аукциона</h3>
            <span class="modal-close" onclick="closeModal('cancelAuctionModal')">&times;</span>
        </div>
        <p>Вы уверены? Аукцион будет отменён, все ставки аннулированы. Продавец получит уведомление.</p>
        <form method="post">
            <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
            <input type="hidden" name="action" value="cancel_auction">
            <input type="hidden" name="listing_id" id="cancel_listing_id">
            <div class="form-actions" style="display: flex; justify-content: flex-end; gap: 12px; margin-top: 20px;">
                <button type="button" onclick="closeModal('cancelAuctionModal')" class="btn btn-secondary">Отмена</button>
                <button type="submit" class="btn btn-warning">Отменить аукцион</button>
            </div>
        </form>
    </div>
</div>

<!-- Модальное окно для удаления аукциона -->
<div id="deleteAuctionModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h3>Удаление аукциона</h3>
            <span class="modal-close" onclick="closeModal('deleteAuctionModal')">&times;</span>
        </div>
        <p>Удалить аукцион? Это действие необратимо. (Только для черновиков, без ставок или отменённых).</p>
        <form method="post">
            <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
            <input type="hidden" name="action" value="delete_auction">
            <input type="hidden" name="listing_id" id="delete_listing_id">
            <div class="form-actions" style="display: flex; justify-content: flex-end; gap: 12px; margin-top: 20px;">
                <button type="button" onclick="closeModal('deleteAuctionModal')" class="btn btn-secondary">Отмена</button>
                <button type="submit" class="btn btn-danger">Удалить</button>
            </div>
        </form>
    </div>
</div>

<!-- Модальное окно для блокировки пользователя -->
<div id="blockUserModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h3>Блокировка пользователя</h3>
            <span class="modal-close" onclick="closeModal('blockUserModal')">&times;</span>
        </div>
        <form method="post">
            <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
            <input type="hidden" name="action" value="block_user">
            <input type="hidden" name="user_id" id="block_user_id">
            <div class="form-group">
                <label>Пользователь: <span id="block_user_name"></span></label>
            </div>
            <div class="form-group">
                <label>Срок (дней)</label>
                <input type="number" name="block_days" value="30" min="1" max="365">
            </div>
            <div class="form-group">
                <label>Причина</label>
                <textarea name="block_reason" rows="3" placeholder="По решению администрации"></textarea>
            </div>
            <div class="form-actions" style="display: flex; justify-content: flex-end; gap: 12px; margin-top: 20px;">
                <button type="button" onclick="closeModal('blockUserModal')" class="btn btn-secondary">Отмена</button>
                <button type="submit" class="btn btn-danger">Заблокировать</button>
            </div>
        </form>
    </div>
</div>

<!-- Модальное окно для просмотра ставок -->
<div id="bidsModal" class="modal">
    <div class="modal-content" style="max-width: 800px;">
        <div class="modal-header">
            <h3>Ставки аукциона</h3>
            <span class="modal-close" onclick="closeModal('bidsModal')">&times;</span>
        </div>
        <div id="bidsList"></div>
    </div>
</div>

<script>
    function forceComplete(listingId) {
        document.getElementById('force_listing_id').value = listingId;
        document.getElementById('forceCompleteModal').style.display = 'flex';
    }
    function cancelAuction(listingId) {
        document.getElementById('cancel_listing_id').value = listingId;
        document.getElementById('cancelAuctionModal').style.display = 'flex';
    }
    function deleteAuction(listingId) {
        document.getElementById('delete_listing_id').value = listingId;
        document.getElementById('deleteAuctionModal').style.display = 'flex';
    }
    function blockUser(userId, userName) {
        document.getElementById('block_user_id').value = userId;
        document.getElementById('block_user_name').innerText = userName;
        document.getElementById('blockUserModal').style.display = 'flex';
    }
    async function viewBids(listingId) {
        try {
            const response = await fetch(`/api/auctions/bids.php?listing_id=${listingId}`);
            const data = await response.json();
            if (data.success) {
                let html = '<table class="auctions-table" style="width:100%;"><thead><tr><th>Участник</th><th>Сумма</th><th>Время</th></tr></thead><tbody>';
                data.bids.forEach(bid => {
                    html += `<tr>
                        <td>${bid.anonymous_id} (${bid.user_id})</td>
                        <td>${bid.bid_price.toLocaleString()} ₽</td>
                        <td>${new Date(bid.created_at).toLocaleString()}</td>
                    </tr>`;
                });
                html += '</tbody></table>';
                document.getElementById('bidsList').innerHTML = html;
                document.getElementById('bidsModal').style.display = 'flex';
            } else {
                alert('Ошибка загрузки ставок');
            }
        } catch (err) {
            alert('Ошибка сети');
        }
    }
    function closeModal(modalId) {
        document.getElementById(modalId).style.display = 'none';
    }
    window.onclick = function(event) {
        if (event.target.classList.contains('modal')) {
            event.target.style.display = 'none';
        }
    }
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>