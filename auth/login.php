<?php
/* ============================================
   НАЙДУК — Страница входа и регистрации (единое окно)
   Версия 5.0 (март 2026)
   - Плавные переходы, валидация на лету
   - OAuth с иконками и состоянием загрузки
   - Schema.org, ARIA, preconnect
   - Улучшенная обработка ошибок
   ============================================ */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../services/Database.php';

if (isset($_SESSION['user_id'])) {
    header('Location: /profile');
    exit;
}

$csrfToken = generateCsrfToken();
$activeTab = isset($_GET['tab']) && $_GET['tab'] === 'register' ? 'register' : 'login';
$pageTitle = 'Вход и регистрация — Найдук';
$pageDescription = 'Войдите в свой аккаунт или зарегистрируйтесь на Найдук. Быстро, безопасно и бесплатно.';
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <meta name="csrf-token" content="<?= $csrfToken ?>">
    <title><?= htmlspecialchars($pageTitle) ?></title>
    <meta name="description" content="<?= htmlspecialchars($pageDescription) ?>">
    <link rel="canonical" href="https://<?= $_SERVER['HTTP_HOST'] ?>/auth/login">
    <link rel="preconnect" href="https://cdn.hugeicons.com">
    <link rel="preconnect" href="https://cdn.jsdelivr.net">
    <!-- Schema.org -->
    <script type="application/ld+json">
    {
        "@context": "https://schema.org",
        "@type": "WebPage",
        "name": "Вход и регистрация",
        "description": "Страница входа и регистрации на платформе Найдук",
        "url": "https://<?= $_SERVER['HTTP_HOST'] ?>/auth/login"
    }
    </script>
    <link rel="stylesheet" href="https://cdn.hugeicons.com/font/hgi-stroke-rounded.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/toastify-js/src/toastify.min.css">
    <link rel="stylesheet" href="/css/business.css">
    <link rel="stylesheet" href="/css/premium.css">
    <link rel="stylesheet" href="/css/theme-switch.css">
    <style>
        /* ===== УЛУЧШЕННЫЕ СТИЛИ ===== */
        .auth-page {
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            background: var(--bg);
            padding: 20px;
        }
        .auth-modal {
            max-width: 520px;
            width: 100%;
            background: var(--surface);
            border-radius: var(--radius-2xl);
            box-shadow: var(--shadow-lg);
            border: 1px solid var(--border);
            overflow: hidden;
            transition: transform 0.3s ease, box-shadow 0.3s ease;
        }
        .auth-modal:hover {
            transform: translateY(-4px);
            box-shadow: 0 30px 60px -12px rgba(0,0,0,0.25);
        }
        .auth-tabs {
            display: flex;
            border-bottom: 1px solid var(--border);
            background: var(--surface);
        }
        .auth-tab {
            flex: 1;
            padding: 16px 20px;
            text-align: center;
            font-weight: 600;
            font-size: 16px;
            background: none;
            border: none;
            cursor: pointer;
            color: var(--text-secondary);
            transition: all 0.2s ease;
            position: relative;
        }
        .auth-tab.active {
            color: var(--primary);
        }
        .auth-tab.active::after {
            content: '';
            position: absolute;
            bottom: -1px;
            left: 0;
            right: 0;
            height: 2px;
            background: var(--primary);
            animation: slideIn 0.3s ease;
        }
        @keyframes slideIn {
            from { width: 0; left: 50%; }
            to { width: 100%; left: 0; }
        }
        .auth-pane {
            display: none;
            padding: 32px;
            animation: fadeIn 0.3s ease;
        }
        .auth-pane.active {
            display: block;
        }
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(8px); }
            to { opacity: 1; transform: translateY(0); }
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
        .form-input {
            width: 100%;
            padding: 14px 16px 14px 44px;
            border: 1.5px solid var(--border);
            border-radius: var(--radius);
            background: var(--bg);
            color: var(--text);
            font-size: 16px;
            transition: all 0.2s ease;
        }
        .form-input:focus {
            border-color: var(--primary);
            box-shadow: 0 0 0 4px rgba(74,144,226,0.1);
            outline: none;
        }
        .form-input.error {
            border-color: var(--danger);
            background: rgba(255,59,48,0.02);
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
        .input-validation-icon {
            position: absolute;
            right: 16px;
            top: 50%;
            transform: translateY(-50%);
            font-size: 16px;
            pointer-events: none;
        }
        .field-error {
            color: var(--danger);
            font-size: 12px;
            margin-top: 6px;
            display: flex;
            align-items: center;
            gap: 4px;
        }
        .btn {
            width: 100%;
            padding: 14px;
            font-size: 16px;
            font-weight: 600;
            border-radius: var(--radius-full);
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
        .btn-secondary {
            background: var(--surface);
            border: 1.5px solid var(--border);
            color: var(--text);
        }
        .btn-secondary:hover {
            background: var(--bg-secondary);
            border-color: var(--primary);
        }
        .magic-link-form {
            margin-bottom: 20px;
            padding-bottom: 20px;
            border-bottom: 1px solid var(--border-light);
        }
        .divider {
            display: flex;
            align-items: center;
            margin: 24px 0;
            color: var(--text-secondary);
            font-size: 13px;
        }
        .divider::before,
        .divider::after {
            content: '';
            flex: 1;
            border-bottom: 1px solid var(--border-light);
        }
        .divider span {
            margin: 0 12px;
        }
        .oauth-buttons {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(120px, 1fr));
            gap: 12px;
            margin: 20px 0;
        }
        .oauth-btn {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            padding: 12px;
            border-radius: var(--radius-full);
            border: 1px solid var(--border);
            background: var(--bg-secondary);
            color: var(--text);
            font-size: 14px;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.2s ease;
        }
        .oauth-btn:hover {
            background: var(--primary-light);
            border-color: var(--primary);
            color: white;
            transform: translateY(-2px);
        }
        .oauth-icon {
            width: 20px;
            height: 20px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
        }
        .oauth-btn:disabled {
            opacity: 0.6;
            cursor: wait;
        }
        .forgot-link {
            display: inline-block;
            margin-top: 12px;
            font-size: 13px;
            color: var(--primary);
            text-decoration: none;
        }
        .checkbox-group {
            display: flex;
            align-items: center;
            gap: 8px;
            margin: 16px 0;
        }
        .checkbox-group label {
            font-size: 14px;
            color: var(--text-secondary);
        }
        .checkbox-group a {
            color: var(--primary);
            text-decoration: none;
        }
        .auth-footer {
            text-align: center;
            margin-top: 24px;
            font-size: 14px;
            color: var(--text-secondary);
        }
        .auth-footer a {
            color: var(--primary);
            text-decoration: none;
        }
        .honeypot {
            position: absolute;
            left: -9999px;
            opacity: 0;
            pointer-events: none;
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
        .alert {
            padding: 12px 16px;
            border-radius: var(--radius);
            margin-bottom: 20px;
            font-size: 14px;
            display: flex;
            align-items: center;
            gap: 8px;
            animation: slideInAlert 0.3s ease;
        }
        @keyframes slideInAlert {
            from { opacity: 0; transform: translateX(-20px); }
            to { opacity: 1; transform: translateX(0); }
        }
        .alert-error {
            background: rgba(255,59,48,0.1);
            color: var(--danger);
            border-left: 4px solid var(--danger);
        }
        .alert-success {
            background: rgba(52,199,89,0.1);
            color: var(--success);
            border-left: 4px solid var(--success);
        }
        .alert-info {
            background: rgba(74,144,226,0.1);
            color: var(--primary);
            border-left: 4px solid var(--primary);
        }
        .alert-close {
            margin-left: auto;
            cursor: pointer;
            font-weight: bold;
            background: none;
            border: none;
            font-size: 18px;
            opacity: 0.6;
        }
        .alert-close:hover {
            opacity: 1;
        }
        @media (max-width: 768px) {
            .auth-pane { padding: 24px; }
            .oauth-buttons { grid-template-columns: repeat(2, 1fr); }
        }
        @media (max-width: 480px) {
            .auth-modal { border-radius: var(--radius-xl); }
            .auth-pane { padding: 20px; }
            .oauth-buttons { grid-template-columns: 1fr; }
            .btn { padding: 12px; }
        }
    </style>
</head>
<body class="auth-page">
    <div class="auth-modal">
        <div class="auth-tabs" role="tablist">
            <button class="auth-tab <?= $activeTab === 'login' ? 'active' : '' ?>" data-tab="login" role="tab" aria-selected="<?= $activeTab === 'login' ? 'true' : 'false' ?>" aria-controls="login-pane">Вход</button>
            <button class="auth-tab <?= $activeTab === 'register' ? 'active' : '' ?>" data-tab="register" role="tab" aria-selected="<?= $activeTab === 'register' ? 'true' : 'false' ?>" aria-controls="register-pane">Регистрация</button>
        </div>

        <!-- Панель входа -->
        <div id="login-pane" class="auth-pane <?= $activeTab === 'login' ? 'active' : '' ?>" role="tabpanel" aria-labelledby="tab-login">
            <div class="magic-link-form">
                <div class="form-group">
                    <label class="form-label">Email</label>
                    <div class="input-wrapper">
                        <span class="input-icon">✉️</span>
                        <input type="email" id="magic-email" class="form-input" placeholder="your@email.com" autocomplete="email">
                    </div>
                </div>
                <button id="send-magic-link" class="btn btn-primary">📧 Отправить ссылку для входа</button>
                <div class="form-hint">Мы отправим одноразовую ссылку на почту. Пароль не нужен.</div>
            </div>

            <div class="divider"><span>или войдите с паролем</span></div>

            <form id="login-form" novalidate>
                <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
                <div class="form-group">
                    <label class="form-label">Email</label>
                    <div class="input-wrapper">
                        <span class="input-icon">✉️</span>
                        <input type="email" name="email" class="form-input" required autocomplete="email">
                        <span class="input-validation-icon" data-field="login-email"></span>
                    </div>
                    <div class="field-error" data-error="login-email" style="display: none;"></div>
                </div>
                <div class="form-group">
                    <label class="form-label">Пароль</label>
                    <div class="input-wrapper">
                        <span class="input-icon">🔒</span>
                        <input type="password" name="password" class="form-input" required autocomplete="current-password">
                    </div>
                    <div class="field-error" data-error="login-password" style="display: none;"></div>
                </div>
                <div class="checkbox-group">
                    <input type="checkbox" id="remember" name="remember" value="1">
                    <label for="remember">Запомнить меня</label>
                </div>
                <button type="submit" class="btn btn-primary">🔓 Войти с паролем</button>
                <a href="/auth/forgot" class="forgot-link">Забыли пароль?</a>
            </form>

            <div class="divider"><span>или через соцсети</span></div>

            <div class="oauth-buttons" id="oauth-buttons-login">
                <button class="oauth-btn" data-provider="vk"><span class="oauth-icon">🎨</span> VK</button>
                <button class="oauth-btn" data-provider="yandex"><span class="oauth-icon">Я</span> Яндекс</button>
                <button class="oauth-btn" data-provider="google"><span class="oauth-icon">G</span> Google</button>
                <button class="oauth-btn" data-provider="mailru"><span class="oauth-icon">📧</span> Mail.ru</button>
                <button class="oauth-btn" data-provider="telegram"><span class="oauth-icon">📱</span> Telegram</button>
                <button class="oauth-btn" data-provider="rambler"><span class="oauth-icon">Р</span> Рамблер</button>
                <button class="oauth-btn" data-provider="max"><span class="oauth-icon">🔷</span> MAX</button>
            </div>
        </div>

        <!-- Панель регистрации -->
        <div id="register-pane" class="auth-pane <?= $activeTab === 'register' ? 'active' : '' ?>" role="tabpanel" aria-labelledby="tab-register">
            <form id="register-form" novalidate>
                <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
                <div class="form-group">
                    <label class="form-label">Email *</label>
                    <div class="input-wrapper">
                        <span class="input-icon">✉️</span>
                        <input type="email" name="email" class="form-input" required autocomplete="email">
                        <span class="input-validation-icon" data-field="register-email"></span>
                    </div>
                    <div class="field-error" data-error="register-email" style="display: none;"></div>
                </div>
                <div class="form-group">
                    <label class="form-label">Имя *</label>
                    <div class="input-wrapper">
                        <span class="input-icon">👤</span>
                        <input type="text" name="name" class="form-input" required minlength="2" maxlength="100" autocomplete="name">
                    </div>
                    <div class="field-error" data-error="register-name" style="display: none;"></div>
                </div>
                <div class="form-group">
                    <label class="form-label">Телефон <span class="optional">(необязательно)</span></label>
                    <div class="input-wrapper">
                        <span class="input-icon">📱</span>
                        <input type="tel" name="phone" class="form-input" autocomplete="tel" placeholder="+7 (999) 000-00-00">
                        <span class="input-validation-icon" data-field="register-phone"></span>
                    </div>
                    <div class="field-error" data-error="register-phone" style="display: none;"></div>
                    <div class="form-hint">Для повышения доверия и защиты аккаунта</div>
                </div>
                <div class="checkbox-group">
                    <input type="checkbox" id="terms" name="terms" value="1" required>
                    <label for="terms">Я согласен с <a href="/terms" target="_blank">условиями использования</a> и <a href="/privacy" target="_blank">политикой конфиденциальности</a></label>
                </div>
                <div class="honeypot">
                    <input type="text" name="website_url" tabindex="-1" autocomplete="off">
                    <input type="text" name="phone_fake" tabindex="-1" autocomplete="off">
                </div>
                <button type="submit" class="btn btn-primary">📝 Зарегистрироваться</button>
                <div class="form-hint">Пароль не нужен – вы получите ссылку для входа на почту.</div>
            </form>

            <div class="divider"><span>или через соцсети</span></div>

            <div class="oauth-buttons" id="oauth-buttons-register">
                <button class="oauth-btn" data-provider="vk"><span class="oauth-icon">🎨</span> VK</button>
                <button class="oauth-btn" data-provider="yandex"><span class="oauth-icon">Я</span> Яндекс</button>
                <button class="oauth-btn" data-provider="google"><span class="oauth-icon">G</span> Google</button>
                <button class="oauth-btn" data-provider="mailru"><span class="oauth-icon">📧</span> Mail.ru</button>
                <button class="oauth-btn" data-provider="telegram"><span class="oauth-icon">📱</span> Telegram</button>
                <button class="oauth-btn" data-provider="rambler"><span class="oauth-icon">Р</span> Рамблер</button>
                <button class="oauth-btn" data-provider="max"><span class="oauth-icon">🔷</span> MAX</button>
            </div>

            <div class="auth-footer">
                Уже есть аккаунт? <a href="#" id="switch-to-login">Войти</a>
            </div>
        </div>
    </div>

    <div id="toast-container"></div>

    <script src="https://cdn.jsdelivr.net/npm/toastify-js"></script>
    <script>
        // ===== ГЛОБАЛЬНЫЕ ПЕРЕМЕННЫЕ =====
        const csrfToken = document.querySelector('meta[name="csrf-token"]').getAttribute('content');
        let activeTab = '<?= $activeTab ?>';

        // ===== УНИВЕРСАЛЬНЫЕ ФУНКЦИИ =====
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

        function showFieldError(fieldName, message) {
            const errorDiv = document.querySelector(`[data-error="${fieldName}"]`);
            if (errorDiv) {
                errorDiv.textContent = message;
                errorDiv.style.display = 'flex';
            }
            const input = document.querySelector(`[name="${fieldName}"]`);
            if (input) input.classList.add('error');
        }

        function clearFieldError(fieldName) {
            const errorDiv = document.querySelector(`[data-error="${fieldName}"]`);
            if (errorDiv) errorDiv.style.display = 'none';
            const input = document.querySelector(`[name="${fieldName}"]`);
            if (input) input.classList.remove('error');
        }

        function validateEmail(email) {
            return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email);
        }

        function validatePhone(phone) {
            if (!phone) return true;
            return /^[\+\d\s\-\(\)]{5,20}$/.test(phone);
        }

        function updateValidationIcon(input, isValid) {
            const wrapper = input.closest('.input-wrapper');
            const icon = wrapper?.querySelector('.input-validation-icon');
            if (!icon) return;
            if (isValid) {
                icon.innerHTML = '✅';
                icon.style.color = 'var(--success)';
            } else {
                icon.innerHTML = '';
            }
        }

        // ===== РЕАЛЬНАЯ ВАЛИДАЦИЯ =====
        document.querySelectorAll('#register-form input[name="email"]').forEach(input => {
            input.addEventListener('input', () => {
                const email = input.value.trim();
                const isValid = !email || validateEmail(email);
                updateValidationIcon(input, isValid);
                if (isValid) clearFieldError('register-email');
                else showFieldError('register-email', 'Некорректный email');
            });
        });
        document.querySelectorAll('#register-form input[name="name"]').forEach(input => {
            input.addEventListener('input', () => {
                const name = input.value.trim();
                const isValid = name.length >= 2 && name.length <= 100;
                updateValidationIcon(input, isValid);
                if (isValid) clearFieldError('register-name');
                else showFieldError('register-name', 'Имя должно быть от 2 до 100 символов');
            });
        });
        document.querySelectorAll('#register-form input[name="phone"]').forEach(input => {
            input.addEventListener('input', () => {
                const phone = input.value.trim();
                const isValid = !phone || validatePhone(phone);
                updateValidationIcon(input, isValid);
                if (isValid) clearFieldError('register-phone');
                else showFieldError('register-phone', 'Некорректный формат телефона');
            });
        });
        document.querySelectorAll('#login-form input[name="email"]').forEach(input => {
            input.addEventListener('input', () => {
                const email = input.value.trim();
                const isValid = !email || validateEmail(email);
                updateValidationIcon(input, isValid);
                if (isValid) clearFieldError('login-email');
                else showFieldError('login-email', 'Некорректный email');
            });
        });

        // ===== MAGIC LINK =====
        const magicEmailInput = document.getElementById('magic-email');
        const sendMagicBtn = document.getElementById('send-magic-link');
        sendMagicBtn?.addEventListener('click', async () => {
            const email = magicEmailInput.value.trim();
            if (!email || !validateEmail(email)) {
                showToast('Введите корректный email', 'error');
                magicEmailInput.classList.add('error');
                return;
            }
            magicEmailInput.classList.remove('error');
            sendMagicBtn.disabled = true;
            const originalText = sendMagicBtn.innerHTML;
            sendMagicBtn.innerHTML = '<span class="spinner"></span> Отправка...';
            try {
                const res = await fetch('/api/auth/magic-login.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: new URLSearchParams({ csrf_token: csrfToken, email: email })
                });
                const data = await res.json();
                if (data.success) {
                    showToast('Ссылка для входа отправлена на указанный email', 'success');
                    magicEmailInput.value = '';
                } else {
                    showToast(data.error || 'Ошибка отправки', 'error');
                }
            } catch (err) {
                showToast('Ошибка сети. Попробуйте позже.', 'error');
            } finally {
                sendMagicBtn.innerHTML = originalText;
                sendMagicBtn.disabled = false;
            }
        });

        // ===== ПАРОЛЬНЫЙ ВХОД =====
        const loginForm = document.getElementById('login-form');
        loginForm?.addEventListener('submit', async (e) => {
            e.preventDefault();
            const email = loginForm.querySelector('input[name="email"]').value.trim();
            if (!validateEmail(email)) {
                showFieldError('login-email', 'Введите корректный email');
                return;
            }
            clearFieldError('login-email');
            const submitBtn = loginForm.querySelector('button[type="submit"]');
            const originalText = submitBtn.innerHTML;
            submitBtn.disabled = true;
            submitBtn.innerHTML = '<span class="spinner"></span> Вход...';
            const formData = new FormData(loginForm);
            formData.append('action', 'login');
            formData.append('csrf_token', csrfToken);
            try {
                const res = await fetch('/api/auth/login.php', { method: 'POST', body: formData });
                const data = await res.json();
                if (data.success) {
                    window.location.href = data.redirect || '/profile';
                } else {
                    showToast(data.error || 'Ошибка входа', 'error');
                    showFieldError('login-password', data.error || 'Неверный email или пароль');
                }
            } catch (err) {
                showToast('Ошибка сети. Попробуйте позже.', 'error');
            } finally {
                submitBtn.innerHTML = originalText;
                submitBtn.disabled = false;
            }
        });

        // ===== РЕГИСТРАЦИЯ =====
        const registerForm = document.getElementById('register-form');
        registerForm?.addEventListener('submit', async (e) => {
            e.preventDefault();
            const website = registerForm.querySelector('input[name="website_url"]').value;
            const phoneFake = registerForm.querySelector('input[name="phone_fake"]').value;
            if (website || phoneFake) {
                // Bot trap
                showToast('Регистрация выполнена', 'success');
                return;
            }
            const email = registerForm.querySelector('input[name="email"]').value.trim();
            const name = registerForm.querySelector('input[name="name"]').value.trim();
            const phone = registerForm.querySelector('input[name="phone"]').value.trim();
            const terms = registerForm.querySelector('input[name="terms"]').checked;
            if (!email || !validateEmail(email)) {
                showFieldError('register-email', 'Введите корректный email');
                return;
            }
            if (!name || name.length < 2) {
                showFieldError('register-name', 'Имя должно быть не менее 2 символов');
                return;
            }
            if (!terms) {
                showToast('Необходимо согласиться с условиями', 'error');
                return;
            }
            if (phone && !validatePhone(phone)) {
                showFieldError('register-phone', 'Некорректный формат телефона');
                return;
            }
            const submitBtn = registerForm.querySelector('button[type="submit"]');
            const originalText = submitBtn.innerHTML;
            submitBtn.disabled = true;
            submitBtn.innerHTML = '<span class="spinner"></span> Регистрация...';
            const formData = new URLSearchParams();
            formData.append('csrf_token', csrfToken);
            formData.append('email', email);
            formData.append('name', name);
            formData.append('phone', phone);
            formData.append('terms', terms ? '1' : '0');
            try {
                const res = await fetch('/api/auth/register.php', { method: 'POST', body: formData });
                const data = await res.json();
                if (data.success) {
                    showToast('Регистрация успешна! Проверьте почту для входа.', 'success');
                    registerForm.reset();
                    document.querySelector('.auth-tab[data-tab="login"]').click();
                } else {
                    showToast(data.error || 'Ошибка регистрации', 'error');
                }
            } catch (err) {
                showToast('Ошибка сети. Попробуйте позже.', 'error');
            } finally {
                submitBtn.innerHTML = originalText;
                submitBtn.disabled = false;
            }
        });

        // ===== OAuth =====
        function handleOAuth(btn) {
            const provider = btn.dataset.provider;
            if (!provider) return;
            btn.disabled = true;
            const originalText = btn.innerHTML;
            btn.innerHTML = '<span class="spinner"></span> Перенаправление...';
            const redirectUri = encodeURIComponent(window.location.origin + '/api/auth/oauth-callback.php');
            window.location.href = `/api/auth/oauth-redirect.php?provider=${provider}&state=${csrfToken}&redirect_uri=${redirectUri}`;
            // fallback: если через секунду не ушло, восстанавливаем
            setTimeout(() => {
                if (btn.disabled) {
                    btn.disabled = false;
                    btn.innerHTML = originalText;
                }
            }, 3000);
        }
        document.querySelectorAll('.oauth-btn').forEach(btn => {
            btn.addEventListener('click', () => handleOAuth(btn));
        });

        // ===== ПЕРЕКЛЮЧЕНИЕ ВКЛАДОК =====
        const tabs = document.querySelectorAll('.auth-tab');
        const loginPane = document.getElementById('login-pane');
        const registerPane = document.getElementById('register-pane');
        tabs.forEach(tab => {
            tab.addEventListener('click', () => {
                const tabName = tab.dataset.tab;
                if (tabName === 'login') {
                    loginPane.classList.add('active');
                    registerPane.classList.remove('active');
                    tab.classList.add('active');
                    tabs[1].classList.remove('active');
                    activeTab = 'login';
                    history.replaceState(null, '', '/auth/login');
                } else {
                    registerPane.classList.add('active');
                    loginPane.classList.remove('active');
                    tab.classList.add('active');
                    tabs[0].classList.remove('active');
                    activeTab = 'register';
                    history.replaceState(null, '', '/auth/register');
                }
            });
        });
        document.getElementById('switch-to-login')?.addEventListener('click', (e) => {
            e.preventDefault();
            document.querySelector('.auth-tab[data-tab="login"]').click();
        });
    </script>
</body>
</html>