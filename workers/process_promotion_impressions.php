#!/usr/bin/env php
<?php
/* ============================================
   НАЙДУК — Воркер для обработки показов рефералок
   Запуск: * * * * * php /path/to/workers/process_promotion_impressions.php
   ============================================ */

if (PHP_SAPI !== 'cli') {
    die("Этот скрипт только для командной строки.\n");
}

require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../services/Database.php';

$db = Database::getInstance();
$pdo = $db->getPdo();

// ==================== ОБРАБОТКА REDIS ====================
$redis = class_exists('Redis') ? new Redis() : null;
if ($redis) {
    try {
        $redis->connect('127.0.0.1', 6379, 1);
        $keys = $redis->keys('promo_impressions:*');
        if (!empty($keys)) {
            $updates = [];
            foreach ($keys as $key) {
                $id = (int) str_replace('promo_impressions:', '', $key);
                $count = (int) $redis->get($key);
                if ($count > 0) {
                    $updates[$id] = $count;
                    $redis->del($key);
                }
            }
            foreach ($updates as $id => $count) {
                $stmt = $pdo->prepare("UPDATE promotions SET impressions = impressions + ? WHERE id = ?");
                $stmt->execute([$count, $id]);
            }
        }
    } catch (Exception $e) {
        // Redis недоступен, пропускаем
    }
}

// ==================== ОБРАБОТКА ФАЙЛОВОГО FALLBACK ====================
$dir = __DIR__ . '/../storage/promotions/';
if (is_dir($dir)) {
    $files = glob($dir . 'impressions_*.count');
    foreach ($files as $file) {
        $id = (int) preg_replace('/[^0-9]/', '', basename($file, '.count'));
        $count = (int) file_get_contents($file);
        if ($count > 0) {
            $stmt = $pdo->prepare("UPDATE promotions SET impressions = impressions + ? WHERE id = ?");
            $stmt->execute([$count, $id]);
        }
        unlink($file);
    }
}

echo "[" . date('Y-m-d H:i:s') . "] Показы обработаны\n";