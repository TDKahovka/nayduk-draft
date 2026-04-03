#!/usr/bin/env php
<?php
/* ============================================
   НАЙДУК — Очистка устаревших данных отзывов v2.0
   - Удаление отзывов к удалённым объявлениям
   - Удаление старых жалоб
   - Удаление orphan-голосов полезности
   - Пересчёт рейтингов для затронутых пользователей
   ============================================ */

if (php_sapi_name() !== 'cli') {
    die("CLI only");
}

require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../services/Database.php';

define('LOCK_FILE', __DIR__ . '/../storage/cleanup_reviews.lock');
define('LOG_FILE', __DIR__ . '/../storage/logs/cleanup_reviews.log');
define('LOG_MAX_SIZE', 10 * 1024 * 1024);

function logMessage($message, $level = 'INFO') {
    if (file_exists(LOG_FILE) && filesize(LOG_FILE) > LOG_MAX_SIZE) {
        $backup = LOG_FILE . '.' . date('Ymd-His');
        rename(LOG_FILE, $backup);
    }
    $date = date('Y-m-d H:i:s');
    $line = "[$date] [$level] $message" . PHP_EOL;
    file_put_contents(LOG_FILE, $line, FILE_APPEND | LOCK_EX);
}

$fp = fopen(LOCK_FILE, 'w');
if (!$fp || !flock($fp, LOCK_EX | LOCK_NB)) {
    logMessage("Скрипт уже запущен, выход.", 'WARNING');
    exit(0);
}
register_shutdown_function(function() use ($fp) {
    flock($fp, LOCK_UN);
    fclose($fp);
    @unlink(LOCK_FILE);
});

logMessage("========== НАЧАЛО ОЧИСТКИ ОТЗЫВОВ ==========");

try {
    $db = Database::getInstance();
    $pdo = $db->getPdo();
} catch (Exception $e) {
    logMessage("Не удалось подключиться к БД: " . $e->getMessage(), 'ERROR');
    exit(1);
}

// 1. Удаляем отзывы к объявлениям, которые были удалены более 90 дней назад
$stmt = $pdo->prepare("
    DELETE r FROM reviews r
    JOIN listings l ON r.listing_id = l.id
    WHERE l.deleted_at IS NOT NULL AND l.deleted_at < NOW() - INTERVAL 90 DAY
");
$stmt->execute();
$deleted = $stmt->rowCount();
logMessage("Удалено $deleted отзывов к удалённым объявлениям");

// 2. Удаляем жалобы со статусом resolved или rejected старше 180 дней
$stmt = $pdo->prepare("
    DELETE FROM review_reports
    WHERE status IN ('resolved', 'rejected') AND resolved_at < NOW() - INTERVAL 180 DAY
");
$stmt->execute();
$deleted = $stmt->rowCount();
logMessage("Удалено $deleted старых жалоб на отзывы");

// 3. Удаляем голоса полезности, которые не были использованы более года (отзыв удалён)
$stmt = $pdo->prepare("
    DELETE rh FROM review_helpfulness rh
    LEFT JOIN reviews r ON rh.review_id = r.id
    WHERE r.id IS NULL AND rh.created_at < NOW() - INTERVAL 365 DAY
");
$stmt->execute();
$deleted = $stmt->rowCount();
logMessage("Удалено $deleted orphan-голосов полезности");

// 4. Обновляем агрегированные рейтинги для пользователей, у которых изменились отзывы
$stmt = $pdo->prepare("
    SELECT DISTINCT reviewed_id FROM reviews
    WHERE updated_at > NOW() - INTERVAL 1 DAY
    UNION
    SELECT user_id FROM user_ratings
    WHERE updated_at > NOW() - INTERVAL 1 DAY
");
$stmt->execute();
$users = $stmt->fetchAll(PDO::FETCH_COLUMN);
$updated = 0;
foreach ($users as $uid) {
    $ratingStmt = $pdo->prepare("SELECT rating FROM reviews WHERE reviewed_id = ? AND is_visible = 1");
    $ratingStmt->execute([$uid]);
    $ratings = $ratingStmt->fetchAll(PDO::FETCH_COLUMN);
    $total = count($ratings);
    $avg = $total > 0 ? array_sum($ratings) / $total : 0;
    $dist = [];
    foreach ($ratings as $r) {
        $dist[$r] = ($dist[$r] ?? 0) + 1;
    }
    $last30 = $pdo->prepare("SELECT COUNT(*) FROM reviews WHERE reviewed_id = ? AND is_visible = 1 AND created_at > NOW() - INTERVAL 30 DAY");
    $last30->execute([$uid]);
    $last30Count = $last30->fetchColumn();
    $pdo->prepare("
        INSERT INTO user_ratings (user_id, avg_rating, total_reviews, rating_distribution, last_30_days_count, updated_at)
        VALUES (?, ?, ?, ?, ?, NOW())
        ON DUPLICATE KEY UPDATE
            avg_rating = VALUES(avg_rating),
            total_reviews = VALUES(total_reviews),
            rating_distribution = VALUES(rating_distribution),
            last_30_days_count = VALUES(last_30_days_count),
            updated_at = NOW()
    ")->execute([$uid, round($avg, 2), $total, json_encode($dist), $last30Count]);
    $updated++;
}
logMessage("Обновлены рейтинги для $updated пользователей");

logMessage("========== ОЧИСТКА ЗАВЕРШЕНА ==========");