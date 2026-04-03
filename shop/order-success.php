<?php
// shop/order-success.php
if (!isset($_GET['order_id'])) {
    header('Location: /');
    exit;
}
$orderId = (int)$_GET['order_id'];

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
$pageTitle = 'Заказ оплачен — Найдук';
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($pageTitle) ?></title>
    <link rel="stylesheet" href="/css/business.css">
</head>
<body>
<div class="container" style="text-align:center; padding:60px 20px;">
    <h1>✅ Заказ успешно оплачен!</h1>
    <p>Номер заказа: #<?= $orderId ?></p>
    <p>Спасибо за покупку. Мы свяжемся с вами в ближайшее время.</p>
    <a href="/shop/order/<?= $orderId ?>" class="btn btn-primary">Перейти к заказу</a>
</div>
</body>
</html>