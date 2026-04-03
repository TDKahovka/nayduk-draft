<?php
/* ============================================
   НАЙДУК — API анализа ошибок через DeepSeek
   Версия 1.0
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

$user = Database::getInstance()->fetchOne("SELECT role FROM users WHERE id = ?", [$_SESSION['user_id']]);
if (!$user || $user['role'] !== 'admin') {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Доступ запрещён']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
if (!$input || empty($input['error_text'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Не указан текст ошибки']);
    exit;
}

$errorText = trim($input['error_text']);
$csrfToken = $input['csrf_token'] ?? '';
if (!verifyCsrfToken($csrfToken)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Недействительный токен безопасности']);
    exit;
}

// Получаем API ключ из .env
$apiKey = getenv('DEEPSEEK_API_KEY') ?: (function() {
    $env = file(__DIR__ . '/../../.env', FILE_IGNORE_NEW_LINES);
    foreach ($env as $line) {
        if (strpos($line, 'DEEPSEEK_API_KEY=') === 0) {
            return trim(substr($line, strlen('DEEPSEEK_API_KEY=')));
        }
    }
    return '';
})();

if (empty($apiKey)) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'API ключ DeepSeek не найден в .env']);
    exit;
}

// Формируем запрос к DeepSeek
$prompt = "Ты помощник администратора сайта. Проанализируй следующую ошибку и предложи конкретное решение (причину и как исправить). Будь краток, но полезен.\n\nОшибка:\n" . $errorText;

$postData = [
    'model' => 'deepseek-chat',
    'messages' => [
        ['role' => 'system', 'content' => 'Ты технический эксперт по PHP и веб-разработке. Отвечай на русском языке кратко и по делу.'],
        ['role' => 'user', 'content' => $prompt]
    ],
    'temperature' => 0.3,
    'max_tokens' => 800
];

$ch = curl_init('https://api.deepseek.com/v1/chat/completions');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($postData));
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Content-Type: application/json',
    'Authorization: Bearer ' . $apiKey
]);
curl_setopt($ch, CURLOPT_TIMEOUT, 30);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($httpCode !== 200) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Ошибка запроса к DeepSeek API: HTTP ' . $httpCode]);
    exit;
}

$data = json_decode($response, true);
$analysis = $data['choices'][0]['message']['content'] ?? 'Не удалось получить анализ.';

echo json_encode(['success' => true, 'analysis' => $analysis]);