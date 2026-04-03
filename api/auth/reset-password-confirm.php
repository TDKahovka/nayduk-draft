<?php
/* ============================================
   НАЙДУК — API подтверждения сброса пароля
   Версия 1.0 (март 2026)
   - Проверка токена (хеш, срок действия, не использован)
   - Обновление пароля, удаление токена
   - Уведомление пользователя о смене пароля
   - Логирование, защита от повторного использования
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

// Получение данных
$email = trim($_POST['email'] ?? '');
$token = trim($_POST['token'] ?? '');
$password = $_POST['password'] ?? '';
$passwordConfirm = $_POST['password_confirmation'] ?? '';

// Валидация входных данных
if (empty($email) || empty($token) || empty($password) || empty($passwordConfirm)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Все поля обязательны']);
    exit;
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Некорректный email']);
    exit;
}

if ($password !== $passwordConfirm) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Пароли не совпадают']);
    exit;
}

// Проверка сложности пароля
if (strlen($password) < 8) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Пароль должен содержать не менее 8 символов']);
    exit;
}
if (!preg_match('/[A-Z]/', $password)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Пароль должен содержать хотя бы одну заглавную букву']);
    exit;
}
if (!preg_match('/[a-z]/', $password)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Пароль должен содержать хотя бы одну строчную букву']);
    exit;
}
if (!preg_match('/[0-9]/', $password)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Пароль должен содержать хотя бы одну цифру']);
    exit;
}

$db = Database::getInstance();
$pdo = $db->getPdo();

// ==================== АВТОСОЗДАНИЕ ТАБЛИЦЫ (на случай, если её нет) ====================
$pdo->exec("
    CREATE TABLE IF NOT EXISTS password_resets (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        email VARCHAR(255) NOT NULL,
        token_hash VARCHAR(64) NOT NULL,
        expires_at TIMESTAMP NOT NULL,
        used_at TIMESTAMP NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY idx_token_hash (token_hash),
        INDEX idx_email (email),
        INDEX idx_expires (expires_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
");

// ==================== ПРОВЕРКА ТОКЕНА ====================
$tokenHash = hash('sha256', $token);
$stmt = $pdo->prepare("
    SELECT * FROM password_resets
    WHERE email = ? AND token_hash = ? AND expires_at > NOW() AND used_at IS NULL
");
$stmt->execute([$email, $tokenHash]);
$reset = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$reset) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Ссылка для сброса пароля недействительна или истекла']);
    exit;
}

// ==================== ПОИСК ПОЛЬЗОВАТЕЛЯ ====================
$user = $db->getUserByEmail($email);
if (!$user || !empty($user['deleted_at'])) {
    http_response_code(404);
    echo json_encode(['success' => false, 'error' => 'Пользователь не найден']);
    exit;
}

// Проверяем, что новый пароль не совпадает со старым
if (!empty($user['password_hash']) && password_verify($password, $user['password_hash'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Новый пароль не должен совпадать со старым']);
    exit;
}

// ==================== ОБНОВЛЕНИЕ ПАРОЛЯ ====================
$newHash = password_hash($password, PASSWORD_ARGON2ID);
$pdo->beginTransaction();
try {
    // Обновляем пароль
    $stmt = $pdo->prepare("UPDATE users SET password_hash = ?, updated_at = NOW() WHERE id = ?");
    $stmt->execute([$newHash, $user['id']]);

    // Отмечаем токен как использованный
    $stmt = $pdo->prepare("UPDATE password_resets SET used_at = NOW() WHERE id = ?");
    $stmt->execute([$reset['id']]);

    // Логируем успешный сброс
    $db->insert('security_logs', [
        'user_id' => $user['id'],
        'ip_address' => getUserIP(),
        'event_type' => 'password_reset_success',
        'description' => "Пароль успешно изменён через восстановление",
        'severity' => 'high',
        'created_at' => date('Y-m-d H:i:s')
    ]);

    $pdo->commit();

    // ==================== УВЕДОМЛЕНИЕ ====================
    $notify = new NotificationService();
    $notify->sendEmail($email, 'Ваш пароль на Найдук был изменён',
        "Здравствуйте!\n\nВаш пароль на платформе Найдук был успешно изменён.\n\n" .
        "Если вы не совершали это действие, немедленно свяжитесь со службой поддержки.\n\n" .
        "Если это были вы, просто проигнорируйте это письмо.\n\n" .
        "С уважением,\nКоманда Найдук");

    // Уведомление в личный кабинет (если пользователь зайдёт)
    $notify->send($user['id'], 'password_changed', []);

    echo json_encode(['success' => true, 'message' => 'Пароль успешно изменён']);

} catch (Exception $e) {
    $pdo->rollBack();
    error_log("Password reset error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Ошибка при смене пароля']);
}