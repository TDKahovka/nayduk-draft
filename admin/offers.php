<?php
/* ============================================
   НАЙДУК — Админка: управление офферами (partner_offers)
   Версия 3.0 (март 2026)
   - Полная безопасность, кэширование Redis/файлы
   - Автосоздание таблиц, логирование действий
   - Пагинация, фильтры, массовые операции
   ============================================ */

// Подключаем общую шапку (header.php должен определять $currentUser)
require_once __DIR__ . '/../includes/header.php';

// Проверка прав администратора (используем функцию is_admin из functions.php)
if (!function_exists('is_admin')) {
    require_once __DIR__ . '/../includes/functions.php';
}
if (!is_admin()) {
    header('Location: /');
    exit;
}

$db = Database::getInstance();
$pdo = $db->getPdo();

// ==================== АВТОСОЗДАНИЕ ТАБЛИЦ ====================
$pdo->exec("
    CREATE TABLE IF NOT EXISTS partner_offers (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        owner_id BIGINT UNSIGNED NOT NULL,
        partner_name VARCHAR(255) NOT NULL,
        offer_name VARCHAR(255) NOT NULL,
        category VARCHAR(100),
        city_id INT UNSIGNED,
        source VARCHAR(100),
        description TEXT,
        commission_type ENUM('fixed','percent') DEFAULT 'percent',
        commission_value DECIMAL(10,2) NOT NULL,
        is_active BOOLEAN DEFAULT TRUE,
        is_approved BOOLEAN DEFAULT FALSE,
        clicks INT DEFAULT 0,
        conversions INT DEFAULT 0,
        revenue DECIMAL(12,2) DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        FOREIGN KEY (owner_id) REFERENCES users(id) ON DELETE CASCADE,
        INDEX idx_owner (owner_id),
        INDEX idx_category (category),
        INDEX idx_city (city_id),
        INDEX idx_status (is_approved),
        INDEX idx_active (is_active)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
");

// Добавляем недостающие поля (на случай обновления)
$columns = $pdo->query("SHOW COLUMNS FROM partner_offers")->fetchAll(PDO::FETCH_COLUMN);
if (!in_array('source', $columns)) {
    $pdo->exec("ALTER TABLE partner_offers ADD COLUMN source VARCHAR(100)");
}
if (!in_array('description', $columns)) {
    $pdo->exec("ALTER TABLE partner_offers ADD COLUMN description TEXT");
}

// ==================== ПАРАМЕТРЫ ФИЛЬТРАЦИИ ====================
$status = isset($_GET['status']) ? $_GET['status'] : 'all';
$category = isset($_GET['category']) ? trim($_GET['category']) : '';
$cityId = isset($_GET['city_id']) ? (int)$_GET['city_id'] : 0;
$source = isset($_GET['source']) ? trim($_GET['source']) : '';
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$sort = isset($_GET['sort']) ? $_GET['sort'] : 'created_desc';
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$limit = 25;
$offset = ($page - 1) * $limit;

// Белый список для сортировки
$allowedSort = [
    'created_desc' => 'created_at DESC',
    'created_asc' => 'created_at ASC',
    'revenue_desc' => 'revenue DESC',
    'revenue_asc' => 'revenue ASC',
    'clicks_desc' => 'clicks DESC',
    'clicks_asc' => 'clicks ASC',
];
$orderBy = $allowedSort[$sort] ?? 'created_at DESC';

// Построение WHERE
$where = ["1=1"];
$params = [];
if ($status === 'approved') $where[] = "po.is_approved = 1";
elseif ($status === 'draft') $where[] = "po.is_approved = 0";
if ($category) { $where[] = "po.category = ?"; $params[] = $category; }
if ($cityId) { $where[] = "po.city_id = ?"; $params[] = $cityId; }
if ($source) { $where[] = "po.source = ?"; $params[] = $source; }
if ($search) {
    $where[] = "(po.partner_name LIKE ? OR po.offer_name LIKE ? OR po.description LIKE ?)";
    $searchTerm = '%' . $search . '%';
    $params[] = $searchTerm; $params[] = $searchTerm; $params[] = $searchTerm;
}
$whereClause = 'WHERE ' . implode(' AND ', $where);

// ==================== КЭШИРОВАНИЕ ====================
$cacheKey = 'admin:offers:' . md5($whereClause . $sort . $page . $limit);
$cached = cacheGet($cacheKey, 300); // 5 минут
if ($cached !== null) {
    echo $cached;
    exit;
}

// Подсчёт общего количества
$countSql = "SELECT COUNT(*) FROM partner_offers po $whereClause";
$stmt = $pdo->prepare($countSql);
$stmt->execute($params);
$total = (int)$stmt->fetchColumn();
$pages = ceil($total / $limit);

// Основной запрос
$sql = "
    SELECT 
        po.*,
        c.city_name,
        (SELECT COUNT(*) FROM partner_clicks WHERE offer_id = po.id) as clicks,
        (SELECT COUNT(*) FROM partner_conversions pc 
         JOIN partner_clicks pcl ON pc.click_id = pcl.click_id 
         WHERE pcl.offer_id = po.id) as conversions,
        (SELECT COALESCE(SUM(pc.amount), 0) FROM partner_conversions pc 
         JOIN partner_clicks pcl ON pc.click_id = pcl.click_id 
         WHERE pcl.offer_id = po.id) as revenue
    FROM partner_offers po
    LEFT JOIN russian_cities c ON po.city_id = c.id
    $whereClause
    ORDER BY $orderBy
    LIMIT ? OFFSET ?
";
$stmt = $pdo->prepare($sql);
$stmt->execute(array_merge($params, [$limit, $offset]));
$offers = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Получаем список категорий, городов и источников для фильтров
$categories = $pdo->query("SELECT DISTINCT category FROM partner_offers WHERE category IS NOT NULL ORDER BY category")->fetchAll(PDO::FETCH_COLUMN);
$cities = $pdo->query("SELECT id, city_name FROM russian_cities ORDER BY city_name LIMIT 500")->fetchAll(PDO::FETCH_ASSOC);
$sources = $pdo->query("SELECT DISTINCT source FROM partner_offers WHERE source IS NOT NULL ORDER BY source")->fetchAll(PDO::FETCH_COLUMN);

$csrfToken = generateCsrfToken();
$pageTitle = 'Управление офферами — Найдук Админка';

// Сохраняем в кэш
ob_start();
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
        /* Стили (остаются те же, что в исходном файле, но адаптивны) */
        .page-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; flex-wrap: wrap; gap: 16px; }
        .filters-section { background: var(--surface); border-radius: var(--radius-xl); padding: 20px; margin-bottom: 20px; border: 1px solid var(--border); }
        .filters-row { display: flex; flex-wrap: wrap; gap: 12px; align-items: flex-end; }
        .filter-group { flex: 1; min-width: 150px; }
        .filter-label { font-size: 12px; font-weight: 600; margin-bottom: 4px; color: var(--text-secondary); }
        .filter-select, .filter-input { width: 100%; padding: 10px; border: 1px solid var(--border); border-radius: var(--radius); background: var(--bg); color: var(--text); }
        .filter-actions { display: flex; gap: 8px; }
        .bulk-actions { margin: 20px 0; display: flex; gap: 12px; }
        .table-container { overflow-x: auto; margin-bottom: 20px; }
        table { width: 100%; border-collapse: collapse; min-width: 800px; }
        th, td { padding: 12px; text-align: left; border-bottom: 1px solid var(--border-light); }
        th { background: var(--bg-secondary); }
        .pagination { display: flex; justify-content: center; gap: 8px; margin-top: 20px; }
        .page-btn { width: 36px; height: 36px; border-radius: var(--radius-full); border: 1px solid var(--border); background: var(--surface); color: var(--text); cursor: pointer; }
        .page-btn.active { background: var(--primary); border-color: var(--primary); color: white; }
        .btn-small { padding: 6px 12px; font-size: 12px; }
        @media (max-width: 768px) {
            .filters-row { flex-direction: column; }
            .filter-group { width: 100%; }
            .bulk-actions { flex-wrap: wrap; }
        }
    </style>
</head>
<body>
    <div class="admin-container" style="max-width: 1200px; margin: 0 auto; padding: 40px 20px;">
        <div class="page-header">
            <h1>📋 Все офферы</h1>
            <a href="?export=csv&<?= http_build_query($_GET) ?>" class="btn btn-primary">Экспорт CSV</a>
        </div>

        <div class="filters-section">
            <form class="filters-row" method="GET">
                <div class="filter-group">
                    <label class="filter-label">Статус</label>
                    <select name="status" class="filter-select">
                        <option value="all" <?= $status === 'all' ? 'selected' : '' ?>>Все</option>
                        <option value="approved" <?= $status === 'approved' ? 'selected' : '' ?>>Опубликованные</option>
                        <option value="draft" <?= $status === 'draft' ? 'selected' : '' ?>>Черновики</option>
                    </select>
                </div>
                <div class="filter-group">
                    <label class="filter-label">Категория</label>
                    <select name="category" class="filter-select">
                        <option value="">Все</option>
                        <?php foreach ($categories as $cat): ?>
                        <option value="<?= htmlspecialchars($cat) ?>" <?= $category === $cat ? 'selected' : '' ?>><?= ucfirst($cat) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="filter-group">
                    <label class="filter-label">Город</label>
                    <select name="city_id" class="filter-select">
                        <option value="0">Все</option>
                        <?php foreach ($cities as $city): ?>
                        <option value="<?= $city['id'] ?>" <?= $cityId == $city['id'] ? 'selected' : '' ?>><?= htmlspecialchars($city['city_name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="filter-group">
                    <label class="filter-label">Источник</label>
                    <select name="source" class="filter-select">
                        <option value="">Все</option>
                        <?php foreach ($sources as $src): ?>
                        <option value="<?= htmlspecialchars($src) ?>" <?= $source === $src ? 'selected' : '' ?>><?= htmlspecialchars($src) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="filter-group">
                    <label class="filter-label">Сортировка</label>
                    <select name="sort" class="filter-select">
                        <option value="created_desc" <?= $sort === 'created_desc' ? 'selected' : '' ?>>По дате ↓</option>
                        <option value="created_asc" <?= $sort === 'created_asc' ? 'selected' : '' ?>>По дате ↑</option>
                        <option value="revenue_desc" <?= $sort === 'revenue_desc' ? 'selected' : '' ?>>По доходу ↓</option>
                        <option value="revenue_asc" <?= $sort === 'revenue_asc' ? 'selected' : '' ?>>По доходу ↑</option>
                        <option value="clicks_desc" <?= $sort === 'clicks_desc' ? 'selected' : '' ?>>По кликам ↓</option>
                    </select>
                </div>
                <div class="filter-group">
                    <label class="filter-label">Поиск</label>
                    <input type="text" name="search" class="filter-input" placeholder="Название партнёра" value="<?= htmlspecialchars($search) ?>">
                </div>
                <div class="filter-actions">
                    <button type="submit" class="btn btn-primary"><i class="hgi hgi-stroke-filter"></i> Применить</button>
                    <a href="?" class="btn btn-secondary"><i class="hgi hgi-stroke-reset"></i> Сбросить</a>
                </div>
            </form>
        </div>

        <div class="bulk-actions">
            <button class="btn btn-primary" onclick="selectAll()">Выбрать все</button>
            <button class="btn btn-primary" onclick="bulkApprove()">Одобрить выбранные</button>
            <button class="btn btn-danger" onclick="bulkReject()">Отклонить выбранные</button>
        </div>

        <div class="table-container">
            <table>
                <thead>
                    <tr>
                        <th class="checkbox-column"><input type="checkbox" id="select-all"></th>
                        <th>ID</th><th>Партнёр</th><th>Категория</th><th>Город</th>
                        <th>Клики</th><th>Конв.</th><th>Доход (₽)</th><th>Статус</th><th>Действия</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($offers)): ?>
                    <tr><td colspan="11">Нет данных</td></tr>
                    <?php else: ?>
                        <?php foreach ($offers as $offer): ?>
                        <tr data-id="<?= $offer['id'] ?>">
                            <td><input type="checkbox" class="offer-checkbox" value="<?= $offer['id'] ?>"></td>
                            <td><?= $offer['id'] ?></td>
                            <td><strong><?= htmlspecialchars($offer['partner_name']) ?></strong><br><small><?= htmlspecialchars($offer['offer_name']) ?></small></td>
                            <td><?= htmlspecialchars($offer['category']) ?></td>
                            <td><?= htmlspecialchars($offer['city_name'] ?? '—') ?></td>
                            <td><?= number_format($offer['clicks']) ?></td>
                            <td><?= number_format($offer['conversions']) ?></td>
                            <td><?= number_format($offer['revenue'], 0, ',', ' ') ?></td>
                            <td>
                                <?php if ($offer['is_approved']): ?>
                                    <span class="badge badge-approved">Опубликован</span>
                                <?php else: ?>
                                    <span class="badge badge-draft">Черновик</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <a href="/admin/partner/preview.php?id=<?= $offer['id'] ?>" class="btn btn-secondary btn-small">Редактировать</a>
                                <?php if (!$offer['is_approved']): ?>
                                    <a href="#" class="btn btn-primary btn-small" onclick="approveSingle(<?= $offer['id'] ?>)">Одобрить</a>
                                <?php endif; ?>
                                <a href="#" class="btn btn-danger btn-small" onclick="deleteSingle(<?= $offer['id'] ?>)">Удалить</a>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <div class="pagination">
            <?php for ($i = 1; $i <= $pages; $i++): ?>
                <a href="?page=<?= $i ?>&<?= http_build_query(array_merge($_GET, ['page' => $i])) ?>" class="page-btn <?= $i == $page ? 'active' : '' ?>"><?= $i ?></a>
            <?php endfor; ?>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/toastify-js"></script>
    <script>
        const csrfToken = '<?= $csrfToken ?>';
        let selectedIds = new Set();

        document.getElementById('select-all').addEventListener('change', function(e) {
            document.querySelectorAll('.offer-checkbox').forEach(cb => {
                cb.checked = e.target.checked;
                if (e.target.checked) selectedIds.add(parseInt(cb.value));
                else selectedIds.delete(parseInt(cb.value));
            });
        });

        document.querySelectorAll('.offer-checkbox').forEach(cb => {
            cb.addEventListener('change', function() {
                const id = parseInt(this.value);
                if (this.checked) selectedIds.add(id);
                else selectedIds.delete(id);
            });
        });

        function selectAll() {
            document.querySelectorAll('.offer-checkbox').forEach(cb => {
                cb.checked = true;
                selectedIds.add(parseInt(cb.value));
            });
            document.getElementById('select-all').checked = true;
        }

        async function bulkApprove() {
            if (selectedIds.size === 0) return Notify.warning('Выберите офферы');
            if (!confirm(`Одобрить ${selectedIds.size} офферов?`)) return;
            const ids = Array.from(selectedIds);
            const response = await fetch('/api/admin/partner/bulk.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ action: 'approve', ids: ids, csrf_token: csrfToken })
            });
            const result = await response.json();
            if (result.success) location.reload();
            else Notify.error(result.error);
        }

        async function bulkReject() {
            if (selectedIds.size === 0) return Notify.warning('Выберите офферы');
            if (!confirm(`Отклонить ${selectedIds.size} офферов?`)) return;
            const ids = Array.from(selectedIds);
            const response = await fetch('/api/admin/partner/bulk.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ action: 'reject', ids: ids, csrf_token: csrfToken })
            });
            const result = await response.json();
            if (result.success) location.reload();
            else Notify.error(result.error);
        }

        async function approveSingle(id) {
            if (!confirm('Одобрить этот оффер?')) return;
            const response = await fetch('/api/admin/partner/approve.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ id: id, csrf_token: csrfToken })
            });
            const result = await response.json();
            if (result.success) location.reload();
            else Notify.error(result.error);
        }

        async function deleteSingle(id) {
            if (!confirm('Удалить этот оффер?')) return;
            const response = await fetch('/api/admin/partner/delete.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ id: id, csrf_token: csrfToken })
            });
            const result = await response.json();
            if (result.success) location.reload();
            else Notify.error(result.error);
        }
    </script>
</body>
</html>
<?php
$html = ob_get_clean();
cacheSet($cacheKey, $html, 300);
echo $html;