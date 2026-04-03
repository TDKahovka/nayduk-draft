<?php
/* ============================================
   НАЙДУК — API запроса сброса пароля
   Версия 1.0 (март 2026)
   - Генерация криптостойкого токена, хранение только хеша
   - Rate limiting (IP + email, файловый fallback)
   - Единый ответ для защиты от перебора email
   - Автосоздание таблицы password_resets
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

// CSRF-защита
$csrfToken = $_POST['csrf_token'] ?? '';
if (!verify_csrf_token($csrfToken)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Недействительный токен безопасности']);
    exit;
}

// Получение email
$email = trim($_POST['email'] ?? '');
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    // Единый ответ – не сообщаем, что email неверный
    http_response_code(202);
    echo json_encode(['success' => true, 'message' => 'Если email зарегистрирован, вы получите ссылку для сброса пароля.']);
    exit;
}

$db = Database::getInstance();
$pdo = $db->getPdo();

// ==================== АВТОСОЗДАНИЕ ТАБЛИЦЫ ====================
$pdo->exec("
    CREATE TABLE IF NOT EXISTS password_resets (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        email VARCHAR(255) NOT NULL,
        token_hash VARCHAR(64) NOT NULL,
        expires_at TIMESTAMP NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY idx_token_hash (token_hash),
        INDEX idx_email (email),
        INDEX idx_expires (expires_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
");

// ==================== RATE LIMITING ====================
$ip = getUserIP();
$rateKey = 'reset_' . md5($email . '_' . $ip);
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
    // Лимит превышен – единый ответ
    http_response_code(429);
    echo json_encode(['success' => true, 'message' => 'Слишком много попыток. Попробуйте через час.']);
    exit;
}
$attempts[] = $now;
file_put_contents($rateFile, json_encode($attempts), LOCK_EX);

// ==================== ПРОВЕРКА СУЩЕСТВОВАНИЯ EMAIL ====================
$user = $db->getUserByEmail($email);
$userExists = ($user !== false && empty($user['deleted_at']));

// ==================== ГЕНЕРАЦИЯ ТОКЕНА ====================
$rawToken = bin2hex(random_bytes(32));   // 64 символа
$tokenHash = hash('sha256', $rawToken);
$expiresAt = date('Y-m-d H:i:s', time() + 900); // 15 минут

// Удаляем старые неиспользованные токены для этого email
$pdo->prepare("DELETE FROM password_resets WHERE email = ? AND expires_at < NOW()")->execute([$email]);

// Сохраняем только хеш токена
$stmt = $pdo->prepare("INSERT INTO password_resets (email, token_hash, expires_at) VALUES (?, ?, ?)");
$stmt->execute([$email, $tokenHash, $expiresAt]);

// ==================== ОТПРАВКА ПИСЬМА ====================
$link = 'https://' . $_SERVER['HTTP_HOST'] . '/auth/reset-password.php?token=' . $rawToken . '&email=' . urlencode($email);
$notify = new NotificationService();

$subject = '🔐 Восстановление пароля на Найдук';
$body = "
    <!DOCTYPE html>
    <html>
    <head><meta charset='UTF-8'></head>
    <body style='font-family: sans-serif; max-width: 600px; margin: 0 auto; padding: 20px; background: #f5f5f7;'>
        <div style='background: white; border-radius: 20px; padding: 30px; box-shadow: 0 4px 16px rgba(0,0,0,0.05);'>
            <h2 style='color: #4A90E2; margin-bottom: 20px;'>Восстановление пароля</h2>
            <p>Здравствуйте!</p>
            <p>Вы (или кто-то другой) запросили сброс пароля для аккаунта на Найдук.</p>
            <p>Для создания нового пароля нажмите на кнопку ниже:</p>
            <p style='text-align: center; margin: 30px 0;'>
                <a href='{$link}' style='display: inline-block; padding: 12px 30px; background: #4A90E2; color: white; text-decoration: none; border-radius: 50px; font-weight: bold;'>Сбросить пароль</a>
            </p>
            <p>Или скопируйте ссылку в браузер:<br>
            <code style='word-break: break-all;'>{$link}</code></p>
            <p><strong>Ссылка действительна 15 минут.</strong> Если вы не запрашивали сброс, просто проигнорируйте это письмо.</p>
            <hr style='margin: 30px 0; border: none; border-top: 1px solid #eee;'>
            <p style='font-size: 12px; color: #999;'>© Найдук — доска объявлений и партнёрская платформа</p>
        </div>
    </body>
    </html>
";

if ($userExists) {
    $notify->sendEmail($email, $subject, $body);
} else {
    // Если email не существует, письмо не отправляем, но логируем попытку
    error_log("Password reset requested for non-existent email: $email");
}

// ==================== ЛОГИРОВАНИЕ ====================
$db->insert('security_logs', [
    'user_id' => $userExists ? $user['id'] : null,
    'ip_address' => $ip,
    'event_type' => 'password_reset_requested',
    'description' => "Запрос сброса пароля для email $email" . ($userExists ? '' : ' (несуществующий)'),
    'severity' => 'low',
    'created_at' => date('Y-m-d H:i:s')
]);

// ==================== ЗАЩИТА ОТ TIMING ATTACK ====================
usleep(rand(100000, 300000)); // 100–300 мс

// ==================== ЕДИНЫЙ ОТВЕТ ====================
http_response_code(202);
echo json_encode([
    'success' => true,
    'message' => 'Если email зарегистрирован, вы получите ссылку для сброса пароля.'
]);