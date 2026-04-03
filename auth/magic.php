<?php
/* ============================================
   НАЙДУК — Обработчик Magic Link (безпарольный вход)
   Версия 1.0 (март 2026)
   - Принимает token, проверяет, авторизует, удаляет токен
   - Автосоздание таблицы magic_links
   - Полная безопасность, логирование, защита от повторного использования
   - SEO: канонические ссылки, мета-теги, robots
   ============================================ */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../services/Database.php';

$db = Database::getInstance();
$pdo = $db->getPdo();

// ==================== АВТОСОЗДАНИЕ ТАБЛИЦЫ (если нет) ====================
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

// ==================== ПОЛУЧЕНИЕ ТОКЕНА ====================
$token = $_GET['token'] ?? '';
$error = null;
$success = false;

if (empty($token)) {
    $error = 'Ссылка для входа не содержит токена. Проверьте правильность ссылки.';
} else {
    $tokenHash = hash('sha256', $token);
    $now = date('Y-m-d H:i:s');

    // Поиск токена в базе
    $stmt = $pdo->prepare("
        SELECT ml.id, ml.user_id, ml.expires_at, ml.used_at,
               u.email, u.name, u.deleted_at
        FROM magic_links ml
        JOIN users u ON ml.user_id = u.id
        WHERE ml.token_hash = ?
    ");
    $stmt->execute([$tokenHash]);
    $record = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$record) {
        $error = 'Ссылка для входа недействительна. Возможно, она уже была использована или никогда не существовала.';
    } elseif (!empty($record['deleted_at'])) {
        $error = 'Аккаунт был удалён. Вход невозможен.';
    } elseif (strtotime($record['expires_at']) < time()) {
        $error = 'Ссылка для входа истекла. Срок действия ссылки — 1 час. Запросите новую ссылку на странице входа.';
    } elseif ($record['used_at'] !== null) {
        $error = 'Ссылка для входа уже была использована. Каждая ссылка может быть использована только один раз.';
    } else {
        // ==================== УСПЕШНЫЙ ВХОД ====================
        $pdo->beginTransaction();
        try {
            // Отмечаем токен как использованный
            $stmt = $pdo->prepare("UPDATE magic_links SET used_at = ? WHERE id = ?");
            $stmt->execute([$now, $record['id']]);

            // Если email не был подтверждён — подтверждаем
            $stmt = $pdo->prepare("UPDATE users SET email_verified = 1 WHERE id = ? AND email_verified = 0");
            $stmt->execute([$record['user_id']]);

            // Создаём сессию
            $_SESSION['user_id'] = (int)$record['user_id'];
            $_SESSION['user_name'] = $record['name'];
            $_SESSION['user_email'] = $record['email'];
            $_SESSION['login_method'] = 'magic_link';
            $_SESSION['login_time'] = time();

            // Обновляем last_login в users
            $pdo->prepare("UPDATE users SET last_login = ? WHERE id = ?")->execute([$now, $record['user_id']]);

            // Логируем успешный вход
            $db->insert('security_logs', [
                'user_id' => $record['user_id'],
                'ip_address' => getUserIP(),
                'event_type' => 'magic_link_login_success',
                'description' => 'Успешный вход через Magic Link',
                'severity' => 'low',
                'created_at' => $now
            ]);

            $pdo->commit();
            $success = true;

            // Перенаправление (выполняется в конце скрипта)
            header('Location: /profile');
            exit;

        } catch (Exception $e) {
            $pdo->rollBack();
            error_log("Magic link login error: " . $e->getMessage());
            $error = 'Произошла ошибка при входе. Пожалуйста, попробуйте ещё раз.';
        }
    }

    // Логируем неудачную попытку (если была ошибка)
    if ($error && $record) {
        $db->insert('security_logs', [
            'user_id' => $record['user_id'] ?? null,
            'ip_address' => getUserIP(),
            'event_type' => 'magic_link_login_failed',
            'description' => 'Неудачная попытка входа через Magic Link: ' . $error,
            'severity' => 'medium',
            'created_at' => date('Y-m-d H:i:s')
        ]);
    }
}

// ==================== СТРАНИЦА РЕЗУЛЬТАТА (если не было редиректа) ====================
$pageTitle = $success ? 'Вход выполнен — Найдук' : 'Вход по ссылке — Найдук';
$pageDescription = $success ? 'Вы успешно вошли в аккаунт Найдук.' : 'Проверка ссылки для входа в Найдук.';
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title><?= htmlspecialchars($pageTitle) ?></title>
    <meta name="description" content="<?= htmlspecialchars($pageDescription) ?>">
    <meta name="robots" content="noindex, follow">
    <link rel="canonical" href="https://<?= $_SERVER['HTTP_HOST'] ?>/auth/login">
    <link rel="stylesheet" href="https://cdn.hugeicons.com/font/hgi-stroke-rounded.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/toastify-js/src/toastify.min.css">
    <link rel="stylesheet" href="/css/business.css">
    <link rel="stylesheet" href="/css/premium.css">
    <link rel="stylesheet" href="/css/theme-switch.css">
    <style>
        .magic-container {
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 40px 20px;
            background: var(--bg);
        }
        .magic-card {
            max-width: 480px;
            width: 100%;
            background: var(--surface);
            border-radius: var(--radius-2xl);
            padding: 40px;
            box-shadow: var(--shadow-lg);
            border: 1px solid var(--border);
            text-align: center;
            animation: fadeInUp 0.3s ease;
        }
        @keyframes fadeInUp {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
        }
        .magic-icon {
            font-size: 64px;
            margin-bottom: 24px;
        }
        .magic-title {
            font-size: 28px;
            font-weight: 700;
            margin-bottom: 16px;
            color: var(--text);
        }
        .magic-message {
            font-size: 16px;
            color: var(--text-secondary);
            line-height: 1.5;
            margin-bottom: 24px;
        }
        .magic-error {
            background: rgba(255,59,48,0.1);
            color: var(--danger);
            padding: 12px 16px;
            border-radius: var(--radius);
            margin-bottom: 24px;
            text-align: left;
        }
        .magic-success {
            background: rgba(52,199,89,0.1);
            color: var(--success);
            padding: 12px 16px;
            border-radius: var(--radius);
            margin-bottom: 24px;
        }
        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            padding: 12px 24px;
            border-radius: var(--radius-full);
            font-weight: 600;
            text-decoration: none;
            transition: all var(--transition);
        }
        .btn-primary {
            background: linear-gradient(135deg, var(--primary), var(--primary-dark));
            color: white;
        }
        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow-md);
        }
        .btn-secondary {
            background: var(--surface);
            border: 1px solid var(--border);
            color: var(--text);
        }
        .btn-secondary:hover {
            background: var(--bg-secondary);
        }
        .spinner {
            display: inline-block;
            width: 20px;
            height: 20px;
            border: 2px solid rgba(255,255,255,0.3);
            border-radius: 50%;
            border-top-color: #fff;
            animation: spin 0.6s linear infinite;
        }
        @keyframes spin {
            to { transform: rotate(360deg); }
        }
        .redirect-info {
            margin-top: 24px;
            font-size: 13px;
            color: var(--text-secondary);
        }
        @media (max-width: 768px) {
            .magic-card { padding: 24px; }
            .magic-title { font-size: 24px; }
        }
    </style>
</head>
<body>
    <div class="magic-container">
        <div class="magic-card">
            <?php if ($success): ?>
                <div class="magic-icon">✅</div>
                <h1 class="magic-title">Вход выполнен</h1>
                <div class="magic-message">
                    Вы успешно вошли в свой аккаунт. Перенаправление...
                </div>
                <div class="redirect-info">
                    <span class="spinner"></span> Если перенаправление не произошло, 
                    <a href="/profile">нажмите здесь</a>
                </div>
                <script>
                    setTimeout(function() {
                        window.location.href = '/profile';
                    }, 2000);
                </script>
            <?php else: ?>
                <div class="magic-icon">🔐</div>
                <h1 class="magic-title">Вход по ссылке</h1>
                <div class="magic-error">
                    <strong>⚠️ Ошибка</strong><br>
                    <?= htmlspecialchars($error) ?>
                </div>
                <div class="magic-message">
                    Вы можете запросить новую ссылку на странице входа.
                </div>
                <div class="magic-message" style="margin-bottom: 0;">
                    <a href="/auth/login" class="btn btn-primary">Вернуться на страницу входа</a>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/toastify-js"></script>
    <?php if (!$success && $error): ?>
    <script>
        Toastify({
            text: "<?= addslashes($error) ?>",
            duration: 5000,
            gravity: "top",
            position: "right",
            backgroundColor: "linear-gradient(135deg, #FF3B30, #C72A2A)"
        }).showToast();
    </script>
    <?php endif; ?>
</body>
</html>