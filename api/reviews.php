<?php
/* ============================================
   НАЙДУК — API отзывов (reviews)
   Версия 2.0 — полная автоматизация, защита, очереди
   - Только после подтверждённой сделки
   - Обязательные фото для низких оценок (1–3★)
   - Жёсткие лимиты (создание: 3/мин, 10/час, 30/сутки; жалобы: 2/мин)
   - Авто-блокировка при превышении, детекция ботов по одинаковым отзывам
   - Асинхронный пересчёт рейтинга через Redis-очередь
   - Поведенческий вес жалоб (report_accuracy)
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

// Rate limiting с разными ключами для разных действий
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
$allowedActions = ['create', 'list', 'reply', 'report', 'helpful', 'my'];
if (!in_array($action, $allowedActions)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Неизвестное действие']);
    exit;
}

$db = Database::getInstance();
$pdo = $db->getPdo();
$redis = class_exists('Redis') ? new Redis() : null;
if ($redis) {
    $redis->connect('127.0.0.1', 6379);
}

// ==================== СОЗДАНИЕ ТАБЛИЦ (если нет) ====================
$db->query("
    CREATE TABLE IF NOT EXISTS reviews (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        listing_id BIGINT UNSIGNED NOT NULL,
        reviewer_id BIGINT UNSIGNED NOT NULL,
        reviewed_id BIGINT UNSIGNED NOT NULL,
        rating TINYINT UNSIGNED NOT NULL CHECK (rating BETWEEN 1 AND 5),
        comment TEXT,
        photos JSON,
        is_visible BOOLEAN DEFAULT TRUE,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (listing_id) REFERENCES listings(id) ON DELETE CASCADE,
        FOREIGN KEY (reviewer_id) REFERENCES users(id) ON DELETE CASCADE,
        FOREIGN KEY (reviewed_id) REFERENCES users(id) ON DELETE CASCADE,
        INDEX idx_reviewed (reviewed_id, created_at DESC),
        INDEX idx_listing (listing_id),
        INDEX idx_visible (is_visible),
        INDEX idx_reviewed_rating (reviewed_id, rating, is_visible),
        INDEX idx_reviewed_photos (reviewed_id, (photos IS NOT NULL), is_visible)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
");

$db->query("
    CREATE TABLE IF NOT EXISTS review_replies (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        review_id BIGINT UNSIGNED NOT NULL,
        user_id BIGINT UNSIGNED NOT NULL,
        reply TEXT NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        FOREIGN KEY (review_id) REFERENCES reviews(id) ON DELETE CASCADE,
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
        UNIQUE KEY unique_reply (review_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
");

$db->query("
    CREATE TABLE IF NOT EXISTS review_reports (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        review_id BIGINT UNSIGNED NOT NULL,
        reporter_id BIGINT UNSIGNED NOT NULL,
        reason VARCHAR(255),
        status ENUM('pending','resolved','rejected') DEFAULT 'pending',
        admin_note TEXT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        resolved_at TIMESTAMP NULL,
        FOREIGN KEY (review_id) REFERENCES reviews(id) ON DELETE CASCADE,
        FOREIGN KEY (reporter_id) REFERENCES users(id) ON DELETE CASCADE,
        UNIQUE KEY unique_report (review_id, reporter_id),
        INDEX idx_status (status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
");

$db->query("
    CREATE TABLE IF NOT EXISTS review_helpfulness (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        review_id BIGINT UNSIGNED NOT NULL,
        user_id BIGINT UNSIGNED NOT NULL,
        is_helpful BOOLEAN NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY unique_vote (review_id, user_id),
        FOREIGN KEY (review_id) REFERENCES reviews(id) ON DELETE CASCADE,
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
");

$db->query("
    CREATE TABLE IF NOT EXISTS user_ratings (
        user_id BIGINT UNSIGNED PRIMARY KEY,
        avg_rating DECIMAL(3,2) DEFAULT 0,
        total_reviews INT UNSIGNED DEFAULT 0,
        rating_distribution JSON,
        last_30_days_count INT DEFAULT 0,
        response_rate DECIMAL(5,2) DEFAULT 0,
        avg_response_time_seconds INT DEFAULT 0,
        successful_deals INT DEFAULT 0,
        report_accuracy DECIMAL(5,2) DEFAULT 0,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
");

// ==================== ВСПОМОГАТЕЛЬНЫЕ ФУНКЦИИ ====================

/**
 * Проверяет, может ли пользователь оставить отзыв по данной сделке
 */
function canReview($pdo, $userId, $listingId, $reviewedId) {
    $stmt = $pdo->prepare("
        SELECT id FROM deal_confirmations
        WHERE listing_id = ? AND confirmed_at IS NOT NULL
          AND (seller_id = ? OR buyer_id = ?)
    ");
    $stmt->execute([$listingId, $userId, $userId]);
    $deal = $stmt->fetch();
    if (!$deal) {
        return ['allowed' => false, 'error' => 'Вы можете оставить отзыв только после взаимного подтверждения сделки'];
    }
    $stmt = $pdo->prepare("SELECT id FROM reviews WHERE listing_id = ? AND reviewer_id = ?", [$listingId, $userId]);
    if ($stmt->fetch()) {
        return ['allowed' => false, 'error' => 'Вы уже оставляли отзыв по этой сделке'];
    }
    return ['allowed' => true];
}

/**
 * Помещает задачу пересчёта рейтинга в очередь Redis
 */
function enqueueRatingRecalc($redis, $userId) {
    if (!$redis) return false;
    $redis->rpush('rating_recalc_queue', json_encode(['user_id' => $userId, 'time' => time()]));
    return true;
}

/**
 * Жёсткая проверка лимитов с автоматической блокировкой
 * @param string $actionType create|report|helpful
 * @param int $userId
 * @param string $ip
 * @return bool true если лимит не превышен, иначе false (запрос уже обработан)
 */
function enforceRateLimit($actionType, $userId, $ip) {
    $limits = [
        'create' => [3, 10, 30],   // в минуту, в час, в сутки
        'report' => [2, 5, 10],
        'helpful' => [5, 20, 100]
    ];
    if (!isset($limits[$actionType])) return true;

    list($minuteLimit, $hourLimit, $dayLimit) = $limits[$actionType];
    $minuteKey = "ratelimit:{$actionType}:{$userId}:minute";
    $hourKey   = "ratelimit:{$actionType}:{$userId}:hour";
    $dayKey    = "ratelimit:{$actionType}:{$userId}:day";

    $redis = null;
    if (class_exists('Redis')) {
        $redis = new Redis();
        $redis->connect('127.0.0.1', 6379);
    }

    if ($redis) {
        $minuteCount = $redis->incr($minuteKey);
        if ($minuteCount == 1) $redis->expire($minuteKey, 60);
        $hourCount = $redis->incr($hourKey);
        if ($hourCount == 1) $redis->expire($hourKey, 3600);
        $dayCount = $redis->incr($dayKey);
        if ($dayCount == 1) $redis->expire($dayKey, 86400);
    } else {
        // файловый fallback (упрощённо)
        $now = time();
        $minuteFile = sys_get_temp_dir() . "/ratelimit_{$actionType}_{$userId}_minute";
        $hourFile   = sys_get_temp_dir() . "/ratelimit_{$actionType}_{$userId}_hour";
        $dayFile    = sys_get_temp_dir() . "/ratelimit_{$actionType}_{$userId}_day";
        $minuteCount = $this->getFileCounter($minuteFile, 60);
        $hourCount   = $this->getFileCounter($hourFile, 3600);
        $dayCount    = $this->getFileCounter($dayFile, 86400);
    }

    if ($minuteCount > $minuteLimit || $hourCount > $hourLimit || $dayCount > $dayLimit) {
        // Блокировка: пишем в лог, возвращаем ошибку
        $db = Database::getInstance();
        $db->insert('security_logs', [
            'user_id' => $userId,
            'ip_address' => $ip,
            'event_type' => 'rate_limit_exceeded',
            'description' => "Превышен лимит $actionType",
            'severity' => 'medium',
            'created_at' => date('Y-m-d H:i:s')
        ]);
        http_response_code(429);
        echo json_encode(['success' => false, 'error' => 'Слишком много действий. Попробуйте позже.']);
        exit;
    }
    return true;
}

/**
 * Детекция ботов: проверка, не оставлял ли пользователь одинаковые отзывы подряд
 */
function detectBotSpam($pdo, $userId, $comment) {
    if (empty($comment)) return false;
    $stmt = $pdo->prepare("
        SELECT COUNT(*) FROM reviews
        WHERE reviewer_id = ? AND comment = ? AND created_at > NOW() - INTERVAL 5 MINUTE
    ");
    $stmt->execute([$userId, $comment]);
    $count = $stmt->fetchColumn();
    if ($count >= 3) {
        $db = Database::getInstance();
        $db->insert('security_logs', [
            'user_id' => $userId,
            'ip_address' => getUserIP(),
            'event_type' => 'bot_detected',
            'description' => "Обнаружен бот: одинаковый отзыв 3 раза за 5 минут",
            'severity' => 'high',
            'created_at' => date('Y-m-d H:i:s')
        ]);
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Обнаружена подозрительная активность. Ваш аккаунт временно заблокирован.']);
        exit;
    }
    return true;
}

// ==================== ОСНОВНАЯ ЛОГИКА ====================

switch ($action) {
    case 'create':
        enforceRateLimit('create', $userId, $ip);

        $listingId = isset($input['listing_id']) ? (int)$input['listing_id'] : 0;
        $rating = isset($input['rating']) ? (int)$input['rating'] : 0;
        $comment = trim($input['comment'] ?? '');
        $photos = $input['photos'] ?? null; // массив URL

        if (!$listingId || $rating < 1 || $rating > 5) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Неверные параметры']);
            exit;
        }

        // Обязательные фото для низких оценок
        if ($rating <= 3 && empty($photos)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Для оценки 1–3 звезды обязательно загрузите фото подтверждения']);
            exit;
        }

        // Получаем продавца объявления
        $stmt = $pdo->prepare("SELECT user_id FROM listings WHERE id = ?", [$listingId]);
        $sellerId = $stmt->fetchColumn();
        if (!$sellerId) {
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => 'Объявление не найдено']);
            exit;
        }
        if ($sellerId == $userId) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Нельзя оставить отзыв на свой товар']);
            exit;
        }

        $check = canReview($pdo, $userId, $listingId, $sellerId);
        if (!$check['allowed']) {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => $check['error']]);
            exit;
        }

        // Детекция ботов
        detectBotSpam($pdo, $userId, $comment);

        // Валидация длины текста (если нет фото)
        if (empty($photos) && mb_strlen($comment) < 20) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Текст отзыва должен быть не менее 20 символов']);
            exit;
        }

        $reviewId = $db->insert('reviews', [
            'listing_id' => $listingId,
            'reviewer_id' => $userId,
            'reviewed_id' => $sellerId,
            'rating' => $rating,
            'comment' => $comment ?: null,
            'photos' => $photos ? json_encode($photos) : null,
            'created_at' => date('Y-m-d H:i:s')
        ]);

        // Отправляем задачу в очередь для асинхронного пересчёта рейтинга
        enqueueRatingRecalc($redis, $sellerId);

        // Уведомляем продавца (асинхронно, не блокируем ответ)
        $notify = new NotificationService();
        $notify->send($sellerId, 'new_review', [
            'review_id' => $reviewId,
            'listing_id' => $listingId,
            'rating' => $rating
        ]);

        // Логируем
        $db->insert('security_logs', [
            'user_id' => $userId,
            'ip_address' => $ip,
            'event_type' => 'review_created',
            'description' => "Оставлен отзыв #$reviewId на объявление #$listingId",
            'severity' => 'low',
            'created_at' => date('Y-m-d H:i:s')
        ]);

        echo json_encode(['success' => true, 'review_id' => $reviewId]);
        break;

    case 'list':
        $userIdParam = isset($input['user_id']) ? (int)$input['user_id'] : 0;
        $page = max(1, (int)($input['page'] ?? 1));
        $limit = min(50, max(1, (int)($input['limit'] ?? 20)));
        $offset = ($page - 1) * $limit;
        $ratingFilter = isset($input['rating']) ? (int)$input['rating'] : 0;
        $hasPhotos = !empty($input['has_photos']);

        if (!$userIdParam) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Не указан ID пользователя']);
            exit;
        }

        $cacheKey = "reviews:user:{$userIdParam}:page:{$page}:limit:{$limit}:rating:{$ratingFilter}:photos:{$hasPhotos}";
        if ($redis && $cached = $redis->get($cacheKey)) {
            echo $cached;
            exit;
        }

        $where = "r.reviewed_id = ? AND r.is_visible = 1";
        $params = [$userIdParam];
        if ($ratingFilter >= 1 && $ratingFilter <= 5) {
            $where .= " AND r.rating = ?";
            $params[] = $ratingFilter;
        }
        if ($hasPhotos) {
            $where .= " AND r.photos IS NOT NULL AND JSON_LENGTH(r.photos) > 0";
        }

        $total = $db->fetchCount("SELECT COUNT(*) FROM reviews r WHERE $where", $params);
        $sql = "
            SELECT r.*, u.name as reviewer_name, u.avatar_url as reviewer_avatar,
                   rr.reply as seller_reply,
                   (SELECT COUNT(*) FROM review_helpfulness WHERE review_id = r.id AND is_helpful = 1) as helpful_count,
                   (SELECT COUNT(*) FROM review_helpfulness WHERE review_id = r.id AND is_helpful = 0) as not_helpful_count
            FROM reviews r
            LEFT JOIN users u ON r.reviewer_id = u.id
            LEFT JOIN review_replies rr ON r.id = rr.review_id
            WHERE $where
            ORDER BY r.created_at DESC
            LIMIT ? OFFSET ?
        ";
        $stmt = $pdo->prepare($sql);
        $stmt->execute(array_merge($params, [$limit, $offset]));
        $reviews = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Добавляем флаг голоса текущего пользователя
        if (isset($_SESSION['user_id'])) {
            $currentUserId = $_SESSION['user_id'];
            foreach ($reviews as &$review) {
                $stmt = $pdo->prepare("SELECT is_helpful FROM review_helpfulness WHERE review_id = ? AND user_id = ?");
                $stmt->execute([$review['id'], $currentUserId]);
                $vote = $stmt->fetch(PDO::FETCH_ASSOC);
                $review['user_vote'] = $vote ? ($vote['is_helpful'] ? 'helpful' : 'not_helpful') : null;
            }
        }

        $response = json_encode([
            'success' => true,
            'data' => $reviews,
            'meta' => [
                'total' => $total,
                'page' => $page,
                'limit' => $limit,
                'pages' => ceil($total / $limit)
            ]
        ]);
        if ($redis) {
            $redis->setex($cacheKey, 300, $response);
        }
        echo $response;
        break;

    case 'reply':
        enforceRateLimit('reply', $userId, $ip); // мягкий лимит

        $reviewId = isset($input['review_id']) ? (int)$input['review_id'] : 0;
        $reply = trim($input['reply'] ?? '');

        if (!$reviewId || empty($reply)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Неверные параметры']);
            exit;
        }

        // Проверяем, что пользователь является продавцом по этому отзыву
        $stmt = $pdo->prepare("
            SELECT r.reviewed_id, l.user_id as seller_id
            FROM reviews r
            JOIN listings l ON r.listing_id = l.id
            WHERE r.id = ?
        ");
        $stmt->execute([$reviewId]);
        $review = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$review || $review['seller_id'] != $userId) {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'Доступ запрещён']);
            exit;
        }

        try {
            $db->insert('review_replies', [
                'review_id' => $reviewId,
                'user_id' => $userId,
                'reply' => $reply,
                'created_at' => date('Y-m-d H:i:s')
            ]);
            echo json_encode(['success' => true, 'message' => 'Ответ опубликован']);
        } catch (PDOException $e) {
            if ($e->errorInfo[1] == 1062) {
                $db->update('review_replies', [
                    'reply' => $reply,
                    'updated_at' => date('Y-m-d H:i:s')
                ], 'review_id = ?', [$reviewId]);
                echo json_encode(['success' => true, 'message' => 'Ответ обновлён']);
            } else {
                throw $e;
            }
        }
        break;

    case 'report':
        enforceRateLimit('report', $userId, $ip);

        $reviewId = isset($input['review_id']) ? (int)$input['review_id'] : 0;
        $reason = trim($input['reason'] ?? '');

        if (!$reviewId || empty($reason)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Неверные параметры']);
            exit;
        }

        // Проверяем, существует ли отзыв
        $stmt = $pdo->prepare("SELECT id FROM reviews WHERE id = ?", [$reviewId]);
        if (!$stmt->fetch()) {
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => 'Отзыв не найден']);
            exit;
        }

        // Получаем report_accuracy жалобщика
        $accuracy = $db->fetchColumn("SELECT report_accuracy FROM user_ratings WHERE user_id = ?", [$userId]);
        if ($accuracy === null) $accuracy = 0;

        if ($accuracy < 30) {
            // Игнорируем жалобу, но не сообщаем об этом пользователю
            echo json_encode(['success' => true, 'message' => 'Жалоба отправлена']);
            exit;
        }

        try {
            $db->insert('review_reports', [
                'review_id' => $reviewId,
                'reporter_id' => $userId,
                'reason' => $reason,
                'status' => 'pending',
                'created_at' => date('Y-m-d H:i:s')
            ]);
            // Скрываем отзыв до проверки модератором
            $db->update('reviews', ['is_visible' => 0], 'id = ?', [$reviewId]);
            echo json_encode(['success' => true, 'message' => 'Жалоба отправлена, отзыв скрыт до проверки']);
        } catch (PDOException $e) {
            if ($e->errorInfo[1] == 1062) {
                echo json_encode(['success' => false, 'error' => 'Вы уже жаловались на этот отзыв']);
            } else {
                throw $e;
            }
        }
        break;

    case 'helpful':
        enforceRateLimit('helpful', $userId, $ip);

        $reviewId = isset($input['review_id']) ? (int)$input['review_id'] : 0;
        $isHelpful = isset($input['is_helpful']) ? (bool)$input['is_helpful'] : true;

        if (!$reviewId) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Не указан ID отзыва']);
            exit;
        }

        // Нельзя голосовать за свой отзыв
        $stmt = $pdo->prepare("SELECT reviewer_id FROM reviews WHERE id = ?", [$reviewId]);
        $reviewerId = $stmt->fetchColumn();
        if ($reviewerId == $userId) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Нельзя голосовать за свой отзыв']);
            exit;
        }

        try {
            $db->insert('review_helpfulness', [
                'review_id' => $reviewId,
                'user_id' => $userId,
                'is_helpful' => $isHelpful ? 1 : 0,
                'created_at' => date('Y-m-d H:i:s')
            ]);
            echo json_encode(['success' => true, 'message' => 'Ваш голос учтён']);
        } catch (PDOException $e) {
            if ($e->errorInfo[1] == 1062) {
                $db->update('review_helpfulness', ['is_helpful' => $isHelpful ? 1 : 0], 'review_id = ? AND user_id = ?', [$reviewId, $userId]);
                echo json_encode(['success' => true, 'message' => 'Ваш голос изменён']);
            } else {
                throw $e;
            }
        }
        break;

    case 'my':
        $page = max(1, (int)($input['page'] ?? 1));
        $limit = min(50, max(1, (int)($input['limit'] ?? 20)));
        $offset = ($page - 1) * $limit;

        $sql = "
            SELECT r.*, l.title as listing_title,
                   (SELECT name FROM users WHERE id = r.reviewed_id) as reviewed_name
            FROM reviews r
            JOIN listings l ON r.listing_id = l.id
            WHERE r.reviewer_id = ?
            ORDER BY r.created_at DESC
            LIMIT ? OFFSET ?
        ";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$userId, $limit, $offset]);
        $reviews = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $total = $db->fetchCount("SELECT COUNT(*) FROM reviews WHERE reviewer_id = ?", [$userId]);

        echo json_encode([
            'success' => true,
            'data' => $reviews,
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