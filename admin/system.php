<?php
/* ============================================
   НАЙДУК — Панель управления системой
   Версия 2.0 (март 2026)
   - Защита от параллельного запуска воркеров (flock)
   - Пагинация логов, автоочистка
   - Кэширование информации о системе
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

$csrfToken = generateCsrfToken();

// ==================== ОБРАБОТКА ЗАПУСКА ВОРКЕРОВ ====================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['run_cleanup'])) {
    if (!verify_csrf_token($_POST['csrf_token'])) die('Ошибка CSRF');
    // Защита от параллельного запуска (flock)
    $lockFile = __DIR__ . '/../storage/cleanup.lock';
    $fp = fopen($lockFile, 'w');
    if ($fp && flock($fp, LOCK_EX | LOCK_NB)) {
        $output = [];
        $return = 0;
        exec('php ' . escapeshellarg(__DIR__ . '/../workers/cleanup.php') . ' 2>&1', $output, $return);
        flock($fp, LOCK_UN);
        fclose($fp);
        $message = 'Очистка запущена. Результат: ' . implode("\n", $output);
    } else {
        $message = 'Очистка уже выполняется. Попробуйте позже.';
    }
    $_SESSION['system_message'] = $message;
    header('Location: /admin/system');
    exit;
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['run_integrity'])) {
    if (!verify_csrf_token($_POST['csrf_token'])) die('Ошибка CSRF');
    $lockFile = __DIR__ . '/../storage/integrity.lock';
    $fp = fopen($lockFile, 'w');
    if ($fp && flock($fp, LOCK_EX | LOCK_NB)) {
        $output = [];
        $return = 0;
        exec('php ' . escapeshellarg(__DIR__ . '/../workers/integrity_check.php') . ' 2>&1', $output, $return);
        flock($fp, LOCK_UN);
        fclose($fp);
        $message = 'Проверка целостности запущена. Результат: ' . implode("\n", $output);
    } else {
        $message = 'Проверка целостности уже выполняется. Попробуйте позже.';
    }
    $_SESSION['system_message'] = $message;
    header('Location: /admin/system');
    exit;
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['clear_logs'])) {
    if (!verify_csrf_token($_POST['csrf_token'])) die('Ошибка CSRF');
    @unlink(__DIR__ . '/../storage/logs/cleanup.log');
    @unlink(__DIR__ . '/../storage/logs/integrity.log');
    $_SESSION['system_message'] = 'Логи очищены';
    header('Location: /admin/system');
    exit;
}

$systemMessage = $_SESSION['system_message'] ?? '';
unset($_SESSION['system_message']);

// ==================== ПАГИНАЦИЯ ЛОГОВ ====================
$logFileCleanup = __DIR__ . '/../storage/logs/cleanup.log';
$logFileIntegrity = __DIR__ . '/../storage/logs/integrity.log';
$logLinesCleanup = file_exists($logFileCleanup) ? file($logFileCleanup, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) : [];
$logLinesIntegrity = file_exists($logFileIntegrity) ? file($logFileIntegrity, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) : [];

$page = isset($_GET['log_page']) ? max(1, (int)$_GET['log_page']) : 1;
$logLimit = 50;
$offset = ($page - 1) * $logLimit;

function paginateLogs($lines, $page, $limit) {
    $total = count($lines);
    $pages = ceil($total / $limit);
    $start = ($page - 1) * $limit;
    $sliced = array_slice($lines, $start, $limit);
    return ['data' => $sliced, 'total' => $total, 'pages' => $pages, 'page' => $page];
}

$cleanupPaginated = paginateLogs($logLinesCleanup, $page, $logLimit);
$integrityPaginated = paginateLogs($logLinesIntegrity, $page, $logLimit);

// ==================== ИНФОРМАЦИЯ О СИСТЕМЕ (С КЭШЕМ) ====================
$systemInfo = cacheGet('system_info', 600);
if ($systemInfo === null) {
    $systemInfo = [
        'php_version' => PHP_VERSION,
        'mysql_version' => $db->fetchColumn("SELECT VERSION()"),
        'server_software' => $_SERVER['SERVER_SOFTWARE'] ?? 'Unknown',
        'max_execution_time' => ini_get('max_execution_time'),
        'memory_limit' => ini_get('memory_limit'),
        'upload_max_filesize' => ini_get('upload_max_filesize'),
        'post_max_size' => ini_get('post_max_size'),
    ];
    cacheSet('system_info', $systemInfo, 600);
}

$pageTitle = 'Система — Найдук Админка';
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <meta name="csrf-token" content="<?= $csrfToken ?>">
    <title><?= htmlspecialchars($pageTitle) ?></title>
    <link rel="stylesheet" href="https://cdn.hugeicons.com/font/hgi-stroke-rounded.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/toastify-js/src/toastify.min.css">
    <link rel="stylesheet" href="/css/business.css">
    <link rel="stylesheet" href="/css/premium.css">
    <link rel="stylesheet" href="/css/theme-switch.css">
    <style>
        .admin-container { max-width: 1200px; margin: 0 auto; padding: 40px 20px; }
        .admin-header { margin-bottom: 30px; }
        .card { background: var(--surface); border-radius: var(--radius-xl); padding: 24px; margin-bottom: 30px; border: 1px solid var(--border); }
        .btn { display: inline-block; padding: 8px 16px; border-radius: var(--radius-full); font-weight: 600; cursor: pointer; border: none; background: var(--primary); color: white; text-decoration: none; }
        .btn-secondary { background: var(--bg-secondary); border: 1px solid var(--border); color: var(--text); }
        .buttons { display: flex; gap: 16px; margin-bottom: 30px; flex-wrap: wrap; }
        pre { background: var(--bg-secondary); padding: 16px; border-radius: var(--radius); overflow-x: auto; font-size: 12px; max-height: 400px; overflow-y: auto; }
        .pagination { display: flex; justify-content: center; gap: 8px; margin-top: 20px; }
        .page-btn { width: 36px; height: 36px; border-radius: var(--radius-full); border: 1px solid var(--border); background: var(--surface); color: var(--text); cursor: pointer; text-align: center; line-height: 36px; display: inline-block; }
        .page-btn.active { background: var(--primary); border-color: var(--primary); color: white; }
        .info-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(250px, 1fr)); gap: 16px; margin-top: 16px; }
        .info-item { background: var(--bg-secondary); padding: 12px; border-radius: var(--radius); }
        .info-label { font-weight: 600; font-size: 12px; color: var(--text-secondary); }
        .info-value { font-size: 14px; margin-top: 4px; }
        @media (max-width: 768px) { .buttons { flex-direction: column; } }
    </style>
</head>
<body>
    <div class="admin-container">
        <div class="admin-header">
            <h1>🛠️ Управление системой</h1>
        </div>

        <?php if ($systemMessage): ?>
            <div class="alert alert-info" style="background: rgba(74,144,226,0.1); padding: 12px; border-radius: var(--radius); margin-bottom: 20px;">ℹ️ <?= htmlspecialchars($systemMessage) ?></div>
        <?php endif; ?>

        <div class="buttons">
            <form method="post" style="display: inline;">
                <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
                <input type="hidden" name="run_cleanup" value="1">
                <button type="submit" class="btn">🧹 Запустить очистку</button>
            </form>
            <form method="post" style="display: inline;">
                <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
                <input type="hidden" name="run_integrity" value="1">
                <button type="submit" class="btn">🔍 Проверить целостность</button>
            </form>
            <form method="post" style="display: inline;">
                <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
                <input type="hidden" name="clear_logs" value="1">
                <button type="submit" class="btn btn-secondary">🗑️ Очистить логи</button>
            </form>
        </div>

        <div class="card">
            <h2>💻 Информация о системе</h2>
            <div class="info-grid">
                <div class="info-item"><div class="info-label">PHP версия</div><div class="info-value"><?= htmlspecialchars($systemInfo['php_version']) ?></div></div>
                <div class="info-item"><div class="info-label">MySQL версия</div><div class="info-value"><?= htmlspecialchars($systemInfo['mysql_version']) ?></div></div>
                <div class="info-item"><div class="info-label">Сервер</div><div class="info-value"><?= htmlspecialchars($systemInfo['server_software']) ?></div></div>
                <div class="info-item"><div class="info-label">max_execution_time</div><div class="info-value"><?= htmlspecialchars($systemInfo['max_execution_time']) ?></div></div>
                <div class="info-item"><div class="info-label">memory_limit</div><div class="info-value"><?= htmlspecialchars($systemInfo['memory_limit']) ?></div></div>
                <div class="info-item"><div class="info-label">upload_max_filesize</div><div class="info-value"><?= htmlspecialchars($systemInfo['upload_max_filesize']) ?></div></div>
                <div class="info-item"><div class="info-label">post_max_size</div><div class="info-value"><?= htmlspecialchars($systemInfo['post_max_size']) ?></div></div>
            </div>
        </div>

        <div class="card">
            <h2>📋 Лог очистки</h2>
            <pre><?= htmlspecialchars(implode("\n", $cleanupPaginated['data']) ?: 'Лог пуст') ?></pre>
            <?php if ($cleanupPaginated['pages'] > 1): ?>
                <div class="pagination">
                    <?php for ($i = 1; $i <= $cleanupPaginated['pages']; $i++): ?>
                        <a href="?log_page=<?= $i ?>" class="page-btn <?= $i == $cleanupPaginated['page'] ? 'active' : '' ?>"><?= $i ?></a>
                    <?php endfor; ?>
                </div>
            <?php endif; ?>
        </div>

        <div class="card">
            <h2>📋 Лог проверки целостности</h2>
            <pre><?= htmlspecialchars(implode("\n", $integrityPaginated['data']) ?: 'Лог пуст') ?></pre>
            <?php if ($integrityPaginated['pages'] > 1): ?>
                <div class="pagination">
                    <?php for ($i = 1; $i <= $integrityPaginated['pages']; $i++): ?>
                        <a href="?log_page=<?= $i ?>" class="page-btn <?= $i == $integrityPaginated['page'] ? 'active' : '' ?>"><?= $i ?></a>
                    <?php endfor; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>