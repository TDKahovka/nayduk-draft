<?php
/* ============================================
   НАЙДУК — Каталог офферов (публичная страница)
   Версия 2.0 (март 2026)
   - Использует новый layout
   - Сохраняет всю логику из старой версии
   ============================================ */

// Подключаем ядро
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../services/Database.php';

$db = Database::getInstance();
$pdo = $db->getConnection();

// Параметры фильтрации (те же, что и раньше)
$cityId = isset($_GET['city_id']) ? (int)$_GET['city_id'] : 0;
$category = isset($_GET['category']) ? trim($_GET['category']) : '';
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$limit = 20;
$offset = ($page - 1) * $limit;

// Построение WHERE-условий
$where = ["po.is_active = 1", "po.is_approved = 1"];
$params = [];

if ($cityId) {
    $where[] = "po.city_id = ?";
    $params[] = $cityId;
}
if ($category) {
    $where[] = "po.category = ?";
    $params[] = $category;
}
if ($search) {
    $where[] = "(po.partner_name ILIKE ? OR po.offer_name ILIKE ? OR po.description ILIKE ?)";
    $searchTerm = '%' . $search . '%';
    $params[] = $searchTerm;
    $params[] = $searchTerm;
    $params[] = $searchTerm;
}
$whereClause = 'WHERE ' . implode(' AND ', $where);

// Подсчёт общего количества
$countSql = "SELECT COUNT(*) FROM partner_offers po $whereClause";
$stmt = $pdo->prepare($countSql);
$stmt->execute($params);
$total = $stmt->fetchColumn();
$pages = ceil($total / $limit);

// Получение офферов
$sql = "
    SELECT 
        po.id,
        po.category,
        po.partner_name,
        po.offer_name,
        po.description,
        po.commission_type,
        po.commission_value,
        po.currency,
        po.is_smartlink,
        po.city_id,
        po.address,
        po.phone,
        po.website,
        po.logo_url,
        po.seo_url,
        c.city_name,
        c.region_name
    FROM partner_offers po
    LEFT JOIN russian_cities c ON po.city_id = c.id
    $whereClause
    ORDER BY po.priority DESC, po.created_at DESC
    LIMIT ? OFFSET ?
";
$stmt = $pdo->prepare($sql);
$stmt->execute(array_merge($params, [$limit, $offset]));
$offers = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Получаем списки для фильтров
$categories = $pdo->query("
    SELECT DISTINCT category, COUNT(*) as count
    FROM partner_offers
    WHERE is_active = 1 AND is_approved = 1
    GROUP BY category
    ORDER BY count DESC
")->fetchAll(PDO::FETCH_ASSOC);

$cities = $pdo->query("
    SELECT c.id, c.city_name, c.region_name, COUNT(po.id) as offers_count
    FROM russian_cities c
    JOIN partner_offers po ON c.id = po.city_id
    WHERE po.is_active = 1 AND po.is_approved = 1
    GROUP BY c.id, c.city_name, c.region_name
    HAVING offers_count > 0
    ORDER BY offers_count DESC, c.city_name ASC
    LIMIT 100
")->fetchAll(PDO::FETCH_ASSOC);

// Буферизация HTML-кода
ob_start();
?>
<div class="page-header">
    <h1>🎯 Все партнёрские предложения</h1>
    <p>Тысячи выгодных офферов: от кредитных карт до доставки еды. Выбирайте по категориям и городам.</p>
</div>

<!-- Фильтры -->
<div class="filters-section">
    <form class="filters-form" method="GET" action="">
        <div class="filter-group">
            <label class="filter-label">Категория</label>
            <select name="category" class="filter-select">
                <option value="">Все категории</option>
                <?php 
                $categoryIcons = [
                    'finance' => '💰', 'taxi' => '🚖', 'food_delivery' => '🍕',
                    'pharmacy' => '💊', 'clinic' => '🏥', 'ecom' => '🛍️',
                    'telecom' => '📱', 'insurance' => '🛡️', 'b2b' => '🤝'
                ];
                foreach ($categories as $cat): 
                    $icon = $categoryIcons[$cat['category']] ?? '📌';
                ?>
                <option value="<?= htmlspecialchars($cat['category']) ?>" <?= $category === $cat['category'] ? 'selected' : '' ?>>
                    <?= $icon ?> <?= ucfirst($cat['category']) ?> (<?= $cat['count'] ?>)
                </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="filter-group">
            <label class="filter-label">Город</label>
            <select name="city_id" class="filter-select">
                <option value="0">Все города</option>
                <?php foreach ($cities as $city): ?>
                <option value="<?= $city['id'] ?>" <?= $cityId == $city['id'] ? 'selected' : '' ?>>
                    <?= htmlspecialchars($city['city_name']) ?> (<?= $city['offers_count'] ?>)
                </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="filter-group">
            <label class="filter-label">Поиск</label>
            <input type="text" name="search" class="filter-input" placeholder="Название или описание" value="<?= htmlspecialchars($search) ?>">
        </div>
        <div class="filter-actions">
            <button type="submit" class="btn btn-primary"><i class="hgi hgi-stroke-filter"></i> Применить</button>
            <a href="?" class="btn btn-secondary"><i class="hgi hgi-stroke-reset"></i> Сбросить</a>
        </div>
    </form>

    <div class="categories-cloud">
        <a href="?" class="category-tag <?= !$category ? 'active' : '' ?>">Все</a>
        <?php foreach ($categories as $cat): ?>
        <a href="?category=<?= urlencode($cat['category']) ?>" class="category-tag <?= $category === $cat['category'] ? 'active' : '' ?>">
            <?= $categoryIcons[$cat['category']] ?? '📌' ?> <?= ucfirst($cat['category']) ?>
        </a>
        <?php endforeach; ?>
    </div>
</div>

<!-- Список офферов -->
<?php if (empty($offers)): ?>
    <div style="text-align: center; padding: 60px 20px; background: var(--surface); border-radius: var(--radius-2xl);">
        <i class="hgi hgi-stroke-inbox" style="font-size: 64px; color: var(--text-secondary);"></i>
        <p style="margin-top: 20px; font-size: 18px; color: var(--text-secondary);">По вашему запросу ничего не найдено.</p>
        <a href="?" class="btn btn-primary" style="margin-top: 20px;">Сбросить фильтры</a>
    </div>
<?php else: ?>
    <div class="offers-grid">
        <?php foreach ($offers as $offer): 
            $commission = '';
            if ($offer['commission_type'] === 'fixed') {
                $commission = number_format($offer['commission_value'], 0, ',', ' ') . ' ' . $offer['currency'];
            } elseif ($offer['commission_type'] === 'percent') {
                $commission = $offer['commission_value'] . '%';
            } elseif ($offer['commission_type'] === 'cpa') {
                $commission = 'до ' . number_format($offer['commission_value'], 0, ',', ' ') . ' ' . $offer['currency'];
            } else {
                $commission = 'Договорная';
            }
            
            $categoryIcon = $categoryIcons[$offer['category']] ?? '📌';
            $categoryName = ucfirst($offer['category']);
        ?>
        <div class="offer-card">
            <div class="offer-image">
                <?php if (!empty($offer['logo_url'])): ?>
                    <img src="<?= htmlspecialchars($offer['logo_url']) ?>" alt="<?= htmlspecialchars($offer['partner_name']) ?>" loading="lazy">
                <?php else: ?>
                    <div class="fallback-icon"><?= $categoryIcon ?></div>
                <?php endif; ?>
            </div>
            <div class="offer-content">
                <span class="offer-category"><?= $categoryIcon ?> <?= $categoryName ?></span>
                <h3 class="offer-title"><?= htmlspecialchars($offer['partner_name']) ?></h3>
                <div class="offer-partner"><?= htmlspecialchars($offer['offer_name']) ?></div>
                
                <div class="offer-meta">
                    <?php if ($offer['city_name']): ?>
                    <span><i class="hgi hgi-stroke-location-01"></i> <?= htmlspecialchars($offer['city_name']) ?></span>
                    <?php endif; ?>
                    <?php if ($offer['is_smartlink']): ?>
                    <span><i class="hgi hgi-stroke-ai-magic"></i> Смартлинк</span>
                    <?php endif; ?>
                </div>
                
                <p class="offer-description"><?= htmlspecialchars($offer['description'] ?? 'Подробности по ссылке') ?></p>
                
                <div class="offer-footer">
                    <div class="offer-commission">
                        <?= $commission ?>
                        <?php if ($offer['commission_type'] === 'recurring'): ?>
                        <small>/мес</small>
                        <?php endif; ?>
                    </div>
                    <a href="/go/<?= $offer['id'] ?>" class="offer-link" target="_blank" rel="nofollow sponsored">
                        <i class="hgi hgi-stroke-link-01"></i> Перейти
                    </a>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>

    <!-- Пагинация -->
    <?php if ($pages > 1): ?>
        <div class="pagination">
            <?php if ($page > 1): ?>
                <a href="?<?= http_build_query(array_merge($_GET, ['page' => $page - 1])) ?>" class="page-btn">‹</a>
            <?php endif; ?>
            
            <?php
            $start = max(1, $page - 2);
            $end = min($pages, $page + 2);
            for ($i = $start; $i <= $end; $i++):
            ?>
                <a href="?<?= http_build_query(array_merge($_GET, ['page' => $i])) ?>" class="page-btn <?= $i == $page ? 'active' : '' ?>"><?= $i ?></a>
            <?php endfor; ?>
            
            <?php if ($page < $pages): ?>
                <a href="?<?= http_build_query(array_merge($_GET, ['page' => $page + 1])) ?>" class="page-btn">›</a>
            <?php endif; ?>
        </div>
    <?php endif; ?>
<?php endif; ?>

<?php
$content = ob_get_clean();

// Заголовок и описание для SEO
$pageTitle = 'Каталог партнёрских предложений — Найдук';
$pageDescription = 'Тысячи выгодных предложений: кредитные карты, вклады, страховки, доставка еды, такси, аптеки и многое другое. Выбирайте по категориям и городам.';
if ($category) {
    $categoryNames = [
        'finance' => 'Финансы',
        'taxi' => 'Такси',
        'food_delivery' => 'Доставка еды',
        'pharmacy' => 'Аптеки',
        'clinic' => 'Клиники',
        'ecom' => 'Интернет-магазины',
        'telecom' => 'Телеком',
        'insurance' => 'Страхование',
        'b2b' => 'B2B'
    ];
    $catName = $categoryNames[$category] ?? $category;
    $pageTitle = "$catName — партнёрские предложения | Найдук";
    $pageDescription = "Лучшие предложения в категории $catName. Акции, скидки, партнёрские программы.";
}
if ($cityId) {
    $cityName = '';
    foreach ($cities as $city) {
        if ($city['id'] == $cityId) {
            $cityName = $city['city_name'];
            break;
        }
    }
    if ($cityName) {
        $pageTitle .= " в $cityName";
        $pageDescription .= " Актуальные предложения в городе $cityName.";
    }
}

// Подключаем layout
require_once __DIR__ . '/../views/layout.php';