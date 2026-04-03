#!/usr/bin/php
<?php
/**
 * НАЙДУК — AI уведомление об ошибках по email
 * Версия 2.0 (март 2026)
 * - Полная автономная работа, без ручного вмешательства
 * - Автоматический выбор email администратора (из БД или .env)
 * - Анализ ошибок через DeepSeek API
 * - Защита от дублирования отправок
 * - Логирование работы
 * - Использование PHPMailer, если доступен, иначе mail()
 */

// Запуск только из CLI
if (php_sapi_name() !== 'cli') {
    die('This script can only be run from command line.');
}

// Подключаем основные файлы
$baseDir = dirname(__DIR__);
require_once $baseDir . '/includes/functions.php';
require_once $baseDir . '/services/Database.php';

// Инициализируем лог-файл для скрипта
$logFile = $baseDir . '/storage/logs/ai_notify.log';
$logDir = dirname($logFile);
if (!is_dir($logDir)) {
    mkdir($logDir, 0755, true);
}

function logMessage($message) {
    global $logFile;
    $timestamp = date('Y-m-d H:i:s');
    file_put_contents($logFile, "[$timestamp] $message\n", FILE_APPEND);
}

logMessage("Скрипт запущен");

// --- Получаем API ключ DeepSeek ---
$apiKey = getenv('DEEPSEEK_API_KEY');
if (empty($apiKey) && file_exists($baseDir . '/.env')) {
    $env = file($baseDir . '/.env', FILE_IGNORE_NEW_LINES);
    foreach ($env as $line) {
        if (strpos($line, 'DEEPSEEK_API_KEY=') === 0) {
            $apiKey = trim(substr($line, strlen('DEEPSEEK_API_KEY=')));
            break;
        }
    }
}
if (empty($apiKey)) {
    logMessage("Ошибка: API ключ DeepSeek не найден");
    die("API ключ DeepSeek не найден\n");
}

// --- Получаем email администратора ---
$db = Database::getInstance();
$adminEmail = '';

// 1. Из таблицы settings
$settings = $db->fetchOne("SELECT value FROM settings WHERE name = 'admin_email'");
if ($settings && !empty($settings['value'])) {
    $adminEmail = $settings['value'];
}

// 2. Если нет, пробуем из .env
if (empty($adminEmail) && file_exists($baseDir . '/.env')) {
    $env = file($baseDir . '/.env', FILE_IGNORE_NEW_LINES);
    foreach ($env as $line) {
        if (strpos($line, 'ADMIN_EMAIL=') === 0) {
            $adminEmail = trim(substr($line, strlen('ADMIN_EMAIL=')));
            break;
        }
    }
}

// 3. Если всё ещё нет, берём первого администратора из БД
if (empty($adminEmail)) {
    $admin = $db->fetchOne("SELECT email FROM users WHERE role = 'admin' LIMIT 1");
    if ($admin) {
        $adminEmail = $admin['email'];
    }
}

if (empty($adminEmail)) {
    logMessage("Ошибка: Email администратора не найден");
    die("Email администратора не найден\n");
}

logMessage("Email администратора: $adminEmail");

// --- Файл для хранения времени последней обработанной ошибки ---
$lastProcessedFile = $baseDir . '/storage/last_error_notify.txt';
$lastTimestamp = file_exists($lastProcessedFile) ? (int)file_get_contents($lastProcessedFile) : 0;

// --- Лог ошибок ---
$errorLogFile = $baseDir . '/storage/logs/errors.log';
if (!file_exists($errorLogFile)) {
    logMessage("Лог ошибок не найден");
    exit(0);
}

// Читаем все строки лога
$lines = file($errorLogFile);
$newErrors = [];

foreach ($lines as $line) {
    // Ищем дату в начале строки в формате YYYY-MM-DD HH:MM:SS
    if (preg_match('/^(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})/', $line, $matches)) {
        $lineTime = strtotime($matches[1]);
        if ($lineTime > $lastTimestamp) {
            $newErrors[] = $line;
        }
    }
}

if (empty($newErrors)) {
    logMessage("Новых ошибок нет");
    exit(0);
}

logMessage("Найдено " . count($newErrors) . " новых ошибок");

// Берём последние 5 ошибок для контекста (чтобы не превысить лимит токенов)
$errorText = implode('', array_slice($newErrors, -5));

// --- Формируем запрос к DeepSeek ---
$prompt = "Ты эксперт по PHP и веб-разработке. Проанализируй следующую ошибку и дай:
- Краткое описание проблемы
- Вероятную причину
- Рекомендации по исправлению

Ошибка:
$errorText";

$postData = [
    'model' => 'deepseek-chat',
    'messages' => [
        ['role' => 'system', 'content' => 'Ты опытный системный администратор. Отвечай кратко и по делу.'],
        ['role' => 'user', 'content' => $prompt]
    ],
    'temperature' => 0.3,
    'max_tokens' => 800
];

$ch = curl_init('https://api.deepseek.com/v1/chat/completions');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($postData));
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Content-Type: application/json',
    'Authorization: Bearer ' . $apiKey
]);
curl_setopt($ch, CURLOPT_TIMEOUT, 30);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($httpCode !== 200) {
    logMessage("Ошибка API DeepSeek: HTTP $httpCode");
    die("Ошибка API DeepSeek: HTTP $httpCode\n");
}

$data = json_decode($response, true);
$analysis = $data['choices'][0]['message']['content'] ?? 'Анализ не получен';

logMessage("Анализ получен от AI");

// --- Отправляем email ---
$subject = "[Найдук] Обнаружена ошибка на сайте";

$body = "Здравствуйте!\n\n";
$body .= "На сайте обнаружены следующие ошибки (последние):\n\n";
$body .= $errorText . "\n\n";
$body .= "Анализ от AI:\n";
$body .= $analysis . "\n\n";
$body .= "--\nЭто автоматическое сообщение. Для отключения измените настройки в админке.\n";

// Пытаемся отправить через PHPMailer, если доступен
$mailSent = false;
if (class_exists('PHPMailer\PHPMailer\PHPMailer')) {
    try {
        $mail = new PHPMailer\PHPMailer\PHPMailer(true);
        // Настройки SMTP можно взять из БД или .env
        $smtpHost = getenv('SMTP_HOST') ?: '';
        $smtpPort = getenv('SMTP_PORT') ?: 587;
        $smtpUser = getenv('SMTP_USER') ?: '';
        $smtpPass = getenv('SMTP_PASS') ?: '';

        if ($smtpHost && $smtpUser && $smtpPass) {
            $mail->isSMTP();
            $mail->Host = $smtpHost;
            $mail->Port = $smtpPort;
            $mail->SMTPAuth = true;
            $mail->Username = $smtpUser;
            $mail->Password = $smtpPass;
            $mail->SMTPSecure = PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
        }

        $mail->setFrom('no-reply@nayduk.ru', 'Найдук');
        $mail->addAddress($adminEmail);
        $mail->Subject = $subject;
        $mail->Body = $body;

        $mailSent = $mail->send();
        if ($mailSent) {
            logMessage("Email отправлен через PHPMailer на $adminEmail");
        } else {
            logMessage("PHPMailer не смог отправить: " . $mail->ErrorInfo);
        }
    } catch (Exception $e) {
        logMessage("PHPMailer ошибка: " . $e->getMessage());
    }
}

// Если PHPMailer не доступен или не сработал, используем mail()
if (!$mailSent) {
    $headers = "From: no-reply@nayduk.ru\r\n";
    $headers .= "Content-Type: text/plain; charset=UTF-8\r\n";
    $mailSent = mail($adminEmail, $subject, $body, $headers);
    if ($mailSent) {
        logMessage("Email отправлен через mail() на $adminEmail");
    } else {
        logMessage("Ошибка отправки email через mail()");
    }
}

// Обновляем метку времени последней обработанной ошибки (максимальная дата из новых)
$newLastTimestamp = 0;
foreach ($newErrors as $errLine) {
    if (preg_match('/^(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})/', $errLine, $matches)) {
        $ts = strtotime($matches[1]);
        if ($ts > $newLastTimestamp) {
            $newLastTimestamp = $ts;
        }
    }
}
file_put_contents($lastProcessedFile, $newLastTimestamp);

if ($mailSent) {
    logMessage("Скрипт завершён успешно");
    echo "Уведомление отправлено на $adminEmail\n";
} else {
    logMessage("Скрипт завершён с ошибкой отправки email");
    echo "Ошибка отправки email\n";
}