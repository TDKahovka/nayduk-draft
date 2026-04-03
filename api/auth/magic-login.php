<?php
/* ============================================
   НАЙДУК — API Magic Link (безпарольный вход)
   Версия 1.0 (март 2026)
   - Принимает email, отправляет ссылку для входа
   - Если email не зарегистрирован — создаёт пользователя
   - Генерация токена, срок 1 час, одноразовый
   - Rate limiting (3 попытки в час на email)
   - Логирование, безопасность, автосоздание таблиц
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

// Проверка CSRF
$csrfToken = $_POST['csrf_token'] ?? '';
if (!verifyCsrfToken($csrfToken)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Недействительный токен безопасности']);
    exit;
}

// Получение email
$email = trim($_POST['email'] ?? '');
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Некорректный email']);
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

// Добавляем поле email_verified, если его нет
$columns = $pdo->query("SHOW COLUMNS FROM users")->fetchAll(PDO::FETCH_COLUMN);
if (!in_array('email_verified', $columns)) {
    $pdo->exec("ALTER TABLE users ADD COLUMN email_verified BOOLEAN DEFAULT FALSE");
}

// ==================== RATE LIMITING ====================
$rateKey = 'magic_' . md5($email);
$rateFile = __DIR__ . '/../../storage/rate/' . $rateKey . '.txt';
$rateDir = dirname($rateFile);
if (!is_dir($rateDir)) mkdir($rateDir, 0755, true);

$now = time();
$attempts = [];
if (file_exists($rateFile)) {
    $attempts = json_decode(file_get_contents($rateFile), true);
    if (!is_array($attempts)) $attempts = [];
    // удаляем попытки старше часа
    $attempts = array_filter($attempts, fn($t) => $t > $now - 3600);
}
if (count($attempts) >= 3) {
    http_response_code(429);
    echo json_encode(['success' => false, 'error' => 'Слишком много попыток. Попробуйте через час.']);
    exit;
}
$attempts[] = $now;
file_put_contents($rateFile, json_encode($attempts), LOCK_EX);

// ==================== ПОИСК ИЛИ СОЗДАНИЕ ПОЛЬЗОВАТЕЛЯ ====================
$user = $db->getUserByEmail($email);
if (!$user) {
    // Создаём нового пользователя
    $name = explode('@', $email)[0];
    $userId = $db->createUser([
        'email' => $email,
        'name' => $name,
        'trust_score' => 50,
        'email_verified' => 0,
        'registration_method' => 'magic_link',
        'created_at' => date('Y-m-d H:i:s'),
        'updated_at' => date('Y-m-d H:i:s')
    ]);
    if (!$userId) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Ошибка создания пользователя']);
        exit;
    }
    $user = $db->getUserById($userId);
} else {
    $userId = $user['id'];
}

// ==================== ГЕНЕРАЦИЯ ТОКЕНА ====================
$token = bin2hex(random_bytes(32));
$tokenHash = hash('sha256', $token);
$expiresAt = date('Y-m-d H:i:s', time() + 3600); // 1 час

// Удаляем старые неиспользованные токены этого пользователя (опционально, чтобы не захламлять)
$pdo->prepare("DELETE FROM magic_links WHERE user_id = ? AND used_at IS NULL AND expires_at < NOW()")->execute([$userId]);

// Сохраняем хеш токена
$stmt = $pdo->prepare("INSERT INTO magic_links (user_id, token_hash, expires_at) VALUES (?, ?, ?)");
$stmt->execute([$userId, $tokenHash, $expiresAt]);

// ==================== ОТПРАВКА ПИСЬМА ====================
$link = 'https://' . $_SERVER['HTTP_HOST'] . '/auth/magic?token=' . urlencode($token);
$notify = new NotificationService();

$subject = '🔐 Вход в Найдук';
$body = "
    <!DOCTYPE html>
    <html>
    <head><meta charset='UTF-8'></head>
    <body style='font-family: sans-serif; max-width: 600px; margin: 0 auto; padding: 20px;'>
        <h2 style='color: #4A90E2;'>Вход в Найдук</h2>
        <p>Здравствуйте!</p>
        <p>Вы (или кто-то другой) запросили вход в ваш аккаунт на Найдук.</p>
        <p>Для входа нажмите на кнопку ниже:</p>
        <p style='text-align: center; margin: 30px 0;'>
            <a href='{$link}' style='display: inline-block; padding: 12px 30px; background: #4A90E2; color: white; text-decoration: none; border-radius: 50px; font-weight: bold;'>Войти в Найдук</a>
        </p>
        <p>Или скопируйте ссылку в браузер:<br>
        <code style='word-break: break-all;'>{$link}</code></p>
        <p><strong>Ссылка действительна 1 час.</strong> Если вы не запрашивали вход, просто проигнорируйте это письмо.</p>
        <hr style='margin: 30px 0; border: none; border-top: 1px solid #eee;'>
        <p style='font-size: 12px; color: #999;'>© Найдук — доска объявлений и партнёрская платформа</p>
    </body>
    </html>
";

$sent = $notify->sendEmail($email, $subject, $body);
if (!$sent) {
    // Логируем ошибку, но пользователю говорим, что всё ок (чтобы не перебирали email)
    error_log("Magic link failed to send to $email");
}

// ==================== ЛОГИРОВАНИЕ ====================
$db->insert('security_logs', [
    'user_id' => $userId,
    'ip_address' => getUserIP(),
    'event_type' => 'magic_link_requested',
    'description' => "Запрошена ссылка для входа на email $email",
    'severity' => 'low',
    'created_at' => date('Y-m-d H:i:s')
]);

// Ответ
echo json_encode([
    'success' => true,
    'message' => 'Ссылка для входа отправлена на указанный email'
]);