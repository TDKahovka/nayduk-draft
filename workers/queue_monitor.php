#!/usr/bin/env php
<?php
/* ============================================
   НАЙДУК — Мониторинг очередей
   Запуск: */5 * * * * php /path/to/workers/queue_monitor.php
   Версия 2.0 — только очереди, без heartbeat (все воркеры разовые)
   ============================================ */

if (PHP_SAPI !== 'cli') {
    die("Только командная строка");
}

require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../services/Database.php';
require_once __DIR__ . '/../services/NotificationService.php';

$db = Database::getInstance();
$pdo = $db->getPdo();

// ==================== АВТОСОЗДАНИЕ ТАБЛИЦЫ ДЛЯ ЛОГОВ ====================
$pdo->exec("
    CREATE TABLE IF NOT EXISTS queue_logs (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        queue_name VARCHAR(100) NOT NULL,
        length INT NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_queue_name (queue_name),
        INDEX idx_created (created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
");

$redis = class_exists('Redis') ? new Redis() : null;
$redisAvailable = false;
if ($redis) {
    try {
        $redis->connect('127.0.0.1', 6379, 1);
        $redisAvailable = $redis->ping();
    } catch (Exception $e) {}
}

// Список очередей для мониторинга
$queues = [
    'queue:promotion_impressions',
    'import_queue',
    'queue:email',
    'queue:telegram',
    'queue:image_optimization',
];

// Пороги
$warningThreshold = 1000;
$criticalThreshold = 5000;

// Проверяем очереди
$alerts = [];
foreach ($queues as $queue) {
    $length = 0;
    if ($redisAvailable) {
        $length = $redis->llen($queue);
    } else {
        // файловая очередь (если есть)
        $queueFile = __DIR__ . '/../storage/queue/' . basename($queue) . '.queue';
        if (file_exists($queueFile)) {
            $lines = file($queueFile, FILE_IGNORE_NEW_LINES);
            $length = count($lines);
        }
    }
    
    if ($length > $criticalThreshold) {
        $alerts[] = "КРИТИЧЕСКАЯ: очередь $queue содержит $length задач";
    } elseif ($length > $warningThreshold) {
        $alerts[] = "ВНИМАНИЕ: очередь $queue содержит $length задач";
    }
    
    // Логируем в БД (для графиков)
    $db->insert('queue_logs', [
        'queue_name' => $queue,
        'length' => $length,
        'created_at' => date('Y-m-d H:i:s')
    ]);
}

// Отправляем уведомления, если есть проблемы
if (!empty($alerts)) {
    $message = "⚠️ Проблемы с очередями на сайте\n\n" . implode("\n", $alerts);
    $notify = new NotificationService();
    $adminEmail = 'admin@nayduk.ru'; // замените на реальный email
    $notify->sendEmail($adminEmail, 'Мониторинг очередей', $message);
    
    file_put_contents(__DIR__ . '/../storage/logs/queue_monitor.log', $message . "\n", FILE_APPEND);
}

echo "[" . date('Y-m-d H:i:s') . "] Мониторинг завершён\n";