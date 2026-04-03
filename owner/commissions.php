<?php
/* ============================================
   НАЙДУК — Начисления комиссий
   Версия 1.0
   - Только для администратора
   - Список всех начислений комиссий с возможностью фильтрации
   ============================================ */

session_start();
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../services/Database.php';

if (!isset($_SESSION['user_id'])) {
    $_SESSION['redirect_after_auth'] = '/owner/commissions';
    header('Location: /auth/login');
    exit;
}

$db = Database::getInstance();
$user = $db->getUserById($_SESSION['user_id']);
if (!$user || $user['role'] !== 'admin') {
    http_response_code(403);
    die('Доступ запрещён');
}

$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$limit = 25;
$offset = ($page - 1) * $limit;

// Фильтры
$status = isset($_GET['status']) ? $_GET['status'] : 'all';
$dateFrom = isset($_GET['date_from']) ? trim($_GET['date_from']) : '';
$dateTo = isset($_GET['date_to']) ? trim($_GET['date_to']) : '';

$where = ["r.referrer_id = ?"];
$params = [$user['id']];

if ($status !== 'all') {
    $where[] = "rc.status = ?";
    $params[] = $status;
}
if ($dateFrom) {
    $where[] = "rc.created_at >= ?";
    $params[] = $dateFrom . ' 00:00:00';
}
if ($dateTo) {
    $where[] = "rc.created_at <= ?";
    $params[] = $dateTo . ' 23:59:59';
}

$whereClause = implode(' AND ', $where);

// Общее количество
$countSql = "
    SELECT COUNT(*) 
    FROM referral_commissions rc 
    JOIN referrals r ON rc.referral_id = r.id 
    WHERE $whereClause
";
$total = $db->fetchCount($countSql, $params);
$pages = ceil($total / $limit);

// Список комиссий
$sql = "
    SELECT 
        rc.id,
        rc.amount,
        rc.status,
        rc.created_at,
        rc.paid_at,
        r.referred_id,
        u.name as referred_name,
        u.email as referred_email,
        (SELECT COUNT(*) FROM premium_orders WHERE user_id = u.id AND status = 'paid') as orders_count
    FROM referral_commissions rc
    JOIN referrals r ON rc.referral_id = r.id
    JOIN users u ON r.referred_id = u.id
    WHERE $whereClause
    ORDER BY rc.created_at DESC
    LIMIT ? OFFSET ?
";
$stmt = $db->getPdo()->prepare($sql);
$stmt->execute(array_merge($params, [$limit, $offset]));
$commissions = $stmt->fetchAll(PDO::FETCH_ASSOC);

$csrfToken = generateCsrfToken();

$pageTitle = 'Начисления комиссий — Найдук';
require_once __DIR__ . '/../includes/header.php';
?>
<div class="owner-container" style="max-width: 1200px; margin: 0 auto; padding: 40px 20px;">
    <div class="page-header" style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 16px; margin-bottom: 30px;">
        <h1>💰 Начисления комиссий</h1>
        <a href="/owner/dashboard.php" class="btn btn-secondary">← Назад в дашборд</a>
    </div>

    <div class="filters-card" style="background: var(--surface); border-radius: var(--radius-xl); padding: 20px; margin-bottom: 30px; border: 1px solid var(--border);">
        <form method="GET" class="filters-row" style="display: flex; flex-wrap: wrap; gap: 12px; align-items: flex-end;">
            <div class="filter-group">
                <label class="filter-label">Статус</label>
                <select name="status" class="form-select">
                    <option value="all" <?= $status === 'all' ? 'selected' : '' ?>>Все</option>
                    <option value="pending" <?= $status === 'pending' ? 'selected' : '' ?>>Ожидает</option>
                    <option value="paid" <?= $status === 'paid' ? 'selected' : '' ?>>Выплачено</option>
                    <option value="cancelled" <?= $status === 'cancelled' ? 'selected' : '' ?>>Отменено</option>
                </select>
            </div>
            <div class="filter-group">
                <label class="filter-label">С даты</label>
                <input type="date" name="date_from" class="form-input" value="<?= htmlspecialchars($dateFrom) ?>">
            </div>
            <div class="filter-group">
                <label class="filter-label">По дату</label>
                <input type="date" name="date_to" class="form-input" value="<?= htmlspecialchars($dateTo) ?>">
            </div>
            <div class="filter-actions">
                <button type="submit" class="btn btn-primary">Применить</button>
                <a href="?" class="btn btn-secondary">Сбросить</a>
            </div>
        </form>
    </div>

    <div class="stats-cards" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 20px; margin-bottom: 30px;">
        <?php
        $pendingTotal = $db->fetchColumn("SELECT COALESCE(SUM(amount),0) FROM referral_commissions rc JOIN referrals r ON rc.referral_id = r.id WHERE r.referrer_id = ? AND rc.status = 'pending'", [$user['id']]);
        $paidTotal = $db->fetchColumn("SELECT COALESCE(SUM(amount),0) FROM referral_commissions rc JOIN referrals r ON rc.referral_id = r.id WHERE r.referrer_id = ? AND rc.status = 'paid'", [$user['id']]);
        ?>
        <div class="stat-card" style="background: var(--surface); border-radius: var(--radius-xl); padding: 20px; border: 1px solid var(--border);">
            <div class="stat-label" style="color: var(--text-secondary); font-size: 14px;">Ожидает выплаты</div>
            <div class="stat-value" style="font-size: 32px; font-weight: 700;"><?= number_format($pendingTotal, 0, ',', ' ') ?> ₽</div>
        </div>
        <div class="stat-card" style="background: var(--surface); border-radius: var(--radius-xl); padding: 20px; border: 1px solid var(--border);">
            <div class="stat-label" style="color: var(--text-secondary); font-size: 14px;">Выплачено всего</div>
            <div class="stat-value" style="font-size: 32px; font-weight: 700;"><?= number_format($paidTotal, 0, ',', ' ') ?> ₽</div>
        </div>
    </div>

    <div class="table-container" style="overflow-x: auto;">
        <table class="data-table" style="width: 100%; border-collapse: collapse;">
            <thead>
                <tr style="background: var(--bg-secondary);">
                    <th style="padding: 12px; text-align: left;">ID</th>
                    <th style="padding: 12px; text-align: left;">Реферал</th>
                    <th style="padding: 12px; text-align: left;">Сумма</th>
                    <th style="padding: 12px; text-align: left;">Статус</th>
                    <th style="padding: 12px; text-align: left;">Дата начисления</th>
                    <th style="padding: 12px; text-align: left;">Дата выплаты</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($commissions)): ?>
                    <tr><td colspan="6" style="text-align: center; padding: 40px;">Нет начислений</td></tr>
                <?php else: ?>
                    <?php foreach ($commissions as $c): ?>
                    <tr style="border-bottom: 1px solid var(--border-light);">
                        <td style="padding: 12px;"><?= $c['id'] ?></td>
                        <td style="padding: 12px;"><?= htmlspecialchars($c['referred_name']) ?> (<?= $c['orders_count'] ?> заказов)</td>
                        <td style="padding: 12px;"><?= number_format($c['amount'], 0, ',', ' ') ?> ₽</td>
                        <td style="padding: 12px;">
                            <?php if ($c['status'] === 'pending'): ?>
                                <span class="badge badge-warning">Ожидает</span>
                            <?php elseif ($c['status'] === 'paid'): ?>
                                <span class="badge badge-success">Выплачено</span>
                            <?php else: ?>
                                <span class="badge badge-danger">Отменено</span>
                            <?php endif; ?>
                        </td>
                        <td style="padding: 12px;"><?= date('d.m.Y H:i', strtotime($c['created_at'])) ?></td>
                        <td style="padding: 12px;"><?= $c['paid_at'] ? date('d.m.Y H:i', strtotime($c['paid_at'])) : '—' ?></td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <?php if ($pages > 1): ?>
        <div class="pagination" style="display: flex; justify-content: center; gap: 8px; margin-top: 30px;">
            <?php for ($i = 1; $i <= $pages; $i++): ?>
                <a href="?page=<?= $i ?>&<?= http_build_query(array_merge($_GET, ['page' => $i])) ?>" class="page-btn <?= $i == $page ? 'active' : '' ?>" style="display: inline-block; padding: 8px 12px; border-radius: var(--radius-full); background: <?= $i == $page ? 'var(--primary)' : 'var(--surface)' ?>; color: <?= $i == $page ? 'white' : 'var(--text)' ?>; text-decoration: none;"><?= $i ?></a>
            <?php endfor; ?>
        </div>
    <?php endif; ?>
</div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>