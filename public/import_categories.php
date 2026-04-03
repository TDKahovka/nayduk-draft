<?php
// Скрипт импорта категорий (запустить один раз)
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../services/Database.php';

$db = Database::getInstance();
$pdo = $db->getPdo();

// Данные категорий (копия из categories.php)
$categories = [
    // ... (вставьте сюда полный массив из categories.php, начиная с 'name' => 'Одежда, обувь, аксессуары', ...)
];

function insertCategory($pdo, $name, $slug, $parentId = 0, $icon = '') {
    $stmt = $pdo->prepare("INSERT INTO listing_categories (parent_id, name, slug, icon, is_active) VALUES (?, ?, ?, ?, 1)");
    $stmt->execute([$parentId, $name, $slug, $icon]);
    return $pdo->lastInsertId();
}

// Удаляем старые категории (опционально)
$pdo->exec("TRUNCATE TABLE listing_categories");

$mainCategories = [];
foreach ($categories as $cat) {
    $slug = \Transliterator::create('Any-Latin; Latin-ASCII; [\u0080-\u7fff] remove') ?: $cat['slug'];
    $slug = preg_replace('/[^a-z0-9-]/', '-', strtolower($slug));
    $mainId = insertCategory($pdo, $cat['name'], $slug, 0, $cat['icon']);
    $mainCategories[$cat['name']] = $mainId;
    
    foreach ($cat['children'] as $subKey => $subValue) {
        if (is_array($subValue)) {
            // Это раздел (например, Женская одежда) — создаём как подкатегорию
            $subSlug = \Transliterator::create('Any-Latin; Latin-ASCII; [\u0080-\u7fff] remove') ?: $subKey;
            $subSlug = preg_replace('/[^a-z0-9-]/', '-', strtolower($subSlug));
            $subId = insertCategory($pdo, $subKey, $subSlug, $mainId, '');
            // Дочерние элементы раздела (например, Платья, Блузки) – создаём как подкатегории второго уровня
            foreach ($subValue as $item) {
                $itemSlug = \Transliterator::create('Any-Latin; Latin-ASCII; [\u0080-\u7fff] remove') ?: $item;
                $itemSlug = preg_replace('/[^a-z0-9-]/', '-', strtolower($itemSlug));
                insertCategory($pdo, $item, $itemSlug, $subId, '');
            }
        } else {
            // Обычная подкатегория (без разделов)
            $subSlug = \Transliterator::create('Any-Latin; Latin-ASCII; [\u0080-\u7fff] remove') ?: $subValue;
            $subSlug = preg_replace('/[^a-z0-9-]/', '-', strtolower($subSlug));
            insertCategory($pdo, $subValue, $subSlug, $mainId, '');
        }
    }
}
echo "Категории импортированы!";