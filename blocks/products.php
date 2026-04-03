<?php
$limit = (int)($params['limit'] ?? 12);
$sort = $params['sort'] ?? 'created_desc';
$categoryId = (int)($params['category_id'] ?? 0);

$sql = "SELECT id, title, price, image_urls, description FROM shop_products WHERE shop_id = ? AND is_active = 1";
$sqlParams = [$shop['id']];
if ($categoryId) {
    $sql .= " AND category_id = ?";
    $sqlParams[] = $categoryId;
}
$order = match($sort) {
    'price_asc' => 'price ASC',
    'price_desc' => 'price DESC',
    'title_asc' => 'title ASC',
    default => 'created_at DESC'
};
$sql .= " ORDER BY $order LIMIT ?";
$sqlParams[] = $limit;

$stmt = $pdo->prepare($sql);
$stmt->execute($sqlParams);
$products = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<div class="shop-block products-block">
    <?php if (!empty($params['title'])): ?>
        <h2><?= htmlspecialchars($params['title']) ?></h2>
    <?php endif; ?>
    <div class="products-grid">
        <?php foreach ($products as $p):
            $firstImage = null;
            if (!empty($p['image_urls'])) {
                $images = json_decode($p['image_urls'], true);
                if (is_array($images) && !empty($images)) $firstImage = $images[0];
            }
        ?>
        <div class="product-card">
            <img class="product-image" src="<?= htmlspecialchars($firstImage ?? '/assets/no-image.png') ?>" alt="<?= htmlspecialchars($p['title']) ?>" loading="lazy">
            <div class="product-info">
                <div class="product-title"><?= htmlspecialchars($p['title']) ?></div>
                <div class="product-price"><?= number_format($p['price'], 0, ',', ' ') ?> ₽</div>
                <a href="/product/<?= $p['id'] ?>" class="btn-product">Подробнее</a>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
</div>