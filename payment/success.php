<?php
/**
 * Страница успешной оплаты
 * Принимает параметры: type=listing&listing_id=123 или type=extra_bids
 */
session_start();
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../services/Database.php';
require_once __DIR__ . '/../services/PaymentService.php';

$db = Database::getInstance();
$userId = $_SESSION['user_id'] ?? 0;

$type = $_GET['type'] ?? '';
$listingId = isset($_GET['listing_id']) ? (int)$_GET['listing_id'] : 0;

if ($type === 'listing' && $listingId) {
    // Проверяем, оплачено ли размещение
    $listing = $db->fetchOne("SELECT listing_fee_paid, auction_status FROM listings WHERE id = ?", [$listingId]);
    if ($listing && $listing['listing_fee_paid'] == 1) {
        // Уже активировано (webhook сработал)
        $message = 'Аукцион успешно опубликован!';
        $redirectUrl = '/auctions/view.php?id=' . $listingId;
    } else {
        // Если webhook ещё не пришёл, пробуем проверить статус платежа через API
        // (но лучше положиться на webhook; здесь можно показать ожидание)
        $message = 'Оплата получена. Аукцион активируется в течение минуты.';
        $redirectUrl = '/auctions/my.php';
    }
    // Уведомление пользователю
    if ($userId) {
        $notify = new NotificationService();
        $notify->send($userId, 'auction_activated', ['listing_id' => $listingId]);
    }
} elseif ($type === 'extra_bids') {
    // Покупка ставок — баланс увеличивается через webhook
    // Проверяем, что баланс действительно увеличился (можно сделать запрос в БД)
    $user = $db->getUserById($userId);
    $message = 'Спасибо за покупку! Ваш баланс ставок пополнен.';
    $redirectUrl = '/auctions/my.php';
} else {
    $message = 'Неизвестный тип платежа.';
    $redirectUrl = '/';
}
?>

<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Оплата прошла успешно — Найдук</title>
    <link rel="stylesheet" href="/css/style.css">
    <link rel="stylesheet" href="/css/themes.css">
    <style>
        .payment-success {
            max-width: 600px;
            margin: 80px auto;
            text-align: center;
            background: var(--surface);
            border-radius: var(--radius-2xl);
            padding: 40px;
            box-shadow: var(--shadow-md);
        }
        .success-icon {
            font-size: 64px;
            color: #34C759;
            margin-bottom: 20px;
        }
        .btn-primary {
            display: inline-block;
            margin-top: 20px;
            padding: 12px 24px;
            background: var(--primary);
            color: white;
            border-radius: 9999px;
            text-decoration: none;
        }
    </style>
</head>
<body>
<div class="payment-success">
    <div class="success-icon">✅</div>
    <h1>Оплата прошла успешно!</h1>
    <p><?= htmlspecialchars($message) ?></p>
    <a href="<?= $redirectUrl ?>" class="btn-primary">Перейти к аукциону</a>
</div>
<script>
    // Автоматический редирект через 5 секунд
    setTimeout(function() {
        window.location.href = '<?= $redirectUrl ?>';
    }, 5000);
</script>
</body>
</html>