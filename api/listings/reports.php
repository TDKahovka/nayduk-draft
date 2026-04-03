<?php
/* ============================================
   НАЙДУК — API жалоб на объявления (reports) v4.0
   Версия 4.0 (март 2026)
   - Полная автоматизация: автосоздание таблиц и полей
   - Redis для rate limiting (файловый fallback)
   - Транзакции с блокировкой для точного подсчёта жалоб
   - Конфигурируемый порог авто-модерации (AUTO_HIDE_THRESHOLD)
   - Асинхронные уведомления администраторов (через очередь)
   - Улучшенная валидация, логирование, безопасность
   ============================================ */

session_start();
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../services/Database.php';
require_once __DIR__ . '/../../services/NotificationService.php';

header('Content-Type: application/json');

// Константы (можно вынести в конфиг)
define('AUTO_HIDE_THRESHOLD', 3);
define('RATE_LIMIT_REPORTS', 10);
define('RATE_LIMIT_WINDOW', 3600);

// ==================== ПРОВЕРКА МЕТОДА ====================
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

// ==================== АВТОРИЗАЦИЯ ====================
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Требуется авторизация']);
    exit;
}

$userId = (int)$_SESSION['user_id'];
$ip = getUserIP();

$db = Database::getInstance();
$pdo = $db->getPdo();

// ==================== АВТО-СОЗДАНИЕ ТАБЛИЦЫ ====================
$db->query("
    CREATE TABLE IF NOT EXISTS listing_reports (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        listing_id BIGINT UNSIGNED NOT NULL,
        user_id BIGINT UNSIGNED NOT NULL,
        reason VARCHAR(50) NOT NULL,
        comment TEXT,
        status VARCHAR(20) DEFAULT 'new',
        resolved_by BIGINT UNSIGNED,
        resolved_at TIMESTAMP NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (listing_id) REFERENCES listings(id) ON DELETE CASCADE,
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
        INDEX idx_listing (listing_id),
        INDEX idx_user (user_id),
        INDEX idx_status (status),
        INDEX idx_created (created_at),
        UNIQUE KEY unique_report (listing_id, user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
");

// Добавляем поле is_hidden в listings, если его нет
$columns = $pdo->query("SHOW COLUMNS FROM listings")->fetchAll(PDO::FETCH_COLUMN);
if (!in_array('is_hidden', $columns)) {
    $pdo->exec("ALTER TABLE listings ADD COLUMN is_hidden BOOLEAN DEFAULT FALSE");
}
if (!in_array('hidden_at', $columns)) {
    $pdo->exec("ALTER TABLE listings ADD COLUMN hidden_at TIMESTAMP NULL");
}

// ==================== RATE LIMITING (Redis или файлы) ====================
function checkRateLimitReport($userId, $limit = RATE_LIMIT_REPORTS, $window = RATE_LIMIT_WINDOW) {
    static $redis = null;
    if ($redis === null) {
        $redis = class_exists('Redis') ? new Redis() : null;
        if ($redis) {
            try {
                $redis->connect('127.0.0.1', 6379, 1);
                $redis->ping();
            } catch (Exception $e) {
                $redis = null;
            }
        }
    }
    $key = 'rate:report:' . $userId;
    if ($redis) {
        $count = $redis->incr($key);
        if ($count == 1) $redis->expire($key, $window);
        return $count <= $limit;
    }
    // Файловый fallback
    $file = __DIR__ . '/../../storage/rate/' . md5('report_' . $userId) . '.txt';
    $dir = dirname($file);
    if (!is_dir($dir)) mkdir($dir, 0755, true);
    $now = time();
    $data = [];
    if (file_exists($file)) {
        $data = json_decode(file_get_contents($file), true);
        if (!is_array($data)) $data = [];
    }
    $data = array_filter($data, fn($t) => $t > $now - $window);
    if (count($data) >= $limit) return false;
    $data[] = $now;
    file_put_contents($file, json_encode($data), LOCK_EX);
    return true;
}

// ==================== ВСПОМОГАТЕЛЬНЫЕ ФУНКЦИИ ====================
function autoHideListing($pdo, $listingId, $reportCount) {
    // Используем транзакцию с блокировкой для атомарности
    $pdo->beginTransaction();
    try {
        // Блокируем запись на чтение (FOR UPDATE)
        $stmt = $pdo->prepare("SELECT is_hidden FROM listings WHERE id = ? FOR UPDATE");
        $stmt->execute([$listingId]);
        $listing = $stmt->fetch();
        if ($listing && $listing['is_hidden']) {
            $pdo->commit();
            return false;
        }
        $stmt = $pdo->prepare("UPDATE listings SET is_hidden = TRUE, hidden_at = NOW(), status = 'hidden' WHERE id = ?");
        $stmt->execute([$listingId]);
        // Обновляем статус жалоб на 'resolved'
        $pdo->prepare("UPDATE listing_reports SET status = 'resolved', resolved_at = NOW() WHERE listing_id = ? AND status = 'new'")->execute([$listingId]);
        $pdo->commit();
        return true;
    } catch (Exception $e) {
        $pdo->rollBack();
        error_log("Auto-hide failed for listing $listingId: " . $e->getMessage());
        return false;
    }
}

function notifyAdmins($pdo, $listingId, $reportCount) {
    $stmt = $pdo->prepare("SELECT id, email FROM users WHERE role = 'admin'");
    $stmt->execute();
    $admins = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (empty($admins)) return;

    $stmt2 = $pdo->prepare("SELECT title, user_id FROM listings WHERE id = ?");
    $stmt2->execute([$listingId]);
    $listing = $stmt2->fetch(PDO::FETCH_ASSOC);
    $listingTitle = $listing ? $listing['title'] : "#$listingId";

    $notify = new NotificationService();
    foreach ($admins as $admin) {
        // Отправляем уведомление асинхронно (через очередь, если есть)
        $notify->send($admin['id'], 'report_threshold_reached', [
            'listing_id' => $listingId,
            'listing_title' => $listingTitle,
            'report_count' => $reportCount,
            'link' => "/admin/listings?view=$listingId"
        ]);
    }
}

// ==================== ОСНОВНАЯ ЛОГИКА ====================
$input = json_decode(file_get_contents('php://input'), true);
if (!$input) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Неверный формат JSON']);
    exit;
}

$action = $input['action'] ?? '';
$allowedActions = ['create'];
if (!in_array($action, $allowedActions)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Неизвестное действие']);
    exit;
}

// CSRF защита
$csrfToken = $input['csrf_token'] ?? '';
if (!verify_csrf_token($csrfToken)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Недействительный токен безопасности']);
    exit;
}

// Rate limiting
if (!checkRateLimitReport($userId, RATE_LIMIT_REPORTS, RATE_LIMIT_WINDOW)) {
    http_response_code(429);
    echo json_encode(['success' => false, 'error' => 'Слишком много жалоб. Попробуйте позже.']);
    exit;
}

switch ($action) {
    case 'create':
        $listingId = isset($input['listing_id']) ? (int)$input['listing_id'] : 0;
        $reason = $input['reason'] ?? '';
        $comment = trim($input['comment'] ?? '');

        if (!$listingId) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Не указан ID объявления']);
            exit;
        }
        if (!in_array($reason, ['spam', 'fraud', 'wrong_category', 'offensive', 'other'])) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Некорректная причина жалобы']);
            exit;
        }
        if (strlen($comment) > 1000) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Комментарий не должен превышать 1000 символов']);
            exit;
        }

        // Проверяем существование объявления
        $listing = $db->fetchOne("SELECT id, user_id, is_hidden, status FROM listings WHERE id = ?", [$listingId]);
        if (!$listing) {
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => 'Объявление не найдено']);
            exit;
        }
        if ($listing['user_id'] == $userId) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Нельзя жаловаться на своё объявление']);
            exit;
        }
        if ($listing['is_hidden'] || $listing['status'] === 'hidden') {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Объявление уже скрыто']);
            exit;
        }

        // Проверяем, не жаловался ли уже пользователь
        $exists = $db->exists('listing_reports', 'listing_id = ? AND user_id = ?', [$listingId, $userId]);
        if ($exists) {
            http_response_code(409);
            echo json_encode(['success' => false, 'error' => 'Вы уже отправили жалобу на это объявление']);
            exit;
        }

        // Вставляем жалобу в транзакции
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare("INSERT INTO listing_reports (listing_id, user_id, reason, comment) VALUES (?, ?, ?, ?)");
            $stmt->execute([$listingId, $userId, $reason, $comment ?: null]);

            // Логируем
            $db->insert('security_logs', [
                'user_id' => $userId,
                'ip_address' => $ip,
                'event_type' => 'report_created',
                'description' => "Пожаловались на объявление #$listingId (причина: $reason)",
                'severity' => 'medium',
                'created_at' => date('Y-m-d H:i:s')
            ]);

            // Подсчитываем количество активных жалоб (блокируем для точности)
            $stmtCount = $pdo->prepare("SELECT COUNT(*) FROM listing_reports WHERE listing_id = ? AND status = 'new' FOR UPDATE");
            $stmtCount->execute([$listingId]);
            $reportCount = $stmtCount->fetchColumn();

            $autoHidden = false;
            if ($reportCount >= AUTO_HIDE_THRESHOLD) {
                $autoHidden = autoHideListing($pdo, $listingId, $reportCount);
                if ($autoHidden) {
                    // Уведомляем администраторов (через очередь)
                    notifyAdmins($pdo, $listingId, $reportCount);
                    $db->insert('security_logs', [
                        'user_id' => null,
                        'ip_address' => $ip,
                        'event_type' => 'auto_hide',
                        'description' => "Объявление #$listingId автоматически скрыто после $reportCount жалоб",
                        'severity' => 'high',
                        'created_at' => date('Y-m-d H:i:s')
                    ]);
                }
            }

            $pdo->commit();

            $response = ['success' => true, 'message' => 'Жалоба отправлена'];
            if ($autoHidden) {
                $response['auto_hidden'] = true;
                $response['message'] .= ' Объявление скрыто из-за большого количества жалоб.';
            }
            echo json_encode($response);
        } catch (Exception $e) {
            $pdo->rollBack();
            error_log("Report creation failed: " . $e->getMessage());
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => 'Ошибка при отправке жалобы']);
        }
        break;

    default:
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Неизвестное действие']);
        break;
}