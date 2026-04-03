<?php
/* ============================================
   НАЙДУК — AI-парсинг товара по ссылке
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

if (!verifyCsrfToken($input['csrf_token'] ?? '')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Недействительный токен безопасности']);
    exit;
}

$url = trim($input['url'] ?? '');
if (empty($url) || !filter_var($url, FILTER_VALIDATE_URL)) {
    echo json_encode(['success' => false, 'error' => 'Неверная ссылка']);
    exit;
}

// Получаем API ключ DeepSeek
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

// 1. Парсим страницу (простой cURL + DOMDocument)
$html = @file_get_contents($url);
if (!$html) {
    echo json_encode(['success' => false, 'error' => 'Не удалось загрузить страницу']);
    exit;
}

libxml_use_internal_errors(true);
$dom = new DOMDocument();
$dom->loadHTML($html);
$xpath = new DOMXPath($dom);

// Примеры извлечения данных (можно расширить под разные магазины)
$title = '';
$price = '';
$description = '';
$images = [];

// Ozon
if (strpos($url, 'ozon.ru') !== false) {
    $titleNode = $xpath->query("//h1");
    if ($titleNode->length) $title = trim($titleNode->item(0)->textContent);
    $priceNode = $xpath->query("//span[contains(@class,'price')]");
    if ($priceNode->length) $price = trim($priceNode->item(0)->textContent);
    $descNode = $xpath->query("//div[contains(@class,'description')]");
    if ($descNode->length) $description = trim($descNode->item(0)->textContent);
}
// Wildberries
elseif (strpos($url, 'wildberries.ru') !== false) {
    $titleNode = $xpath->query("//h1");
    if ($titleNode->length) $title = trim($titleNode->item(0)->textContent);
    $priceNode = $xpath->query("//span[contains(@class,'price')]");
    if ($priceNode->length) $price = trim($priceNode->item(0)->textContent);
    $descNode = $xpath->query("//div[contains(@class,'product-description')]");
    if ($descNode->length) $description = trim($descNode->item(0)->textContent);
}
// Яндекс.Маркет
elseif (strpos($url, 'market.yandex.ru') !== false) {
    $titleNode = $xpath->query("//h1");
    if ($titleNode->length) $title = trim($titleNode->item(0)->textContent);
    $priceNode = $xpath->query("//span[contains(@class,'price')]");
    if ($priceNode->length) $price = trim($priceNode->item(0)->textContent);
    $descNode = $xpath->query("//div[contains(@class,'product-description')]");
    if ($descNode->length) $description = trim($descNode->item(0)->textContent);
} else {
    // Общая попытка
    $titleNode = $xpath->query("//h1");
    if ($titleNode->length) $title = trim($titleNode->item(0)->textContent);
}

if (empty($title)) {
    echo json_encode(['success' => false, 'error' => 'Не удалось извлечь данные со страницы']);
    exit;
}

// 2. Отправляем данные в DeepSeek для генерации описания
$prompt = "Ты эксперт по копирайтингу. На основе данных о товаре создай:
- Заголовок (до 70 символов, с ключевыми словами)
- Описание (3-4 предложения, продающее, уникальное)
- Ключевые слова (через запятую, 5-7 шт)

Данные:
Название: {$title}
Цена: {$price}
Описание: {$description}

Выведи в формате:
Заголовок: ...
Описание: ...
Ключевые слова: ...";

$postData = [
    'model' => 'deepseek-chat',
    'messages' => [
        ['role' => 'system', 'content' => 'Ты профессиональный копирайтер. Отвечай только в указанном формате.'],
        ['role' => 'user', 'content' => $prompt]
    ],
    'temperature' => 0.7,
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

$newTitle = '';
$newDesc = '';
$keywords = '';
if (preg_match('/Заголовок:\s*(.+)/i', $aiOutput, $m)) $newTitle = trim($m[1]);
if (preg_match('/Описание:\s*(.+)/is', $aiOutput, $m)) $newDesc = trim($m[1]);
if (preg_match('/Ключевые слова:\s*(.+)/i', $aiOutput, $m)) $keywords = trim($m[1]);

echo json_encode([
    'success' => true,
    'title' => $newTitle ?: $title,
    'description' => $newDesc ?: $description,
    'keywords' => $keywords
]);