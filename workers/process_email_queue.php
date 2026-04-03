<?php
/**
 * Воркер: обработка очереди email-уведомлений
 * Запускать по cron каждую минуту:
 * * * * * php /path/to/workers/process_email_queue.php >> /path/to/storage/logs/email_queue.log 2>&1
 * 
 * Использует метод processEmailQueue из NotificationService
 * Обрабатывает до 100 писем за один запуск
 */
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../services/NotificationService.php';

// Настройка таймаута (чтобы скрипт не висел долго)
set_time_limit(60);

$startTime = microtime(true);
$limit = 100;

try {
    $notificationService = new NotificationService();
    $processed = $notificationService->processEmailQueue($limit);
    
    $duration = round(microtime(true) - $startTime, 2);
    $logMessage = date('Y-m-d H:i:s') . " Processed $processed emails in {$duration}s\n";
    file_put_contents(__DIR__ . '/../storage/logs/email_queue.log', $logMessage, FILE_APPEND);
    
    // Если обработано много писем, можно добавить задержку перед завершением
    if ($processed >= $limit) {
        sleep(1);
    }
} catch (Exception $e) {
    $errorMessage = date('Y-m-d H:i:s') . " ERROR: " . $e->getMessage() . "\n";
    file_put_contents(__DIR__ . '/../storage/logs/email_queue.log', $errorMessage, FILE_APPEND);
}