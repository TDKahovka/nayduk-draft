<?php
/**
 * НАЙДУК — Список аукционов
 * Версия 2.1 (апрель 2026) — исправлены пути
 */
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../services/Database.php';

$db = Database::getInstance();

// Фильтры
$type = isset($_GET['type']) ? (int)$_GET['type'] : 0;
$category = isset($_GET['category']) ? (int)$_GET['category'] : 0;
$city = isset($_GET['city']) ? trim($_GET['city']) : '';
$minPrice = isset($_GET['min_price']) ? (float)$_GET['min_price'] : 0;
$maxPrice = isset($_GET['max_price']) ? (float)$_GET['max_price'] : 0;
$sort = isset($_GET['sort']) ? $_GET['sort'] : 'ending';
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$limit = 20;
$offset = ($page - 1) * $limit;

// Базовый запрос
$where = ["auction_type IN (1,2)", "auction_status = 'active'", "listing_fee_paid = 1"];
$params = [];

if ($type) {
    $where[] = "auction_type = ?";
    $params[] = $type;
}
if ($category) {
    $where[] = "category_id = ?";
    $params[] = $category;
}
if ($city) {
    $where[] = "city LIKE ?";
    $params[] = "%$city%";
}
if ($minPrice) {
    if ($type == 1) {
        $where[] = "start_bid >= ?";
        $params[] = $minPrice;
    } else {
        $where[] = "price >= ?";
        $params[] = $minPrice;
    }
}
if ($maxPrice) {
    if ($type == 1) {
        $where[] = "start_bid <= ?";
        $params[] = $maxPrice;
    } else {
        $where[] = "price <= ?";
        $params[] = $maxPrice;
    }
}

$whereSql = "WHERE " . implode(" AND ", $where);

// Сортировка
switch ($sort) {
    case 'new':
        $order = "ORDER BY created_at DESC";
        break;
    case 'price_asc':
        $order = "ORDER BY IF(auction_type=1, start_bid, price) ASC";
        break;
    case 'price_desc':
        $order = "ORDER BY IF(auction_type=1, start_bid, price) DESC";
        break;
    default:
        $order = "ORDER BY auction_end_at ASC";
}

// Подсчёт общего количества
$countSql = "SELECT COUNT(*) FROM listings $whereSql";
$total = $db->fetchCount($countSql, $params);
$totalPages = ceil($total / $limit);

// Получение списка
$sql = "SELECT l.id, l.title, l.start_bid, l.price, l.auction_type, l.auction_end_at, l.hidden_bids,
               (SELECT MAX(bid_price) FROM auction_bids WHERE listing_id = l.id) AS current_bid,
               (SELECT COUNT(*) FROM auction_bids WHERE listing_id = l.id) AS bids_count,
               (SELECT photo_url FROM listing_photos WHERE listing_id = l.id ORDER BY sort_order LIMIT 1) AS photo
        FROM listings l
        $whereSql
        $order
        LIMIT $offset, $limit";
$auctions = $db->fetchAll($sql, $params);

$pageTitle = 'Аукционы — Найдук';
$pageDescription = 'Каталог активных аукционов. Прямые и обратные торги на любые товары и услуги.';
require_once __DIR__ . '/../../includes/header.php';
?>
<style>
    .auctions-container { max-width: 1280px; margin: 0 auto; padding: 20px; }
    .auctions-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; flex-wrap: wrap; gap: 15px; }
    .create-btn { background: var(--primary); color: white; padding: 10px 20px; border-radius: 9999px; text-decoration: none; font-weight: 600; transition: all 0.2s; display: inline-block; }
    .create-btn:hover { background: var(--primary-dark); transform: translateY(-2px); }
    .filters { background: var(--surface); padding: 20px; border-radius: var(--radius-xl); margin-bottom: 30px; display: flex; flex-wrap: wrap; gap: 15px; align-items: flex-end; }
    .filter-group { flex: 1; min-width: 150px; }
    .filter-group label { display: block; font-size: 12px; margin-bottom: 4px; color: var(--text-secondary); }
    .filter-group input, .filter-group select { width: 100%; padding: 8px 12px; border: 1px solid var(--border); border-radius: var(--radius); background: var(--bg); color: var(--text); }
    .filter-group button { width: 100%; padding: 8px; background: var(--primary); color: white; border: none; border-radius: var(--radius); cursor: pointer; }
    .auction-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); gap: 20px; }
    .auction-card { background: var(--surface); border: 1px solid var(--border); border-radius: var(--radius-lg); overflow: hidden; transition: transform 0.2s; text-decoration: none; color: inherit; display: block; }
    .auction-card:hover { transform: translateY(-4px); box-shadow: var(--shadow-md); }
    .auction-img { height: 180px; background: var(--bg-secondary); display: flex; align-items: center; justify-content: center; font-size: 48px; color: var(--text-secondary); overflow: hidden; }
    .auction-img img { width: 100%; height: 100%; object-fit: cover; }
    .auction-info { padding: 12px; }
    .auction-title { font-weight: 600; margin-bottom: 8px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .auction-price { font-size: 20px; font-weight: 700; color: var(--primary); }
    .auction-meta { display: flex; justify-content: space-between; font-size: 12px; color: var(--text-secondary); margin-top: 8px; }
    .timer { font-weight: 600; }
    .timer-urgent { color: var(--danger); }
    .hidden-badge { background: rgba(0,0,0,0.1); border-radius: 4px; padding: 2px 6px; font-size: 10px; margin-left: 8px; }
    .pagination { display: flex; justify-content: center; gap: 8px; margin-top: 30px; flex-wrap: wrap; }
    .page-item { padding: 8px 12px; border: 1px solid var(--border); border-radius: var(--radius); background: var(--surface); color: var(--text); text-decoration: none; }
    .page-item.active { background: var(--primary); color: white; border-color: var(--primary); }
    .empty-state { text-align: center; padding: 60px; color: var(--text-secondary); }
</style>

<div class="auctions-container">
    <div class="auctions-header">
        <h1>Аукционы</h1>
        <a href="/auctions/create.php" class="create-btn">➕ Создать аукцион</a>
    </div>

    <form method="GET" class="filters">
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
                <?php
                $cats = $db->fetchAll("SELECT id, name FROM listing_categories WHERE is_active = 1 ORDER BY name");
                foreach ($cats as $cat) {
                    $selected = ($category == $cat['id']) ? 'selected' : '';
                    echo "<option value='{$cat['id']}' $selected>" . htmlspecialchars($cat['name']) . "</option>";
                }
                ?>
            </select>
        </div>
        <div class="filter-group">
            <label>Город</label>
            <input type="text" name="city" value="<?= htmlspecialchars($city) ?>" placeholder="Город">
        </div>
        <div class="filter-group">
            <label>Цена от</label>
            <input type="number" name="min_price" value="<?= $minPrice ?: '' ?>" step="1">
        </div>
        <div class="filter-group">
            <label>Цена до</label>
            <input type="number" name="max_price" value="<?= $maxPrice ?: '' ?>" step="1">
        </div>
        <div class="filter-group">
            <label>Сортировка</label>
            <select name="sort">
                <option value="ending" <?= $sort == 'ending' ? 'selected' : '' ?>>Скоро завершатся</option>
                <option value="new" <?= $sort == 'new' ? 'selected' : '' ?>>Новые</option>
                <option value="price_asc" <?= $sort == 'price_asc' ? 'selected' : '' ?>>Цена (сначала дешёвые)</option>
                <option value="price_desc" <?= $sort == 'price_desc' ? 'selected' : '' ?>>Цена (сначала дорогие)</option>
            </select>
        </div>
        <div class="filter-group">
            <button type="submit">Применить</button>
        </div>
    </form>

    <div class="auction-grid">
        <?php if (empty($auctions)): ?>
            <div class="empty-state">Нет активных аукционов. Будьте первым!</div>
        <?php else: ?>
            <?php foreach ($auctions as $a): 
                $isDirect = ($a['auction_type'] == 1);
                $current = $isDirect ? ($a['current_bid'] ?? $a['start_bid']) : $a['price'];
                $timeLeft = strtotime($a['auction_end_at']) - time();
                $timerClass = ($timeLeft < 3600) ? 'timer-urgent' : '';
                $icon = $isDirect ? '🔥' : '🔄';
                ?>
                <a href="/auctions/<?= $a['id'] ?>" class="auction-card">
                    <div class="auction-img">
                        <?php if ($a['photo']): ?>
                            <img src="<?= htmlspecialchars($a['photo']) ?>" alt="<?= htmlspecialchars($a['title']) ?>">
                        <?php else: ?>
                            <span><?= $icon ?></span>
                        <?php endif; ?>
                    </div>
                    <div class="auction-info">
                        <div class="auction-title">
                            <?= htmlspecialchars(mb_substr($a['title'], 0, 50)) ?>
                            <?php if ($isDirect && $a['hidden_bids']): ?>
                                <span class="hidden-badge">скрыто</span>
                            <?php endif; ?>
                        </div>
                        <div class="auction-price"><?= number_format($current, 0) ?> ₽</div>
                        <div class="auction-meta">
                            <span>📊 <?= $a['bids_count'] ?> ставок</span>
                            <span class="timer <?= $timerClass ?>" data-end="<?= strtotime($a['auction_end_at']) ?>">
                                <?= formatTimeLeft($timeLeft) ?>
                            </span>
                        </div>
                    </div>
                </a>
            <?php endforeach; ?>
        <?php endif; ?>
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

<script>
    function updateTimers() {
        document.querySelectorAll('.timer').forEach(el => {
            const end = parseInt(el.dataset.end) * 1000;
            const now = Date.now();
            const diff = end - now;
            if (diff <= 0) {
                el.textContent = 'Завершён';
            } else {
                const hours = Math.floor(diff / 3600000);
                const minutes = Math.floor((diff % 3600000) / 60000);
                const seconds = Math.floor((diff % 60000) / 1000);
                el.textContent = `${hours}ч ${minutes}м ${seconds}с`;
            }
        });
    }
    setInterval(updateTimers, 1000);
    updateTimers();
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>