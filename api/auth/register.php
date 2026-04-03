<?php
/* ============================================
   НАЙДУК — API безпарольной регистрации (Magic Link)
   Версия 3.0 (март 2026)
   - Создаёт пользователя, отправляет ссылку для входа
   - Принимает email, имя, телефон (опционально), согласие с условиями
   - Поддержка реферальной системы (сохранение реферера из сессии)
   - Автосоздание таблиц magic_links, добавление поля email_verified
   - Rate limiting (3 попытки в час на email)
   - Защита от ботов (honeypot, CSRF)
   - Логирование
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

// CSRF
$csrfToken = $_POST['csrf_token'] ?? '';
if (!verifyCsrfToken($csrfToken)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Недействительный токен безопасности']);
    exit;
}

// Honeypot (ловушка для ботов)
if (!empty($_POST['website_url']) || !empty($_POST['phone_fake'])) {
    echo json_encode(['success' => true, 'message' => 'Регистрация успешна']); // тихий ответ для ботов
    exit;
}

// Получение и валидация данных
$email = trim($_POST['email'] ?? '');
$name = trim($_POST['name'] ?? '');
$phone = trim($_POST['phone'] ?? '');
$terms = isset($_POST['terms']) ? (int)$_POST['terms'] : 0;

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Некорректный email']);
    exit;
}
if (strlen($name) < 2 || strlen($name) > 100) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Имя должно быть от 2 до 100 символов']);
    exit;
}
if ($phone && !preg_match('/^[\+\d\s\-\(\)]{5,20}$/', $phone)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Некорректный формат телефона']);
    exit;
}
if (!$terms) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Необходимо согласиться с условиями']);
    exit;
}

$db = Database::getInstance();
$pdo = $db->getPdo();

// ==================== АВТОСОЗДАНИЕ ТАБЛИЦ ====================
$pdo->exec("
    CREATE TABLE IF NOT EXISTS magic_links (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        user_id BIGINT UNSIGNED NOT NULL,
        token_hash VARCHAR(64) NOT NULL,
        expires_at TIMESTAMP NOT NULL,
        used_at TIMESTAMP NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY idx_token_hash (token_hash),
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
");

// Добавляем поля email_verified и phone, если их нет
$columns = $pdo->query("SHOW COLUMNS FROM users")->fetchAll(PDO::FETCH_COLUMN);
if (!in_array('email_verified', $columns)) {
    $pdo->exec("ALTER TABLE users ADD COLUMN email_verified BOOLEAN DEFAULT FALSE");
}
if (!in_array('phone', $columns)) {
    $pdo->exec("ALTER TABLE users ADD COLUMN phone VARCHAR(20)");
}
if (!in_array('phone_visible', $columns)) {
    $pdo->exec("ALTER TABLE users ADD COLUMN phone_visible BOOLEAN DEFAULT FALSE");
}
if (!in_array('telegram', $columns)) {
    $pdo->exec("ALTER TABLE users ADD COLUMN telegram VARCHAR(100)");
}
if (!in_array('whatsapp', $columns)) {
    $pdo->exec("ALTER TABLE users ADD COLUMN whatsapp VARCHAR(100)");
}

// ==================== RATE LIMITING ====================
$rateKey = 'register_' . md5($email);
$rateFile = __DIR__ . '/../../storage/rate/' . $rateKey . '.txt';
$rateDir = dirname($rateFile);
if (!is_dir($rateDir)) mkdir($rateDir, 0755, true);

$now = time();
$attempts = [];
if (file_exists($rateFile)) {
    $attempts = json_decode(file_get_contents($rateFile), true);
    if (!is_array($attempts)) $attempts = [];
    $attempts = array_filter($attempts, fn($t) => $t > $now - 3600);
}
if (count($attempts) >= 3) {
    http_response_code(429);
    echo json_encode(['success' => false, 'error' => 'Слишком много попыток. Попробуйте через час.']);
    exit;
}
$attempts[] = $now;
file_put_contents($rateFile, json_encode($attempts), LOCK_EX);

// ==================== ПРОВЕРКА СУЩЕСТВОВАНИЯ EMAIL ====================
$existing = $db->getUserByEmail($email);
if ($existing) {
    http_response_code(409);
    echo json_encode(['success' => false, 'error' => 'Этот email уже зарегистрирован']);
    exit;
}

// ==================== СОЗДАНИЕ ПОЛЬЗОВАТЕЛЯ ====================
$userId = $db->createUser([
    'email' => $email,
    'name' => $name,
    'phone' => $phone ?: null,
    'trust_score' => 50,
    'email_verified' => 0,
    'is_partner' => 0,
    'notify_email' => 1,
    'registration_method' => 'magic_link',
    'created_at' => date('Y-m-d H:i:s'),
    'updated_at' => date('Y-m-d H:i:s')
]);

if (!$userId) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Ошибка при создании пользователя']);
    exit;
}

// ==================== РЕФЕРАЛЬНАЯ СИСТЕМА ====================
if (isset($_SESSION['ref']) && is_numeric($_SESSION['ref']) && $_SESSION['ref'] != $userId) {
    $referrerId = (int)$_SESSION['ref'];
    $referrer = $db->getUserById($referrerId);
    if ($referrer && empty($referrer['deleted_at'])) {
        // Проверяем, не создана ли уже реферальная связь
        $existingReferral = $db->fetchOne("SELECT id FROM referrals WHERE referred_id = ?", [$userId]);
        if (!$existingReferral) {
            $db->createReferral($referrerId, $userId);
            // Логируем
            $db->insert('security_logs', [
                'user_id' => $referrerId,
                'ip_address' => getUserIP(),
                'event_type' => 'referral_created',
                'description' => "Пользователь $referrerId привёл реферала $userId",
                'severity' => 'low',
                'created_at' => date('Y-m-d H:i:s')
            ]);
        }
    }
    // Очищаем сессию, чтобы не использовать повторно
    unset($_SESSION['ref']);
}

// ==================== ГЕНЕРАЦИЯ TOKEN MAGIC LINK ====================
$token = bin2hex(random_bytes(32));
$tokenHash = hash('sha256', $token);
$expiresAt = date('Y-m-d H:i:s', time() + 3600); // 1 час

// Удаляем старые неиспользованные токены этого пользователя
$pdo->prepare("DELETE FROM magic_links WHERE user_id = ? AND used_at IS NULL AND expires_at < NOW()")->execute([$userId]);

$stmt = $pdo->prepare("INSERT INTO magic_links (user_id, token_hash, expires_at) VALUES (?, ?, ?)");
$stmt->execute([$userId, $tokenHash, $expiresAt]);

// ==================== ОТПРАВКА ПИСЬМА ====================
$link = 'https://' . $_SERVER['HTTP_HOST'] . '/auth/magic?token=' . urlencode($token);
$notify = new NotificationService();

$subject = '🎉 Добро пожаловать в Найдук! Подтвердите вход';
$body = "
    <!DOCTYPE html>
    <html>
    <head><meta charset='UTF-8'></head>
    <body style='font-family: sans-serif; max-width: 600px; margin: 0 auto; padding: 20px; background: #f5f5f7;'>
        <div style='background: white; border-radius: 20px; padding: 30px; box-shadow: 0 4px 16px rgba(0,0,0,0.05);'>
            <h2 style='color: #4A90E2; margin-bottom: 20px;'>Добро пожаловать в Найдук, $name!</h2>
            <p>Вы успешно зарегистрировались на платформе. Теперь вы можете войти в свой аккаунт.</p>
            <p>Для входа нажмите на кнопку ниже:</p>
            <p style='text-align: center; margin: 30px 0;'>
                <a href='{$link}' style='display: inline-block; padding: 14px 32px; background: #4A90E2; color: white; text-decoration: none; border-radius: 50px; font-weight: bold;'>Войти в Найдук</a>
            </p>
            <p>Или скопируйте ссылку в браузер:<br>
            <code style='word-break: break-all; font-size: 12px;'>{$link}</code></p>
            <p><strong>Ссылка действительна 1 час.</strong> Если вы не регистрировались, просто проигнорируйте это письмо.</p>
            <hr style='margin: 30px 0; border: none; border-top: 1px solid #eee;'>
            <p style='font-size: 12px; color: #999;'>© Найдук — доска объявлений и партнёрская платформа</p>
        </div>
    </body>
    </html>
";

$sent = $notify->sendEmail($email, $subject, $body);
if (!$sent) {
    error_log("Registration email failed to send to $email");
    // Не показываем ошибку пользователю, чтобы не перебирали email
}

// ==================== ЛОГИРОВАНИЕ ====================
$db->insert('security_logs', [
    'user_id' => $userId,
    'ip_address' => getUserIP(),
    'event_type' => 'user_registered_magic',
    'description' => "Пользователь зарегистрирован через Magic Link: $email" . (isset($referrerId) ? " (реферал $referrerId)" : ''),
    'severity' => 'low',
    'created_at' => date('Y-m-d H:i:s')
]);

// ==================== ОТВЕТ ====================
echo json_encode([
    'success' => true,
    'message' => 'Регистрация успешна! Проверьте почту для входа.'
]);