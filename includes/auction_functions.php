<?php
/**
 * Вспомогательные функции для аукционного модуля
 * - Расчёт комиссии
 * - Форматирование времени
 * - Проверка лимитов ставок
 * - Генерация анонимных ID и цветов
 * - Получение текущей версии правил
 */

require_once __DIR__ . '/../services/Database.php';
require_once __DIR__ . '/../services/NotificationService.php';

/**
 * Рассчитать комиссию с продажи
 * @param float $price Цена сделки
 * @return float Сумма комиссии (min 50, max 2000)
 */
function calculateCommission($price) {
    $commission = $price * 0.02;
    $commission = max(50, min(2000, $commission));
    return $commission;
}

/**
 * Рассчитать плату за размещение аукциона
 * @param float $startBid Стартовая цена
 * @return int Сумма (30–100 ₽)
 */
function calculateListingFee($startBid) {
    if ($startBid <= 20000) return 30;
    if ($startBid <= 50000) return 50;
    if ($startBid <= 100000) return 70;
    return 100;
}

/**
 * Форматировать оставшееся время в читаемый вид
 * @param int $seconds Количество секунд
 * @return string Строка вида "2ч 15м 30с" или "Завершён"
 */
function formatTimeLeft($seconds) {
    if ($seconds <= 0) return 'Завершён';
    $hours = floor($seconds / 3600);
    $minutes = floor(($seconds % 3600) / 60);
    $secs = $seconds % 60;
    return sprintf("%dч %02dм %02dс", $hours, $minutes, $secs);
}

/**
 * Сгенерировать анонимный ID для участника аукциона
 * @param int $listingId ID аукциона
 * @param int $userId ID пользователя
 * @return string 4-символьный код (A-Z, 0-9)
 */
function generateAnonymousId($listingId, $userId) {
    $hash = md5($listingId . $userId . uniqid() . time());
    return substr($hash, 0, 4);
}

/**
 * Сгенерировать цвет на основе анонимного ID
 * @param string $anonId
 * @return string Цвет в формате #RRGGBB
 */
function generateColor($anonId) {
    $hash = crc32($anonId);
    $r = ($hash & 0xFF0000) >> 16;
    $g = ($hash & 0x00FF00) >> 8;
    $b = ($hash & 0x0000FF);
    // Приглушаем яркость
    $r = round($r * 0.7);
    $g = round($g * 0.7);
    $b = round($b * 0.7);
    return sprintf("#%02x%02x%02x", $r, $g, $b);
}

/**
 * Получить текущий текст правил (для согласия)
 * @return string|null Текст правил или null
 */
function getCurrentConsentText() {
    $db = Database::getInstance();
    $row = $db->fetchOne("SELECT full_text FROM auction_consent_versions ORDER BY id DESC LIMIT 1");
    return $row ? $row['full_text'] : null;
}

/**
 * Получить текущую версию правил
 * @return string|null Версия или null
 */
function getCurrentConsentVersion() {
    $db = Database::getInstance();
    $row = $db->fetchOne("SELECT version FROM auction_consent_versions ORDER BY id DESC LIMIT 1");
    return $row ? $row['version'] : null;
}

/**
 * Проверить, подписал ли пользователь текущую версию правил
 * @param int $userId
 * @return bool
 */
function hasActiveConsent($userId) {
    $db = Database::getInstance();
    $version = getCurrentConsentVersion();
    if (!$version) return false;
    $consent = $db->fetchOne("SELECT 1 FROM user_auction_consents WHERE user_id = ? AND consent_version = ?", [$userId, $version]);
    return (bool)$consent;
}

/**
 * Проверить, заблокирован ли пользователь в аукционах
 * @param int $userId
 * @return bool
 */
function isUserBlocked($userId) {
    $db = Database::getInstance();
    $block = $db->fetchOne("SELECT expires_at FROM user_blocks WHERE user_id = ? AND block_type = 'auction' AND expires_at > NOW()", [$userId]);
    return !empty($block);
}

/**
 * Проверить, может ли пользователь сделать ещё одну ставку на указанный аукцион
 * @param int $userId
 * @param int $listingId
 * @return array ['can_bid' => bool, 'free_remaining' => int, 'extra_remaining' => int, 'message' => string]
 */
function checkRemainingBids($userId, $listingId) {
    $db = Database::getInstance();

    $participant = $db->fetchOne("SELECT free_bids_used, extra_bids_used FROM auction_participants WHERE listing_id = ? AND user_id = ?", [$listingId, $userId]);
    $freeUsed = $participant['free_bids_used'] ?? 0;
    $extraUsed = $participant['extra_bids_used'] ?? 0;

    $user = $db->fetchOne("SELECT extra_bids_balance FROM users WHERE id = ?", [$userId]);
    $freeRemaining = max(0, 3 - $freeUsed);
    $extraRemaining = max(0, ($user['extra_bids_balance'] ?? 0) - $extraUsed);
    $totalRemaining = $freeRemaining + $extraRemaining;

    if ($totalRemaining <= 0) {
        return [
            'can_bid' => false,
            'free_remaining' => 0,
            'extra_remaining' => 0,
            'message' => 'У вас закончились ставки. Купите дополнительные.'
        ];
    }

    if ($extraUsed >= 20) {
        return [
            'can_bid' => false,
            'free_remaining' => $freeRemaining,
            'extra_remaining' => $extraRemaining,
            'message' => 'Вы превысили лимит платных ставок на этот аукцион (20)'
        ];
    }

    return [
        'can_bid' => true,
        'free_remaining' => $freeRemaining,
        'extra_remaining' => $extraRemaining,
        'message' => ''
    ];
}

/**
 * Отправить уведомление пользователю через существующий NotificationService
 * @param int $userId
 * @param string $type
 * @param array $data
 */
function sendAuctionNotification($userId, $type, $data = []) {
    $notify = new NotificationService();
    $notify->send($userId, $type, $data);
}

/**
 * Заблокировать пользователя в аукционах
 * @param int $userId
 * @param int $days
 * @param string $reason
 */
function blockUserFromAuctions($userId, $days = 30, $reason = 'Неоплата выигрыша') {
    $db = Database::getInstance();
    $expires = date('Y-m-d H:i:s', strtotime("+{$days} days"));
    $db->insert('user_blocks', [
        'user_id' => $userId,
        'block_type' => 'auction',
        'expires_at' => $expires,
        'reason' => $reason
    ]);
}

/**
 * Получить максимальную ставку для лота
 * @param int $listingId
 * @return float|int|null
 */
function getCurrentMaxBid($listingId) {
    $db = Database::getInstance();
    $row = $db->fetchOne("SELECT MAX(bid_price) as max FROM auction_bids WHERE listing_id = ?", [$listingId]);
    return $row ? (float)$row['max'] : null;
}

/**
 * Получить количество ставок на лот
 * @param int $listingId
 * @return int
 */
function getBidsCount($listingId) {
    $db = Database::getInstance();
    return $db->fetchCount("SELECT COUNT(*) FROM auction_bids WHERE listing_id = ?", [$listingId]);
}