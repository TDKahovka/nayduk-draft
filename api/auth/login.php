<?php
/* ============================================
   НАЙДУК — API входа (полная версия)
   Версия 3.0 (март 2026)
   - Парольный вход с "Запомнить меня"
   - Magic Link (отправка ссылки на email)
   - Rate limiting по IP и email
   - Полное логирование
   ============================================ */

session_start();
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../services/Database.php';
require_once __DIR__ . '/../../services/NotificationService.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

// Rate limiting по IP
$ip = getUserIP();
if (!checkRateLimit('login_ip_' . $ip, 5, 60)) {
    http_response_code(429);
    echo json_encode(['success' => false, 'error' => 'Слишком много попыток входа. Попробуйте позже.']);
    exit;
}

// CSRF
$csrfToken = $_POST['csrf_token'] ?? '';
if (!verify_csrf_token($csrfToken)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Недействительный токен безопасности']);
    exit;
}

$email = trim($_POST['email'] ?? '');
$password = $_POST['password'] ?? '';
$magicLink = isset($_POST['magic_link']) && $_POST['magic_link'] == '1';
$remember = isset($_POST['remember']) && $_POST['remember'] == '1';

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Некорректный email']);
    exit;
}

// Rate limiting по email
if (!checkRateLimit('login_email_' . md5($email), 3, 300)) { // 3 попытки за 5 минут
    http_response_code(429);
    echo json_encode(['success' => false, 'error' => 'Слишком много попыток для этого email. Попробуйте позже.']);
    exit;
}

$db = Database::getInstance();
$pdo = $db->getConnection();

// Проверка существования пользователя
$user = $db->fetchOne("SELECT * FROM users WHERE email = ?", [$email]);

if (!$user) {
    // Не сообщаем, что пользователь не найден
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Неверный email или пароль']);
    exit;
}

if (!empty($user['is_banned'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Ваш аккаунт заблокирован']);
    exit;
}

// === Magic Link ===
if ($magicLink) {
    $token = bin2hex(random_bytes(32));
    $tokenHash = hash_hmac('sha256', $token, $_ENV['APP_SECRET'] ?? 'default_secret');
    $expires = date('Y-m-d H:i:s', strtotime('+15 minutes')); // 15 минут

    // Сохраняем хеш токена
    $db->insert('login_tokens', [
        'user_id' => $user['id'],
        'token_hash' => $tokenHash,
        'expires_at' => $expires,
        'used' => 0,
        'created_at' => date('Y-m-d H:i:s')
    ]);

    $link = "https://{$_SERVER['HTTP_HOST']}/api/auth/magic-login.php?token={$token}";

    // Отправка email через очередь (упрощённо – через NotificationService)
    $notifier = new NotificationService();
    $sent = $notifier->sendEmail($email, 'Вход на Найдук', "Здравствуйте! Перейдите по ссылке для входа:\n{$link}\nСсылка действительна 15 минут.");

    $db->insert('security_logs', [
        'user_id' => $user['id'],
        'ip_address' => $ip,
        'event_type' => 'magic_link_requested',
        'description' => "Magic link requested for $email",
        'severity' => 'low',
        'created_at' => date('Y-m-d H:i:s')
    ]);

    echo json_encode(['success' => true, 'magic_link_sent' => true]);
    exit;
}

// === Парольный вход ===
if (empty($password)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Введите пароль']);
    exit;
}

if (empty($user['password_hash'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Для этого аккаунта требуется вход через соцсети']);
    exit;
}

if (!password_verify($password, $user['password_hash'])) {
    $db->insert('security_logs', [
        'user_id' => $user['id'],
        'ip_address' => $ip,
        'event_type' => 'failed_login',
        'description' => "Failed login attempt for $email",
        'severity' => 'medium',
        'created_at' => date('Y-m-d H:i:s')
    ]);
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Неверный email или пароль']);
    exit;
}

// Успешный вход
$_SESSION['user_id'] = $user['id'];
$_SESSION['user_name'] = $user['name'];
$_SESSION['user_email'] = $user['email'];
$_SESSION['user_role'] = $user['role'] ?? 'user';
$_SESSION['user_is_partner'] = $user['is_partner'] ?? false;

// "Запомнить меня" – создаём отдельный токен (если есть отдельная таблица, реализуем позже)
if ($remember) {
    // Устанавливаем долгоживущую сессию (стандартный подход)
    ini_set('session.cookie_lifetime', 30 * 24 * 60 * 60);
    session_regenerate_id(true);
}

// Обновляем last_login
$db->update('users', ['last_login_at' => date('Y-m-d H:i:s')], 'id = ?', [$user['id']]);

$db->insert('security_logs', [
    'user_id' => $user['id'],
    'ip_address' => $ip,
    'event_type' => 'successful_login',
    'description' => "User logged in",
    'severity' => 'low',
    'created_at' => date('Y-m-d H:i:s')
]);

$redirect = $_SESSION['auth_redirect'] ?? '/profile';
unset($_SESSION['auth_redirect']);

echo json_encode(['success' => true, 'redirect' => $redirect]);