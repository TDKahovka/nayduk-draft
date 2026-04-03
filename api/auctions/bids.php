<?php
/**
 * API: получение списка ставок аукциона (с пагинацией)
 * Используется в админке, а также для продавца.
 * Параметры: listing_id, page (по умолчанию 1), limit (по умолчанию 20), sort (price_desc, date_desc)
 */
session_start();
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../services/Database.php';

$db = Database::getInstance();

// Проверка авторизации
if (!isset($_SESSION['user_id'])) {
    json_error('Требуется авторизация');
}
$user = $db->getUserById($_SESSION['user_id']);
$isAdmin = ($user && $user['role'] === 'admin');

$listingId = isset($_GET['listing_id']) ? (int)$_GET['listing_id'] : 0;
if (!$listingId) json_error('Не указан ID аукциона');

// Проверяем существование аукциона
$listing = $db->fetchOne("SELECT user_id FROM listings WHERE id = ?", [$listingId]);
if (!$listing) json_error('Аукцион не найден');

// Если не админ, проверяем, что пользователь — продавец
if (!$isAdmin && $listing['user_id'] != $_SESSION['user_id']) {
    json_error('Доступ запрещён');
}

// Параметры пагинации
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$limit = isset($_GET['limit']) ? min(100, max(1, (int)$_GET['limit'])) : 20;
$offset = ($page - 1) * $limit;

// Параметры сортировки
$sort = isset($_GET['sort']) ? $_GET['sort'] : 'price_desc';
switch ($sort) {
    case 'price_asc':
        $order = "ORDER BY b.bid_price ASC, b.created_at DESC";
        break;
    case 'date_asc':
        $order = "ORDER BY b.created_at ASC";
        break;
    case 'date_desc':
        $order = "ORDER BY b.created_at DESC";
        break;
    default:
        $order = "ORDER BY b.bid_price DESC, b.created_at DESC";
}

// Общее количество ставок (для пагинации)
$total = $db->fetchCount("SELECT COUNT(*) FROM auction_bids WHERE listing_id = ?", [$listingId]);
$totalPages = ceil($total / $limit);

// Получение ставок с учётом пагинации
$bids = $db->fetchAll("
    SELECT b.id, b.user_id, b.bid_price, b.anonymous_id, b.color_code, b.created_at,
           u.name as user_name
    FROM auction_bids b
    LEFT JOIN users u ON b.user_id = u.id
    WHERE b.listing_id = ?
    $order
    LIMIT ? OFFSET ?
", [$listingId, $limit, $offset]);

json_success([
    'bids' => $bids,
    'pagination' => [
        'current_page' => $page,
        'total_pages' => $totalPages,
        'total_items' => $total,
        'items_per_page' => $limit,
        'next_page' => $page < $totalPages ? $page + 1 : null,
        'prev_page' => $page > 1 ? $page - 1 : null
    ]
]);