<?php
$hasContacts = !empty($shop['contact_phone']) || !empty($shop['contact_email']) || !empty($shop['contact_telegram']) || !empty($shop['contact_whatsapp']);
if (!$hasContacts) return;
?>
<div class="shop-block contacts-block" id="contacts">
    <?php if (!empty($params['title'])): ?>
        <h2><?= htmlspecialchars($params['title']) ?></h2>
    <?php endif; ?>
    <div class="shop-contacts">
        <?php if (!empty($shop['contact_phone'])): ?>
            <button class="contact-btn" data-type="phone"><i class="hgi hgi-stroke-phone"></i> Показать телефон</button>
        <?php endif; ?>
        <?php if (!empty($shop['contact_email'])): ?>
            <button class="contact-btn" data-type="email"><i class="hgi hgi-stroke-mail"></i> Показать email</button>
        <?php endif; ?>
        <?php if (!empty($shop['contact_telegram'])): ?>
            <button class="contact-btn" data-type="telegram"><i class="hgi hgi-stroke-telegram"></i> Telegram</button>
        <?php endif; ?>
        <?php if (!empty($shop['contact_whatsapp'])): ?>
            <button class="contact-btn" data-type="whatsapp"><i class="hgi hgi-stroke-whatsapp"></i> WhatsApp</button>
        <?php endif; ?>
    </div>
    <div id="contact-info" class="contact-info"></div>
</div>