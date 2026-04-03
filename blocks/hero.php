<?php
$title = $params['title'] ?? $shop['name'];
$subtitle = $params['subtitle'] ?? ($shop['description'] ?? '');
$buttonText = $params['button_text'] ?? 'Связаться';
$buttonLink = $params['button_link'] ?? '#contacts';
?>
<div class="shop-block hero-block" style="text-align: center;">
    <h1><?= htmlspecialchars($title) ?></h1>
    <?php if ($subtitle): ?>
        <p class="hero-subtitle"><?= nl2br(htmlspecialchars($subtitle)) ?></p>
    <?php endif; ?>
    <?php if ($buttonText): ?>
        <a href="<?= htmlspecialchars($buttonLink) ?>" class="btn btn-primary hero-btn"><?= htmlspecialchars($buttonText) ?></a>
    <?php endif; ?>
</div>