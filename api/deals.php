<?php
/* ============================================
   НАЙДУК — API подтверждения сделок (deals)
   Версия 1.0 — полностью самодостаточный
   - Подтверждение встречи (кнопки «встретились»)
   - Авто-подтверждение через 14 дней (воркер)
   - Уведомления через NotificationService
   - Защита от повторных подтверждений
   ============================================ */

session_start();
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../services/Database.php';
require_once __DIR__ . '/../services/NotificationService.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Требуется авторизация']);
    exit;
}

$userId = $_SESSION['user_id'];
$ip = getUserIP();

// Rate limiting (не более 20 действий в минуту)
if (!checkRateLimit('deals_' . $userId, 20, 60)) {
    http_response_code(429);
    echo json_encode(['success' => false, 'error' => 'Слишком много запросов. Попробуйте позже.']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
if (!$input) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Неверный формат JSON']);
    exit;
}

$csrfToken = $input['csrf_token'] ?? '';
if (!verifyCsrfToken($csrfToken)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Недействительный токен безопасности']);
    exit;
}

$action = $input['action'] ?? '';
$allowedActions = ['confirm', 'status', 'list'];
if (!in_array($action, $allowedActions)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Неизвестное действие']);
    exit;
}

$db = Database::getInstance();
$pdo = $db->getPdo();

// ==================== СОЗДАНИЕ ТАБЛИЦЫ (если нет) ====================
$db->query("
    CREATE TABLE IF NOT EXISTS deal_confirmations (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        listing_id BIGINT UNSIGNED NOT NULL,
        seller_id BIGINT UNSIGNED NOT NULL,
        buyer_id BIGINT UNSIGNED NOT NULL,
        seller_confirmed BOOLEAN DEFAULT FALSE,
        buyer_confirmed BOOLEAN DEFAULT FALSE,
        confirmed_at TIMESTAMP NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY unique_deal (listing_id, buyer_id),
        FOREIGN KEY (listing_id) REFERENCES listings(id) ON DELETE CASCADE,
        FOREIGN KEY (seller_id) REFERENCES users(id) ON DELETE CASCADE,
        FOREIGN KEY (buyer_id) REFERENCES users(id) ON DELETE CASCADE,
        INDEX idx_seller (seller_id, confirmed_at),
        INDEX idx_buyer (buyer_id, confirmed_at),
        INDEX idx_pending (seller_confirmed, buyer_confirmed)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
");

// ==================== ВСПОМОГАТЕЛЬНЫЕ ФУНКЦИИ ====================

/**
 * Проверяет, может ли пользователь подтвердить сделку по данному объявлению
 */
function canConfirmDeal($pdo, $userId, $listingId, $role = null) {
    // Проверяем, существует ли объявление и его тип
    $stmt = $pdo->prepare("SELECT user_id, type FROM listings WHERE id = ? AND status = 'approved'");
    $stmt->execute([$listingId]);
    $listing = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$listing) return ['allowed' => false, 'error' => 'Объявление не найдено или неактивно'];

    // Для частных объявлений (sell) – сделка возможна только между продавцом и покупателем
    if ($listing['type'] === 'sell') {
        if ($role === 'seller' && $listing['user_id'] != $userId) {
            return ['allowed' => false, 'error' => 'Вы не являетесь продавцом этого товара'];
        }
        if ($role === 'buyer' && $listing['user_id'] == $userId) {
            return ['allowed' => false, 'error' => 'Вы не можете купить свой собственный товар'];
        }
        return ['allowed' => true, 'seller_id' => $listing['user_id']];
    }

    // Для услуг и других типов можно расширить
    return ['allowed' => false, 'error' => 'Тип объявления не поддерживает сделки'];
}

/**
 * Получить текущую запись подтверждения
 */
function getDealRecord($pdo, $listingId, $buyerId = null) {
    $sql = "SELECT * FROM deal_confirmations WHERE listing_id = ?";
    $params = [$listingId];
    if ($buyerId !== null) {
        $sql .= " AND buyer_id = ?";
        $params[] = $buyerId;
    }
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

/**
 * Обновляет рейтинг пользователя после успешной сделки
 */
function updateUserSuccessMetrics($pdo, $userId) {
    // Увеличиваем счётчик успешных сделок
    $pdo->prepare("UPDATE users SET trust_score = trust_score + 1 WHERE id = ?")->execute([$userId]);
    // Обновляем агрегированную таблицу user_ratings
    $pdo->prepare("
        INSERT INTO user_ratings (user_id, successful_deals, updated_at)
        VALUES (?, 1, NOW())
        ON DUPLICATE KEY UPDATE successful_deals = successful_deals + 1, updated_at = NOW()
    ")->execute([$userId]);
}

// ==================== ОБРАБОТКА ДЕЙСТВИЙ ====================

switch ($action) {
    case 'confirm':
        $listingId = isset($input['listing_id']) ? (int)$input['listing_id'] : 0;
        $role = $input['role'] ?? ''; // 'seller' или 'buyer'

        if (!$listingId || !in_array($role, ['seller', 'buyer'])) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Неверные параметры']);
            exit;
        }

        // Проверяем право на подтверждение
        $check = canConfirmDeal($pdo, $userId, $listingId, $role);
        if (!$check['allowed']) {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => $check['error']]);
            exit;
        }

        $sellerId = $check['seller_id'];
        $buyerId = ($role === 'seller') ? null : $userId;

        $pdo->beginTransaction();

        try {
            // Получаем или создаём запись о сделке
            $deal = getDealRecord($pdo, $listingId, $role === 'buyer' ? $userId : null);
            if (!$deal) {
                // Создаём новую запись (если покупатель ещё не начал сделку)
                if ($role !== 'buyer') {
                    throw new Exception('Сначала покупатель должен инициировать сделку');
                }
                $stmt = $pdo->prepare("
                    INSERT INTO deal_confirmations (listing_id, seller_id, buyer_id)
                    VALUES (?, ?, ?)
                ");
                $stmt->execute([$listingId, $sellerId, $userId]);
                $deal = getDealRecord($pdo, $listingId, $userId);
            }

            // Обновляем подтверждение
            $field = ($role === 'seller') ? 'seller_confirmed' : 'buyer_confirmed';
            $stmt = $pdo->prepare("
                UPDATE deal_confirmations
                SET $field = 1
                WHERE id = ? AND $field = 0
            ");
            $stmt->execute([$deal['id']]);
            $updated = $stmt->rowCount();

            if (!$updated) {
                // Уже было подтверждено
                echo json_encode(['success' => true, 'message' => 'Вы уже подтвердили эту сделку']);
                exit;
            }

            // Проверяем, оба ли подтвердили
            $stmt = $pdo->prepare("SELECT seller_confirmed, buyer_confirmed FROM deal_confirmations WHERE id = ?");
            $stmt->execute([$deal['id']]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($row['seller_confirmed'] && $row['buyer_confirmed']) {
                // Оба подтвердили – сделка завершена
                $pdo->prepare("UPDATE deal_confirmations SET confirmed_at = NOW() WHERE id = ?")->execute([$deal['id']]);
                // Обновляем метрики для обоих
                updateUserSuccessMetrics($pdo, $deal['seller_id']);
                updateUserSuccessMetrics($pdo, $deal['buyer_id']);

                // Отправляем уведомление обоим
                $notify = new NotificationService();
                $notify->send($deal['seller_id'], 'deal_completed', [
                    'listing_id' => $listingId,
                    'partner_id' => $deal['buyer_id']
                ]);
                $notify->send($deal['buyer_id'], 'deal_completed', [
                    'listing_id' => $listingId,
                    'partner_id' => $deal['seller_id']
                ]);

                echo json_encode(['success' => true, 'message' => 'Сделка подтверждена обеими сторонами!']);
            } else {
                echo json_encode(['success' => true, 'message' => 'Ваше подтверждение сохранено. Ожидаем подтверждения от ' . ($role === 'seller' ? 'покупателя' : 'продавца')]);
            }

            $pdo->commit();

            // Логируем действие
            $db->insert('security_logs', [
                'user_id' => $userId,
                'ip_address' => $ip,
                'event_type' => 'deal_confirmed',
                'description' => "Пользователь $role подтвердил сделку по объявлению #$listingId",
                'severity' => 'low',
                'created_at' => date('Y-m-d H:i:s')
            ]);

        } catch (Exception $e) {
            $pdo->rollBack();
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
        break;

    case 'status':
        // Получить статус подтверждения сделки для текущего пользователя
        $listingId = isset($input['listing_id']) ? (int)$input['listing_id'] : 0;
        if (!$listingId) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Не указан ID объявления']);
            exit;
        }

        // Определяем, является ли пользователь продавцом или покупателем
        $stmt = $pdo->prepare("SELECT user_id, type FROM listings WHERE id = ?", [$listingId]);
        $listing = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$listing) {
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => 'Объявление не найдено']);
            exit;
        }

        if ($listing['user_id'] == $userId) {
            // Пользователь – продавец
            $deal = getDealRecord($pdo, $listingId, null);
            if (!$deal) {
                echo json_encode(['success' => true, 'confirmed' => false, 'message' => 'Сделка ещё не начата']);
                break;
            }
            echo json_encode([
                'success' => true,
                'confirmed' => (bool)$deal['seller_confirmed'],
                'other_confirmed' => (bool)$deal['buyer_confirmed'],
                'confirmed_at' => $deal['confirmed_at']
            ]);
        } else {
            // Пользователь – возможный покупатель, проверим, есть ли сделка с ним
            $deal = getDealRecord($pdo, $listingId, $userId);
            if (!$deal) {
                echo json_encode(['success' => true, 'confirmed' => false, 'message' => 'Вы ещё не начинали сделку по этому объявлению']);
                break;
            }
            echo json_encode([
                'success' => true,
                'confirmed' => (bool)$deal['buyer_confirmed'],
                'other_confirmed' => (bool)$deal['seller_confirmed'],
                'confirmed_at' => $deal['confirmed_at']
            ]);
        }
        break;

    case 'list':
        // Список сделок, где пользователь участвует (как продавец или покупатель)
        $role = $input['role'] ?? 'all'; // 'seller', 'buyer', 'all'
        $page = max(1, (int)($input['page'] ?? 1));
        $limit = min(50, max(1, (int)($input['limit'] ?? 20)));
        $offset = ($page - 1) * $limit;

        $where = [];
        $params = [$userId];

        if ($role === 'seller') {
            $where[] = "d.seller_id = ?";
            $params = [$userId];
        } elseif ($role === 'buyer') {
            $where[] = "d.buyer_id = ?";
            $params = [$userId];
        } else {
            $where[] = "(d.seller_id = ? OR d.buyer_id = ?)";
            $params = [$userId, $userId];
        }

        $whereClause = 'WHERE ' . implode(' AND ', $where);
        $sql = "
            SELECT d.*, 
                   l.title as listing_title,
                   s.name as seller_name,
                   b.name as buyer_name,
                   (SELECT url FROM listing_photos WHERE listing_id = l.id ORDER BY sort_order LIMIT 1) as listing_photo
            FROM deal_confirmations d
            JOIN listings l ON d.listing_id = l.id
            JOIN users s ON d.seller_id = s.id
            JOIN users b ON d.buyer_id = b.id
            $whereClause
            ORDER BY d.created_at DESC
            LIMIT ? OFFSET ?
        ";
        $stmt = $pdo->prepare($sql);
        $stmt->execute(array_merge($params, [$limit, $offset]));
        $deals = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $countSql = "SELECT COUNT(*) FROM deal_confirmations d $whereClause";
        $total = $db->fetchCount($countSql, $params);

        echo json_encode([
            'success' => true,
            'data' => $deals,
            'meta' => [
                'total' => $total,
                'page' => $page,
                'limit' => $limit,
                'pages' => ceil($total / $limit)
            ]
        ]);
        break;

    default:
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Неизвестное действие']);
        break;
}