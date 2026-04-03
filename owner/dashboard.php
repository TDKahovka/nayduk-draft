<?php
/* ============================================
   НАЙДУК — Бизнес-кабинет владельца (дашборд)
   Версия 1.0 (март 2026)
   - Доступ только для суперадмина
   - Статистика: доходы, рефералы, партнёры
   - Управление партнёрами, рефералами
   ============================================ */

session_start();
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../services/Database.php';

if (!isset($_SESSION['user_id'])) {
    $_SESSION['redirect_after_auth'] = '/owner/dashboard';
    header('Location: /auth/login');
    exit;
}

$db = Database::getInstance();
$userId = $_SESSION['user_id'];
$user = $db->getUserById($userId);
if (!$user || ($user['role'] !== 'admin' && $user['role'] !== 'superadmin')) {
    header('Location: /');
    exit;
}

$csrfToken = generateCsrfToken();

// ===== СТАТИСТИКА =====
// Доходы от премиум-продаж
$premiumStats = $db->fetchOne("
    SELECT 
        COUNT(*) as total_orders,
        COALESCE(SUM(amount), 0) as total_revenue,
        COALESCE(SUM(CASE WHEN status = 'paid' THEN amount ELSE 0 END), 0) as paid_revenue
    FROM premium_orders
");
if (!$premiumStats) $premiumStats = ['total_orders' => 0, 'total_revenue' => 0, 'paid_revenue' => 0];

// Доходы от рефералов
$referralStats = $db->fetchOne("
    SELECT 
        COUNT(DISTINCT r.id) as total_referrals,
        COALESCE(SUM(rc.amount), 0) as total_earned,
        COALESCE(SUM(CASE WHEN rc.status = 'paid' THEN rc.amount ELSE 0 END), 0) as paid_earned
    FROM referrals r
    LEFT JOIN referral_commissions rc ON r.id = rc.referral_id
");
if (!$referralStats) $referralStats = ['total_referrals' => 0, 'total_earned' => 0, 'paid_earned' => 0];

// Доходы от партнёров
$affiliateStats = $db->fetchOne("
    SELECT 
        COUNT(DISTINCT a.id) as total_affiliates,
        COALESCE(SUM(ao.commission), 0) as total_commission,
        COALESCE(SUM(CASE WHEN ao.status = 'paid' THEN ao.commission ELSE 0 END), 0) as paid_commission
    FROM affiliates a
    LEFT JOIN affiliate_orders ao ON a.id = ao.affiliate_id
");
if (!$affiliateStats) $affiliateStats = ['total_affiliates' => 0, 'total_commission' => 0, 'paid_commission' => 0];

// Активность сайта
$activityStats = $db->fetchOne("
    SELECT 
        (SELECT COUNT(*) FROM users WHERE created_at > NOW() - INTERVAL 7 DAY) as new_users,
        (SELECT COUNT(*) FROM listings WHERE created_at > NOW() - INTERVAL 7 DAY) as new_listings,
        (SELECT COUNT(*) FROM reviews WHERE created_at > NOW() - INTERVAL 7 DAY) as new_reviews,
        (SELECT COUNT(*) FROM premium_orders WHERE created_at > NOW() - INTERVAL 7 DAY AND status = 'paid') as new_paid_orders
");

// Список последних рефералов
$recentReferrals = $db->fetchAll("
    SELECT r.id, r.referrer_id, r.referred_id, r.created_at,
           u_ref.name as referrer_name, u_ref.email as referrer_email,
           u_rec.name as referred_name, u_rec.email as referred_email
    FROM referrals r
    JOIN users u_ref ON r.referrer_id = u_ref.id
    JOIN users u_rec ON r.referred_id = u_rec.id
    ORDER BY r.created_at DESC
    LIMIT 10
");

// Список последних начислений
$recentCommissions = $db->fetchAll("
    SELECT rc.*, r.referrer_id, u.name as referrer_name, r.referred_id, u2.name as referred_name
    FROM referral_commissions rc
    JOIN referrals r ON rc.referral_id = r.id
    JOIN users u ON r.referrer_id = u.id
    JOIN users u2 ON r.referred_id = u2.id
    ORDER BY rc.created_at DESC
    LIMIT 10
");

// Список последних задач для админа
$pendingTasks = $db->getPendingTasks(10);

$pageTitle = 'Бизнес-кабинет — Найдук';
require_once __DIR__ . '/../includes/header.php';
?>

<style>
    .owner-container { max-width: 1200px; margin: 0 auto; padding: 40px 20px; }
    .stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: 20px; margin-bottom: 30px; }
    .stat-card { background: var(--surface); border-radius: var(--radius-xl); padding: 20px; border: 1px solid var(--border); box-shadow: var(--shadow-sm); }
    .stat-value { font-size: 32px; font-weight: 700; color: var(--primary); }
    .stat-label { font-size: 14px; color: var(--text-secondary); margin-top: 8px; }
    .section { margin-bottom: 40px; }
    .section-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; flex-wrap: wrap; gap: 16px; }
    .section-title { font-size: 20px; font-weight: 600; }
    .table-container { overflow-x: auto; background: var(--surface); border-radius: var(--radius-xl); border: 1px solid var(--border); }
    table { width: 100%; border-collapse: collapse; }
    th, td { padding: 12px; text-align: left; border-bottom: 1px solid var(--border-light); }
    th { background: var(--bg-secondary); font-weight: 600; }
    .badge { display: inline-block; padding: 4px 10px; border-radius: var(--radius-full); font-size: 12px; font-weight: 600; }
    .badge-success { background: rgba(52,199,89,0.1); color: var(--success); }
    .badge-warning { background: rgba(255,149,0,0.1); color: var(--warning); }
    .badge-info { background: rgba(74,144,226,0.1); color: var(--primary); }
    .task-list { background: var(--surface); border-radius: var(--radius-xl); border: 1px solid var(--border); padding: 16px; }
    .task-item { padding: 12px; border-bottom: 1px solid var(--border-light); display: flex; justify-content: space-between; align-items: center; }
    .task-item:last-child { border-bottom: none; }
    .btn { padding: 8px 16px; border-radius: var(--radius-full); font-weight: 600; cursor: pointer; border: none; }
    .btn-primary { background: var(--primary); color: white; }
    .btn-secondary { background: var(--bg-secondary); border: 1px solid var(--border); color: var(--text); }
    @media (max-width: 768px) { .stats-grid { grid-template-columns: 1fr; } th, td { display: block; text-align: right; } th::before, td::before { content: attr(data-label); float: left; font-weight: 600; } thead { display: none; } tr { margin-bottom: 16px; display: block; border: 1px solid var(--border); border-radius: var(--radius); } }
</style>

<div class="owner-container">
    <div class="section-header">
        <h1>📊 Бизнес-кабинет</h1>
        <div>
            <a href="/owner/partners" class="btn btn-primary">➕ Управление партнёрами</a>
            <a href="/owner/settings" class="btn btn-secondary">⚙️ Настройки</a>
        </div>
    </div>

    <!-- Ключевые метрики -->
    <div class="stats-grid">
        <div class="stat-card">
            <div class="stat-value"><?= number_format($premiumStats['total_revenue'], 0, ',', ' ') ?> ₽</div>
            <div class="stat-label">Доход от премиума</div>
            <div class="stat-sub"><?= $premiumStats['total_orders'] ?> продаж</div>
        </div>
        <div class="stat-card">
            <div class="stat-value"><?= number_format($referralStats['total_earned'], 0, ',', ' ') ?> ₽</div>
            <div class="stat-label">Начислено рефералам</div>
            <div class="stat-sub"><?= $referralStats['total_referrals'] ?> рефералов</div>
        </div>
        <div class="stat-card">
            <div class="stat-value"><?= number_format($affiliateStats['total_commission'], 0, ',', ' ') ?> ₽</div>
            <div class="stat-label">Комиссия партнёров</div>
            <div class="stat-sub"><?= $affiliateStats['total_affiliates'] ?> партнёров</div>
        </div>
        <div class="stat-card">
            <div class="stat-value"><?= $activityStats['new_users'] ?? 0 ?></div>
            <div class="stat-label">Новых пользователей (7 дней)</div>
            <div class="stat-sub"><?= $activityStats['new_listings'] ?? 0 ?> новых объявлений</div>
        </div>
    </div>

    <!-- Последние задачи для админа -->
    <div class="section">
        <div class="section-header">
            <h2 class="section-title">📋 Задачи для администратора</h2>
            <a href="/admin/tasks" class="btn btn-secondary">Все задачи</a>
        </div>
        <div class="task-list">
            <?php if (empty($pendingTasks)): ?>
                <div class="task-item">Нет активных задач</div>
            <?php else: ?>
                <?php foreach ($pendingTasks as $task): ?>
                    <div class="task-item">
                        <div>
                            <strong><?= htmlspecialchars($task['type']) ?></strong>
                            <div class="task-data" style="font-size: 12px; color: var(--text-secondary);"><?= htmlspecialchars(json_encode(json_decode($task['data'], true))) ?></div>
                        </div>
                        <span class="badge badge-warning">в ожидании</span>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>

    <!-- Последние рефералы -->
    <div class="section">
        <div class="section-header">
            <h2 class="section-title">👥 Последние рефералы</h2>
            <a href="/owner/referrals" class="btn btn-secondary">Все рефералы</a>
        </div>
        <div class="table-container">
            <table>
                <thead>
                    <tr><th>Реферер</th><th>Реферал</th><th>Дата</th></tr>
                </thead>
                <tbody>
                    <?php if (empty($recentReferrals)): ?>
                        <tr><td colspan="3">Нет рефералов</td></tr>
                    <?php else: ?>
                        <?php foreach ($recentReferrals as $ref): ?>
                            <tr>
                                <td data-label="Реферер"><?= htmlspecialchars($ref['referrer_name']) ?><br><small><?= htmlspecialchars($ref['referrer_email']) ?></small></td>
                                <td data-label="Реферал"><?= htmlspecialchars($ref['referred_name']) ?><br><small><?= htmlspecialchars($ref['referred_email']) ?></small></td>
                                <td data-label="Дата"><?= date('d.m.Y', strtotime($ref['created_at'])) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Последние начисления комиссий -->
    <div class="section">
        <div class="section-header">
            <h2 class="section-title">💰 Последние начисления рефералам</h2>
            <a href="/owner/commissions" class="btn btn-secondary">Все начисления</a>
        </div>
        <div class="table-container">
            <table>
                <thead>
                    <tr><th>Реферер</th><th>Реферал</th><th>Сумма</th><th>Статус</th><th>Дата</th></tr>
                </thead>
                <tbody>
                    <?php if (empty($recentCommissions)): ?>
                        <tr><td colspan="5">Нет начислений</td></tr>
                    <?php else: ?>
                        <?php foreach ($recentCommissions as $com): ?>
                            <tr>
                                <td data-label="Реферер"><?= htmlspecialchars($com['referrer_name']) ?></td>
                                <td data-label="Реферал"><?= htmlspecialchars($com['referred_name']) ?></td>
                                <td data-label="Сумма"><?= number_format($com['amount'], 0, ',', ' ') ?> ₽</td>
                                <td data-label="Статус">
                                    <span class="badge <?= $com['status'] === 'paid' ? 'badge-success' : 'badge-warning' ?>">
                                        <?= $com['status'] === 'paid' ? 'Выплачено' : 'Ожидает' ?>
                                    </span>
                                </td>
                                <td data-label="Дата"><?= date('d.m.Y', strtotime($com['created_at'])) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>