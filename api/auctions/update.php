<?php
/**
 * API: обновление аукциона (до начала или если нет ставок)
 * Позволяет продавцу изменить: заголовок, описание, цену, шаг, длительность, резерв и т.д.
 * Доступно только для аукционов со статусом 'draft' или 'active' без ставок.
 */
session_start();
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../services/Database.php';

$db = Database::getInstance();
$userId = $_SESSION['user_id'] ?? 0;
if (!$userId) json_error('Требуется авторизация');

$data = json_decode(file_get_contents('php://input'), true);
$listingId = (int)($data['listing_id'] ?? 0);
$csrf = $data['csrf_token'] ?? '';
if (!verify_csrf_token($csrf)) json_error('Неверный CSRF-токен');

$listing = $db->fetchOne("SELECT * FROM listings WHERE id = ? AND user_id = ? AND auction_type = 1", [$listingId, $userId]);
if (!$listing) json_error('Аукцион не найден или не принадлежит вам');

// Проверяем, можно ли редактировать
if (!in_array($listing['auction_status'], ['draft', 'active'])) {
    json_error('Аукцион нельзя редактировать (уже есть ставки или завершён)');
}
if ($listing['auction_status'] == 'active') {
    $bidsCount = $db->fetchCount("SELECT COUNT(*) FROM auction_bids WHERE listing_id = ?", [$listingId]);
    if ($bidsCount > 0) {
        json_error('Нельзя редактировать аукцион, в котором уже есть ставки');
    }
}

// Общие поля (могут быть переданы не все)
$title = isset($data['title']) ? trim($data['title']) : null;
$description = isset($data['description']) ? trim($data['description']) : null;
$city = isset($data['city']) ? trim($data['city']) : null;
$categoryId = isset($data['category_id']) ? (int)$data['category_id'] : null;
$phone = isset($data['phone']) ? trim($data['phone']) : null;
$phoneVisible = isset($data['phone_visible']) ? (int)$data['phone_visible'] : null;
$duration = isset($data['duration']) ? (int)$data['duration'] : null;
$hiddenBids = isset($data['hidden_bids']) ? (int)$data['hidden_bids'] : null;
$autoOfferSecond = isset($data['auto_offer_second']) ? (int)$data['auto_offer_second'] : null;
$enable3d = isset($data['enable_3d_effect']) ? (int)$data['enable_3d_effect'] : null;
$buyNowPrice = isset($data['buy_now_price']) && $data['buy_now_price'] !== '' ? (float)$data['buy_now_price'] : null;

// Поля прямого аукциона
$startBid = isset($data['start_bid']) ? (float)$data['start_bid'] : null;
$reservePrice = isset($data['reserve_price']) && $data['reserve_price'] !== '' ? (float)$data['reserve_price'] : null;
$minBidType = isset($data['min_bid_type']) ? $data['min_bid_type'] : null;
$minBidFixed = isset($data['min_bid_fixed']) ? (float)$data['min_bid_fixed'] : null;

// Валидация
if ($title !== null && (mb_strlen($title) < 5 || mb_strlen($title) > 100)) {
    json_error('Заголовок должен быть от 5 до 100 символов');
}
if ($description !== null && (mb_strlen($description) < 50 || mb_strlen($description) > 3000)) {
    json_error('Описание должно быть от 50 до 3000 символов');
}
if ($city !== null && empty($city)) json_error('Город не может быть пустым');
if ($categoryId !== null && $categoryId <= 0) json_error('Неверная категория');
if ($duration !== null && !in_array($duration, [1,3,7,14])) json_error('Неверная длительность');

if ($startBid !== null) {
    if ($startBid <= 0) json_error('Стартовая цена должна быть больше 0');
    if ($reservePrice !== null && $reservePrice < $startBid) json_error('Резервная цена не может быть ниже стартовой');
    $minIncrement = null;
    if ($minBidType == 'auto') {
        $minIncrement = $startBid * 0.01;
        if ($minIncrement < 1) $minIncrement = 1;
    } elseif ($minBidType == 'fixed') {
        if ($minBidFixed <= 0) json_error('Фиксированный шаг должен быть положительным');
        $minIncrement = $minBidFixed;
    }
}

// Собираем массив обновляемых полей
$updateFields = [];
if ($title !== null) $updateFields['title'] = $title;
if ($description !== null) $updateFields['description'] = $description;
if ($city !== null) $updateFields['city'] = $city;
if ($categoryId !== null) $updateFields['category_id'] = $categoryId;
if ($phone !== null) $updateFields['phone'] = $phone;
if ($phoneVisible !== null) $updateFields['phone_visible'] = $phoneVisible;
if ($hiddenBids !== null) $updateFields['hidden_bids'] = $hiddenBids;
if ($autoOfferSecond !== null) $updateFields['auto_offer_second'] = $autoOfferSecond;
if ($enable3d !== null) $updateFields['enable_3d_effect'] = $enable3d;
if ($buyNowPrice !== null) $updateFields['buy_now_price'] = $buyNowPrice;

if ($startBid !== null) {
    $updateFields['start_bid'] = $startBid;
    $updateFields['price'] = $startBid;
    if ($reservePrice !== null) $updateFields['reserve_price'] = $reservePrice;
    if (isset($minIncrement)) $updateFields['min_bid_increment'] = $minIncrement;
}
if ($duration !== null) {
    $newEnd = date('Y-m-d H:i:s', strtotime("+{$duration} days"));
    $updateFields['auction_end_at'] = $newEnd;
}

if (empty($updateFields)) {
    json_error('Нет данных для обновления');
}

// Обновляем
$db->update('listings', $updateFields, 'id = ?', [$listingId]);

// Логируем
$db->insert('audit_log', [
    'event_type' => 'auction_updated',
    'listing_id' => $listingId,
    'user_id' => $userId,
    'details' => json_encode(array_keys($updateFields))
]);

json_success(['message' => 'Аукцион обновлён']);