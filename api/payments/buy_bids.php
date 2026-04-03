<?php
/**
 * API: покупка пакета дополнительных ставок (10 ставок за 50 ₽)
 * - Создаёт платёж через ЮKassa
 * - После успешной оплаты вебхук увеличивает extra_bids_balance пользователя
 */
session_start();
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../services/Database.php';
require_once __DIR__ . '/../../services/PaymentService.php';

$userId = $_SESSION['user_id'] ?? 0;
if (!$userId) json_error('Требуется авторизация');

$data = json_decode(file_get_contents('php://input'), true);
$csrf = $data['csrf_token'] ?? '';
if (!verify_csrf_token($csrf)) json_error('Неверный CSRF-токен');

$payment = new PaymentService();
$url = $payment->createExtraBidsPayment($userId, 10); // 10 ставок за 50₽

if ($url) {
    json_success(['payment_url' => $url]);
} else {
    json_error('Не удалось создать платёж');
}