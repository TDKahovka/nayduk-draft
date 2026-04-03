<?php
/**
 * API: получение текущего текста правил участия в аукционах
 * - Возвращает полный текст правил из таблицы auction_consent_versions
 * - Используется для отображения модального окна перед первой ставкой
 */
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../services/Database.php';

$db = Database::getInstance();
$latest = $db->fetchOne("SELECT full_text FROM auction_consent_versions ORDER BY id DESC LIMIT 1");

if (!$latest) {
    json_error('Правила не найдены');
}

json_success(['text' => $latest['full_text']]);