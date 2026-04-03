<?php
/* ============================================
   НАЙДУК — Страница заказа
   Версия 1.0 (март 2026)
   ============================================ */

if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    header('Location: /');
    exit;
}
$orderId = (int)$_GET['id'];

require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../services/Database.php';

$db = Database::getInstance();
$order = $db->fetchOne("SELECT * FROM shop_orders WHERE id = ?", [$orderId]);
if (!$order) {
    http_response_code(404);
    include __DIR__ . '/../404.php';
    exit;
}

$userId = $_SESSION['user_id'] ?? 0;
$shop = $db->fetchOne("SELECT user_id FROM shops WHERE id = ?", [$order['shop_id']]);
$isOwner = ($shop && $shop['user_id'] == $userId);
$isBuyer = ($order['user_id'] == $userId || ($order['session_id'] === session_id()));

if (!$isOwner && !$isBuyer) {
    http_response_code(403);
    echo 'Доступ запрещён';
    exit;
}

$pageTitle = 'Заказ #' . $orderId . ' — Найдук';
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <meta name="csrf-token" content="<?= csrf_token() ?>">
    <title><?= htmlspecialchars($pageTitle) ?></title>
    <link rel="stylesheet" href="/css/business.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/toastify-js/src/toastify.min.css">
</head>
<body>
<div class="container" style="max-width:800px; margin:40px auto;">
    <h1>Заказ #<?= $orderId ?></h1>
    <div class="card">
        <div>Статус: <span class="badge <?= $order['status'] === 'paid' ? 'badge-success' : 'badge-warning' ?>"><?= $order['status'] === 'paid' ? 'Оплачен' : 'Ожидает оплаты' ?></span></div>
        <div>Сумма: <?= number_format($order['total'], 0, ',', ' ') ?> ₽</div>
        <div>Дата: <?= date('d.m.Y H:i', strtotime($order['created_at'])) ?></div>
        <div>Покупатель: <?= htmlspecialchars($order['customer_name']) ?></div>
        <div>Email: <?= htmlspecialchars($order['customer_email']) ?></div>
        <div>Телефон: <?= htmlspecialchars($order['customer_phone']) ?></div>
        <div>Адрес: <?= htmlspecialchars($order['delivery_address']) ?></div>
        <hr>
        <h3>Товары</h3>
        <?php
        $items = $db->fetchAll("
            SELECT oi.*, p.name as product_name
            FROM shop_order_items oi
            LEFT JOIN shop_products p ON oi.product_id = p.id
            WHERE oi.order_id = ?
        ", [$orderId]);
        ?>
        <table class="table" style="width:100%">
            <?php foreach ($items as $item): ?>
                <tr><td><?= htmlspecialchars($item['product_name']) ?></td><td>x<?= $item['quantity'] ?></td><td><?= number_format($item['price'], 0, ',', ' ') ?> ₽</td></tr>
            <?php endforeach; ?>
        </table>
        <?php if ($order['status'] !== 'paid'): ?>
            <button id="pay-btn" class="btn btn-primary">Оплатить</button>
        <?php endif; ?>
    </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/toastify-js"></script>
<script>
document.getElementById('pay-btn')?.addEventListener('click', async () => {
    const csrfToken = document.querySelector('meta[name="csrf-token"]').content;
    const res = await fetch('/api/payments/create.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
            type: 'order',
            order_id: <?= $orderId ?>,
            amount: <?= $order['total'] ?>,
            description: 'Заказ #<?= $orderId ?>',
            csrf_token: csrfToken
        })
    });
    const data = await res.json();
    if (data.success && data.confirmation_url) {
        window.location.href = data.confirmation_url;
    } else {
        alert(data.error || 'Ошибка оплаты');
    }
});
</script>
</body>
</html>