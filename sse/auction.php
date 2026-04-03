<?php
/**
 * Server-Sent Events endpoint для живых обновлений аукциона
 * URL: /sse/auction.php?listing_id=123
 * 
 * Подключается к Redis, подписывается на канал auction:{id}
 * При получении сообщения отправляет его клиенту в формате SSE
 */
header('Content-Type: text/event-stream');
header('Cache-Control: no-cache');
header('X-Accel-Buffering: no'); // отключаем буферизацию nginx

require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../services/Database.php';
require_once __DIR__ . '/../services/RedisService.php';

$listingId = (int)($_GET['listing_id'] ?? 0);
if (!$listingId) {
    echo "event: error\ndata: No listing_id\n\n";
    exit;
}

// Проверяем, что аукцион существует
$db = Database::getInstance();
$listing = $db->fetchOne("SELECT id FROM listings WHERE id = ? AND auction_type = 1", [$listingId]);
if (!$listing) {
    echo "event: error\ndata: Auction not found\n\n";
    exit;
}

$redis = RedisService::getInstance();
$channel = "auction:{$listingId}";

// Отправляем событие connected с подтверждением
echo "event: connected\ndata: " . json_encode(['listing_id' => $listingId]) . "\n\n";
ob_flush();
flush();

// Подписываемся на канал Redis
$redis->subscribe([$channel], function ($redis, $channel, $message) {
    echo "event: update\ndata: {$message}\n\n";
    ob_flush();
    flush();
});