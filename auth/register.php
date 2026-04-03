<?php
/* ============================================
   НАЙДУК — Страница безпарольной регистрации (Magic Link)
   Версия 4.0 (март 2026)
   - Добавлена поддержка реферальной системы (сохранение ref из URL в сессию)
   - Реальная валидация на лету с иконками
   - OAuth-кнопки с иконками и индикатором загрузки
   - Улучшенная доступность и производительность
   - Премиальный дизайн, анимации, адаптивность
   ============================================ */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// ===== РЕФЕРАЛЬНАЯ СИСТЕМА: СОХРАНЯЕМ ref ИЗ GET-ПАРАМЕТРА В СЕССИЮ =====
if (isset($_GET['ref']) && is_numeric($_GET['ref'])) {
    $_SESSION['ref'] = (int)$_GET['ref'];
} elseif (isset($_POST['ref']) && is_numeric($_POST['ref'])) {
    // На случай, если ref пришёл в POST (например, из скрытой формы)
    $_SESSION['ref'] = (int)$_POST['ref'];
}

require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../services/Database.php';

if (isset($_SESSION['user_id'])) {
    header('Location: /profile');
    exit;
}

$csrfToken = generateCsrfToken();
if (isset($_GET['redirect'])) {
    $_SESSION['auth_redirect'] = $_GET['redirect'];
}

$pageTitle = 'Регистрация — Найдук';
$pageDescription = 'Создайте аккаунт на Найдук за 30 секунд. Без пароля — просто введите email и получите ссылку для входа.';
$pageKeywords = 'регистрация, создать аккаунт, войти, Найдук, доска объявлений';

$schema = [
    '@context' => 'https://schema.org',
    '@type' => 'WebPage',
    'name' => 'Регистрация на Найдук',
    'description' => 'Создайте аккаунт на Найдук — бесплатной доске объявлений и партнёрской платформе',
    'url' => 'https://' . $_SERVER['HTTP_HOST'] . '/auth/register',
];
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <meta name="csrf-token" content="<?= $csrfToken ?>">
    <meta name="description" content="<?= htmlspecialchars($pageDescription) ?>">
    <meta name="keywords" content="<?= htmlspecialchars($pageKeywords) ?>">
    <meta name="robots" content="index, follow">
    <link rel="canonical" href="https://<?= $_SERVER['HTTP_HOST'] ?>/auth/register">
    <link rel="preconnect" href="https://cdn.hugeicons.com">
    <link rel="preconnect" href="https://cdn.jsdelivr.net">
    <title><?= htmlspecialchars($pageTitle) ?></title>
    <script type="application/ld+json"><?= json_encode($schema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?></script>
    <link rel="stylesheet" href="https://cdn.hugeicons.com/font/hgi-stroke-rounded.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/toastify-js/src/toastify.min.css">
    <link rel="stylesheet" href="/css/business.css">
    <link rel="stylesheet" href="/css/premium.css">
    <link rel="stylesheet" href="/css/theme-switch.css">
    <style>
        /* ===== СТИЛИ (аналогичны улучшенной странице входа, адаптированы) ===== */
        :root {
            --register-bg: linear-gradient(135deg, var(--bg) 0%, var(--bg-secondary) 100%);
            --card-shadow: 0 25px 50px -12px rgba(0,0,0,0.25);
            --input-focus-glow: 0 0 0 4px rgba(74,144,226,0.15);
            --transition-smooth: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }
        .register-page {
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            background: var(--register-bg);
            padding: 40px 20px;
            position: relative;
            overflow-x: hidden;
        }
        .register-page::before, .register-page::after {
            content: '';
            position: absolute;
            width: 80%;
            height: 80%;
            background: radial-gradient(circle, rgba(74,144,226,0.03) 0%, transparent 70%);
            border-radius: 50%;
            pointer-events: none;
        }
        .register-page::before { top: -50%; right: -20%; }
        .register-page::after { bottom: -30%; left: -10%; background: radial-gradient(circle, rgba(52,199,89,0.02) 0%, transparent 70%); }
        .register-card {
            max-width: 480px;
            width: 100%;
            background: var(--surface);
            border-radius: 32px;
            box-shadow: var(--card-shadow);
            border: 1px solid var(--border);
            overflow: hidden;
            animation: fadeInUp 0.5s cubic-bezier(0.2, 0.9, 0.4, 1.1);
            transition: transform 0.3s ease, box-shadow 0.3s ease;
            position: relative;
            z-index: 1;
        }
        .register-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 30px 60px -12px rgba(0,0,0,0.3);
        }
        @keyframes fadeInUp {
            from { opacity: 0; transform: translateY(30px); }
            to { opacity: 1; transform: translateY(0); }
        }
        .register-header {
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
        .register-header h2 {
            font-size: 28px;
            font-weight: 700;
            margin-bottom: 8px;
            color: var(--text);
        }
        .register-header p {
            font-size: 15px;
            color: var(--text-secondary);
        }
        .benefits {
            display: flex;
            justify-content: center;
            gap: 16px;
            margin-top: 16px;
            flex-wrap: wrap;
        }
        .benefit-badge {
            font-size: 12px;
            padding: 4px 12px;
            background: var(--bg-secondary);
            border-radius: 50px;
            color: var(--text-secondary);
        }
        .register-form {
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
        .form-label .required { color: var(--danger); margin-left: 2px; }
        .form-label .optional { font-weight: normal; font-size: 12px; color: var(--text-secondary); }
        .input-wrapper { position: relative; }
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
        .form-input {
            width: 100%;
            padding: 14px 16px 14px 48px;
            border: 1.5px solid var(--border);
            border-radius: 16px;
            background: var(--bg);
            color: var(--text);
            font-size: 16px;
            transition: var(--transition-smooth);
        }
        .form-input:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: var(--input-focus-glow);
        }
        .form-input.error {
            border-color: var(--danger);
            background: rgba(255,59,48,0.02);
        }
        .form-hint {
            font-size: 12px;
            color: var(--text-secondary);
            margin-top: 6px;
            display: flex;
            align-items: center;
            gap: 4px;
        }
        .field-error {
            color: var(--danger);
            font-size: 12px;
            margin-top: 6px;
            display: flex;
            align-items: center;
            gap: 4px;
        }
        .checkbox-group {
            display: flex;
            align-items: flex-start;
            gap: 12px;
            margin: 24px 0;
        }
        .checkbox-group input[type="checkbox"] {
            width: 18px;
            height: 18px;
            margin-top: 2px;
            cursor: pointer;
            accent-color: var(--primary);
        }
        .checkbox-group label {
            font-size: 13px;
            color: var(--text-secondary);
            line-height: 1.4;
        }
        .checkbox-group a { color: var(--primary); text-decoration: none; }
        .checkbox-group a:hover { text-decoration: underline; }
        .btn {
            width: 100%;
            padding: 16px;
            font-size: 16px;
            font-weight: 600;
            border-radius: 50px;
            border: none;
            cursor: pointer;
            transition: var(--transition-smooth);
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
        @keyframes spin { to { transform: rotate(360deg); } }
        .divider {
            display: flex;
            align-items: center;
            margin: 24px 0;
            color: var(--text-secondary);
            font-size: 13px;
        }
        .divider::before, .divider::after {
            content: '';
            flex: 1;
            border-bottom: 1px solid var(--border-light);
        }
        .divider span { margin: 0 12px; }
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
            gap: 8px;
            padding: 12px;
            border-radius: 50px;
            border: 1px solid var(--border);
            background: var(--bg-secondary);
            color: var(--text);
            font-size: 14px;
            font-weight: 500;
            cursor: pointer;
            transition: var(--transition-smooth);
        }
        .oauth-btn:hover {
            background: var(--primary-light);
            border-color: var(--primary);
            color: white;
            transform: translateY(-2px);
        }
        .oauth-btn:disabled {
            opacity: 0.6;
            cursor: wait;
        }
        .register-footer {
            padding: 20px 32px 32px;
            text-align: center;
            border-top: 1px solid var(--border-light);
        }
        .register-footer p { font-size: 14px; color: var(--text-secondary); }
        .register-footer a { color: var(--primary); text-decoration: none; font-weight: 600; }
        .register-footer a:hover { text-decoration: underline; }
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
        .alert-success { background: rgba(52,199,89,0.1); border-left: 4px solid var(--success); color: var(--success); }
        .alert-error { background: rgba(255,59,48,0.1); border-left: 4px solid var(--danger); color: var(--danger); }
        .alert-info { background: rgba(74,144,226,0.1); border-left: 4px solid var(--primary); color: var(--primary); }
        .alert-close {
            margin-left: auto;
            cursor: pointer;
            background: none;
            border: none;
            font-size: 18px;
            opacity: 0.6;
        }
        @media (max-width: 768px) {
            .register-header { padding: 24px 24px 0; }
            .register-form { padding: 24px; }
            .register-footer { padding: 20px 24px 24px; }
            .oauth-buttons { grid-template-columns: repeat(2, 1fr); }
        }
        @media (max-width: 480px) {
            .register-page { padding: 20px 16px; }
            .register-header h2 { font-size: 24px; }
            .oauth-buttons { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body class="register-page">
    <div class="register-card">
        <div class="register-header">
            <div class="logo"><h1>Найдук</h1></div>
            <h2>Создайте аккаунт</h2>
            <p>Без пароля — просто введите email и получите ссылку для входа</p>
            <div class="benefits">
                <span class="benefit-badge">🚀 30 секунд</span>
                <span class="benefit-badge">🔐 Без пароля</span>
                <span class="benefit-badge">💯 Бесплатно</span>
            </div>
        </div>

        <div class="register-form">
            <div id="alert-container"></div>

            <form id="register-form-element" method="POST" action="/api/auth/register.php" novalidate>
                <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
                <?php if (isset($_SESSION['ref'])): ?>
                    <input type="hidden" name="ref" value="<?= $_SESSION['ref'] ?>">
                <?php endif; ?>
                
                <div class="form-group">
                    <label class="form-label"><span class="required">*</span> Email</label>
                    <div class="input-wrapper">
                        <span class="input-icon">✉️</span>
                        <input type="email" name="email" id="register-email" class="form-input" placeholder="your@email.com" required autocomplete="email" inputmode="email">
                        <span class="input-validation-icon" id="email-validation-icon"></span>
                    </div>
                    <div class="form-hint">На этот email придёт ссылка для входа</div>
                    <div id="email-error" class="field-error" style="display: none;"></div>
                </div>

                <div class="form-group">
                    <label class="form-label"><span class="required">*</span> Имя</label>
                    <div class="input-wrapper">
                        <span class="input-icon">👤</span>
                        <input type="text" name="name" id="register-name" class="form-input" placeholder="Как к вам обращаться?" required minlength="2" maxlength="100" autocomplete="name">
                        <span class="input-validation-icon" id="name-validation-icon"></span>
                    </div>
                    <div id="name-error" class="field-error" style="display: none;"></div>
                </div>

                <div class="form-group">
                    <label class="form-label">Телефон <span class="optional">(необязательно)</span></label>
                    <div class="input-wrapper">
                        <span class="input-icon">📱</span>
                        <input type="tel" name="phone" id="register-phone" class="form-input" placeholder="+7 (999) 000-00-00" autocomplete="tel" inputmode="tel">
                        <span class="input-validation-icon" id="phone-validation-icon"></span>
                    </div>
                    <div class="form-hint">Для повышения доверия и защиты аккаунта</div>
                    <div id="phone-error" class="field-error" style="display: none;"></div>
                </div>

                <div class="checkbox-group">
                    <input type="checkbox" id="register-terms" name="terms" value="1" required>
                    <label for="register-terms">Я согласен с <a href="/terms" target="_blank">условиями использования</a> и <a href="/privacy" target="_blank">политикой конфиденциальности</a></label>
                </div>

                <div class="honeypot">
                    <input type="text" name="website_url" tabindex="-1" autocomplete="off">
                    <input type="text" name="phone_fake" tabindex="-1" autocomplete="off">
                </div>
                <input type="hidden" name="fill_time" id="fill-time" value="0">

                <button type="submit" id="register-submit" class="btn btn-primary">📝 Зарегистрироваться</button>
                <div class="form-hint">Пароль не нужен – вы получите ссылку для входа на почту.</div>
            </form>

            <div class="divider"><span>или</span></div>

            <div class="oauth-buttons" id="oauth-buttons">
                <button class="oauth-btn" data-provider="vk"><span class="oauth-icon">🎨</span> VK</button>
                <button class="oauth-btn" data-provider="yandex"><span class="oauth-icon">Я</span> Яндекс</button>
                <button class="oauth-btn" data-provider="google"><span class="oauth-icon">G</span> Google</button>
                <button class="oauth-btn" data-provider="mailru"><span class="oauth-icon">📧</span> Mail.ru</button>
                <button class="oauth-btn" data-provider="telegram"><span class="oauth-icon">📱</span> Telegram</button>
                <button class="oauth-btn" data-provider="rambler"><span class="oauth-icon">Р</span> Рамблер</button>
                <button class="oauth-btn" data-provider="max"><span class="oauth-icon">🔷</span> MAX</button>
            </div>
        </div>

        <div class="register-footer">
            <p>Уже есть аккаунт? <a href="/auth/login">Войти</a></p>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/toastify-js"></script>
    <script>
        // ===== ГЛОБАЛЬНЫЕ ПЕРЕМЕННЫЕ =====
        const csrfToken = document.querySelector('meta[name="csrf-token"]').getAttribute('content');
        let startTime = Date.now();
        document.getElementById('fill-time').value = Math.floor(startTime / 1000);

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

        function showAlert(message, type = 'error') {
            const container = document.getElementById('alert-container');
            const id = 'alert-' + Date.now();
            const html = `<div id="${id}" class="alert alert-${type}">
                <span>${type === 'success' ? '✅' : (type === 'error' ? '⚠️' : 'ℹ️')}</span>
                <span>${escapeHtml(message)}</span>
                <button class="alert-close" onclick="document.getElementById('${id}').remove()">✕</button>
            </div>`;
            container.insertAdjacentHTML('beforeend', html);
            setTimeout(() => { const el = document.getElementById(id); if (el) el.remove(); }, 8000);
        }

        function escapeHtml(str) {
            if (!str) return '';
            return str.replace(/[&<>]/g, m => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;' }[m] || m));
        }

        // ===== ВАЛИДАЦИЯ =====
        function validateEmail(email) {
            return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email);
        }
        function validatePhone(phone) {
            if (!phone) return true;
            return /^[\+\d\s\-\(\)]{5,20}$/.test(phone);
        }

        function updateValidationIcon(inputId, isValid) {
            const icon = document.getElementById(inputId + '-validation-icon');
            if (!icon) return;
            if (isValid) {
                icon.innerHTML = '✅';
                icon.style.color = 'var(--success)';
            } else {
                icon.innerHTML = '';
            }
        }

        function showFieldError(fieldId, message) {
            const errorDiv = document.getElementById(fieldId + '-error');
            if (errorDiv) {
                errorDiv.textContent = message;
                errorDiv.style.display = 'flex';
            }
            const input = document.getElementById('register-' + fieldId);
            if (input) input.classList.add('error');
        }

        function clearFieldError(fieldId) {
            const errorDiv = document.getElementById(fieldId + '-error');
            if (errorDiv) errorDiv.style.display = 'none';
            const input = document.getElementById('register-' + fieldId);
            if (input) input.classList.remove('error');
        }

        // ===== РЕАЛЬНАЯ ВАЛИДАЦИЯ =====
        const emailInput = document.getElementById('register-email');
        const nameInput = document.getElementById('register-name');
        const phoneInput = document.getElementById('register-phone');

        emailInput?.addEventListener('input', () => {
            const email = emailInput.value.trim();
            const isValid = !email || validateEmail(email);
            updateValidationIcon('email', isValid);
            if (isValid) clearFieldError('email');
            else showFieldError('email', 'Некорректный email');
        });
        nameInput?.addEventListener('input', () => {
            const name = nameInput.value.trim();
            const isValid = name.length >= 2 && name.length <= 100;
            updateValidationIcon('name', isValid);
            if (isValid) clearFieldError('name');
            else showFieldError('name', 'Имя должно быть от 2 до 100 символов');
        });
        phoneInput?.addEventListener('input', () => {
            const phone = phoneInput.value.trim();
            const isValid = !phone || validatePhone(phone);
            updateValidationIcon('phone', isValid);
            if (isValid) clearFieldError('phone');
            else showFieldError('phone', 'Некорректный формат телефона');
        });

        // ===== ОТПРАВКА ФОРМЫ (теперь напрямую на API) =====
        const form = document.getElementById('register-form-element');
        const submitBtn = document.getElementById('register-submit');

        form.addEventListener('submit', async (e) => {
            e.preventDefault();

            const website = form.querySelector('input[name="website_url"]').value;
            const phoneFake = form.querySelector('input[name="phone_fake"]').value;
            if (website || phoneFake) {
                showAlert('Регистрация выполнена', 'success');
                return;
            }

            const fillTime = parseInt(document.getElementById('fill-time').value);
            if (fillTime < 2) {
                showAlert('Пожалуйста, заполните форму не торопясь', 'warning');
                return;
            }

            const email = emailInput.value.trim();
            const name = nameInput.value.trim();
            const phone = phoneInput.value.trim();
            const terms = document.getElementById('register-terms').checked;

            if (!email || !validateEmail(email)) {
                showFieldError('email', 'Введите корректный email');
                return;
            }
            if (!name || name.length < 2) {
                showFieldError('name', 'Имя должно быть не менее 2 символов');
                return;
            }
            if (phone && !validatePhone(phone)) {
                showFieldError('phone', 'Некорректный формат телефона');
                return;
            }
            if (!terms) {
                showAlert('Необходимо согласиться с условиями', 'error');
                return;
            }

            const formData = new URLSearchParams();
            formData.append('csrf_token', csrfToken);
            formData.append('email', email);
            formData.append('name', name);
            formData.append('phone', phone);
            formData.append('terms', terms ? '1' : '0');
            formData.append('fill_time', fillTime);
            // Добавляем ref, если есть в сессии (через скрытое поле)
            const refInput = form.querySelector('input[name="ref"]');
            if (refInput && refInput.value) {
                formData.append('ref', refInput.value);
            }

            const originalText = submitBtn.innerHTML;
            submitBtn.disabled = true;
            submitBtn.innerHTML = '<span class="spinner"></span> Регистрация...';

            try {
                const res = await fetch('/api/auth/register.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: formData
                });
                const data = await res.json();
                if (data.success) {
                    showAlert('✅ Регистрация успешна! Проверьте почту для входа.', 'success');
                    form.reset();
                    setTimeout(() => window.location.href = '/auth/login', 3000);
                } else {
                    showAlert(data.error || 'Ошибка регистрации', 'error');
                }
            } catch (err) {
                console.error(err);
                showAlert('Ошибка сети. Попробуйте позже.', 'error');
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

        // ===== ФОРМАТИРОВАНИЕ ТЕЛЕФОНА =====
        phoneInput.addEventListener('input', (e) => {
            let value = e.target.value.replace(/\D/g, '');
            if (value.length === 11) {
                value = value.replace(/(\d{1})(\d{3})(\d{3})(\d{2})(\d{2})/, '+$1 ($2) $3-$4-$5');
                e.target.value = value;
            }
        });
    </script>
</body>
</html>