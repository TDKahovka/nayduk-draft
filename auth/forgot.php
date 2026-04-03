<?php
/* ============================================
   НАЙДУК — Страница запроса сброса пароля
   Версия 1.0 (март 2026)
   - Единая форма для запроса ссылки на сброс
   - Защита: CSRF, honeypot, время заполнения
   - Отправка через AJAX, тосты
   - Премиальный дизайн, адаптивность, тёмная тема
   ============================================ */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../services/Database.php';

// Если уже авторизован, смысла в сбросе нет – отправляем в профиль
if (isset($_SESSION['user_id'])) {
    header('Location: /profile');
    exit;
}

$csrfToken = generateCsrfToken();

$pageTitle = 'Восстановление пароля — Найдук';
$pageDescription = 'Введите email, чтобы получить ссылку для сброса пароля.';
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <meta name="csrf-token" content="<?= $csrfToken ?>">
    <title><?= htmlspecialchars($pageTitle) ?></title>
    <meta name="description" content="<?= htmlspecialchars($pageDescription) ?>">
    <link rel="canonical" href="https://<?= $_SERVER['HTTP_HOST'] ?>/auth/forgot">
    <link rel="preconnect" href="https://cdn.hugeicons.com">
    <link rel="preconnect" href="https://cdn.jsdelivr.net">
    <link rel="stylesheet" href="https://cdn.hugeicons.com/font/hgi-stroke-rounded.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/toastify-js/src/toastify.min.css">
    <link rel="stylesheet" href="/css/business.css">
    <link rel="stylesheet" href="/css/premium.css">
    <link rel="stylesheet" href="/css/theme-switch.css">
    <style>
        /* ===== ПРЕМИАЛЬНЫЙ ДИЗАЙН СТРАНИЦЫ ВОССТАНОВЛЕНИЯ ===== */
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
        .honeypot {
            position: absolute;
            left: -9999px;
            opacity: 0;
            pointer-events: none;
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
        .alert-success {
            background: rgba(52,199,89,0.1);
            border-left: 4px solid var(--success);
            color: var(--success);
        }
        .alert-error {
            background: rgba(255,59,48,0.1);
            border-left: 4px solid var(--danger);
            color: var(--danger);
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
            <h2>Восстановление пароля</h2>
            <p>Введите email, и мы отправим ссылку для сброса</p>
        </div>

        <div class="reset-form">
            <div id="alert-container"></div>

            <form id="reset-form" novalidate>
                <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
                <div class="form-group">
                    <label class="form-label">Email</label>
                    <div class="input-wrapper">
                        <span class="input-icon">✉️</span>
                        <input type="email" name="email" id="reset-email" class="form-input" placeholder="your@email.com" required autocomplete="email" inputmode="email">
                    </div>
                    <div id="email-error" class="field-error" style="display: none; color: var(--danger); font-size: 12px; margin-top: 6px;"></div>
                </div>

                <!-- Honeypot поля -->
                <div class="honeypot">
                    <input type="text" name="website_url" tabindex="-1" autocomplete="off">
                    <input type="text" name="phone_fake" tabindex="-1" autocomplete="off">
                </div>
                <input type="hidden" name="fill_time" id="fill-time" value="0">

                <button type="submit" id="submit-btn" class="btn btn-primary">📧 Отправить ссылку</button>
            </form>

            <div class="reset-footer">
                <p>Вспомнили пароль? <a href="/auth/login">Войти</a></p>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/toastify-js"></script>
    <script>
        // ===== ГЛОБАЛЬНЫЕ ПЕРЕМЕННЫЕ =====
        const csrfToken = document.querySelector('meta[name="csrf-token"]').getAttribute('content');
        let startTime = Date.now();
        document.getElementById('fill-time').value = Math.floor(startTime / 1000);

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

        // ===== ВАЛИДАЦИЯ EMAIL =====
        function validateEmail(email) {
            return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email);
        }

        // ===== ОТПРАВКА ЗАПРОСА =====
        const form = document.getElementById('reset-form');
        const submitBtn = document.getElementById('submit-btn');
        const emailInput = document.getElementById('reset-email');
        const emailError = document.getElementById('email-error');

        function clearEmailError() {
            emailInput.classList.remove('error');
            emailError.style.display = 'none';
        }

        form.addEventListener('submit', async (e) => {
            e.preventDefault();

            // Honeypot проверка
            const website = form.querySelector('input[name="website_url"]').value;
            const phoneFake = form.querySelector('input[name="phone_fake"]').value;
            if (website || phoneFake) {
                showAlert('Запрос отправлен', 'success');
                return;
            }

            const email = emailInput.value.trim();
            if (!email || !validateEmail(email)) {
                emailInput.classList.add('error');
                emailError.textContent = 'Введите корректный email';
                emailError.style.display = 'block';
                return;
            }
            clearEmailError();

            const fillTime = parseInt(document.getElementById('fill-time').value);
            if (fillTime < 2) {
                showAlert('Пожалуйста, заполните форму не торопясь', 'warning');
                return;
            }

            const formData = new URLSearchParams();
            formData.append('csrf_token', csrfToken);
            formData.append('email', email);
            formData.append('fill_time', fillTime);

            const originalText = submitBtn.innerHTML;
            submitBtn.disabled = true;
            submitBtn.innerHTML = '<span class="spinner"></span> Отправка...';

            try {
                const response = await fetch('/api/auth/reset-password.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: formData
                });
                const result = await response.json();
                // Всегда показываем успех (единый ответ)
                showAlert('Если email зарегистрирован, вы получите ссылку для сброса пароля.', 'success');
                emailInput.value = '';
            } catch (err) {
                console.error(err);
                showAlert('Ошибка сети. Попробуйте позже.', 'error');
            } finally {
                submitBtn.innerHTML = originalText;
                submitBtn.disabled = false;
            }
        });

        // Очистка ошибки при вводе
        emailInput.addEventListener('input', clearEmailError);
    </script>
</body>
</html>