#!/usr/bin/env php
<?php
/* ============================================
   НАЙДУК — Воркер импорта из CPA-сетей и обработки постбеков
   Версия 2.0 (март 2026)
   - Архитектура "confirmer", а не "forwarder"
   - HMAC для защиты click_id
   - Дедупликация конверсий
   - TTL на постбеки
   - Поддержка Admitad, Actionpay, AdvCake, Leads.su
   ============================================ */

if (php_sapi_name() !== 'cli') {
    die("Этот скрипт предназначен только для запуска из командной строки.\n");
}

require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../services/Database.php';
require_once __DIR__ . '/../services/NotificationService.php';

// ==================== КОНФИГУРАЦИЯ ====================
define('LOG_FILE', __DIR__ . '/../logs/cpa_import.log');
define('CONVERSION_TTL_DAYS', 30);           // постбеки принимаем только в течение 30 дней после клика
define('REQUEST_TIMEOUT', 15);                // таймаут запросов к API
define('REQUEST_DELAY', 500000);              // 0.5 сек между запросами

// ==================== КОНФИГУРАЦИЯ CPA-СЕТЕЙ ====================
// Каждая сеть имеет:
// - url_template: шаблон для получения офферов
// - postback_url: куда отправлять конверсии (если сеть требует)
// - api_key_env: название ключа в owner_settings
// - mapping: как преобразовать поля из ответа API в нашу структуру
$cpaNetworks = [
    'admitad' => [
        'name' => 'Admitad',
        'url_offers' => 'https://api.admitad.com/api/offers/',
        'postback_url' => 'https://ad.admitad.com/go/',
        'api_key_env' => 'admitad_api_key',
        'mapping' => [
            'external_id' => 'id',
            'name' => 'name',
            'description' => 'description',
            'commission_type' => 'payment_type',
            'commission_value' => 'payment_sum',
            'category' => 'categories',
            'url' => 'goto_url',
        ],
        'commission_type_map' => [
            'cpa' => ['fixed', 'cpa'],
            'cps' => ['percent', 'cps', '%'],
        ],
        'headers' => ['Authorization' => 'Bearer {key}'],
        'pagination' => ['type' => 'offset', 'limit' => 100, 'offset_param' => 'offset'],
        'response_path' => 'results',
    ],
    'actionpay' => [
        'name' => 'Actionpay',
        'url_offers' => 'https://api.actionpay.ru/v1/offers',
        'api_key_env' => 'actionpay_api_key',
        'mapping' => [
            'external_id' => 'id',
            'name' => 'name',
            'description' => 'description',
            'commission_type' => 'rate_type',
            'commission_value' => 'rate',
            'category' => 'category_name',
            'url' => 'url',
        ],
        'headers' => ['Authorization' => 'Bearer {key}'],
        'pagination' => ['type' => 'page', 'limit' => 100, 'page_param' => 'page'],
        'response_path' => 'offers',
    ],
    'advcake' => [
        'name' => 'AdvCake',
        'url_offers' => 'https://advcake.ru/api/v2/offers',
        'api_key_env' => 'advcake_api_key',
        'mapping' => [
            'external_id' => 'id',
            'name' => 'name',
            'description' => 'description',
            'commission_type' => 'payout_type',
            'commission_value' => 'payout',
            'category' => 'vertical',
            'url' => 'url',
        ],
        'headers' => ['X-API-Key' => '{key}'],
        'pagination' => ['type' => 'page', 'limit' => 100, 'page_param' => 'page'],
        'response_path' => 'offers',
    ],
    'leads_su' => [
        'name' => 'Leads.su',
        'url_offers' => 'https://api.leads.su/v2/offers',
        'api_key_env' => 'leads_su_api_key',
        'mapping' => [
            'external_id' => 'id',
            'name' => 'name',
            'description' => 'description',
            'commission_type' => 'commission_type',
            'commission_value' => 'commission',
            'category' => 'category',
            'url' => 'url',
        ],
        'headers' => ['Authorization' => 'Bearer {key}'],
        'pagination' => ['type' => 'page', 'limit' => 100, 'page_param' => 'page'],
        'response_path' => 'data',
    ],
];

// ==================== ВСПОМОГАТЕЛЬНЫЕ ФУНКЦИИ ====================

function logMessage($message, $level = 'INFO') {
    $date = date('Y-m-d H:i:s');
    $log = "[$date] [$level] $message" . PHP_EOL;
    file_put_contents(LOG_FILE, $log, FILE_APPEND | LOCK_EX);
}

function httpRequest($url, $method = 'GET', $headers = [], $data = null) {
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, REQUEST_TIMEOUT);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_USERAGENT, 'Nayduk/CPA-Importer/2.0');
    
    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        if ($data) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
            $headers['Content-Type'] = 'application/json';
        }
    }
    
    if (!empty($headers)) {
        $headerArray = [];
        foreach ($headers as $key => $value) {
            $headerArray[] = "$key: $value";
        }
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headerArray);
    }
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    
    if ($httpCode !== 200) {
        return ['error' => "HTTP $httpCode: $error"];
    }
    
    $data = json_decode($response, true);
    if (!$data) {
        return ['error' => 'Invalid JSON response'];
    }
    
    return $data;
}

/**
 * Получить офферы из CPA-сети с пагинацией
 */
function fetchOffersFromNetwork($network, $apiKey) {
    $allItems = [];
    $config = $network['pagination'];
    $limit = $config['limit'];
    $offset = 0;
    $page = 1;
    $hasMore = true;
    
    while ($hasMore) {
        $url = $network['url_offers'];
        $params = [];
        
        if ($config['type'] === 'offset') {
            $params[$config['offset_param']] = $offset;
            $params['limit'] = $limit;
        } else {
            $params[$config['page_param']] = $page;
            $params['limit'] = $limit;
        }
        
        $url .= '?' . http_build_query($params);
        
        $headers = [];
        foreach ($network['headers'] as $key => $value) {
            $headers[$key] = str_replace('{key}', $apiKey, $value);
        }
        
        $result = httpRequest($url, 'GET', $headers);
        
        if (isset($result['error'])) {
            logMessage("{$network['name']} API error: " . $result['error'], 'ERROR');
            break;
        }
        
        // Извлекаем данные по пути response_path
        $items = $result;
        $pathParts = explode('.', $network['response_path']);
        foreach ($pathParts as $part) {
            if (isset($items[$part])) {
                $items = $items[$part];
            } else {
                $items = [];
                break;
            }
        }
        
        if (empty($items)) {
            break;
        }
        
        $allItems = array_merge($allItems, $items);
        
        // Проверяем, есть ли следующая страница
        if ($config['type'] === 'offset') {
            $offset += $limit;
            $hasMore = count($items) >= $limit;
        } else {
            $page++;
            $hasMore = count($items) >= $limit;
        }
        
        usleep(REQUEST_DELAY);
    }
    
    return $allItems;
}

/**
 * Нормализовать оффер из сети в структуру affiliates
 */
function normalizeOffer($offer, $network, $networkName, $sourceId) {
    $mapping = $network['mapping'];
    $normalized = [];
    
    foreach ($mapping as $ourField => $theirField) {
        $value = null;
        if (strpos($theirField, '.') !== false) {
            $parts = explode('.', $theirField);
            $temp = $offer;
            foreach ($parts as $part) {
                if (isset($temp[$part])) {
                    $temp = $temp[$part];
                } else {
                    $temp = null;
                    break;
                }
            }
            $value = $temp;
        } else {
            $value = $offer[$theirField] ?? null;
        }
        
        // Обработка категорий (может быть массивом)
        if ($ourField === 'category' && is_array($value)) {
            $value = implode(',', array_slice($value, 0, 3));
        }
        
        $normalized[$ourField] = $value;
    }
    
    // Определяем тип комиссии
    $commissionTypeRaw = strtolower($normalized['commission_type'] ?? '');
    $commissionType = 'cpa';
    foreach ($network['commission_type_map'] ?? [] as $stdType => $aliases) {
        foreach ($aliases as $alias) {
            if (strpos($commissionTypeRaw, $alias) !== false) {
                $commissionType = $stdType;
                break 2;
            }
        }
    }
    
    // Нормализуем значение комиссии
    $commissionValue = (float)preg_replace('/[^0-9.]/', '', $normalized['commission_value'] ?? 0);
    
    // URL оффера
    $urlTemplate = $normalized['url'] ?? '';
    if (empty($urlTemplate)) {
        $urlTemplate = $network['postback_url'] . $normalized['external_id'];
    }
    
    return [
        'partner_name' => $network['name'],
        'offer_name' => $normalized['name'] ?? 'Оффер',
        'slug' => transliterate($network['name'] . '-' . ($normalized['name'] ?? $normalized['external_id'])),
        'category' => $normalized['category'] ?? 'ecom',
        'commission_type' => $commissionType,
        'commission_value' => $commissionValue,
        'url_template' => $urlTemplate,
        'is_smartlink' => 1,
        'source' => 'cpa_network',
        'cpa_network' => $networkName,
        'external_id' => (string)($normalized['external_id'] ?? ''),
        'description' => $normalized['description'] ?? null,
        'is_active' => 1,
        'is_approved' => 0,
    ];
}

/**
 * Сохранить или обновить оффер в базе
 */
function saveAffiliate($pdo, $offer, $networkName) {
    // Проверяем, существует ли уже такой оффер (по source + external_id)
    $stmt = $pdo->prepare("SELECT id FROM affiliates WHERE cpa_network = ? AND external_id = ?");
    $stmt->execute([$networkName, $offer['external_id']]);
    $existing = $stmt->fetch();
    
    if ($existing) {
        // Обновляем существующий
        $updateFields = [];
        $params = [];
        foreach ($offer as $key => $value) {
            if ($key !== 'external_id' && $key !== 'cpa_network') {
                $updateFields[] = "$key = ?";
                $params[] = $value;
            }
        }
        $params[] = $networkName;
        $params[] = $offer['external_id'];
        
        $sql = "UPDATE affiliates SET " . implode(', ', $updateFields) . ", updated_at = NOW() WHERE cpa_network = ? AND external_id = ?";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        logMessage("Updated: {$offer['partner_name']} - {$offer['offer_name']}");
        return $existing['id'];
    }
    
    // Создаём новый оффер (требует одобрения админа)
    $fields = array_keys($offer);
    $placeholders = implode(', ', array_fill(0, count($offer), '?'));
    $sql = "INSERT INTO affiliates (" . implode(', ', $fields) . ", created_at, updated_at) VALUES ($placeholders, NOW(), NOW())";
    $stmt = $pdo->prepare($sql);
    $stmt->execute(array_values($offer));
    $id = $pdo->lastInsertId();
    
    // Создаём задачу для админа на проверку
    $stmt = $pdo->prepare("
        INSERT INTO admin_tasks (type, data, status, created_at)
        VALUES ('new_affiliate', ?, 'pending', NOW())
    ");
    $stmt->execute([json_encode([
        'affiliate_id' => $id,
        'name' => $offer['offer_name'],
        'partner' => $offer['partner_name'],
        'source' => 'cpa_network',
        'network' => $networkName,
    ])]);
    
    logMessage("Created new affiliate draft: {$offer['partner_name']} - {$offer['offer_name']}");
    return $id;
}

/**
 * Генерация HMAC для click_id (защита от обратного восстановления)
 */
function hashClickId($clickId, $secretKey) {
    return hash_hmac('sha256', $clickId, $secretKey);
}

/**
 * Обработка входящего постбека от CPA-сети (вызывается из API)
 * Это публичный метод для внешнего вебхука
 */
function processPostback($clickId, $conversionId, $amount, $userId = null) {
    $db = Database::getInstance();
    $pdo = $db->getPdo();
    $secretKey = $db->getOwnerSetting('hmac_secret');
    
    // Хэшируем click_id для поиска (без хранения оригинального)
    $clickHash = hashClickId($clickId, $secretKey);
    
    // Проверяем, существует ли клик
    $stmt = $pdo->prepare("SELECT id, affiliate_id, user_id, clicked_at FROM affiliate_clicks WHERE click_hash = ?");
    $stmt->execute([$clickHash]);
    $click = $stmt->fetch();
    
    if (!$click) {
        logMessage("Postback: click_id not found: $clickId", 'WARNING');
        return false;
    }
    
    // Проверяем TTL (не старше 30 дней)
    $clickTime = strtotime($click['clicked_at']);
    if (time() - $clickTime > CONVERSION_TTL_DAYS * 86400) {
        logMessage("Postback: click expired (clicked: {$click['clicked_at']})", 'WARNING');
        return false;
    }
    
    // Дедупликация
    $stmt = $pdo->prepare("SELECT id FROM partner_conversions WHERE click_id = ? AND conversion_id = ?");
    $stmt->execute([$click['id'], $conversionId]);
    if ($stmt->fetch()) {
        logMessage("Postback: duplicate conversion $conversionId for click $clickId", 'WARNING');
        return false;
    }
    
    // Сохраняем конверсию
    $stmt = $pdo->prepare("
        INSERT INTO partner_conversions 
        (click_id, conversion_id, amount, status, converted_at)
        VALUES (?, ?, ?, 'pending', NOW())
    ");
    $stmt->execute([$click['id'], $conversionId, $amount]);
    $convId = $pdo->lastInsertId();
    
    logMessage("Postback: saved conversion $conversionId for click $clickId, amount: $amount");
    
    // Создаём задачу для админа на подтверждение конверсии (если нужно ручное подтверждение)
    if ($amount > 1000) { // крупные конверсии требуют проверки
        $stmt = $pdo->prepare("
            INSERT INTO admin_tasks (type, data, status, created_at)
            VALUES ('conversion_verify', ?, 'pending', NOW())
        ");
        $stmt->execute([json_encode([
            'conversion_id' => $convId,
            'click_id' => $clickId,
            'amount' => $amount,
        ])]);
    }
    
    return true;
}

// ==================== ОСНОВНОЙ ЦИКЛ ====================

logMessage("=== CPA Importer v2.0 started ===");

$db = Database::getInstance();
$pdo = $db->getPdo();

// Проверяем наличие необходимых таблиц
$db->query("
    CREATE TABLE IF NOT EXISTS affiliate_clicks (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        affiliate_id BIGINT UNSIGNED NOT NULL,
        user_id BIGINT UNSIGNED,
        click_hash VARCHAR(64) NOT NULL,
        original_click_id VARCHAR(255),
        ip VARCHAR(45),
        user_agent TEXT,
        clicked_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_click_hash (click_hash),
        INDEX idx_affiliate (affiliate_id),
        INDEX idx_clicked (clicked_at),
        FOREIGN KEY (affiliate_id) REFERENCES affiliates(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

$db->query("
    CREATE TABLE IF NOT EXISTS partner_conversions (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        click_id BIGINT UNSIGNED NOT NULL,
        conversion_id VARCHAR(255) UNIQUE NOT NULL,
        amount DECIMAL(12,2),
        status ENUM('pending', 'approved', 'rejected', 'paid') DEFAULT 'pending',
        converted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_click (click_id),
        INDEX idx_conversion_id (conversion_id),
        FOREIGN KEY (click_id) REFERENCES affiliate_clicks(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

// Получаем все API-ключи из настроек
$apiKeys = [];
foreach ($cpaNetworks as $networkKey => $network) {
    $key = $db->getOwnerSetting($network['api_key_env']);
    if (!empty($key)) {
        $apiKeys[$networkKey] = $key;
    } else {
        logMessage("API key missing for {$network['name']} (env: {$network['api_key_env']})", 'WARNING');
    }
}

if (empty($apiKeys)) {
    logMessage("No CPA API keys configured. Run: INSERT INTO owner_settings (key_name, value) VALUES ('admitad_api_key', 'your_key')", 'ERROR');
    exit(1);
}

$totalProcessed = 0;
$totalErrors = 0;

foreach ($cpaNetworks as $networkKey => $network) {
    if (!isset($apiKeys[$networkKey])) {
        continue;
    }
    
    logMessage("Processing network: {$network['name']}");
    
    try {
        $offers = fetchOffersFromNetwork($network, $apiKeys[$networkKey]);
        logMessage("  Fetched " . count($offers) . " offers");
        
        foreach ($offers as $rawOffer) {
            try {
                $normalized = normalizeOffer($rawOffer, $network, $networkKey, $apiKeys[$networkKey]);
                if (!empty($normalized['external_id'])) {
                    saveAffiliate($pdo, $normalized, $networkKey);
                    $totalProcessed++;
                }
            } catch (Exception $e) {
                logMessage("  Error processing offer: " . $e->getMessage(), 'ERROR');
                $totalErrors++;
                
                // Логируем ошибку
                $stmt = $pdo->prepare("INSERT INTO affiliate_import_errors (source, error_message, raw_data) VALUES (?, ?, ?)");
                $stmt->execute(['cpa_' . $networkKey, $e->getMessage(), json_encode($rawOffer)]);
            }
        }
    } catch (Exception $e) {
        logMessage("  Failed to fetch offers from {$network['name']}: " . $e->getMessage(), 'ERROR');
        $totalErrors++;
    }
    
    usleep(REQUEST_DELAY * 2);
}

logMessage("=== Import finished: processed $totalProcessed, errors $totalErrors ===");

// ==================== API ДЛЯ ВНЕШНИХ ПОСТБЕКОВ ====================
// Этот код должен быть вынесен в отдельный файл /api/cpa/postback.php
// Здесь он приведён как пример для интеграции
/*
 * POST /api/cpa/postback.php
 * {
 *   "click_id": "abc123",
 *   "conversion_id": "conv456",
 *   "amount": 100.50,
 *   "network": "admitad"
 * }
 */
function handlePostbackApi() {
    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid input']);
        return;
    }
    
    $clickId = $input['click_id'] ?? '';
    $conversionId = $input['conversion_id'] ?? '';
    $amount = (float)($input['amount'] ?? 0);
    $network = $input['network'] ?? '';
    
    if (empty($clickId) || empty($conversionId)) {
        http_response_code(400);
        echo json_encode(['error' => 'Missing click_id or conversion_id']);
        return;
    }
    
    $result = processPostback($clickId, $conversionId, $amount);
    
    if ($result) {
        echo json_encode(['status' => 'ok']);
    } else {
        http_response_code(400);
        echo json_encode(['error' => 'Postback rejected']);
    }
}