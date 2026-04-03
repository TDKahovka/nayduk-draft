<?php
/* ============================================
   НАЙДУК — Управление партнёрами (страховки, услуги)
   Версия 1.0 (март 2026)
   - CRUD партнёров
   - Статистика по кликам и заказам
   ============================================ */

session_start();
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../services/Database.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: /auth/login');
    exit;
}

$db = Database::getInstance();
$user = $db->getUserById($_SESSION['user_id']);
if (!$user || ($user['role'] !== 'admin' && $user['role'] !== 'superadmin')) {
    header('Location: /');
    exit;
}

$csrfToken = generateCsrfToken();

// Обработка POST (создание, обновление, удаление)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        die('Ошибка CSRF');
    }

    $action = $_POST['action'] ?? '';
    $id = isset($_POST['id']) ? (int)$_POST['id'] : 0;

    if ($action === 'create') {
        $name = trim($_POST['name'] ?? '');
        $slug = trim($_POST['slug'] ?? '');
        $url = trim($_POST['url'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $commission_rate = (float)($_POST['commission_rate'] ?? 10);
        $type = $_POST['type'] ?? 'link';
        $widget_code = trim($_POST['widget_code'] ?? '');
        $api_key = trim($_POST['api_key'] ?? '');
        $webhook_url = trim($_POST['webhook_url'] ?? '');
        $icon_url = trim($_POST['icon_url'] ?? '');
        $categories = isset($_POST['categories']) ? json_encode(explode(',', $_POST['categories'])) : '[]';
        $is_active = isset($_POST['is_active']) ? 1 : 0;

        if ($name && $slug) {
            $db->insert('affiliates', [
                'name' => $name,
                'slug' => $slug,
                'url' => $url,
                'description' => $description,
                'commission_rate' => $commission_rate,
                'type' => $type,
                'widget_code' => $widget_code,
                'api_key' => $api_key,
                'webhook_url' => $webhook_url,
                'icon_url' => $icon_url,
                'categories' => $categories,
                'is_active' => $is_active,
                'created_at' => date('Y-m-d H:i:s')
            ]);
            $success = 'Партнёр добавлен';
        } else {
            $error = 'Заполните название и slug';
        }
    } elseif ($action === 'update' && $id) {
        $name = trim($_POST['name'] ?? '');
        $slug = trim($_POST['slug'] ?? '');
        $url = trim($_POST['url'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $commission_rate = (float)($_POST['commission_rate'] ?? 10);
        $type = $_POST['type'] ?? 'link';
        $widget_code = trim($_POST['widget_code'] ?? '');
        $api_key = trim($_POST['api_key'] ?? '');
        $webhook_url = trim($_POST['webhook_url'] ?? '');
        $icon_url = trim($_POST['icon_url'] ?? '');
        $categories = isset($_POST['categories']) ? json_encode(explode(',', $_POST['categories'])) : '[]';
        $is_active = isset($_POST['is_active']) ? 1 : 0;

        if ($name && $slug) {
            $db->update('affiliates', [
                'name' => $name,
                'slug' => $slug,
                'url' => $url,
                'description' => $description,
                'commission_rate' => $commission_rate,
                'type' => $type,
                'widget_code' => $widget_code,
                'api_key' => $api_key,
                'webhook_url' => $webhook_url,
                'icon_url' => $icon_url,
                'categories' => $categories,
                'is_active' => $is_active,
                'updated_at' => date('Y-m-d H:i:s')
            ], 'id = ?', [$id]);
            $success = 'Партнёр обновлён';
        } else {
            $error = 'Заполните название и slug';
        }
    } elseif ($action === 'delete' && $id) {
        $db->delete('affiliates', 'id = ?', [$id]);
        $success = 'Партнёр удалён';
    } elseif ($action === 'toggle' && $id) {
        $affiliate = $db->fetchOne("SELECT is_active FROM affiliates WHERE id = ?", [$id]);
        if ($affiliate) {
            $newStatus = $affiliate['is_active'] ? 0 : 1;
            $db->update('affiliates', ['is_active' => $newStatus], 'id = ?', [$id]);
            $success = 'Статус изменён';
        }
    }

    if (isset($success)) {
        header('Location: /owner/partners?success=' . urlencode($success));
        exit;
    } elseif (isset($error)) {
        header('Location: /owner/partners?error=' . urlencode($error));
        exit;
    }
}

// Получение списка партнёров
$affiliates = $db->fetchAll("
    SELECT a.*,
           (SELECT COUNT(*) FROM affiliate_clicks WHERE affiliate_id = a.id) as total_clicks,
           (SELECT COUNT(*) FROM affiliate_orders WHERE affiliate_id = a.id AND status = 'approved') as total_orders,
           (SELECT COALESCE(SUM(commission), 0) FROM affiliate_orders WHERE affiliate_id = a.id AND status = 'approved') as total_commission
    FROM affiliates a
    ORDER BY a.created_at DESC
");

$pageTitle = 'Управление партнёрами — Найдук';
require_once __DIR__ . '/../includes/header.php';
?>

<style>
    .owner-container { max-width: 1200px; margin: 0 auto; padding: 40px 20px; }
    .card { background: var(--surface); border-radius: var(--radius-xl); padding: 24px; margin-bottom: 30px; border: 1px solid var(--border); }
    .form-group { margin-bottom: 16px; }
    .form-label { display: block; font-weight: 600; margin-bottom: 6px; }
    .form-input, .form-textarea, .form-select { width: 100%; padding: 10px; border: 1px solid var(--border); border-radius: var(--radius); background: var(--bg); color: var(--text); }
    .btn { padding: 8px 16px; border-radius: var(--radius-full); font-weight: 600; cursor: pointer; border: none; }
    .btn-primary { background: var(--primary); color: white; }
    .btn-danger { background: var(--danger); color: white; }
    .btn-secondary { background: var(--bg-secondary); border: 1px solid var(--border); color: var(--text); }
    .table-container { overflow-x: auto; background: var(--surface); border-radius: var(--radius-xl); border: 1px solid var(--border); }
    table { width: 100%; border-collapse: collapse; }
    th, td { padding: 12px; text-align: left; border-bottom: 1px solid var(--border-light); }
    th { background: var(--bg-secondary); }
    .badge { display: inline-block; padding: 4px 10px; border-radius: var(--radius-full); font-size: 12px; font-weight: 600; }
    .badge-success { background: rgba(52,199,89,0.1); color: var(--success); }
    .badge-danger { background: rgba(255,59,48,0.1); color: var(--danger); }
    .edit-form { display: none; margin-top: 20px; padding-top: 20px; border-top: 1px solid var(--border); }
    @media (max-width: 768px) { th, td { display: block; text-align: right; } th::before, td::before { content: attr(data-label); float: left; font-weight: 600; } thead { display: none; } tr { margin-bottom: 16px; display: block; border: 1px solid var(--border); border-radius: var(--radius); } }
</style>

<div class="owner-container">
    <div class="section-header">
        <h1>🤝 Управление партнёрами</h1>
        <button class="btn btn-primary" onclick="showAddForm()">➕ Добавить партнёра</button>
    </div>

    <?php if (isset($_GET['success'])): ?>
        <div class="alert alert-success">✅ <?= htmlspecialchars($_GET['success']) ?></div>
    <?php endif; ?>
    <?php if (isset($_GET['error'])): ?>
        <div class="alert alert-error">❌ <?= htmlspecialchars($_GET['error']) ?></div>
    <?php endif; ?>

    <!-- Форма добавления (скрыта по умолчанию) -->
    <div id="add-form" class="card" style="display: none;">
        <h2>➕ Новый партнёр</h2>
        <form method="POST">
            <input type="hidden" name="action" value="create">
            <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
            <div class="form-group"><label class="form-label">Название *</label><input type="text" name="name" class="form-input" required></div>
            <div class="form-group"><label class="form-label">Slug (уникальный идентификатор) *</label><input type="text" name="slug" class="form-input" required placeholder="insurance-company"></div>
            <div class="form-group"><label class="form-label">URL партнёра</label><input type="text" name="url" class="form-input" placeholder="https://..."></div>
            <div class="form-group"><label class="form-label">Описание</label><textarea name="description" class="form-textarea" rows="2"></textarea></div>
            <div class="form-group"><label class="form-label">Комиссия (%)</label><input type="number" name="commission_rate" class="form-input" value="10" step="0.1"></div>
            <div class="form-group"><label class="form-label">Тип</label><select name="type" class="form-select"><option value="link">Ссылка</option><option value="widget">Виджет</option><option value="api">API</option></select></div>
            <div class="form-group"><label class="form-label">Код виджета</label><textarea name="widget_code" class="form-textarea" rows="3"></textarea></div>
            <div class="form-group"><label class="form-label">API ключ</label><input type="text" name="api_key" class="form-input"></div>
            <div class="form-group"><label class="form-label">Webhook URL</label><input type="text" name="webhook_url" class="form-input"></div>
            <div class="form-group"><label class="form-label">URL иконки</label><input type="text" name="icon_url" class="form-input"></div>
            <div class="form-group"><label class="form-label">Категории (через запятую)</label><input type="text" name="categories" class="form-input" placeholder="auto, realty, electronics"></div>
            <div class="form-group"><label><input type="checkbox" name="is_active" value="1" checked> Активен</label></div>
            <div class="form-actions"><button type="submit" class="btn btn-primary">Сохранить</button><button type="button" class="btn btn-secondary" onclick="hideAddForm()">Отмена</button></div>
        </form>
    </div>

    <!-- Список партнёров -->
    <div class="table-container">
        <table>
            <thead>
                <tr><th>ID</th><th>Название</th><th>Тип</th><th>Комиссия</th><th>Клики</th><th>Заказы</th><th>Доход</th><th>Активен</th><th>Действия</th></tr>
            </thead>
            <tbody>
                <?php foreach ($affiliates as $a): ?>
                    <tr>
                        <td data-label="ID"><?= $a['id'] ?></td>
                        <td data-label="Название"><strong><?= htmlspecialchars($a['name']) ?></strong><br><small><?= htmlspecialchars($a['slug']) ?></small></td>
                        <td data-label="Тип"><?= $a['type'] ?></td>
                        <td data-label="Комиссия"><?= $a['commission_rate'] ?>%</td>
                        <td data-label="Клики"><?= number_format($a['total_clicks']) ?></td>
                        <td data-label="Заказы"><?= $a['total_orders'] ?></td>
                        <td data-label="Доход"><?= number_format($a['total_commission'], 0, ',', ' ') ?> ₽</td>
                        <td data-label="Активен"><span class="badge <?= $a['is_active'] ? 'badge-success' : 'badge-danger' ?>"><?= $a['is_active'] ? 'Да' : 'Нет' ?></span></td>
                        <td data-label="Действия">
                            <button class="btn btn-secondary" onclick="editPartner(<?= $a['id'] ?>)">Редактировать</button>
                            <form method="POST" style="display: inline;" onsubmit="return confirm('Удалить партнёра?')">
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
                                <input type="hidden" name="id" value="<?= $a['id'] ?>">
                                <button type="submit" class="btn btn-danger">Удалить</button>
                            </form>
                            <form method="POST" style="display: inline;">
                                <input type="hidden" name="action" value="toggle">
                                <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
                                <input type="hidden" name="id" value="<?= $a['id'] ?>">
                                <button type="submit" class="btn btn-secondary"><?= $a['is_active'] ? 'Отключить' : 'Включить' ?></button>
                            </form>
                        </td>
                    </tr>
                    <tr id="edit-<?= $a['id'] ?>" style="display: none;">
                        <td colspan="9">
                            <form method="POST" class="edit-form" style="display: block;">
                                <input type="hidden" name="action" value="update">
                                <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
                                <input type="hidden" name="id" value="<?= $a['id'] ?>">
                                <div class="form-group"><label>Название</label><input type="text" name="name" value="<?= htmlspecialchars($a['name']) ?>" class="form-input" required></div>
                                <div class="form-group"><label>Slug</label><input type="text" name="slug" value="<?= htmlspecialchars($a['slug']) ?>" class="form-input" required></div>
                                <div class="form-group"><label>URL</label><input type="text" name="url" value="<?= htmlspecialchars($a['url']) ?>" class="form-input"></div>
                                <div class="form-group"><label>Описание</label><textarea name="description" class="form-textarea" rows="2"><?= htmlspecialchars($a['description']) ?></textarea></div>
                                <div class="form-group"><label>Комиссия (%)</label><input type="number" name="commission_rate" value="<?= $a['commission_rate'] ?>" class="form-input" step="0.1"></div>
                                <div class="form-group"><label>Тип</label><select name="type" class="form-select"><option value="link" <?= $a['type'] === 'link' ? 'selected' : '' ?>>Ссылка</option><option value="widget" <?= $a['type'] === 'widget' ? 'selected' : '' ?>>Виджет</option><option value="api" <?= $a['type'] === 'api' ? 'selected' : '' ?>>API</option></select></div>
                                <div class="form-group"><label>Код виджета</label><textarea name="widget_code" class="form-textarea" rows="3"><?= htmlspecialchars($a['widget_code']) ?></textarea></div>
                                <div class="form-group"><label>API ключ</label><input type="text" name="api_key" value="<?= htmlspecialchars($a['api_key']) ?>" class="form-input"></div>
                                <div class="form-group"><label>Webhook URL</label><input type="text" name="webhook_url" value="<?= htmlspecialchars($a['webhook_url']) ?>" class="form-input"></div>
                                <div class="form-group"><label>URL иконки</label><input type="text" name="icon_url" value="<?= htmlspecialchars($a['icon_url']) ?>" class="form-input"></div>
                                <div class="form-group"><label>Категории (через запятую)</label><input type="text" name="categories" value="<?= implode(',', json_decode($a['categories'], true) ?: []) ?>" class="form-input"></div>
                                <div class="form-group"><label><input type="checkbox" name="is_active" value="1" <?= $a['is_active'] ? 'checked' : '' ?>> Активен</label></div>
                                <div class="form-actions"><button type="submit" class="btn btn-primary">Сохранить</button><button type="button" class="btn btn-secondary" onclick="cancelEdit(<?= $a['id'] ?>)">Отмена</button></div>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<script>
    function showAddForm() { document.getElementById('add-form').style.display = 'block'; }
    function hideAddForm() { document.getElementById('add-form').style.display = 'none'; }
    function editPartner(id) { document.getElementById('edit-' + id).style.display = 'table-row'; }
    function cancelEdit(id) { document.getElementById('edit-' + id).style.display = 'none'; }
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>