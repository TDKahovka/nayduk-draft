#!/usr/bin/env php
<?php
/* ============================================
   НАЙДУК — Воркер для отправки email из очереди
   Версия 1.0 (март 2026)
   - Обрабатывает очередь email (Redis или файлы)
   - Защита от параллельного запуска (flock)
   - Логирование отправленных писем
   ============================================ */

if (PHP_SAPI !== 'cli') {
    die("Этот скрипт предназначен только для запуска из командной строки.\n");
}

// Загружаем ядро
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../services/NotificationService.php';

$lockFile = __DIR__ . '/../storage/mail_queue.lock';
$fp = fopen($lockFile, 'w');
if (!$fp || !flock($fp, LOCK_EX | LOCK_NB)) {
    echo "Скрипт уже запущен, выход.\n";
    exit(0);
}

$notify = new NotificationService();
$processed = $notify->processEmailQueue(100); // обрабатываем до 100 писем за раз

echo "Обработано писем: $processed\n";

flock($fp, LOCK_UN);
fclose($fp);
@unlink($lockFile);