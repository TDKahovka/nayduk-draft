<?php
/* ============================================
   НАЙДУК — AI-улучшение текста объявления
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

$title = trim($input['title'] ?? '');
$description = trim($input['description'] ?? '');
$category = trim($input['category'] ?? '');
$price = isset($input['price']) ? (float)$input['price'] : 0;

if (empty($title) && empty($description)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Нет данных для улучшения']);
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
    echo json_encode(['success' => false, 'error' => 'API ключ DeepSeek не найден']);
    exit;
}

// Формируем промпт для AI
$prompt = "Ты помощник по созданию объявлений. Улучши заголовок и описание для товара/услуги. Сделай их более привлекательными, добавь ключевые слова для SEO, убери ошибки, но сохрани смысл. Используй формат:

Заголовок: [улучшенный заголовок]
Описание: [улучшенное описание]

Исходные данные:
Категория: {$category}
Цена: {$price} руб.
Заголовок: {$title}
Описание: {$description}";

$postData = [
    'model' => 'deepseek-chat',
    'messages' => [
        ['role' => 'system', 'content' => 'Ты эксперт по маркетингу и копирайтингу. Отвечай только в указанном формате, без лишних слов.'],
        ['role' => 'user', 'content' => $prompt]
    ],
    'temperature' => 0.5,
    'max_tokens' => 500
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
    echo json_encode(['success' => false, 'error' => 'Ошибка AI: HTTP ' . $httpCode]);
    exit;
}

$data = json_decode($response, true);
$aiOutput = $data['choices'][0]['message']['content'] ?? '';

// Парсим ответ
$newTitle = '';
$newDescription = '';
if (preg_match('/Заголовок:\s*(.+)/i', $aiOutput, $matches)) {
    $newTitle = trim($matches[1]);
}
if (preg_match('/Описание:\s*(.+)/is', $aiOutput, $matches)) {
    $newDescription = trim($matches[1]);
}

if (empty($newTitle)) $newTitle = $title;
if (empty($newDescription)) $newDescription = $description;

echo json_encode([
    'success' => true,
    'title' => $newTitle,
    'description' => $newDescription
]);