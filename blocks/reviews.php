<?php
$limit = (int)($params['limit'] ?? 6);
$stmt = $pdo->prepare("
    SELECT r.*, u.name as user_name, u.avatar_url as user_avatar
    FROM shop_reviews r
    LEFT JOIN users u ON r.user_id = u.id
    WHERE r.shop_id = ? AND r.is_approved = 1
    ORDER BY r.created_at DESC
    LIMIT ?
");
$stmt->execute([$shop['id'], $limit]);
$reviews = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<div class="shop-block reviews-block">
    <?php if (!empty($params['title'])): ?>
        <h2><?= htmlspecialchars($params['title']) ?></h2>
    <?php endif; ?>
    <div class="reviews-grid">
        <?php foreach ($reviews as $r): ?>
        <div class="review-card">
            <div class="review-rating"><?= str_repeat('★', $r['rating']) . str_repeat('☆', 5 - $r['rating']) ?></div>
            <div class="review-text"><?= nl2br(htmlspecialchars($r['comment'])) ?></div>
            <div class="review-author">— <?= htmlspecialchars($r['user_name']) ?></div>
            <?php if (!empty($r['photos'])): ?>
            <div class="review-photos">
                <?php foreach (json_decode($r['photos'], true) as $photo): ?>
                <img src="<?= htmlspecialchars($photo) ?>" class="review-photo" onclick="window.open(this.src)">
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>
    </div>
</div>