<?php
/**
 * Страница принятия предложения второму участнику
 * URL: /auctions/accept_offer.php?offer_id=123
 */
session_start();
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../services/Database.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: /auth/login');
    exit;
}

$db = Database::getInstance();
$userId = (int)$_SESSION['user_id'];
$offerId = isset($_GET['offer_id']) ? (int)$_GET['offer_id'] : 0;

if (!$offerId) {
    die('Не указан ID предложения');
}

$offer = $db->fetchOne("
    SELECT so.*, l.title, l.user_id as seller_id
    FROM second_offers so
    JOIN listings l ON so.listing_id = l.id
    WHERE so.id = ? AND so.buyer_id = ? AND so.status = 'pending'
", [$offerId, $userId]);

if (!$offer) {
    die('Предложение не найдено, истекло или уже обработано');
}

if (strtotime($offer['expires_at']) <= time()) {
    $db->update('second_offers', ['status' => 'expired'], 'id = ?', [$offerId]);
    die('Срок действия предложения истёк');
}

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $csrf = $_POST['csrf_token'] ?? '';
    if (!verify_csrf_token($csrf)) {
        $error = 'Неверный CSRF-токен';
    } elseif ($action === 'accept') {
        // Принимаем предложение
        $db->beginTransaction();
        $db->update('second_offers', ['status' => 'accepted'], 'id = ?', [$offerId]);
        $db->update('listings', [
            'winner_id' => $offer['buyer_id'],
            'final_price' => $offer['price'],
            'auction_status' => 'payment_pending'
        ], 'id = ?', [$offer['listing_id']]);
        $expires = date('Y-m-d H:i:s', strtotime('+24 hours'));
        $dealId = $db->insert('deals', [
            'listing_id' => $offer['listing_id'],
            'seller_id' => $offer['seller_id'],
            'buyer_id' => $offer['buyer_id'],
            'price' => $offer['price'],
            'expires_at' => $expires
        ]);
        $db->update('listings', ['deal_id' => $dealId], 'id = ?', [$offer['listing_id']]);
        $db->commit();

        // Уведомления
        $notify = new NotificationService();
        $notify->send($offer['buyer_id'], 'second_offer_accepted', [
            'listing_id' => $offer['listing_id'],
            'title' => $offer['title'],
            'price' => $offer['price']
        ]);
        $notify->send($offer['seller_id'], 'second_offer_accepted', [
            'listing_id' => $offer['listing_id'],
            'title' => $offer['title'],
            'price' => $offer['price']
        ]);

        $success = 'Вы приняли предложение! Сделка создана.';
    } elseif ($action === 'decline') {
        $db->update('second_offers', ['status' => 'declined'], 'id = ?', [$offerId]);
        $db->commit();

        $notify = new NotificationService();
        $notify->send($offer['seller_id'], 'second_offer_declined', [
            'listing_id' => $offer['listing_id'],
            'title' => $offer['title']
        ]);

        $success = 'Вы отказались от предложения.';
    }
}

$csrfToken = generateCsrfToken();
$pageTitle = 'Принять предложение — Найдук';
require_once __DIR__ . '/../includes/header.php';
?>

<style>
    .offer-container {
        max-width: 600px;
        margin: 40px auto;
        background: var(--surface);
        border-radius: var(--radius-2xl);
        padding: 30px;
        border: 1px solid var(--border);
        text-align: center;
    }
    .offer-price {
        font-size: 32px;
        font-weight: 800;
        color: var(--primary);
        margin: 20px 0;
    }
    .offer-buttons {
        display: flex;
        gap: 16px;
        justify-content: center;
        margin-top: 30px;
    }
    .btn-accept {
        background: #22c55e;
        color: white;
        padding: 12px 32px;
        border: none;
        border-radius: 9999px;
        font-weight: 700;
        cursor: pointer;
    }
    .btn-decline {
        background: var(--danger);
        color: white;
        padding: 12px 32px;
        border: none;
        border-radius: 9999px;
        font-weight: 700;
        cursor: pointer;
    }
    .error { color: var(--danger); margin: 16px 0; }
    .success { color: #22c55e; margin: 16px 0; }
</style>

<div class="offer-container">
    <h1>Предложение купить лот</h1>
    <?php if ($success): ?>
        <div class="success">✅ <?= htmlspecialchars($success) ?></div>
        <a href="/auctions/my.php" class="btn-primary" style="margin-top: 20px;">В личный кабинет</a>
    <?php elseif ($error): ?>
        <div class="error">❌ <?= htmlspecialchars($error) ?></div>
        <a href="/auctions/my.php" class="btn-primary">Назад</a>
    <?php else: ?>
        <p>Продавец предлагает вам купить лот <strong><?= htmlspecialchars($offer['title']) ?></strong></p>
        <div class="offer-price"><?= number_format($offer['price'], 0) ?> ₽</div>
        <p>Это ваша последняя ставка на аукционе.</p>
        <p>Срок действия предложения: до <?= date('d.m.Y H:i', strtotime($offer['expires_at'])) ?></p>

        <form method="post">
            <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
            <div class="offer-buttons">
                <button type="submit" name="action" value="accept" class="btn-accept">Принять</button>
                <button type="submit" name="action" value="decline" class="btn-decline">Отказаться</button>
            </div>
        </form>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>