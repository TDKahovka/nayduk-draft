<?php
/**
 * Воркер: очистка устаревших данных
 * Запускать по cron раз в сутки (например, в 3:00):
 * 0 3 * * * php /path/to/workers/cleanup_expired.php >> /path/to/storage/logs/cleanup_expired.log 2>&1
 * 
 * Удаляет:
 * - черновики аукционов без оплаты старше 1 дня
 * - ставки старше 1 года
 * - записи аудита старше 3 лет
 * - истёкшие блокировки пользователей
 */
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../services/Database.php';

$db = Database::getInstance();

// Удаляем черновики аукционов без оплаты, созданные более 1 дня назад
$deletedDrafts = $db->query("
    DELETE FROM listings 
    WHERE auction_type IN (1,2) 
      AND listing_fee_paid = 0 
      AND auction_status = 'draft' 
      AND created_at < NOW() - INTERVAL 1 DAY
");

// Удаляем ставки старше 1 года (можно оставить для аудита, но для производительности удаляем)
$deletedBids = $db->query("
    DELETE FROM auction_bids 
    WHERE created_at < NOW() - INTERVAL 1 YEAR
");

// Удаляем логи аудита старше 3 лет
$deletedAudit = $db->query("
    DELETE FROM audit_log 
    WHERE created_at < NOW() - INTERVAL 3 YEAR
");

// Удаляем истёкшие блокировки (чисто для порядка, они уже неактивны)
$deletedBlocks = $db->query("
    DELETE FROM user_blocks 
    WHERE expires_at < NOW()
");

// Логируем результат (можно писать в файл или в таблицу, если нужно)
error_log("Cleanup: deleted $deletedDrafts drafts, $deletedBids bids, $deletedAudit audit logs, $deletedBlocks blocks");