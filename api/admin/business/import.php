<?php
/* ============================================
   НАЙДУК — API импорта офферов (приём файлов/URL)
   Версия 2.0 (март 2026)
   - Принимает файл или URL
   - Определяет формат (CSV, Excel, JSON, XML, ZIP, GZ)
   - Сохраняет задачу в import_jobs, ставит в очередь
   - Возвращает job_id и HTML-форму для настройки маппинга (если нужно)
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
$db = Database::getInstance();
$user = $db->fetchOne("SELECT role FROM users WHERE id = ?", [$userId]);
if (!$user || $user['role'] !== 'admin') {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Доступ запрещён']);
    exit;
}

$csrfToken = $_POST['csrf_token'] ?? '';
if (!verifyCsrfToken($csrfToken)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Недействительный токен безопасности']);
    exit;
}

// Rate limiting
if (!checkRateLimit('admin_import_' . $userId, 10, 3600)) {
    http_response_code(429);
    echo json_encode(['success' => false, 'error' => 'Слишком много запросов. Попробуйте позже.']);
    exit;
}

// ==================== ПАРАМЕТРЫ ====================
$file = $_FILES['import_file'] ?? null;
$url = trim($_POST['import_url'] ?? '');
$mapping = isset($_POST['mapping']) ? json_decode($_POST['mapping'], true) : null;
$provider = trim($_POST['provider'] ?? '');
$dryRun = isset($_POST['dry_run']) && $_POST['dry_run'] == '1';

if (!$file && !$url) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Не указан файл или URL']);
    exit;
}

// ==================== СОХРАНЕНИЕ ФАЙЛА ====================
$tempDir = __DIR__ . '/../../uploads/import_temp/';
if (!is_dir($tempDir)) mkdir($tempDir, 0755, true);

$jobId = uniqid('import_', true);
$filePath = null;
$originalName = null;

if ($file && $file['error'] === UPLOAD_ERR_OK) {
    $originalName = $file['name'];
    $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
    $filePath = $tempDir . $jobId . '_' . basename($originalName);
    if (!move_uploaded_file($file['tmp_name'], $filePath)) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Не удалось сохранить файл']);
        exit;
    }
} elseif ($url) {
    $originalName = basename(parse_url($url, PHP_URL_PATH)) ?: 'from_url';
    $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
    $filePath = $tempDir . $jobId . '_' . $originalName;
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    $data = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($httpCode !== 200 || $data === false) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Не удалось скачать файл по ссылке (HTTP ' . $httpCode . ')']);
        exit;
    }
    file_put_contents($filePath, $data);
}

if (!$filePath || !file_exists($filePath)) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Ошибка сохранения файла']);
    exit;
}

// ==================== ОПРЕДЕЛЕНИЕ ТИПА ФАЙЛА ====================
function detectFileType($path) {
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime = finfo_file($finfo, $path);
    finfo_close($finfo);
    $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    if (in_array($ext, ['zip', 'gz', 'tar'])) return 'archive';
    if (in_array($ext, ['xls', 'xlsx']) || $mime === 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet') return 'excel';
    if ($ext === 'csv' || $mime === 'text/csv') return 'csv';
    if ($ext === 'json' || $mime === 'application/json') return 'json';
    if ($ext === 'xml' || $mime === 'application/xml') return 'xml';
    return 'unknown';
}

$fileType = detectFileType($filePath);
if ($fileType === 'unknown') {
    @unlink($filePath);
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Неподдерживаемый формат файла']);
    exit;
}

// ==================== ПРЕДВАРИТЕЛЬНЫЙ ПРОСМОТР (для маппинга) ====================
$preview = null;
$columns = [];

if (!$mapping) {
    // Пытаемся извлечь первые строки
    try {
        if ($fileType === 'csv') {
            $handle = fopen($filePath, 'r');
            if ($handle) {
                $firstLine = fgets($handle);
                $delimiter = $this->detectDelimiter($firstLine);
                rewind($handle);
                $data = [];
                $i = 0;
                while (($row = fgetcsv($handle, 0, $delimiter)) !== false && $i < 5) {
                    $data[] = $row;
                    $i++;
                }
                fclose($handle);
                if (!empty($data)) $columns = $data[0];
                $preview = $data;
            }
        } elseif ($fileType === 'excel' && class_exists('PhpOffice\PhpSpreadsheet\IOFactory')) {
            $spreadsheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($filePath);
            $worksheet = $spreadsheet->getActiveSheet();
            $rows = [];
            $i = 0;
            foreach ($worksheet->getRowIterator() as $row) {
                if ($i++ >= 5) break;
                $cells = [];
                foreach ($row->getCellIterator() as $cell) {
                    $cells[] = $cell->getValue();
                }
                $rows[] = $cells;
            }
            if (!empty($rows)) $columns = $rows[0];
            $preview = $rows;
        } elseif ($fileType === 'json') {
            $content = file_get_contents($filePath);
            $data = json_decode($content, true);
            if (is_array($data) && count($data) > 0) {
                $firstItem = reset($data);
                $columns = array_keys($firstItem);
                $preview = array_slice($data, 0, 5);
            }
        } elseif ($fileType === 'xml') {
            $content = file_get_contents($filePath);
            $xml = simplexml_load_string($content);
            if ($xml && isset($xml->offer)) {
                $firstOffer = $xml->offer[0];
                $columns = array_keys((array)$firstOffer);
                $preview = array_slice($xml->offer, 0, 5);
            }
        }
    } catch (Exception $e) {
        // игнорируем, продолжим без preview
    }
}

// ==================== СОЗДАНИЕ ЗАДАЧИ В БД ====================
$pdo = $db->getPdo();
$pdo->exec("
    CREATE TABLE IF NOT EXISTS import_jobs (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        job_id VARCHAR(255) UNIQUE NOT NULL,
        user_id BIGINT UNSIGNED NOT NULL,
        status VARCHAR(50) DEFAULT 'pending',
        file_name VARCHAR(255),
        file_path TEXT,
        file_type VARCHAR(20),
        source_url TEXT,
        provider VARCHAR(100),
        mapping JSON,
        total_rows INT DEFAULT 0,
        processed_rows INT DEFAULT 0,
        failed_rows INT DEFAULT 0,
        errors JSON,
        started_at TIMESTAMP NULL,
        finished_at TIMESTAMP NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_user (user_id),
        INDEX idx_status (status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
");

$stmt = $pdo->prepare("
    INSERT INTO import_jobs (job_id, user_id, status, file_name, file_path, file_type, source_url, provider, mapping, created_at)
    VALUES (?, ?, 'pending', ?, ?, ?, ?, ?, ?, NOW())
");
$stmt->execute([
    $jobId,
    $userId,
    $originalName,
    $filePath,
    $fileType,
    $url ?: null,
    $provider ?: null,
    $mapping ? json_encode($mapping) : null
]);

// ==================== ПОСТАВИТЬ В ОЧЕРЕДЬ ====================
$redis = class_exists('Redis') ? new Redis() : null;
$redisAvailable = false;
if ($redis) {
    try {
        $redis->connect('127.0.0.1', 6379, 1);
        $redisAvailable = $redis->ping();
    } catch (Exception $e) {}
}

$queueName = 'import_queue';
$jobData = [
    'job_id' => $jobId,
    'file_path' => $filePath,
    'file_type' => $fileType,
    'provider' => $provider,
    'mapping' => $mapping,
    'dry_run' => $dryRun
];

if ($redisAvailable) {
    $redis->rpush($queueName, json_encode($jobData));
} else {
    $queueDir = __DIR__ . '/../../storage/queue/';
    if (!is_dir($queueDir)) mkdir($queueDir, 0755, true);
    file_put_contents($queueDir . $queueName . '.queue', json_encode($jobData) . PHP_EOL, FILE_APPEND | LOCK_EX);
}

// ==================== ОТВЕТ ====================
if ($mapping) {
    // Если маппинг уже передан, сразу запускаем обработку
    echo json_encode([
        'success' => true,
        'job_id' => $jobId,
        'message' => 'Импорт запущен. Статус можно отслеживать в дашборде.'
    ]);
} else {
    // Возвращаем preview и форму маппинга
    echo json_encode([
        'success' => true,
        'job_id' => $jobId,
        'preview' => $preview,
        'columns' => $columns,
        'message' => 'Необходимо настроить соответствие полей.'
    ]);
}