<?php
/* ============================================
   НАЙДУК — API Бизнес-кабинета (единый эндпоинт)
   Версия 2.1 (март 2026)
   - Добавлено действие import_status для отслеживания прогресса
   - Полная обработка офферов, вебхуков, выплат, статистики
   ============================================ */

session_start();
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../services/Database.php';

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

$userId = (int)$_SESSION['user_id'];
$ip = getUserIP();

$db = Database::getInstance();
$user = $db->fetchOne("SELECT role FROM users WHERE id = ?", [$userId]);
if (!$user || $user['role'] !== 'admin') {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Доступ запрещён']);
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

if (!checkRateLimit('admin_business_' . $userId, 50, 60)) {
    http_response_code(429);
    echo json_encode(['success' => false, 'error' => 'Слишком много запросов. Попробуйте позже.']);
    exit;
}

$action = $input['action'] ?? '';
$pdo = $db->getPdo();

// ==================== ВСПОМОГАТЕЛЬНЫЕ ФУНКЦИИ ====================

function formatPrice($price) {
    return number_format((float)$price, 0, ',', ' ');
}

function logAdminAction($pdo, $userId, $action, $resourceType, $resourceId = null, $oldData = null, $newData = null, $error = null) {
    $ip = $_SERVER['HTTP_CF_CONNECTING_IP'] ?? $_SERVER['REMOTE_ADDR'] ?? '';
    $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
    $stmt = $pdo->prepare("INSERT INTO admin_actions (user_id, action, resource_type, resource_id, old_data, new_data, ip, user_agent, error) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
    $stmt->execute([
        $userId,
        $action,
        $resourceType,
        $resourceId,
        $oldData ? json_encode($oldData) : null,
        $newData ? json_encode($newData) : null,
        $ip,
        $ua,
        $error
    ]);
}

function getCached($key, $ttl = 300) {
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
    if ($redis) {
        $val = $redis->get($key);
        return $val !== false ? json_decode($val, true) : null;
    }
    $file = __DIR__ . '/../../storage/cache/' . md5($key) . '.json';
    if (!file_exists($file)) return null;
    $data = json_decode(file_get_contents($file), true);
    if (!$data || $data['expires'] < time()) {
        @unlink($file);
        return null;
    }
    return $data['value'];
}

function setCached($key, $value, $ttl = 300) {
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
    $variation = rand(-round($ttl * 0.1), round($ttl * 0.1));
    $actualTtl = max(60, $ttl + $variation);
    if ($redis) {
        $redis->setex($key, $actualTtl, json_encode($value));
        return;
    }
    $file = __DIR__ . '/../../storage/cache/' . md5($key) . '.json';
    $dir = dirname($file);
    if (!is_dir($dir)) mkdir($dir, 0755, true);
    file_put_contents($file, json_encode(['expires' => time() + $actualTtl, 'value' => $value]), LOCK_EX);
}

function invalidateStatsCache() {
    setCached('business_stats', null, 1);
    setCached('business_top_offers', null, 1);
    setCached('business_chart', null, 1);
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
    if ($redis) {
        $keys = $redis->keys('business_offers_list_*');
        foreach ($keys as $k) $redis->del($k);
    } else {
        $files = glob(__DIR__ . '/../../storage/cache/' . md5('business_offers_') . '*');
        foreach ($files as $f) @unlink($f);
    }
}

function getOffersCacheKey($search, $status, $sort, $page, $limit) {
    return 'business_offers_list_' . md5($search . '_' . $status . '_' . $sort . '_' . $page . '_' . $limit);
}

// ==================== ОБРАБОТКА ДЕЙСТВИЙ ====================

switch ($action) {
    // ---------- ОБЗОР ----------
    case 'stats':
        $cacheKey = 'business_stats';
        $cached = getCached($cacheKey, 300);
        if ($cached) {
            echo json_encode(['success' => true, 'data' => $cached]);
            exit;
        }

        $monthAgo = date('Y-m-d', strtotime('-30 days'));
        $lastMonth = date('Y-m-d', strtotime('-60 days'));

        $stmt = $pdo->prepare("
            SELECT
                COALESCE(SUM(c.amount), 0) as revenue,
                COUNT(DISTINCT pc.click_id) as clicks,
                COUNT(c.id) as conversions
            FROM partner_clicks pc
            LEFT JOIN partner_conversions c ON pc.click_id = c.click_id AND c.status = 'approved'
            WHERE pc.clicked_at >= ?
        ");
        $stmt->execute([$monthAgo]);
        $current = $stmt->fetch(PDO::FETCH_ASSOC);
        $stmt->execute([$lastMonth]);
        $prev = $stmt->fetch(PDO::FETCH_ASSOC);

        $monthRevenue = (float)$current['revenue'];
        $monthClicks = (int)$current['clicks'];
        $monthConversions = (int)$current['conversions'];
        $prevRevenue = (float)$prev['revenue'];
        $prevClicks = (int)$prev['clicks'];
        $prevConversions = (int)$prev['conversions'];

        $revenueChange = $prevRevenue > 0 ? round(($monthRevenue - $prevRevenue) / $prevRevenue * 100, 1) : 100;
        $clicksChange = $prevClicks > 0 ? round(($monthClicks - $prevClicks) / $prevClicks * 100, 1) : 100;
        $convChange = $prevConversions > 0 ? round(($monthConversions - $prevConversions) / $prevConversions * 100, 1) : 100;

        $totalOffers = $db->fetchCount("SELECT COUNT(*) FROM partner_offers");
        $activeOffers = $db->fetchCount("SELECT COUNT(*) FROM partner_offers WHERE is_active = 1");

        $chartStmt = $pdo->prepare("
            SELECT
                DATE(pc.clicked_at) as date,
                COUNT(DISTINCT pc.click_id) as clicks,
                COALESCE(SUM(c.amount), 0) as revenue
            FROM partner_clicks pc
            LEFT JOIN partner_conversions c ON pc.click_id = c.click_id AND c.status = 'approved'
            WHERE pc.clicked_at >= ?
            GROUP BY DATE(pc.clicked_at)
            ORDER BY date ASC
        ");
        $chartStmt->execute([$monthAgo]);
        $chartRows = $chartStmt->fetchAll(PDO::FETCH_ASSOC);
        $chartLabels = [];
        $chartClicks = [];
        $chartRevenue = [];
        foreach ($chartRows as $row) {
            $chartLabels[] = date('d.m', strtotime($row['date']));
            $chartClicks[] = (int)$row['clicks'];
            $chartRevenue[] = (float)$row['revenue'];
        }

        $topStmt = $pdo->prepare("
            SELECT
                po.id,
                po.name,
                po.partner_name,
                COUNT(DISTINCT pc.click_id) as clicks,
                COUNT(c.id) as conversions,
                COALESCE(SUM(c.amount), 0) as revenue
            FROM partner_offers po
            LEFT JOIN partner_clicks pc ON po.id = pc.offer_id AND pc.clicked_at >= ?
            LEFT JOIN partner_conversions c ON pc.click_id = c.click_id AND c.status = 'approved'
            WHERE po.is_active = 1
            GROUP BY po.id
            ORDER BY revenue DESC
            LIMIT 5
        ");
        $topStmt->execute([$monthAgo]);
        $topOffers = $topStmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($topOffers as &$o) {
            $o['revenue'] = (float)$o['revenue'];
        }

        $stats = [
            'month_revenue' => $monthRevenue,
            'month_clicks' => $monthClicks,
            'month_conversions' => $monthConversions,
            'month_change' => $revenueChange,
            'clicks_change' => $clicksChange,
            'conversions_change' => $convChange,
            'total_offers' => $totalOffers,
            'active_offers' => $activeOffers,
            'chart_labels' => $chartLabels,
            'chart_clicks' => $chartClicks,
            'chart_revenue' => $chartRevenue,
            'top_offers' => $topOffers
        ];

        setCached($cacheKey, $stats, 300);
        echo json_encode(['success' => true, 'data' => $stats]);
        break;

    // ---------- ОФФЕРЫ: список ----------
    case 'offers_list':
        $page = max(1, (int)($input['page'] ?? 1));
        $limit = min(100, max(1, (int)($input['limit'] ?? 20)));
        $offset = ($page - 1) * $limit;
        $search = trim($input['search'] ?? '');
        $status = $input['status'] ?? '';
        $sort = $input['sort'] ?? 'revenue_desc';

        $cacheKey = getOffersCacheKey($search, $status, $sort, $page, $limit);
        $cached = getCached($cacheKey, 120);
        if ($cached) {
            echo json_encode($cached);
            exit;
        }

        $where = [];
        $params = [];

        if ($search !== '') {
            $where[] = "(po.name LIKE ? OR po.partner_name LIKE ?)";
            $params[] = "%$search%";
            $params[] = "%$search%";
        }
        if ($status !== '') {
            $where[] = "po.is_active = ?";
            $params[] = (int)$status;
        }

        $whereClause = $where ? 'WHERE ' . implode(' AND ', $where) : '';

        $orderBy = match($sort) {
            'revenue_desc' => 'revenue DESC',
            'revenue_asc' => 'revenue ASC',
            'clicks_desc' => 'clicks DESC',
            'name_asc' => 'po.name ASC',
            default => 'revenue DESC',
        };

        $countCacheKey = 'business_offers_count_' . md5($search . '_' . $status);
        $total = getCached($countCacheKey, 300);
        if ($total === null) {
            $countSql = "SELECT COUNT(*) FROM partner_offers po $whereClause";
            $countStmt = $pdo->prepare($countSql);
            $countStmt->execute($params);
            $total = (int)$countStmt->fetchColumn();
            setCached($countCacheKey, $total, 300);
        }

        $sql = "
            SELECT
                po.id,
                po.name,
                po.partner_name,
                po.commission_type,
                po.commission_value,
                po.url_template,
                po.city,
                po.sort_order,
                po.is_active,
                COALESCE(SUM(c.amount), 0) as revenue,
                COUNT(DISTINCT pc.click_id) as clicks,
                COUNT(c.id) as conversions
            FROM partner_offers po
            LEFT JOIN partner_clicks pc ON po.id = pc.offer_id
            LEFT JOIN partner_conversions c ON pc.click_id = c.click_id AND c.status = 'approved'
            $whereClause
            GROUP BY po.id
            ORDER BY $orderBy
            LIMIT ? OFFSET ?
        ";
        $queryParams = array_merge($params, [$limit, $offset]);
        $stmt = $pdo->prepare($sql);
        $stmt->execute($queryParams);
        $offers = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $response = [
            'success' => true,
            'data' => $offers,
            'meta' => [
                'total' => $total,
                'page' => $page,
                'limit' => $limit,
                'pages' => ceil($total / $limit)
            ]
        ];
        setCached($cacheKey, $response, 120);
        echo json_encode($response);
        break;

    // ---------- ОФФЕРЫ: получить один ----------
    case 'offer_get':
        $id = (int)($input['id'] ?? 0);
        if (!$id) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Не указан ID']);
            exit;
        }
        $offer = $db->fetchOne("SELECT * FROM partner_offers WHERE id = ?", [$id]);
        if (!$offer) {
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => 'Оффер не найден']);
            exit;
        }
        echo json_encode(['success' => true, 'data' => $offer]);
        break;

    // ---------- ОФФЕРЫ: создание ----------
    case 'offer_create':
        $name = trim($input['name'] ?? '');
        $partnerName = trim($input['partner_name'] ?? '');
        $category = trim($input['category'] ?? '') ?: null;
        $commissionType = $input['commission_type'] ?? 'percent';
        $commissionValue = (float)($input['commission_value'] ?? 0);
        $urlTemplate = trim($input['url_template'] ?? '');
        $city = trim($input['city'] ?? '') ?: null;
        $sortOrder = (int)($input['sort_order'] ?? 0);
        $isActive = isset($input['is_active']) ? (int)$input['is_active'] : 1;

        if (!$name || !$partnerName || !$urlTemplate || $commissionValue <= 0) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Заполните все обязательные поля']);
            exit;
        }

        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare("
                INSERT INTO partner_offers (name, partner_name, category, commission_type, commission_value, url_template, city, sort_order, is_active, created_at, updated_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
            ");
            $stmt->execute([$name, $partnerName, $category, $commissionType, $commissionValue, $urlTemplate, $city, $sortOrder, $isActive]);
            $newId = $pdo->lastInsertId();
            $pdo->commit();
            logAdminAction($pdo, $userId, 'offer_create', 'offer', $newId, null, ['name' => $name]);
            invalidateStatsCache();
            echo json_encode(['success' => true, 'message' => 'Оффер добавлен', 'id' => $newId]);
        } catch (Exception $e) {
            $pdo->rollBack();
            logAdminAction($pdo, $userId, 'offer_create', 'offer', null, null, null, $e->getMessage());
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => 'Ошибка при добавлении: ' . $e->getMessage()]);
        }
        break;

    // ---------- ОФФЕРЫ: обновление ----------
    case 'offer_update':
        $id = (int)($input['offer_id'] ?? 0);
        if (!$id) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Не указан ID']);
            exit;
        }
        $old = $db->fetchOne("SELECT * FROM partner_offers WHERE id = ?", [$id]);
        if (!$old) {
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => 'Оффер не найден']);
            exit;
        }

        $updateData = [];
        if (isset($input['name'])) $updateData['name'] = trim($input['name']);
        if (isset($input['partner_name'])) $updateData['partner_name'] = trim($input['partner_name']);
        if (isset($input['category'])) $updateData['category'] = trim($input['category']) ?: null;
        if (isset($input['commission_type'])) $updateData['commission_type'] = $input['commission_type'];
        if (isset($input['commission_value'])) $updateData['commission_value'] = (float)$input['commission_value'];
        if (isset($input['url_template'])) $updateData['url_template'] = trim($input['url_template']);
        if (isset($input['city'])) $updateData['city'] = trim($input['city']) ?: null;
        if (isset($input['sort_order'])) $updateData['sort_order'] = (int)$input['sort_order'];
        if (isset($input['is_active'])) $updateData['is_active'] = (int)$input['is_active'];
        $updateData['updated_at'] = date('Y-m-d H:i:s');

        if (empty($updateData)) {
            echo json_encode(['success' => true, 'message' => 'Нет изменений']);
            exit;
        }

        $db->update('partner_offers', $updateData, 'id = ?', [$id]);
        logAdminAction($pdo, $userId, 'offer_update', 'offer', $id, $old, $updateData);
        invalidateStatsCache();
        echo json_encode(['success' => true, 'message' => 'Оффер обновлён']);
        break;

    // ---------- ОФФЕРЫ: удаление ----------
    case 'offer_delete':
        $id = (int)($input['id'] ?? 0);
        if (!$id) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Не указан ID']);
            exit;
        }
        $old = $db->fetchOne("SELECT name FROM partner_offers WHERE id = ?", [$id]);
        if (!$old) {
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => 'Оффер не найден']);
            exit;
        }
        $hasClicks = $db->fetchCount("SELECT 1 FROM partner_clicks WHERE offer_id = ? LIMIT 1", [$id]);
        if ($hasClicks) {
            http_response_code(409);
            echo json_encode(['success' => false, 'error' => 'Невозможно удалить оффер, по нему уже есть клики. Отключите его вместо удаления.']);
            exit;
        }
        $db->delete('partner_offers', 'id = ?', [$id]);
        logAdminAction($pdo, $userId, 'offer_delete', 'offer', $id, $old, null);
        invalidateStatsCache();
        echo json_encode(['success' => true, 'message' => 'Оффер удалён']);
        break;

    // ---------- ВЕБХУКИ: список ----------
    case 'webhooks_list':
        $cacheKey = 'business_webhooks_list';
        $hooks = getCached($cacheKey, 300);
        if ($hooks === null) {
            $hooks = $db->fetchAll("SELECT * FROM webhooks ORDER BY id DESC");
            setCached($cacheKey, $hooks, 300);
        }
        echo json_encode(['success' => true, 'data' => $hooks]);
        break;

    case 'webhook_get':
        $id = (int)($input['id'] ?? 0);
        if (!$id) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Не указан ID']);
            exit;
        }
        $hook = $db->fetchOne("SELECT * FROM webhooks WHERE id = ?", [$id]);
        if (!$hook) {
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => 'Вебхук не найден']);
            exit;
        }
        echo json_encode(['success' => true, 'data' => $hook]);
        break;

    case 'webhook_create':
        $name = trim($input['name'] ?? '');
        $url = trim($input['url'] ?? '');
        $events = $input['events'] ?? [];
        $isActive = isset($input['is_active']) ? (int)$input['is_active'] : 1;

        if (!$name || !$url) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Название и URL обязательны']);
            exit;
        }

        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare("
                INSERT INTO webhooks (user_id, name, url, events, is_active, created_at, updated_at)
                VALUES (?, ?, ?, ?, ?, NOW(), NOW())
            ");
            $stmt->execute([$userId, $name, $url, json_encode($events), $isActive]);
            $newId = $pdo->lastInsertId();
            $pdo->commit();
            logAdminAction($pdo, $userId, 'webhook_create', 'webhook', $newId, null, ['name' => $name, 'url' => $url]);
            setCached('business_webhooks_list', null, 1);
            echo json_encode(['success' => true, 'message' => 'Вебхук создан', 'id' => $newId]);
        } catch (Exception $e) {
            $pdo->rollBack();
            logAdminAction($pdo, $userId, 'webhook_create', 'webhook', null, null, null, $e->getMessage());
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => 'Ошибка при создании: ' . $e->getMessage()]);
        }
        break;

    case 'webhook_update':
        $id = (int)($input['webhook_id'] ?? 0);
        if (!$id) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Не указан ID']);
            exit;
        }
        $old = $db->fetchOne("SELECT * FROM webhooks WHERE id = ?", [$id]);
        if (!$old) {
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => 'Вебхук не найден']);
            exit;
        }

        $updateData = [];
        if (isset($input['name'])) $updateData['name'] = trim($input['name']);
        if (isset($input['url'])) $updateData['url'] = trim($input['url']);
        if (isset($input['events'])) $updateData['events'] = json_encode($input['events']);
        if (isset($input['is_active'])) $updateData['is_active'] = (int)$input['is_active'];
        $updateData['updated_at'] = date('Y-m-d H:i:s');

        if (empty($updateData)) {
            echo json_encode(['success' => true, 'message' => 'Нет изменений']);
            exit;
        }

        $db->update('webhooks', $updateData, 'id = ?', [$id]);
        logAdminAction($pdo, $userId, 'webhook_update', 'webhook', $id, $old, $updateData);
        setCached('business_webhooks_list', null, 1);
        echo json_encode(['success' => true, 'message' => 'Вебхук обновлён']);
        break;

    case 'webhook_delete':
        $id = (int)($input['id'] ?? 0);
        if (!$id) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Не указан ID']);
            exit;
        }
        $old = $db->fetchOne("SELECT name FROM webhooks WHERE id = ?", [$id]);
        if (!$old) {
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => 'Вебхук не найден']);
            exit;
        }
        $db->delete('webhooks', 'id = ?', [$id]);
        logAdminAction($pdo, $userId, 'webhook_delete', 'webhook', $id, $old, null);
        setCached('business_webhooks_list', null, 1);
        echo json_encode(['success' => true, 'message' => 'Вебхук удалён']);
        break;

    case 'webhook_test':
        $id = (int)($input['id'] ?? 0);
        if (!$id) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Не указан ID']);
            exit;
        }
        $hook = $db->fetchOne("SELECT url FROM webhooks WHERE id = ? AND is_active = 1", [$id]);
        if (!$hook) {
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => 'Вебхук не найден или неактивен']);
            exit;
        }
        $ch = curl_init($hook['url']);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode(['test' => true, 'event' => 'test', 'timestamp' => time()]));
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 5);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($httpCode >= 200 && $httpCode < 300) {
            echo json_encode(['success' => true, 'message' => 'Тестовое уведомление отправлено успешно']);
        } else {
            echo json_encode(['success' => false, 'error' => 'Ошибка при отправке (HTTP ' . $httpCode . ')']);
        }
        break;

    // ---------- ВЫПЛАТЫ: список ----------
    case 'payouts_list':
        $page = max(1, (int)($input['page'] ?? 1));
        $limit = min(100, max(1, (int)($input['limit'] ?? 20)));
        $offset = ($page - 1) * $limit;

        $cacheKey = "business_payouts_list_page_{$page}_limit_{$limit}";
        $cached = getCached($cacheKey, 60);
        if ($cached) {
            echo json_encode($cached);
            exit;
        }

        $stmt = $pdo->prepare("
            SELECT p.*, u.name as user_name, u.email as user_email
            FROM partner_payouts p
            LEFT JOIN users u ON p.user_id = u.id
            ORDER BY p.created_at DESC
            LIMIT ? OFFSET ?
        ");
        $stmt->execute([$limit, $offset]);
        $payouts = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $total = $db->fetchCount("SELECT COUNT(*) FROM partner_payouts");

        $response = [
            'success' => true,
            'data' => $payouts,
            'meta' => [
                'total' => $total,
                'page' => $page,
                'limit' => $limit,
                'pages' => ceil($total / $limit)
            ]
        ];
        setCached($cacheKey, $response, 60);
        echo json_encode($response);
        break;

    case 'payout_create':
        $userIdTarget = (int)($input['user_id'] ?? 0);
        $amount = (float)($input['amount'] ?? 0);
        $comment = trim($input['comment'] ?? '');

        if (!$userIdTarget || $amount <= 0) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Укажите партнёра и сумму']);
            exit;
        }

        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare("
                INSERT INTO partner_payouts (user_id, amount, status, created_at)
                VALUES (?, ?, 'pending', NOW())
            ");
            $stmt->execute([$userIdTarget, $amount]);
            $newId = $pdo->lastInsertId();
            $pdo->commit();
            logAdminAction($pdo, $userId, 'payout_create', 'payout', $newId, null, ['user_id' => $userIdTarget, 'amount' => $amount]);
            echo json_encode(['success' => true, 'message' => 'Выплата создана', 'id' => $newId]);
        } catch (Exception $e) {
            $pdo->rollBack();
            logAdminAction($pdo, $userId, 'payout_create', 'payout', null, null, null, $e->getMessage());
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => 'Ошибка при создании выплаты: ' . $e->getMessage()]);
        }
        break;

    case 'payout_mark_paid':
        $id = (int)($input['id'] ?? 0);
        if (!$id) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Не указан ID выплаты']);
            exit;
        }
        $old = $db->fetchOne("SELECT status FROM partner_payouts WHERE id = ?", [$id]);
        if (!$old) {
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => 'Выплата не найдена']);
            exit;
        }
        if ($old['status'] === 'paid') {
            echo json_encode(['success' => true, 'message' => 'Выплата уже отмечена как выплаченная']);
            exit;
        }
        $db->update('partner_payouts', ['status' => 'paid', 'paid_at' => date('Y-m-d H:i:s')], 'id = ?', [$id]);
        logAdminAction($pdo, $userId, 'payout_mark_paid', 'payout', $id, $old, ['status' => 'paid']);
        echo json_encode(['success' => true, 'message' => 'Выплата отмечена как выплаченная']);
        break;

    // ---------- СПИСОК ПАРТНЁРОВ ----------
    case 'partners_list':
        $cacheKey = 'business_partners_list';
        $partners = getCached($cacheKey, 3600);
        if ($partners === null) {
            $partners = $db->fetchAll("SELECT id, name, email FROM users WHERE is_partner = 1 ORDER BY name");
            setCached($cacheKey, $partners, 3600);
        }
        echo json_encode(['success' => true, 'data' => $partners]);
        break;

    // ---------- ИСТОРИЯ ИМПОРТОВ ----------
    case 'import_history':
        $cacheKey = 'business_import_history';
        $history = getCached($cacheKey, 60);
        if ($history === null) {
            $history = $db->fetchAll("
                SELECT * FROM import_jobs
                WHERE queue_name = 'import_queue' OR job_type = 'cpa_import'
                ORDER BY created_at DESC
                LIMIT 20
            ");
            setCached($cacheKey, $history, 60);
        }
        echo json_encode(['success' => true, 'data' => $history]);
        break;

    // ---------- СТАТУС ИМПОРТА (добавлено) ----------
    case 'import_status':
        $jobId = $input['job_id'] ?? '';
        if (!$jobId) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Не указан job_id']);
            exit;
        }
        $job = $db->fetchOne("SELECT * FROM import_jobs WHERE job_id = ?", [$jobId]);
        if (!$job) {
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => 'Задача не найдена']);
            exit;
        }
        echo json_encode(['success' => true, 'data' => $job]);
        break;

    default:
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Неизвестное действие']);
        break;
}