<?php
/* ============================================
   НАЙДУК — API покупки премиум-пакета
   Версия 2.0 (март 2026)
   - Поддержка Telegram Stars, TON, карт (заглушки, готовы к интеграции)
   - Начисление комиссии рефереру (10% от суммы)
   - Автосоздание таблиц premium_orders
   - Rate limiting, CSRF, логирование
   ============================================ */

session_start();
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../services/Database.php';
require_once __DIR__ . '/../../services/NotificationService.php';

header('Content-Type: application/json');

// Только POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

// Авторизация
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Требуется авторизация']);
    exit;
}
$userId = (int)$_SESSION['user_id'];

// CSRF
$csrfToken = $_POST['csrf_token'] ?? '';
if (!verifyCsrfToken($csrfToken)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Недействительный токен безопасности']);
    exit;
}

// Rate limiting (не более 3 покупок в час)
if (!checkRateLimit('premium_buy_' . $userId, 3, 3600)) {
    http_response_code(429);
    echo json_encode(['success' => false, 'error' => 'Слишком много попыток. Попробуйте позже.']);
    exit;
}

// Получение параметров
$packageId = $_POST['package'] ?? ''; // 'premium', 'vip', 'ultimate'
$paymentMethod = $_POST['payment_method'] ?? ''; // 'stars', 'ton', 'card'

if (!in_array($packageId, ['premium', 'vip', 'ultimate'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Неверный пакет']);
    exit;
}
if (!in_array($paymentMethod, ['stars', 'ton', 'card'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Неверный способ оплаты']);
    exit;
}

// Цены (в рублях, для расчёта комиссии)
$prices = [
    'premium' => 299,
    'vip' => 799,
    'ultimate' => 1999
];
$priceRub = $prices[$packageId];

$db = Database::getInstance();
$pdo = $db->getPdo();

// ==================== АВТОСОЗДАНИЕ ТАБЛИЦ ====================
$pdo->exec("
    CREATE TABLE IF NOT EXISTS premium_orders (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        user_id BIGINT UNSIGNED NOT NULL,
        package VARCHAR(50) NOT NULL,
        amount DECIMAL(12,2) NOT NULL,
        payment_method VARCHAR(50) NOT NULL,
        status ENUM('pending','paid','cancelled') DEFAULT 'pending',
        transaction_id VARCHAR(255),
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        paid_at TIMESTAMP NULL,
        INDEX idx_user (user_id),
        INDEX idx_status (status),
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
");

// Добавляем поле для хранения даты окончания премиума
$columns = $pdo->query("SHOW COLUMNS FROM users")->fetchAll(PDO::FETCH_COLUMN);
if (!in_array('premium_until', $columns)) {
    $pdo->exec("ALTER TABLE users ADD COLUMN premium_until TIMESTAMP NULL");
}
if (!in_array('is_premium', $columns)) {
    $pdo->exec("ALTER TABLE users ADD COLUMN is_premium BOOLEAN DEFAULT FALSE");
}
if (!in_array('premium_package', $columns)) {
    $pdo->exec("ALTER TABLE users ADD COLUMN premium_package VARCHAR(50)");
}

// ==================== ПРОВЕРКА АКТИВНОГО ПРЕМИУМА ====================
$user = $db->getUserById($userId);
if ($user['is_premium'] && strtotime($user['premium_until']) > time()) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'У вас уже активен премиум-пакет']);
    exit;
}

// ==================== СОЗДАНИЕ ЗАКАЗА ====================
$orderId = $db->insert('premium_orders', [
    'user_id' => $userId,
    'package' => $packageId,
    'amount' => $priceRub,
    'payment_method' => $paymentMethod,
    'status' => 'pending',
    'created_at' => date('Y-m-d H:i:s')
]);

if (!$orderId) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Ошибка создания заказа']);
    exit;
}

// ==================== ОБРАБОТКА ОПЛАТЫ ====================
$paymentResult = false;
$transactionId = null;

switch ($paymentMethod) {
    case 'stars':
        // Telegram Stars: создаём инвойс
        $botToken = getenv('TELEGRAM_BOT_TOKEN') ?: '';
        $chatId = $user['telegram_id'] ?? null; // нужно хранить telegram_id в users
        if (!$botToken || !$chatId) {
            $paymentResult = false;
            $errorMsg = 'Не настроен Telegram бот или у пользователя нет Telegram ID';
        } else {
            // Вызываем API Telegram для отправки инвойса
            $starsAmount = ceil($priceRub / 1.5); // курс 1 Star ≈ 1.5 руб (уточнить)
            $payload = json_encode([
                'chat_id' => $chatId,
                'title' => 'Премиум-пакет ' . $packageId,
                'description' => 'Доступ к расширенным возможностям на 30 дней',
                'payload' => 'premium_' . $orderId,
                'provider_token' => '',
                'currency' => 'XTR', // Telegram Stars
                'prices' => [
                    ['label' => 'Премиум', 'amount' => $starsAmount]
                ]
            ]);
            $ch = curl_init("https://api.telegram.org/bot{$botToken}/sendInvoice");
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
            curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            if ($httpCode == 200) {
                $paymentResult = true; // Инвойс отправлен, ждём вебхук
                $transactionId = json_decode($response, true)['result']['invoice_link'] ?? '';
            } else {
                $errorMsg = 'Ошибка создания инвойса в Telegram';
            }
        }
        break;

    case 'ton':
        // TON Connect 2.0 – возвращаем адрес для оплаты (заглушка)
        // В реальности здесь нужно сгенерировать адрес и ожидать вебхук
        $tonAddress = 'UQAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA'; // демо
        $paymentResult = true;
        $transactionId = $tonAddress;
        break;

    case 'card':
        // ЮKassa / CloudPayments – формируем ссылку на оплату (заглушка)
        $paymentUrl = 'https://demo.yookassa.ru/'; // демо
        $paymentResult = true;
        $transactionId = $paymentUrl;
        break;

    default:
        $paymentResult = false;
        $errorMsg = 'Неизвестный способ оплаты';
}

if (!$paymentResult) {
    $db->update('premium_orders', ['status' => 'cancelled'], 'id = ?', [$orderId]);
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $errorMsg ?? 'Ошибка при создании платежа']);
    exit;
}

// Обновляем заказ с transaction_id
$db->update('premium_orders', ['transaction_id' => $transactionId], 'id = ?', [$orderId]);

// ==================== ПОСЛЕ УСПЕШНОЙ ОПЛАТЫ (вебхук) ====================
// Здесь должна быть логика, которая вызывается из вебхука платёжной системы.
// Для демонстрации мы создадим функцию, которая активирует премиум и начисляет комиссию.
// В реальности эта функция будет вызвана из обработчика вебхука после подтверждения платежа.

function activatePremiumAndCommission($orderId) {
    $db = Database::getInstance();
    $pdo = $db->getPdo();

    // Получаем заказ
    $order = $db->fetchOne("SELECT * FROM premium_orders WHERE id = ?", [$orderId]);
    if (!$order || $order['status'] !== 'pending') return false;

    $userId = $order['user_id'];
    $package = $order['package'];
    $amount = $order['amount'];

    // Активация премиума
    $duration = 30; // дней
    $expiresAt = date('Y-m-d H:i:s', strtotime("+{$duration} days"));
    $db->update('users', [
        'is_premium' => 1,
        'premium_until' => $expiresAt,
        'premium_package' => $package
    ], 'id = ?', [$userId]);

    // Обновляем статус заказа
    $db->update('premium_orders', ['status' => 'paid', 'paid_at' => date('Y-m-d H:i:s')], 'id = ?', [$orderId]);

    // Начисление комиссии рефереру (если есть)
    $referral = $db->fetchOne("SELECT id, referrer_id FROM referrals WHERE referred_id = ?", [$userId]);
    if ($referral) {
        $commission = round($amount * 0.10, 2); // 10% от суммы
        if ($commission > 0) {
            $db->addReferralCommission($referral['id'], $commission);
            // Уведомляем реферера
            $notify = new NotificationService();
            $notify->send($referral['referrer_id'], 'referral_commission', [
                'amount' => $commission,
                'referred_name' => $db->fetchOne("SELECT name FROM users WHERE id = ?", [$userId])['name'],
                'order_id' => $orderId
            ]);
        }
    }

    // Логируем
    $db->insert('security_logs', [
        'user_id' => $userId,
        'ip_address' => getUserIP(),
        'event_type' => 'premium_activated',
        'description' => "Активирован премиум-пакет $package (заказ #$orderId)",
        'severity' => 'medium',
        'created_at' => date('Y-m-d H:i:s')
    ]);

    // Отправляем уведомление пользователю
    $notify = new NotificationService();
    $notify->send($userId, 'premium_activated', [
        'package' => $package,
        'expires_at' => $expiresAt
    ]);

    return true;
}

// В реальном коде activatePremiumAndCommission будет вызвана из вебхука.
// Для тестов можно вызвать её сразу после создания заказа (только для карт и тона, где оплата мгновенная?).
// Но мы не будем этого делать, так как оплата асинхронна.

// ==================== ОТВЕТ ====================
// Возвращаем информацию для оплаты
$response = [
    'success' => true,
    'order_id' => $orderId,
    'payment_method' => $paymentMethod,
    'transaction_id' => $transactionId
];

if ($paymentMethod === 'stars') {
    $response['message'] = 'Инвойс отправлен в Telegram. Оплатите его для активации премиума.';
} elseif ($paymentMethod === 'ton') {
    $response['message'] = 'Переведите указанную сумму на кошелёк TON. После подтверждения транзакции премиум активируется.';
    $response['ton_address'] = $transactionId;
} elseif ($paymentMethod === 'card') {
    $response['message'] = 'Перейдите по ссылке для оплаты картой.';
    $response['payment_url'] = $transactionId;
}

echo json_encode($response);