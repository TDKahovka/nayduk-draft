<?php
/**
 * API: сохранение согласия пользователя с правилами аукциона
 * - Проверяет авторизацию
 * - Берёт текущую версию правил из auction_consent_versions
 * - Сохраняет запись в user_auction_consents с IP и user‑agent
 */
session_start();
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../services/Database.php';

$db = Database::getInstance();
$userId = $_SESSION['user_id'] ?? 0;
if (!$userId) json_error('Требуется авторизация');

$data = json_decode(file_get_contents('php://input'), true);
$csrf = $data['csrf_token'] ?? '';
if (!verify_csrf_token($csrf)) json_error('Неверный CSRF-токен');

// Получаем текущую версию правил
$latest = $db->fetchOne("SELECT version, full_text FROM auction_consent_versions ORDER BY id DESC LIMIT 1");
if (!$latest) json_error('Версия правил не найдена');

// Сохраняем согласие
$db->insert('user_auction_consents', [
    'user_id' => $userId,
    'consent_version' => $latest['version'],
    'consent_text' => $latest['full_text'],
    'ip_address' => $_SERVER['REMOTE_ADDR'] ?? null,
    'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null
]);

json_success();