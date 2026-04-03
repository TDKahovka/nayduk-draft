<?php
/* ============================================
   НАЙДУК — Рефералы владельца
   Версия 1.0
   - Только для администратора
   - Список всех пользователей, зарегистрированных по ссылке
   ============================================ */

session_start();
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../services/Database.php';

if (!isset($_SESSION['user_id'])) {
    $_SESSION['redirect_after_auth'] = '/owner/referrals';
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
$dateFrom = isset($_GET['date_from']) ? trim($_GET['date_from']) : '';
$dateTo = isset($_GET['date_to']) ? trim($_GET['date_to']) : '';
$search = isset($_GET['search']) ? trim($_GET['search']) : '';

$where = ["r.referrer_id = ?"];
$params = [$user['id']];

if ($dateFrom) {
    $where[] = "r.created_at >= ?";
    $params[] = $dateFrom . ' 00:00:00';
}
if ($dateTo) {
    $where[] = "r.created_at <= ?";
    $params[] = $dateTo . ' 23:59:59';
}
if ($search) {
    $where[] = "(u.name LIKE ? OR u.email LIKE ?)";
    $searchTerm = "%{$search}%";
    $params[] = $searchTerm;
    $params[] = $searchTerm;
}

$whereClause = implode(' AND ', $where);

// Общее количество
$countSql = "SELECT COUNT(*) FROM referrals r JOIN users u ON r.referred_id = u.id WHERE $whereClause";
$total = $db->fetchCount($countSql, $params);
$pages = ceil($total / $limit);

// Список рефералов с суммой комиссий
$sql = "
    SELECT 
        r.id,
        r.created_at as referral_date,
        u.id as user_id,
        u.name,
        u.email,
        u.created_at as user_created_at,
        u.is_premium,
        (SELECT COUNT(*) FROM premium_orders WHERE user_id = u.id AND status = 'paid') as orders_count,
        (SELECT COALESCE(SUM(amount), 0) FROM referral_commissions WHERE referral_id = r.id AND status = 'paid') as paid_commission,
        (SELECT COALESCE(SUM(amount), 0) FROM referral_commissions WHERE referral_id = r.id AND status = 'pending') as pending_commission
    FROM referrals r
    JOIN users u ON r.referred_id = u.id
    WHERE $whereClause
    ORDER BY r.created_at DESC
    LIMIT ? OFFSET ?
";
$stmt = $db->getPdo()->prepare($sql);
$stmt->execute(array_merge($params, [$limit, $offset]));
$referrals = $stmt->fetchAll(PDO::FETCH_ASSOC);

$csrfToken = generateCsrfToken();

$pageTitle = 'Рефералы — Найдук';
require_once __DIR__ . '/../includes/header.php';
?>
<div class="owner-container" style="max-width: 1200px; margin: 0 auto; padding: 40px 20px;">
    <div class="page-header" style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 16px; margin-bottom: 30px;">
        <h1>👥 Рефералы</h1>
        <a href="/owner/dashboard.php" class="btn btn-secondary">← Назад в дашборд</a>
    </div>

    <div class="filters-card" style="background: var(--surface); border-radius: var(--radius-xl); padding: 20px; margin-bottom: 30px; border: 1px solid var(--border);">
        <form method="GET" class="filters-row" style="display: flex; flex-wrap: wrap; gap: 12px; align-items: flex-end;">
            <div class="filter-group">
                <label class="filter-label">С даты</label>
                <input type="date" name="date_from" class="form-input" value="<?= htmlspecialchars($dateFrom) ?>">
            </div>
            <div class="filter-group">
                <label class="filter-label">По дату</label>
                <input type="date" name="date_to" class="form-input" value="<?= htmlspecialchars($dateTo) ?>">
            </div>
            <div class="filter-group">
                <label class="filter-label">Поиск (имя, email)</label>
                <input type="text" name="search" class="form-input" placeholder="Имя или email" value="<?= htmlspecialchars($search) ?>">
            </div>
            <div class="filter-actions">
                <button type="submit" class="btn btn-primary">Применить</button>
                <a href="?" class="btn btn-secondary">Сбросить</a>
            </div>
        </form>
    </div>

    <div class="stats-cards" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 20px; margin-bottom: 30px;">
        <div class="stat-card" style="background: var(--surface); border-radius: var(--radius-xl); padding: 20px; border: 1px solid var(--border);">
            <div class="stat-label" style="color: var(--text-secondary); font-size: 14px;">Всего рефералов</div>
            <div class="stat-value" style="font-size: 32px; font-weight: 700;"><?= number_format($total, 0, ',', ' ') ?></div>
        </div>
        <?php
        $totalPaid = array_sum(array_column($referrals, 'paid_commission'));
        $totalPending = array_sum(array_column($referrals, 'pending_commission'));
        ?>
        <div class="stat-card" style="background: var(--surface); border-radius: var(--radius-xl); padding: 20px; border: 1px solid var(--border);">
            <div class="stat-label" style="color: var(--text-secondary); font-size: 14px;">Выплачено комиссий</div>
            <div class="stat-value" style="font-size: 32px; font-weight: 700;"><?= number_format($totalPaid, 0, ',', ' ') ?> ₽</div>
        </div>
        <div class="stat-card" style="background: var(--surface); border-radius: var(--radius-xl); padding: 20px; border: 1px solid var(--border);">
            <div class="stat-label" style="color: var(--text-secondary); font-size: 14px;">Ожидает выплаты</div>
            <div class="stat-value" style="font-size: 32px; font-weight: 700;"><?= number_format($totalPending, 0, ',', ' ') ?> ₽</div>
        </div>
    </div>

    <div class="table-container" style="overflow-x: auto;">
        <table class="data-table" style="width: 100%; border-collapse: collapse;">
            <thead>
                <tr style="background: var(--bg-secondary);">
                    <th style="padding: 12px; text-align: left;">ID</th>
                    <th style="padding: 12px; text-align: left;">Имя</th>
                    <th style="padding: 12px; text-align: left;">Email</th>
                    <th style="padding: 12px; text-align: left;">Дата регистрации</th>
                    <th style="padding: 12px; text-align: left;">Премиум</th>
                    <th style="padding: 12px; text-align: left;">Заказов</th>
                    <th style="padding: 12px; text-align: left;">Выплачено</th>
                    <th style="padding: 12px; text-align: left;">Ожидает</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($referrals)): ?>
                    <tr><td colspan="8" style="text-align: center; padding: 40px;">Нет рефералов</td></tr>
                <?php else: ?>
                    <?php foreach ($referrals as $ref): ?>
                    <tr style="border-bottom: 1px solid var(--border-light);">
                        <td style="padding: 12px;"><?= $ref['user_id'] ?></td>
                        <td style="padding: 12px;"><?= htmlspecialchars($ref['name']) ?></td>
                        <td style="padding: 12px;"><?= htmlspecialchars($ref['email']) ?></td>
                        <td style="padding: 12px;"><?= date('d.m.Y', strtotime($ref['referral_date'])) ?></td>
                        <td style="padding: 12px;"><?= $ref['is_premium'] ? '✅' : '—' ?></td>
                        <td style="padding: 12px;"><?= $ref['orders_count'] ?></td>
                        <td style="padding: 12px;"><?= number_format($ref['paid_commission'], 0, ',', ' ') ?> ₽</td>
                        <td style="padding: 12px;"><?= number_format($ref['pending_commission'], 0, ',', ' ') ?> ₽</td>
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