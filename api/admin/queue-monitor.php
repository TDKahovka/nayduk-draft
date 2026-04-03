<?php
/* ============================================
   НАЙДУК — API мониторинга очередей (админка)
   ============================================ */

session_start();
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../services/Database.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

$db = Database::getInstance();
$user = $db->getUserById($_SESSION['user_id']);
if (!$user || $user['role'] !== 'admin') {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Forbidden']);
    exit;
}

$redis = class_exists('Redis') ? new Redis() : null;
$redisAvailable = false;
if ($redis) {
    try {
        $redis->connect('127.0.0.1', 6379, 1);
        $redisAvailable = $redis->ping();
    } catch (Exception $e) {}
}

$input = json_decode(file_get_contents('php://input'), true);
$action = $input['action'] ?? '';

switch ($action) {
    case 'list_queues':
        $queues = [
            'queue:promotion_impressions',
            'import_queue',
            'queue:email',
            'queue:telegram',
            'queue:image_optimization',
        ];
        $data = [];
        foreach ($queues as $queue) {
            $length = 0;
            if ($redisAvailable) {
                $length = $redis->llen($queue);
            } else {
                $queueFile = __DIR__ . '/../../storage/queue/' . basename($queue) . '.queue';
                if (file_exists($queueFile)) {
                    $lines = file($queueFile, FILE_IGNORE_NEW_LINES);
                    $length = count($lines);
                }
            }
            $data[] = ['name' => $queue, 'length' => $length];
        }
        echo json_encode(['success' => true, 'data' => $data]);
        break;

    case 'list_workers':
        $workers = ['import', 'send_mail', 'image_optimize', 'promotion_impressions'];
        $data = [];
        foreach ($workers as $worker) {
            $lastBeat = null;
            if ($redisAvailable) {
                $lastBeat = $redis->get("worker:{$worker}:heartbeat");
            } else {
                $file = __DIR__ . '/../../storage/heartbeat/' . $worker . '.txt';
                if (file_exists($file)) {
                    $lastBeat = (int)file_get_contents($file);
                }
            }
            $data[] = [
                'name' => $worker,
                'last_heartbeat' => $lastBeat ? date('Y-m-d H:i:s', $lastBeat) : null,
                'is_alive' => $lastBeat && time() - $lastBeat < 300
            ];
        }
        echo json_encode(['success' => true, 'data' => $data]);
        break;

    case 'clear_queue':
        $queue = $input['queue'] ?? '';
        if (!$queue) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Queue name required']);
            exit;
        }
        if ($redisAvailable) {
            $redis->del($queue);
        } else {
            $queueFile = __DIR__ . '/../../storage/queue/' . basename($queue) . '.queue';
            @unlink($queueFile);
        }
        echo json_encode(['success' => true, 'message' => 'Queue cleared']);
        break;

    case 'get_stats':
        $days = isset($input['days']) ? (int)$input['days'] : 7;
        $stats = $db->fetchAll("
            SELECT queue_name, DATE(created_at) as date, AVG(length) as avg_length
            FROM queue_logs
            WHERE created_at > NOW() - INTERVAL ? DAY
            GROUP BY queue_name, DATE(created_at)
            ORDER BY date DESC, queue_name
        ", [$days]);
        echo json_encode(['success' => true, 'data' => $stats]);
        break;

    default:
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Unknown action']);
        break;
}