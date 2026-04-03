<?php
/* ============================================
   НАЙДУК — Управление реферальными предложениями
   Версия 3.0 (март 2026)
   - Колонка CTR, сортировка, подсветка неэффективных
   ============================================ */

session_start();
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../services/Database.php';

if (!isset($_SESSION['user_id'])) {
    $_SESSION['redirect_after_auth'] = '/admin/promotions';
    header('Location: /auth/login');
    exit;
}

$db = Database::getInstance();
$user = $db->getUserById($_SESSION['user_id']);
if (!$user || $user['role'] !== 'admin') {
    header('Location: /');
    exit;
}

$csrfToken = generateCsrfToken();

// ==================== АВТОСОЗДАНИЕ ТАБЛИЦЫ ====================
$pdo = $db->getPdo();
$pdo->exec("
    CREATE TABLE IF NOT EXISTS promotions (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        title VARCHAR(255) NOT NULL,
        description TEXT,
        image_url VARCHAR(500),
        link_url VARCHAR(500) NOT NULL,
        city VARCHAR(100),
        is_active BOOLEAN DEFAULT TRUE,
        sort_order INT DEFAULT 0,
        impressions INT DEFAULT 0,
        clicks INT DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_city (city),
        INDEX idx_active (is_active)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
");

// ==================== ОБРАБОТКА POST ====================
$error = null;
$success = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        die('Ошибка CSRF');
    }
    if (!checkRateLimit('admin_promotions_' . $_SESSION['user_id'], 20, 3600)) {
        $error = 'Слишком много запросов. Попробуйте позже.';
    } else {
        $action = $_POST['action'] ?? '';
        $ip = getUserIP();
        if ($action === 'add') {
            $title = trim($_POST['title'] ?? '');
            $description = trim($_POST['description'] ?? '');
            $image_url = trim($_POST['image_url'] ?? '');
            $link_url = trim($_POST['link_url'] ?? '');
            $city = trim($_POST['city'] ?? '') ?: null;
            $is_active = isset($_POST['is_active']) ? 1 : 0;
            $sort_order = (int)($_POST['sort_order'] ?? 0);

            if ($title && $link_url) {
                $db->insert('promotions', [
                    'title' => $title,
                    'description' => $description,
                    'image_url' => $image_url,
                    'link_url' => $link_url,
                    'city' => $city,
                    'is_active' => $is_active,
                    'sort_order' => $sort_order,
                    'created_at' => date('Y-m-d H:i:s')
                ]);
                $success = 'Предложение добавлено';
                $db->insert('security_logs', [
                    'user_id' => $_SESSION['user_id'],
                    'ip_address' => $ip,
                    'event_type' => 'promotion_added',
                    'description' => "Добавлено предложение: $title",
                    'severity' => 'low',
                    'created_at' => date('Y-m-d H:i:s')
                ]);
                cacheDelete('promotions_list');
            } else {
                $error = 'Заполните обязательные поля (название и ссылка)';
            }
        } elseif ($action === 'edit') {
            $id = (int)($_POST['id'] ?? 0);
            if ($id) {
                $title = trim($_POST['title'] ?? '');
                $description = trim($_POST['description'] ?? '');
                $image_url = trim($_POST['image_url'] ?? '');
                $link_url = trim($_POST['link_url'] ?? '');
                $city = trim($_POST['city'] ?? '') ?: null;
                $is_active = isset($_POST['is_active']) ? 1 : 0;
                $sort_order = (int)($_POST['sort_order'] ?? 0);
                if ($title && $link_url) {
                    $old = $db->fetchOne("SELECT title FROM promotions WHERE id = ?", [$id]);
                    $db->update('promotions', [
                        'title' => $title,
                        'description' => $description,
                        'image_url' => $image_url,
                        'link_url' => $link_url,
                        'city' => $city,
                        'is_active' => $is_active,
                        'sort_order' => $sort_order,
                        'updated_at' => date('Y-m-d H:i:s')
                    ], 'id = ?', [$id]);
                    $success = 'Предложение обновлено';
                    $db->insert('security_logs', [
                        'user_id' => $_SESSION['user_id'],
                        'ip_address' => $ip,
                        'event_type' => 'promotion_updated',
                        'description' => "Обновлено предложение #$id: " . ($old ? $old['title'] : '') . " → $title",
                        'severity' => 'low',
                        'created_at' => date('Y-m-d H:i:s')
                    ]);
                    cacheDelete('promotions_list');
                } else {
                    $error = 'Заполните обязательные поля';
                }
            }
        } elseif ($action === 'delete') {
            $id = (int)($_POST['id'] ?? 0);
            if ($id) {
                $old = $db->fetchOne("SELECT title FROM promotions WHERE id = ?", [$id]);
                $db->delete('promotions', 'id = ?', [$id]);
                $success = 'Предложение удалено';
                $db->insert('security_logs', [
                    'user_id' => $_SESSION['user_id'],
                    'ip_address' => $ip,
                    'event_type' => 'promotion_deleted',
                    'description' => "Удалено предложение #$id: " . ($old ? $old['title'] : ''),
                    'severity' => 'low',
                    'created_at' => date('Y-m-d H:i:s')
                ]);
                cacheDelete('promotions_list');
            }
        } elseif ($action === 'disable_low_ctr') {
            // Массовое отключение слабоэффективных (показов > 500 и CTR < 1%)
            $threshold_impressions = 500;
            $threshold_ctr = 1;
            $stmt = $pdo->prepare("
                UPDATE promotions 
                SET is_active = 0 
                WHERE impressions > ? AND (clicks / impressions) * 100 < ? AND is_active = 1
            ");
            $stmt->execute([$threshold_impressions, $threshold_ctr]);
            $affected = $stmt->rowCount();
            $success = "Отключено $affected предложений (CTR < $threshold_ctr% при более $threshold_impressions показов)";
            cacheDelete('promotions_list');
        }
    }
    if ($success) {
        header('Location: /admin/promotions?success=' . urlencode($success));
        exit;
    }
}

// ==================== ПОЛУЧЕНИЕ СПИСКА (с возможностью сортировки) ====================
$sort = $_GET['sort'] ?? 'id';
$order = $_GET['order'] ?? 'ASC';
$allowedSort = ['id', 'title', 'city', 'is_active', 'impressions', 'clicks', 'ctr'];
$sort = in_array($sort, $allowedSort) ? $sort : 'id';
$order = strtoupper($order) === 'DESC' ? 'DESC' : 'ASC';

// Фильтр по городу
$cityFilter = isset($_GET['filter_city']) ? trim($_GET['filter_city']) : '';
$where = [];
$params = [];
if ($cityFilter !== '') {
    $where[] = "city = ?";
    $params[] = $cityFilter;
}
$whereClause = $where ? 'WHERE ' . implode(' AND ', $where) : '';

if ($sort === 'ctr') {
    // Вычисляем CTR на лету, сортировка в PHP
    $sql = "SELECT * FROM promotions $whereClause";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $promotions = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($promotions as &$p) {
        $p['ctr'] = ($p['impressions'] > 0) ? round(($p['clicks'] / $p['impressions']) * 100, 2) : 0;
    }
    usort($promotions, function($a, $b) use ($order) {
        if ($order === 'ASC') return $a['ctr'] <=> $b['ctr'];
        else return $b['ctr'] <=> $a['ctr'];
    });
} else {
    $sql = "SELECT * FROM promotions $whereClause ORDER BY $sort $order";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $promotions = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($promotions as &$p) {
        $p['ctr'] = ($p['impressions'] > 0) ? round(($p['clicks'] / $p['impressions']) * 100, 2) : 0;
    }
}

// Список уникальных городов для фильтра
$cities = $db->fetchAll("SELECT DISTINCT city FROM promotions WHERE city IS NOT NULL AND city != '' ORDER BY city");
$cityOptions = '';
foreach ($cities as $c) {
    $selected = ($cityFilter === $c['city']) ? 'selected' : '';
    $cityOptions .= "<option value=\"" . htmlspecialchars($c['city']) . "\" $selected>" . htmlspecialchars($c['city']) . "</option>";
}

$pageTitle = 'Управление реферальными предложениями — Найдук';
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <meta name="csrf-token" content="<?= $csrfToken ?>">
    <title><?= htmlspecialchars($pageTitle) ?></title>
    <link rel="stylesheet" href="https://cdn.hugeicons.com/font/hgi-stroke-rounded.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/toastify-js/src/toastify.min.css">
    <link rel="stylesheet" href="/css/business.css">
    <link rel="stylesheet" href="/css/premium.css">
    <link rel="stylesheet" href="/css/theme-switch.css">
    <style>
        .admin-container { max-width: 1400px; margin: 0 auto; padding: 40px 20px; }
        .admin-header { margin-bottom: 30px; }
        .card { background: var(--surface); border-radius: var(--radius-xl); padding: 24px; margin-bottom: 30px; border: 1px solid var(--border); }
        .form-group { margin-bottom: 16px; }
        .form-label { display: block; font-weight: 600; margin-bottom: 6px; }
        .form-input, .form-textarea { width: 100%; padding: 10px; border: 1px solid var(--border); border-radius: var(--radius); background: var(--bg); color: var(--text); }
        .form-checkbox { display: flex; align-items: center; gap: 8px; margin: 16px 0; }
        .btn { padding: 8px 16px; border-radius: var(--radius-full); font-weight: 600; cursor: pointer; border: none; }
        .btn-primary { background: var(--primary); color: white; }
        .btn-danger { background: var(--danger); color: white; }
        .btn-secondary { background: var(--bg-secondary); border: 1px solid var(--border); color: var(--text); }
        .btn-small { padding: 4px 12px; font-size: 12px; }
        table { width: 100%; border-collapse: collapse; }
        th, td { padding: 12px; text-align: left; border-bottom: 1px solid var(--border-light); }
        th { background: var(--bg-secondary); cursor: pointer; }
        th a { color: var(--text); text-decoration: none; display: flex; align-items: center; gap: 4px; }
        .sort-icon { font-size: 12px; }
        .badge { display: inline-block; padding: 4px 10px; border-radius: var(--radius-full); font-size: 12px; font-weight: 600; }
        .badge-success { background: rgba(52,199,89,0.1); color: var(--success); }
        .badge-danger { background: rgba(255,59,48,0.1); color: var(--danger); }
        .row-inactive { opacity: 0.6; background: var(--bg-secondary); }
        .row-low-ctr { background: rgba(255,59,48,0.05); }
        .actions { display: flex; gap: 8px; }
        .edit-form, .inline-form { display: inline; }
        .edit-form { display: block; margin-top: 20px; padding-top: 20px; border-top: 1px solid var(--border); }
        .alert { padding: 12px; border-radius: var(--radius); margin-bottom: 20px; }
        .alert-success { background: rgba(52,199,89,0.1); color: var(--success); }
        .alert-error { background: rgba(255,59,48,0.1); color: var(--danger); }
        .filters-bar { display: flex; gap: 16px; margin-bottom: 20px; flex-wrap: wrap; align-items: flex-end; }
        .filter-group { display: flex; gap: 8px; align-items: center; }
        @media (max-width: 768px) {
            table, thead, tbody, th, td, tr { display: block; }
            thead { display: none; }
            tr { margin-bottom: 20px; border: 1px solid var(--border); border-radius: var(--radius); padding: 12px; }
            td { display: flex; justify-content: space-between; align-items: center; padding: 8px 0; border-bottom: none; }
            td::before { content: attr(data-label); font-weight: 600; width: 40%; }
            .actions { justify-content: flex-start; margin-top: 8px; }
        }
    </style>
</head>
<body>
    <div class="admin-container">
        <div class="admin-header">
            <h1>📢 Реферальные предложения</h1>
            <p>Управляйте ссылками, которые будут показываться в поиске. CTR = (клики / показы) × 100%.</p>
        </div>

        <?php if (isset($_GET['success'])): ?>
            <div class="alert alert-success">✅ <?= htmlspecialchars($_GET['success']) ?></div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div class="alert alert-error">⚠️ <?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <!-- Форма добавления -->
        <div class="card">
            <h2>➕ Добавить новое предложение</h2>
            <form method="POST" class="add-form">
                <input type="hidden" name="action" value="add">
                <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
                <div class="form-group">
                    <label class="form-label">Название *</label>
                    <input type="text" name="title" class="form-input" required>
                </div>
                <div class="form-group">
                    <label class="form-label">Описание</label>
                    <textarea name="description" class="form-textarea" rows="2"></textarea>
                </div>
                <div class="form-group">
                    <label class="form-label">URL изображения</label>
                    <input type="text" name="image_url" class="form-input" placeholder="https://...">
                </div>
                <div class="form-group">
                    <label class="form-label">Ссылка (партнёрская) *</label>
                    <input type="text" name="link_url" class="form-input" required>
                </div>
                <div class="form-group">
                    <label class="form-label">Город (оставьте пустым для всех)</label>
                    <input type="text" name="city" class="form-input" placeholder="Например: Москва">
                </div>
                <div class="form-group">
                    <label class="form-label">Порядок сортировки</label>
                    <input type="number" name="sort_order" class="form-input" value="0">
                </div>
                <div class="form-checkbox">
                    <input type="checkbox" name="is_active" id="is_active" checked>
                    <label for="is_active">Активно</label>
                </div>
                <button type="submit" class="btn btn-primary">Добавить</button>
            </form>
        </div>

        <!-- Фильтр и массовые действия -->
        <div class="card">
            <div class="filters-bar">
                <form method="GET" style="display: flex; gap: 12px; align-items: flex-end;">
                    <div class="filter-group">
                        <label>Город:</label>
                        <select name="filter_city">
                            <option value="">Все</option>
                            <?= $cityOptions ?>
                        </select>
                    </div>
                    <button type="submit" class="btn btn-secondary">Фильтр</button>
                    <a href="/admin/promotions" class="btn btn-secondary">Сбросить</a>
                </form>
                <form method="POST" onsubmit="return confirm('Отключить все слабоэффективные предложения (показов > 500 и CTR < 1%)?');">
                    <input type="hidden" name="action" value="disable_low_ctr">
                    <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
                    <button type="submit" class="btn btn-danger">🗑️ Отключить слабые (CTR < 1%)</button>
                </form>
            </div>

            <div class="table-container">
                <table>
                    <thead>
                        <tr>
                            <th><a href="?sort=id&order=<?= $sort == 'id' ? ($order == 'ASC' ? 'DESC' : 'ASC') : 'ASC' ?>&filter_city=<?= urlencode($cityFilter) ?>">ID <span class="sort-icon"><?= $sort == 'id' ? ($order == 'ASC' ? '↑' : '↓') : '' ?></span></a></th>
                            <th><a href="?sort=title&order=<?= $sort == 'title' ? ($order == 'ASC' ? 'DESC' : 'ASC') : 'ASC' ?>&filter_city=<?= urlencode($cityFilter) ?>">Название</a></th>
                            <th><a href="?sort=city&order=<?= $sort == 'city' ? ($order == 'ASC' ? 'DESC' : 'ASC') : 'ASC' ?>&filter_city=<?= urlencode($cityFilter) ?>">Город</a></th>
                            <th><a href="?sort=impressions&order=<?= $sort == 'impressions' ? ($order == 'ASC' ? 'DESC' : 'ASC') : 'ASC' ?>&filter_city=<?= urlencode($cityFilter) ?>">Показы</a></th>
                            <th><a href="?sort=clicks&order=<?= $sort == 'clicks' ? ($order == 'ASC' ? 'DESC' : 'ASC') : 'ASC' ?>&filter_city=<?= urlencode($cityFilter) ?>">Клики</a></th>
                            <th><a href="?sort=ctr&order=<?= $sort == 'ctr' ? ($order == 'ASC' ? 'DESC' : 'ASC') : 'ASC' ?>&filter_city=<?= urlencode($cityFilter) ?>">CTR</a></th>
                            <th>Активно</th>
                            <th>Действия</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($promotions as $p): 
                        $rowClass = '';
                        if (!$p['is_active']) $rowClass = 'row-inactive';
                        elseif ($p['impressions'] > 500 && $p['ctr'] < 1) $rowClass = 'row-low-ctr';
                    ?>
                        <tr class="<?= $rowClass ?>">
                            <td data-label="ID"><?= $p['id'] ?></td>
                            <td data-label="Название"><?= htmlspecialchars($p['title']) ?></td>
                            <td data-label="Город"><?= $p['city'] ?: 'Все' ?></td>
                            <td data-label="Показы"><?= number_format($p['impressions']) ?></td>
                            <td data-label="Клики"><?= number_format($p['clicks']) ?></td>
                            <td data-label="CTR"><?= $p['ctr'] ?>%</td>
                            <td data-label="Активно"><?= $p['is_active'] ? '✅' : '❌' ?></td>
                            <td data-label="Действия" class="actions">
                                <button class="btn btn-primary btn-small" onclick="editPromotion(<?= $p['id'] ?>)">Редактировать</button>
                                <form method="POST" class="inline-form" onsubmit="return confirm('Удалить?')">
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
                                    <input type="hidden" name="id" value="<?= $p['id'] ?>">
                                    <button type="submit" class="btn btn-danger btn-small">Удалить</button>
                                </form>
                            </td>
                        </tr>
                        <tr id="edit-<?= $p['id'] ?>" style="display: none;">
                            <td colspan="8">
                                <form method="POST" class="edit-form">
                                    <input type="hidden" name="action" value="edit">
                                    <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
                                    <input type="hidden" name="id" value="<?= $p['id'] ?>">
                                    <div class="form-group"><label>Название</label><input type="text" name="title" class="form-input" value="<?= htmlspecialchars($p['title']) ?>" required></div>
                                    <div class="form-group"><label>Описание</label><textarea name="description" class="form-textarea" rows="2"><?= htmlspecialchars($p['description']) ?></textarea></div>
                                    <div class="form-group"><label>URL изображения</label><input type="text" name="image_url" class="form-input" value="<?= htmlspecialchars($p['image_url']) ?>"></div>
                                    <div class="form-group"><label>Ссылка</label><input type="text" name="link_url" class="form-input" value="<?= htmlspecialchars($p['link_url']) ?>" required></div>
                                    <div class="form-group"><label>Город</label><input type="text" name="city" class="form-input" value="<?= htmlspecialchars($p['city']) ?>"></div>
                                    <div class="form-group"><label>Порядок</label><input type="number" name="sort_order" class="form-input" value="<?= $p['sort_order'] ?>"></div>
                                    <div class="form-checkbox"><input type="checkbox" name="is_active" id="active_<?= $p['id'] ?>" <?= $p['is_active'] ? 'checked' : '' ?>><label for="active_<?= $p['id'] ?>">Активно</label></div>
                                    <div class="form-actions" style="display: flex; gap: 8px;">
                                        <button type="submit" class="btn btn-primary">Сохранить</button>
                                        <button type="button" class="btn btn-secondary" onclick="cancelEdit(<?= $p['id'] ?>)">Отмена</button>
                                    </div>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <script>
        function editPromotion(id) {
            document.getElementById('edit-' + id).style.display = 'table-row';
        }
        function cancelEdit(id) {
            document.getElementById('edit-' + id).style.display = 'none';
        }
    </script>
</body>
</html>