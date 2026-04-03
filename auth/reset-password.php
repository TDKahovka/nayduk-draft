<?php
/* ============================================
   НАЙДУК — Страница сброса пароля (ввод нового пароля)
   Версия 1.0 (март 2026)
   - Проверка токена перед отображением формы
   - Защита от истёкших/использованных токенов
   - Форма с новым паролем, индикатор сложности
   - Отправка через AJAX, тосты
   - Премиальный дизайн, адаптивность
   ============================================ */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../services/Database.php';

// Если уже авторизован, редирект в профиль
if (isset($_SESSION['user_id'])) {
    header('Location: /profile');
    exit;
}

// Получаем токен и email из GET
$token = $_GET['token'] ?? '';
$email = isset($_GET['email']) ? urldecode($_GET['email']) : '';

if (empty($token) || empty($email)) {
    $error = 'Неверная ссылка для сброса пароля. Пожалуйста, запросите новую ссылку.';
    $validToken = false;
} else {
    $db = Database::getInstance();
    $pdo = $db->getPdo();

    // Проверяем токен в БД
    $tokenHash = hash('sha256', $token);
    $stmt = $pdo->prepare("
        SELECT * FROM password_resets
        WHERE email = ? AND token_hash = ? AND expires_at > NOW()
    ");
    $stmt->execute([$email, $tokenHash]);
    $reset = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($reset && empty($reset['used_at'])) {
        $validToken = true;
        $resetId = $reset['id'];
    } else {
        $error = 'Ссылка для сброса пароля недействительна или истекла. Пожалуйста, запросите новую ссылку.';
        $validToken = false;
    }
}

$csrfToken = generateCsrfToken();

$pageTitle = 'Сброс пароля — Найдук';
$pageDescription = 'Введите новый пароль для вашего аккаунта.';
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <meta name="csrf-token" content="<?= $csrfToken ?>">
    <title><?= htmlspecialchars($pageTitle) ?></title>
    <meta name="description" content="<?= htmlspecialchars($pageDescription) ?>">
    <link rel="canonical" href="https://<?= $_SERVER['HTTP_HOST'] ?>/auth/reset-password">
    <link rel="preconnect" href="https://cdn.hugeicons.com">
    <link rel="preconnect" href="https://cdn.jsdelivr.net">
    <link rel="stylesheet" href="https://cdn.hugeicons.com/font/hgi-stroke-rounded.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/toastify-js/src/toastify.min.css">
    <link rel="stylesheet" href="/css/business.css">
    <link rel="stylesheet" href="/css/premium.css">
    <link rel="stylesheet" href="/css/theme-switch.css">
    <style>
        /* ===== ПРЕМИАЛЬНЫЙ ДИЗАЙН ===== */
        .reset-page {
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            background: var(--bg);
            padding: 40px 20px;
            position: relative;
            overflow-x: hidden;
        }
        .reset-page::before {
            content: '';
            position: absolute;
            top: -30%;
            right: -20%;
            width: 70%;
            height: 70%;
            background: radial-gradient(circle, rgba(74,144,226,0.05) 0%, transparent 70%);
            border-radius: 50%;
            pointer-events: none;
        }
        .reset-card {
            max-width: 480px;
            width: 100%;
            background: var(--surface);
            border-radius: 32px;
            box-shadow: var(--shadow-lg);
            border: 1px solid var(--border);
            overflow: hidden;
            animation: fadeInUp 0.5s cubic-bezier(0.2, 0.9, 0.4, 1.1);
            transition: transform 0.3s ease, box-shadow 0.3s ease;
        }
        .reset-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 30px 60px -12px rgba(0,0,0,0.3);
        }
        @keyframes fadeInUp {
            from { opacity: 0; transform: translateY(30px); }
            to { opacity: 1; transform: translateY(0); }
        }
        .reset-header {
            padding: 32px 32px 0;
            text-align: center;
        }
        .logo h1 {
            font-size: 32px;
            font-weight: 800;
            background: linear-gradient(135deg, var(--primary), var(--accent));
            -webkit-background-clip: text;
            background-clip: text;
            color: transparent;
            margin-bottom: 24px;
        }
        .reset-header h2 {
            font-size: 28px;
            font-weight: 700;
            margin-bottom: 8px;
            color: var(--text);
        }
        .reset-header p {
            font-size: 15px;
            color: var(--text-secondary);
            margin-bottom: 16px;
        }
        .reset-form {
            padding: 32px;
        }
        .form-group {
            margin-bottom: 24px;
            position: relative;
        }
        .form-label {
            display: block;
            font-size: 14px;
            font-weight: 600;
            margin-bottom: 8px;
            color: var(--text);
        }
        .input-wrapper {
            position: relative;
        }
        .input-icon {
            position: absolute;
            left: 16px;
            top: 50%;
            transform: translateY(-50%);
            font-size: 18px;
            color: var(--text-secondary);
            pointer-events: none;
        }
        .form-input {
            width: 100%;
            padding: 14px 16px 14px 48px;
            border: 1.5px solid var(--border);
            border-radius: 16px;
            background: var(--bg);
            color: var(--text);
            font-size: 16px;
            transition: all 0.2s ease;
        }
        .form-input:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 4px rgba(74,144,226,0.1);
        }
        .form-input.error {
            border-color: var(--danger);
        }
        .password-strength {
            margin-top: 8px;
        }
        .strength-bar {
            height: 4px;
            background: var(--border);
            border-radius: 2px;
            overflow: hidden;
        }
        .strength-fill {
            height: 100%;
            width: 0;
            transition: width 0.3s ease, background 0.3s ease;
        }
        .strength-fill.weak { background: var(--danger); width: 33%; }
        .strength-fill.medium { background: var(--warning); width: 66%; }
        .strength-fill.strong { background: var(--success); width: 100%; }
        .strength-text {
            font-size: 11px;
            margin-top: 4px;
            color: var(--text-secondary);
        }
        .btn {
            width: 100%;
            padding: 16px;
            font-size: 16px;
            font-weight: 600;
            border-radius: 50px;
            border: none;
            cursor: pointer;
            transition: all 0.2s ease;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
        }
        .btn-primary {
            background: linear-gradient(135deg, var(--primary), var(--primary-dark));
            color: white;
            box-shadow: 0 4px 14px rgba(74,144,226,0.35);
        }
        .btn-primary:hover:not(:disabled) {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(74,144,226,0.45);
        }
        .btn-primary:disabled {
            opacity: 0.7;
            cursor: not-allowed;
        }
        .spinner {
            display: inline-block;
            width: 18px;
            height: 18px;
            border: 2px solid rgba(255,255,255,0.3);
            border-radius: 50%;
            border-top-color: #fff;
            animation: spin 0.6s linear infinite;
        }
        @keyframes spin {
            to { transform: rotate(360deg); }
        }
        .reset-footer {
            padding: 20px 32px 32px;
            text-align: center;
            border-top: 1px solid var(--border-light);
        }
        .reset-footer p {
            font-size: 14px;
            color: var(--text-secondary);
        }
        .reset-footer a {
            color: var(--primary);
            text-decoration: none;
            font-weight: 600;
        }
        .reset-footer a:hover {
            text-decoration: underline;
        }
        .alert {
            padding: 14px 18px;
            border-radius: 16px;
            margin-bottom: 24px;
            display: flex;
            align-items: center;
            gap: 12px;
            animation: slideIn 0.3s ease;
        }
        @keyframes slideIn {
            from { opacity: 0; transform: translateX(-20px); }
            to { opacity: 1; transform: translateX(0); }
        }
        .alert-error {
            background: rgba(255,59,48,0.1);
            border-left: 4px solid var(--danger);
            color: var(--danger);
        }
        .alert-success {
            background: rgba(52,199,89,0.1);
            border-left: 4px solid var(--success);
            color: var(--success);
        }
        .alert-info {
            background: rgba(74,144,226,0.1);
            border-left: 4px solid var(--primary);
            color: var(--primary);
        }
        .alert-close {
            margin-left: auto;
            cursor: pointer;
            background: none;
            border: none;
            font-size: 18px;
            opacity: 0.6;
        }
        .field-error {
            color: var(--danger);
            font-size: 12px;
            margin-top: 6px;
            display: flex;
            align-items: center;
            gap: 4px;
        }
        @media (max-width: 768px) {
            .reset-header { padding: 24px 24px 0; }
            .reset-form { padding: 24px; }
            .reset-footer { padding: 20px 24px 24px; }
        }
        @media (max-width: 480px) {
            .reset-page { padding: 20px 16px; }
            .reset-header h2 { font-size: 24px; }
        }
    </style>
</head>
<body class="reset-page">
    <div class="reset-card">
        <div class="reset-header">
            <div class="logo"><h1>Найдук</h1></div>
            <h2>Создание нового пароля</h2>
            <p>Введите новый пароль для вашего аккаунта</p>
        </div>

        <div class="reset-form">
            <div id="alert-container"></div>

            <?php if ($validToken): ?>
                <form id="reset-form" novalidate>
                    <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
                    <input type="hidden" name="email" value="<?= htmlspecialchars($email) ?>">
                    <input type="hidden" name="token" value="<?= htmlspecialchars($token) ?>">
                    
                    <div class="form-group">
                        <label class="form-label">Новый пароль</label>
                        <div class="input-wrapper">
                            <span class="input-icon">🔒</span>
                            <input type="password" name="password" id="new-password" class="form-input" placeholder="••••••••" required autocomplete="new-password" minlength="8">
                        </div>
                        <div id="password-strength" class="password-strength">
                            <div class="strength-bar"><div class="strength-fill" id="strength-fill"></div></div>
                            <div id="strength-text" class="strength-text"></div>
                        </div>
                        <div id="password-error" class="field-error" style="display: none;"></div>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Подтвердите пароль</label>
                        <div class="input-wrapper">
                            <span class="input-icon">✓</span>
                            <input type="password" name="password_confirmation" id="confirm-password" class="form-input" placeholder="••••••••" required autocomplete="off">
                        </div>
                        <div id="confirm-error" class="field-error" style="display: none;"></div>
                    </div>

                    <button type="submit" id="submit-btn" class="btn btn-primary">Сбросить пароль</button>
                </form>
            <?php else: ?>
                <div class="alert alert-error">
                    <span>⚠️</span>
                    <span><?= htmlspecialchars($error) ?></span>
                </div>
                <div class="reset-footer" style="border-top: none; padding-top: 0;">
                    <p><a href="/auth/forgot">Запросить новую ссылку</a></p>
                </div>
            <?php endif; ?>
        </div>

        <div class="reset-footer">
            <p><a href="/auth/login">Вернуться на страницу входа</a></p>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/toastify-js"></script>
    <script>
        <?php if ($validToken): ?>
        const csrfToken = '<?= $csrfToken ?>';
        const email = '<?= addslashes($email) ?>';
        const token = '<?= addslashes($token) ?>';

        // ===== ПОКАЗ УВЕДОМЛЕНИЙ =====
        function showAlert(message, type = 'error') {
            const container = document.getElementById('alert-container');
            const id = 'alert-' + Date.now();
            const html = `
                <div id="${id}" class="alert alert-${type}">
                    <span>${type === 'success' ? '✅' : (type === 'error' ? '⚠️' : 'ℹ️')}</span>
                    <span>${escapeHtml(message)}</span>
                    <button class="alert-close" onclick="document.getElementById('${id}').remove()">✕</button>
                </div>
            `;
            container.insertAdjacentHTML('beforeend', html);
            setTimeout(() => {
                const el = document.getElementById(id);
                if (el) el.remove();
            }, 8000);
        }

        function showToast(message, type = 'success') {
            const colors = {
                success: 'linear-gradient(135deg, #34C759, #2C9B4E)',
                error: 'linear-gradient(135deg, #FF3B30, #C72A2A)',
                warning: 'linear-gradient(135deg, #FF9500, #E68600)',
                info: 'linear-gradient(135deg, #5A67D8, #4C51BF)'
            };
            Toastify({
                text: message,
                duration: 4000,
                gravity: 'top',
                position: 'right',
                backgroundColor: colors[type] || colors.info
            }).showToast();
        }

        function escapeHtml(str) {
            if (!str) return '';
            return str.replace(/[&<>]/g, m => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;' }[m] || m));
        }

        // ===== ИНДИКАТОР СЛОЖНОСТИ ПАРОЛЯ =====
        function checkPasswordStrength(password) {
            let strength = 0;
            if (password.length >= 10) strength++;
            if (password.match(/[a-z]/)) strength++;
            if (password.match(/[A-Z]/)) strength++;
            if (password.match(/[0-9]/)) strength++;
            if (password.match(/[$@#&!]/)) strength++;
            return strength;
        }

        const passwordInput = document.getElementById('new-password');
        const strengthFill = document.getElementById('strength-fill');
        const strengthText = document.getElementById('strength-text');

        passwordInput.addEventListener('input', () => {
            const pass = passwordInput.value;
            const strength = checkPasswordStrength(pass);
            let level = 'weak';
            let text = 'Слабый';
            if (strength >= 4) {
                level = 'strong';
                text = 'Сильный';
            } else if (strength >= 3) {
                level = 'medium';
                text = 'Средний';
            }
            strengthFill.className = 'strength-fill ' + level;
            strengthText.textContent = text;
        });

        // ===== ВАЛИДАЦИЯ И ОТПРАВКА =====
        const form = document.getElementById('reset-form');
        const submitBtn = document.getElementById('submit-btn');
        const passwordField = document.getElementById('new-password');
        const confirmField = document.getElementById('confirm-password');
        const passwordError = document.getElementById('password-error');
        const confirmError = document.getElementById('confirm-error');

        function clearPasswordError() {
            passwordField.classList.remove('error');
            passwordError.style.display = 'none';
        }

        function clearConfirmError() {
            confirmField.classList.remove('error');
            confirmError.style.display = 'none';
        }

        form.addEventListener('submit', async (e) => {
            e.preventDefault();

            const password = passwordField.value;
            const confirm = confirmField.value;

            // Валидация
            let valid = true;
            clearPasswordError();
            clearConfirmError();

            if (password.length < 8) {
                passwordField.classList.add('error');
                passwordError.textContent = 'Пароль должен содержать не менее 8 символов';
                passwordError.style.display = 'flex';
                valid = false;
            } else if (password.length < 10) {
                // Предупреждение, но не ошибка
                showToast('Рекомендуем использовать пароль длиннее 10 символов', 'warning');
            }
            if (password !== confirm) {
                confirmField.classList.add('error');
                confirmError.textContent = 'Пароли не совпадают';
                confirmError.style.display = 'flex';
                valid = false;
            }

            if (!valid) return;

            const formData = new URLSearchParams();
            formData.append('csrf_token', csrfToken);
            formData.append('email', email);
            formData.append('token', token);
            formData.append('password', password);
            formData.append('password_confirmation', confirm);

            const originalText = submitBtn.innerHTML;
            submitBtn.disabled = true;
            submitBtn.innerHTML = '<span class="spinner"></span> Сброс...';

            try {
                const response = await fetch('/api/auth/reset-password-confirm.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: formData
                });
                const result = await response.json();
                if (result.success) {
                    showAlert('Пароль успешно изменён! Перенаправляем на страницу входа...', 'success');
                    setTimeout(() => {
                        window.location.href = '/auth/login';
                    }, 3000);
                } else {
                    showAlert(result.error || 'Ошибка при смене пароля', 'error');
                }
            } catch (err) {
                console.error(err);
                showAlert('Ошибка сети. Попробуйте позже.', 'error');
            } finally {
                submitBtn.innerHTML = originalText;
                submitBtn.disabled = false;
            }
        });

        // Очистка ошибок при вводе
        passwordField.addEventListener('input', clearPasswordError);
        confirmField.addEventListener('input', clearConfirmError);
        <?php endif; ?>
    </script>
</body>
</html>