<?php
/* ============================================
   НАЙДУК — AI-сканер уязвимостей
   ============================================ */

session_start();
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../services/Database.php';

if (!isset($_SESSION['user_id']) || !is_admin()) {
    header('Location: /auth/login');
    exit;
}

$apiKey = getenv('DEEPSEEK_API_KEY') ?: (function() {
    $env = file(__DIR__ . '/../.env', FILE_IGNORE_NEW_LINES);
    foreach ($env as $line) {
        if (strpos($line, 'DEEPSEEK_API_KEY=') === 0) {
            return trim(substr($line, strlen('DEEPSEEK_API_KEY=')));
        }
    }
    return '';
})();

$scanResult = null;
$scanError = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'scan') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $scanError = 'Недействительный CSRF-токен';
    } else {
        // Собираем все PHP-файлы, исключая vendor
        $directory = __DIR__ . '/..';
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory));
        $phpFiles = [];
        foreach ($files as $file) {
            if ($file->isFile() && $file->getExtension() === 'php' && strpos($file->getPathname(), '/vendor/') === false) {
                $phpFiles[] = $file->getPathname();
            }
        }

        // Ограничиваем количество файлов, чтобы не превысить лимит токенов (максимум 30 файлов за раз)
        $filesToAnalyze = array_slice($phpFiles, 0, 30);
        $codeSnippets = '';
        foreach ($filesToAnalyze as $file) {
            $content = file_get_contents($file);
            $relative = str_replace($directory, '', $file);
            $codeSnippets .= "Файл: {$relative}\n```php\n" . mb_substr($content, 0, 2000) . "\n```\n\n";
        }

        $prompt = "Ты эксперт по безопасности PHP. Проанализируй следующий код и найди уязвимости:
- SQL-инъекции (неэкранированный ввод в запросы)
- XSS (неэкранированный вывод)
- Отсутствие CSRF-защиты в формах
- Использование опасных функций (eval, exec, system, shell_exec, passthru, popen)
- Небезопасная работа с файлами (include с переменными)
- Слабые пароли или их хранение в открытом виде

Для каждой найденной уязвимости укажи:
- Файл
- Строку (примерно)
- Тип уязвимости
- Рекомендацию по исправлению

Вот код:
{$codeSnippets}

Выведи отчёт в формате Markdown, сгруппированный по файлам.";

        $postData = [
            'model' => 'deepseek-chat',
            'messages' => [
                ['role' => 'system', 'content' => 'Ты профессиональный аудитор безопасности. Отвечай подробно, перечисляй уязвимости.'],
                ['role' => 'user', 'content' => $prompt]
            ],
            'temperature' => 0.3,
            'max_tokens' => 2000
        ];

        $ch = curl_init('https://api.deepseek.com/v1/chat/completions');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($postData));
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $apiKey
        ]);
        curl_setopt($ch, CURLOPT_TIMEOUT, 60);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200) {
            $scanError = 'Ошибка AI: HTTP ' . $httpCode;
        } else {
            $data = json_decode($response, true);
            $scanResult = $data['choices'][0]['message']['content'] ?? 'Не удалось получить ответ';
        }
    }
}

$pageTitle = 'Сканер уязвимостей — Найдук';
require_once __DIR__ . '/../includes/header.php';
?>

<style>
    .scan-container {
        max-width: 1200px;
        margin: 40px auto;
        padding: 20px;
    }
    .scan-card {
        background: var(--surface);
        border-radius: var(--radius-2xl);
        padding: 30px;
        border: 1px solid var(--border);
    }
    .scan-result {
        background: var(--bg-secondary);
        padding: 20px;
        border-radius: var(--radius);
        font-family: monospace;
        white-space: pre-wrap;
        overflow-x: auto;
        max-height: 70vh;
    }
    .btn-scan {
        background: var(--primary);
        color: white;
        border: none;
        padding: 12px 24px;
        border-radius: var(--radius-full);
        cursor: pointer;
        font-weight: bold;
    }
    .btn-scan:disabled {
        opacity: 0.5;
        cursor: not-allowed;
    }
</style>

<div class="scan-container">
    <div class="scan-card">
        <h1>🔍 AI-сканер уязвимостей</h1>
        <p>Сканирует первые 30 PHP-файлов вашего проекта (исключая vendor) и ищет потенциальные уязвимости.</p>

        <form method="post">
            <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
            <input type="hidden" name="action" value="scan">
            <button type="submit" class="btn-scan" id="scanBtn">🚀 Начать сканирование</button>
        </form>

        <?php if ($scanError): ?>
            <div class="error" style="color: var(--danger); margin-top: 20px;"><?= htmlspecialchars($scanError) ?></div>
        <?php endif; ?>

        <?php if ($scanResult !== null): ?>
            <h2 style="margin-top: 30px;">Результаты сканирования</h2>
            <div class="scan-result">
                <?= nl2br(htmlspecialchars($scanResult)) ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<script>
    document.querySelector('form')?.addEventListener('submit', function() {
        const btn = document.getElementById('scanBtn');
        btn.disabled = true;
        btn.innerText = '🔍 Сканирование... (может занять до минуты)';
    });
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>