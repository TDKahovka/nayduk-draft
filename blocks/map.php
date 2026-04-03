<?php if (!empty($shop['address']) && !empty($shop['lat']) && !empty($shop['lng'])): ?>
<div class="shop-block map-block">
    <?php if (!empty($params['title'])): ?>
        <h2><?= htmlspecialchars($params['title']) ?></h2>
    <?php endif; ?>
    <div id="map" class="map" data-lat="<?= htmlspecialchars($shop['lat']) ?>" data-lng="<?= htmlspecialchars($shop['lng']) ?>"></div>
</div>
<?php endif; ?>