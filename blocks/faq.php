<?php
$faq = json_decode($shop['faq'] ?? '[]', true);
?>
<div class="shop-block faq-block">
    <?php if (!empty($params['title'])): ?>
        <h2><?= htmlspecialchars($params['title']) ?></h2>
    <?php endif; ?>
    <?php foreach ($faq as $item): ?>
        <div class="faq-item">
            <div class="faq-question"><?= htmlspecialchars($item['question']) ?></div>
            <div class="faq-answer"><?= nl2br(htmlspecialchars($item['answer'])) ?></div>
        </div>
    <?php endforeach; ?>
</div>