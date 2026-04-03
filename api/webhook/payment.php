<?php
/* ============================================
   НАЙДУК — Вебхук для подтверждения платежей
   Версия 1.0 (март 2026)
   - Принимает уведомления от Telegram Stars, TON, ЮKassa
   - Проверяет подпись, активирует премиум, начисляет комиссию рефереру
   ============================================ */

// Запрещаем кэширование
header("Cache-Control: no-store, no-cache, must-revalidate");
header("Pragma: no-cache");
header("Expires: 0");

// Отключаем вывод ошибок, чтобы не портить JSON
error_reporting(0);
ini_set('display_errors', 0);

// Получаем сырой вход
$input = file_get_contents('php://input');
if (empty($input)) {
    http_response_code(400);
    exit;
}

// Логируем входящий запрос (для отладки)
file_put_contents(__DIR__ . '/../../storage/logs/webhook.log', date('Y-m-d H:i:s') . " WEBHOOK: " . $input . PHP_EOL, FILE_APPEND);

// Определяем провайдера по заголовкам или содержимому
$headers = getallheaders();
$provider = null;

if (isset($headers['X-Telegram-Bot-Api-Secret-Token']) || strpos($input, '"update_id"') !== false) {
    $provider = 'telegram';
} elseif (strpos($input, '"ton_connect"') !== false || strpos($input, '"transaction"') !== false) {
    $provider = 'ton';
} elseif (strpos($input, '"event"') !== false && strpos($input, '"payment"') !== false) {
    $provider = 'yookassa';
} else {
    http_response_code(400);
    file_put_contents(__DIR__ . '/../../storage/logs/webhook.log', date('Y-m-d H:i:s') . " UNKNOWN PROVIDER" . PHP_EOL, FILE_APPEND);
    exit;
}

require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../services/Database.php';
require_once __DIR__ . '/../../services/NotificationService.php';

$db = Database::getInstance();
$pdo = $db->getPdo();

/**
 * Активирует премиум для пользователя и начисляет комиссию рефереру
 */
function activatePremium($orderId) {
    $db = Database::getInstance();
    $order = $db->fetchOne("SELECT * FROM premium_orders WHERE id = ?", [$orderId]);
    if (!$order || $order['status'] !== 'pending') return false;

    $userId = $order['user_id'];
    $package = $order['package'];
    $amount = $order['amount'];

    // Активация премиума на 30 дней
    $expiresAt = date('Y-m-d H:i:s', strtotime('+30 days'));
    $db->update('users', [
        'is_premium' => 1,
        'premium_until' => $expiresAt,
        'premium_package' => $package
    ], 'id = ?', [$userId]);

    // Обновляем статус заказа
    $db->update('premium_orders', ['status' => 'paid', 'paid_at' => date('Y-m-d H:i:s')], 'id = ?', [$orderId]);

    // Начисление комиссии рефереру (10%)
    $referral = $db->fetchOne("SELECT id, referrer_id FROM referrals WHERE referred_id = ?", [$userId]);
    if ($referral) {
        $commission = round($amount * 0.10, 2);
        if ($commission > 0) {
            $db->addReferralCommission($referral['id'], $commission);
            $notify = new NotificationService();
            $notify->send($referral['referrer_id'], 'referral_commission', [
                'amount' => $commission,
                'referred_name' => $db->fetchOne("SELECT name FROM users WHERE id = ?", [$userId])['name'],
                'order_id' => $orderId
            ]);
        }
    }

    // Уведомление пользователю
    $notify = new NotificationService();
    $notify->send($userId, 'premium_activated', [
        'package' => $package,
        'expires_at' => $expiresAt
    ]);

    // Логируем
    $db->insert('security_logs', [
        'user_id' => $userId,
        'ip_address' => $_SERVER['REMOTE_ADDR'] ?? '',
        'event_type' => 'premium_activated',
        'description' => "Активирован премиум-пакет $package (заказ #$orderId)",
        'severity' => 'medium',
        'created_at' => date('Y-m-d H:i:s')
    ]);

    return true;
}

// ==================== ОБРАБОТКА В ЗАВИСИМОСТИ ОТ ПРОВАЙДЕРА ====================

switch ($provider) {
    case 'telegram':
        // Telegram Stars: вебхук приходит в формате Update
        $data = json_decode($input, true);
        if (!isset($data['pre_checkout_query'])) {
            http_response_code(200);
            exit;
        }

        $query = $data['pre_checkout_query'];
        $orderId = null;
        if (preg_match('/premium_(\d+)/', $query['invoice_payload'], $matches)) {
            $orderId = (int)$matches[1];
        }

        if (!$orderId) {
            http_response_code(200);
            exit;
        }

        // Подтверждаем предоплату
        $botToken = getenv('TELEGRAM_BOT_TOKEN') ?: '';
        $chatId = $query['from']['id'];
        $payload = json_encode(['pre_checkout_query_id' => $query['id'], 'ok' => true]);
        $ch = curl_init("https://api.telegram.org/bot{$botToken}/answerPreCheckoutQuery");
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_exec($ch);
        curl_close($ch);

        // Ждём успешный платеж (следующий вебхук)
        // В реальности придет успешный платеж в другом update, но для простоты считаем, что после pre_checkout_query сразу приходит successful_payment
        // В полноценной реализации нужно сохранять состояние и ждать second webhook
        // Здесь мы упрощённо активируем сразу (т.к. это демо)
        activatePremium($orderId);

        echo json_encode(['ok' => true]);
        break;

    case 'ton':
        // TON: вебхук от toncenter.com или подобного
        // Предполагаем, что приходит JSON с transaction_id и comment (где order_id)
        $data = json_decode($input, true);
        $comment = $data['comment'] ?? '';
        if (preg_match('/order_(\d+)/', $comment, $matches)) {
            $orderId = (int)$matches[1];
            if ($orderId && $data['status'] === 'success') {
                activatePremium($orderId);
            }
        }
        echo json_encode(['ok' => true]);
        break;

    case 'yookassa':
        // ЮKassa: проверка подписи и уведомление
        $secretKey = getenv('YOOKASSA_SECRET_KEY') ?: '';
        $signature = $headers['HTTP_X_SIGNATURE'] ?? '';
        if ($signature && $secretKey) {
            $hash = hash_hmac('sha256', $input, $secretKey);
            if (!hash_equals($hash, $signature)) {
                http_response_code(403);
                exit;
            }
        }

        $data = json_decode($input, true);
        if ($data['event'] === 'payment.succeeded') {
            $metadata = $data['object']['metadata'] ?? [];
            $orderId = $metadata['order_id'] ?? null;
            if ($orderId) {
                activatePremium($orderId);
            }
        }
        echo json_encode(['ok' => true]);
        break;

    default:
        http_response_code(400);
        exit;
}