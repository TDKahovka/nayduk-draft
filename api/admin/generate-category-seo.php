<?php
/* ============================================
   НАЙДУК — Генерация SEO для категории (AI)
   ============================================ */

session_start();
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../services/Database.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id']) || !is_admin()) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Доступ запрещён']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
if (!$input || !verifyCsrfToken($input['csrf_token'] ?? '')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Недействительный токен']);
    exit;
}

$categoryId = (int)($input['category_id'] ?? 0);
if (!$categoryId) {
    echo json_encode(['success' => false, 'error' => 'ID категории не указан']);
    exit;
}

$db = Database::getInstance();
$category = $db->fetchOne("SELECT id, name FROM listing_categories WHERE id = ?", [$categoryId]);
if (!$category) {
    echo json_encode(['success' => false, 'error' => 'Категория не найдена']);
    exit;
}

// Получаем последние 100 объявлений в этой категории (только одобренные)
$listings = $db->fetchAll(
    "SELECT title, description FROM listings WHERE category_id = ? AND status = 'approved' ORDER BY created_at DESC LIMIT 100",
    [$categoryId]
);
if (empty($listings)) {
    echo json_encode(['success' => false, 'error' => 'Нет объявлений для анализа']);
    exit;
}

// Формируем выборку для AI (ограничиваем длину)
$sample = '';
foreach (array_slice($listings, 0, 30) as $i => $l) {
    $sample .= "Объявление " . ($i+1) . ": " . mb_substr($l['title'], 0, 100) . " — " . mb_substr($l['description'], 0, 200) . "\n";
}

// API ключ
$apiKey = getenv('DEEPSEEK_API_KEY') ?: (function() {
    $env = file(__DIR__ . '/../../.env', FILE_IGNORE_NEW_LINES);
    foreach ($env as $line) {
        if (strpos($line, 'DEEPSEEK_API_KEY=') === 0) {
            return trim(substr($line, strlen('DEEPSEEK_API_KEY=')));
        }
    }
    return '';
})();

$prompt = "Ты SEO-специалист. Проанализируй следующие объявления из категории '{$category['name']}' и создай:
- SEO-заголовок (до 70 символов, с ключевыми словами)
- SEO-описание (до 160 символов, привлекательное)
- Ключевые слова (через запятую, 7-10 шт)

Объявления:
{$sample}

Выведи в формате:
title: ...
description: ...
keywords: ...";

$postData = [
    'model' => 'deepseek-chat',
    'messages' => [
        ['role' => 'system', 'content' => 'Ты профессиональный SEO-оптимизатор. Отвечай только в указанном формате.'],
        ['role' => 'user', 'content' => $prompt]
    ],
    'temperature' => 0.5,
    'max_tokens' => 400
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

$seoTitle = '';
$seoDesc = '';
$seoKeywords = '';
if (preg_match('/title:\s*(.+)/i', $aiOutput, $m)) $seoTitle = trim($m[1]);
if (preg_match('/description:\s*(.+)/i', $aiOutput, $m)) $seoDesc = trim($m[1]);
if (preg_match('/keywords:\s*(.+)/i', $aiOutput, $m)) $seoKeywords = trim($m[1]);

// Сохраняем в таблицу categories (нужно добавить поля seo_title, seo_description, seo_keywords)
$db->query(
    "UPDATE listing_categories SET seo_title = ?, seo_description = ?, seo_keywords = ? WHERE id = ?",
    [$seoTitle, $seoDesc, $seoKeywords, $categoryId]
);

echo json_encode([
    'success' => true,
    'title' => $seoTitle,
    'description' => $seoDesc,
    'keywords' => $seoKeywords
]);