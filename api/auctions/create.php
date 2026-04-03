<?php
/**
 * API: создание аукциона (черновик + платёж)
 * Поддерживает прямой (type=1) и обратный (type=2) аукционы
 */
session_start();
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../services/Database.php';
require_once __DIR__ . '/../../services/PaymentService.php';

if (!isset($_SESSION['user_id'])) {
    json_error('Требуется авторизация');
}

$db = Database::getInstance();
$userId = (int)$_SESSION['user_id'];

$csrf = $_POST['csrf_token'] ?? '';
if (!verify_csrf_token($csrf)) {
    json_error('Неверный CSRF-токен');
}

$auctionType = (int)($_POST['auction_type'] ?? 0);
if (!in_array($auctionType, [1,2])) {
    json_error('Неверный тип аукциона');
}

// Проверка лимита черновиков (не более 2 неоплаченных)
$draftCount = $db->fetchCount("SELECT COUNT(*) FROM listings WHERE user_id = ? AND auction_type IN (1,2) AND listing_fee_paid = 0 AND auction_status = 'draft'", [$userId]);
if ($draftCount >= 2) {
    json_error('У вас уже 2 неоплаченных аукциона. Оплатите или удалите один.');
}

// Общие поля
$title = trim($_POST['title'] ?? '');
$description = trim($_POST['description'] ?? '');
$city = trim($_POST['city'] ?? '');
$categoryId = (int)($_POST['category_id'] ?? 0);
$phone = trim($_POST['phone'] ?? '');
$phoneVisible = isset($_POST['phone_visible']) ? 1 : 0;
$duration = (int)($_POST['duration'] ?? 7);

if (empty($title) || mb_strlen($title) < 5) json_error('Заголовок должен быть от 5 символов');
if (empty($description) || mb_strlen($description) < 50) json_error('Описание должно быть от 50 символов');
if (empty($city)) json_error('Укажите город');
if (!$categoryId) json_error('Выберите категорию');

if ($auctionType == 1) {
    // ========== ПРЯМОЙ АУКЦИОН ==========
    $startBid = (float)($_POST['start_bid'] ?? 0);
    if ($startBid <= 0) json_error('Стартовая цена должна быть больше 0');
    $reservePrice = isset($_POST['reserve_price']) && $_POST['reserve_price'] !== '' ? (float)$_POST['reserve_price'] : null;
    if ($reservePrice !== null && $reservePrice < $startBid) {
        json_error('Резервная цена не может быть ниже стартовой');
    }
    $minBidType = $_POST['min_bid_type'] ?? 'auto';
    if ($minBidType == 'auto') {
        $minIncrement = $startBid * 0.01;
        if ($minIncrement < 1) $minIncrement = 1;
    } else {
        $minIncrement = (float)($_POST['min_bid_fixed'] ?? 0);
        if ($minIncrement <= 0) json_error('Фиксированный шаг должен быть положительным');
    }
    $hiddenBids = isset($_POST['hidden_bids']) ? 1 : 0;
    $autoOfferSecond = isset($_POST['auto_offer_second']) ? 1 : 0;
    $enable3d = isset($_POST['enable_3d_effect']) ? 1 : 0;
    $buyNowPrice = isset($_POST['buy_now_price']) && $_POST['buy_now_price'] !== '' ? (float)$_POST['buy_now_price'] : null;

    $auctionEnd = date('Y-m-d H:i:s', strtotime("+{$duration} days"));

    $listingId = $db->insert('listings', [
        'user_id' => $userId,
        'title' => $title,
        'description' => $description,
        'price' => $startBid,
        'type' => 'sell',
        'status' => 'draft',
        'city' => $city,
        'category_id' => $categoryId,
        'phone' => $phone,
        'phone_visible' => $phoneVisible,
        'auction_type' => 1,
        'start_bid' => $startBid,
        'reserve_price' => $reservePrice,
        'min_bid_increment' => $minIncrement,
        'auction_end_at' => $auctionEnd,
        'hidden_bids' => $hiddenBids,
        'auto_offer_second' => $autoOfferSecond,
        'enable_3d_effect' => $enable3d,
        'buy_now_price' => $buyNowPrice,
        'listing_fee_paid' => 0,
        'auction_status' => 'draft',
        'created_at' => date('Y-m-d H:i:s')
    ]);
} else {
    // ========== ОБРАТНЫЙ АУКЦИОН ==========
    $desiredPrice = isset($_POST['desired_price']) && $_POST['desired_price'] !== '' ? (float)$_POST['desired_price'] : null;
    $auctionEnd = date('Y-m-d H:i:s', strtotime("+{$duration} days"));

    $listingId = $db->insert('listings', [
        'user_id' => $userId,
        'title' => $title,
        'description' => $description,
        'price' => $desiredPrice,
        'type' => 'buy',
        'status' => 'draft',
        'city' => $city,
        'category_id' => $categoryId,
        'phone' => $phone,
        'phone_visible' => $phoneVisible,
        'auction_type' => 2,
        'start_bid' => null,
        'reserve_price' => null,
        'min_bid_increment' => null,
        'auction_end_at' => $auctionEnd,
        'hidden_bids' => 0,
        'auto_offer_second' => 0,
        'enable_3d_effect' => 0,
        'buy_now_price' => null,
        'listing_fee_paid' => 0,
        'auction_status' => 'draft',
        'created_at' => date('Y-m-d H:i:s')
    ]);
}

// Сохраняем фотографии
if (!empty($_FILES['photos']['name'][0])) {
    $uploadDir = __DIR__ . '/../../uploads/auctions/';
    if (!is_dir($uploadDir)) mkdir($uploadDir, 0777, true);
    foreach ($_FILES['photos']['tmp_name'] as $idx => $tmpName) {
        if ($_FILES['photos']['error'][$idx] !== UPLOAD_ERR_OK) continue;
        $ext = pathinfo($_FILES['photos']['name'][$idx], PATHINFO_EXTENSION);
        $filename = uniqid() . '.' . $ext;
        $target = $uploadDir . $filename;
        if (move_uploaded_file($tmpName, $target)) {
            $db->insert('listing_photos', [
                'listing_id' => $listingId,
                'photo_url' => '/uploads/auctions/' . $filename,
                'sort_order' => $idx
            ]);
        }
    }
}

// Расчёт платы за размещение (прогрессивная шкала для прямого, для обратного фикс 50)
if ($auctionType == 1) {
    $startBid = (float)($_POST['start_bid'] ?? 0);
    if ($startBid <= 20000) $fee = 30;
    elseif ($startBid <= 50000) $fee = 50;
    elseif ($startBid <= 100000) $fee = 70;
    else $fee = 100;
} else {
    $fee = 50;
}

// Создаём платёж через ЮKassa
$paymentService = new PaymentService();
$paymentUrl = $paymentService->createAuctionListingPayment($listingId, $fee);

json_success(['payment_url' => $paymentUrl]);