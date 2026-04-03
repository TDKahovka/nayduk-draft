<?php
/* ============================================
   НАЙДУК — Мониторинг ошибок с AI-анализом
   Версия 1.0
   ============================================ */

session_start();
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../services/Database.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: /auth/login');
    exit;
}

$db = Database::getInstance();
$user = $db->fetchOne("SELECT role FROM users WHERE id = ?", [$_SESSION['user_id']]);
if (!$user || $user['role'] !== 'admin') {
    header('Location: /');
    exit;
}

// Собираем ошибки из логов (последние 50 строк из файла)
$logFile = __DIR__ . '/../storage/logs/errors.log';
$errors = [];
if (file_exists($logFile)) {
    $lines = file($logFile);
    $lines = array_reverse($lines);
    $errors = array_slice($lines, 0, 50);
}

// Также можно собрать ошибки из БД (security_logs)
$dbErrors = $db->fetchAll("
    SELECT id, description, created_at
    FROM security_logs
    WHERE event_type = 'error'
    ORDER BY created_at DESC
    LIMIT 50
");

$csrfToken = generateCsrfToken();
$pageTitle = 'AI Мониторинг — Найдук';
?>
<!DOCTYPE html>
<html lang="ru" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?= $csrfToken ?>">
    <title><?= htmlspecialchars($pageTitle) ?></title>
    <link rel="stylesheet" href="https://cdn.hugeicons.com/font/hgi-stroke-rounded.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/toastify-js/src/toastify.min.css">
    <link rel="stylesheet" href="/css/business.css">
    <link rel="stylesheet" href="/css/premium.css">
    <style>
        .container { max-width: 1200px; margin: 0 auto; padding: 40px 20px; }
        .error-card {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: var(--radius-xl);
            padding: 20px;
            margin-bottom: 20px;
        }
        .error-message {
            font-family: monospace;
            background: var(--bg-secondary);
            padding: 12px;
            border-radius: var(--radius);
            margin-bottom: 12px;
            overflow-x: auto;
        }
        .error-actions {
            display: flex;
            gap: 12px;
            margin-top: 12px;
        }
        .analysis {
            background: var(--bg-secondary);
            padding: 16px;
            border-radius: var(--radius);
            margin-top: 12px;
            display: none;
            border-left: 4px solid var(--primary);
        }
        .analysis.show { display: block; }
        .btn { padding: 8px 16px; border-radius: var(--radius-full); cursor: pointer; border: none; }
        .btn-primary { background: var(--primary); color: white; }
        .btn-secondary { background: var(--bg-secondary); border: 1px solid var(--border); }
        .spinner {
            display: inline-block;
            width: 16px;
            height: 16px;
            border: 2px solid rgba(255,255,255,0.3);
            border-radius: 50%;
            border-top-color: #fff;
            animation: spin 0.6s linear infinite;
        }
        @keyframes spin { to { transform: rotate(360deg); } }
    </style>
</head>
<body>
    <?php require_once __DIR__ . '/../includes/header.php'; ?>
    <div class="container">
        <h1>🤖 AI Мониторинг ошибок</h1>
        <p>Здесь можно проанализировать ошибки с помощью DeepSeek AI. Нажмите «Анализировать» — получите причину и решение.</p>

        <h2>📁 Лог-файл (последние 50 ошибок)</h2>
        <?php if (empty($errors)): ?>
            <p>Нет ошибок в лог-файле.</p>
        <?php else: ?>
            <?php foreach ($errors as $error): ?>
            <div class="error-card">
                <div class="error-message"><?= nl2br(htmlspecialchars($error)) ?></div>
                <div class="error-actions">
                    <button class="btn btn-primary analyze-btn" data-error="<?= htmlspecialchars($error) ?>">🧠 Анализировать через AI</button>
                </div>
                <div class="analysis"></div>
            </div>
            <?php endforeach; ?>
        <?php endif; ?>

        <h2>📊 Ошибки из БД (security_logs)</h2>
        <?php if (empty($dbErrors)): ?>
            <p>Нет ошибок в БД.</p>
        <?php else: ?>
            <?php foreach ($dbErrors as $err): ?>
            <div class="error-card">
                <div class="error-message">
                    <strong><?= date('d.m.Y H:i:s', strtotime($err['created_at'])) ?></strong><br>
                    <?= nl2br(htmlspecialchars($err['description'])) ?>
                </div>
                <div class="error-actions">
                    <button class="btn btn-primary analyze-btn" data-error="<?= htmlspecialchars($err['description']) ?>">🧠 Анализировать через AI</button>
                </div>
                <div class="analysis"></div>
            </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/toastify-js"></script>
    <script>
        const csrfToken = '<?= $csrfToken ?>';

        document.querySelectorAll('.analyze-btn').forEach(btn => {
            btn.addEventListener('click', async () => {
                const errorText = btn.dataset.error;
                const card = btn.closest('.error-card');
                const analysisDiv = card.querySelector('.analysis');
                const originalText = btn.innerHTML;
                btn.innerHTML = '<span class="spinner"></span> Анализирую...';
                btn.disabled = true;

                try {
                    const response = await fetch('/api/admin/ai-analyze.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ error_text: errorText, csrf_token: csrfToken })
                    });
                    const data = await response.json();
                    if (data.success) {
                        analysisDiv.innerHTML = '<strong>🤖 DeepSeek рекомендует:</strong><br>' + data.analysis.replace(/\n/g, '<br>');
                        analysisDiv.classList.add('show');
                    } else {
                        analysisDiv.innerHTML = '<strong>❌ Ошибка:</strong> ' + data.error;
                        analysisDiv.classList.add('show');
                    }
                } catch (err) {
                    analysisDiv.innerHTML = '<strong>❌ Ошибка сети:</strong> ' + err.message;
                    analysisDiv.classList.add('show');
                } finally {
                    btn.innerHTML = originalText;
                    btn.disabled = false;
                }
            });
        });
    </script>
    <?php require_once __DIR__ . '/../includes/footer.php'; ?>
</body>
</html>