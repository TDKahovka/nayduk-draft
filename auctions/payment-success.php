<?php
session_start();
require_once __DIR__ . '/../includes/functions.php';
$listingId = (int)$_GET['listing_id'];
$db = Database::getInstance();
$listing = $db->fetchOne("SELECT * FROM listings WHERE id = ?", [$listingId]);
if ($listing['listing_fee_paid']) {
    header('Location: /auctions/view.php?id=' . $listingId);
} else {
    echo 'Оплата не подтверждена. Пожалуйста, подождите или свяжитесь с поддержкой.';
}