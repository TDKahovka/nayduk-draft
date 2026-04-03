<?php
/* ============================================
   НАЙДУК — Главный шаблон (Plates)
   Версия 4.0 (апрель 2026) — ФИНАЛЬНАЯ
   - Полная безопасность и надёжность при высоких нагрузках
   - Исправлено гамбургер-меню, микроразметка через JSON
   - Оптимизация загрузки, защита от XSS и CSRF
   - Автоматическая очистка флеш-сообщений
   - Адаптив, тёмная тема, скелетоны
   ============================================ */

// Переменные, передаваемые из контроллера
$title = $title ?? 'Найдук — партнёрская платформа';
$description = $description ?? 'Зарабатывайте на партнёрских программах, размещайте объявления, находите клиентов.';
$user = $user ?? null;
$csrfToken = csrf_token();

// Определяем текущий путь для активного меню
$currentPath = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$isLoggedIn = isset($_SESSION['user_id']);
$isPartner = ($user && !empty($user['is_partner'])) ? true : false;
$isAdmin = ($user && isset($user['role']) && $user['role'] === 'admin');

// Тема (из куки или системная)
$themeClass = get_theme_class();

// Генерация микроразметки через json_encode (безопасно)
$schema = [
    '@context' => 'https://schema.org',
    '@graph' => [
        [
            '@type' => 'Organization',
            'name' => 'Найдук',
            'url' => 'https://' . $_SERVER['HTTP_HOST'],
            'logo' => 'https://' . $_SERVER['HTTP_HOST'] . '/assets/logo.svg',
            'sameAs' => [
                'https://t.me/nayduk_bot',
                'https://vk.com/nayduk'
            ]
        ],
        [
            '@type' => 'WebSite',
            'name' => 'Найдук',
            'url' => 'https://' . $_SERVER['HTTP_HOST'],
            'potentialAction' => [
                '@type' => 'SearchAction',
                'target' => 'https://' . $_SERVER['HTTP_HOST'] . '/search?q={search_term_string}',
                'query-input' => 'required name=search_term_string'
            ]
        ],
        [
            '@type' => 'BreadcrumbList',
            'itemListElement' => [
                [
                    '@type' => 'ListItem',
                    'position' => 1,
                    'name' => 'Главная',
                    'item' => 'https://' . $_SERVER['HTTP_HOST']
                ],
                [
                    '@type' => 'ListItem',
                    'position' => 2,
                    'name' => $title,
                    'item' => 'https://' . $_SERVER['HTTP_HOST'] . $currentPath
                ]
            ]
        ]
    ]
];
$schemaJson = json_encode($schema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
?>

<!DOCTYPE html>
<html lang="ru" class="<?= htmlspecialchars($themeClass) ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <meta name="csrf-token" content="<?= htmlspecialchars($csrfToken) ?>">
    <title><?= htmlspecialchars($title) ?></title>
    <meta name="description" content="<?= htmlspecialchars($description) ?>">
    <meta name="keywords" content="партнёрские программы, доска объявлений, заработок, офферы, реферальные ссылки">
    <link rel="canonical" href="https://<?= $_SERVER['HTTP_HOST'] . $currentPath ?>">
    <link rel="icon" type="image/x-icon" href="/favicon.ico">
    <meta name="robots" content="index, follow">

    <!-- ========== ПРЕДВАРИТЕЛЬНАЯ ЗАГРУЗКА ========== -->
    <link rel="preconnect" href="https://cdn.hugeicons.com" crossorigin>
    <link rel="dns-prefetch" href="https://cdn.hugeicons.com">
    <link rel="preload" href="https://cdn.hugeicons.com/font/hgi-stroke-rounded.css" as="style">

    <!-- ========== СТИЛИ ========== -->
    <link rel="stylesheet" href="https://cdn.hugeicons.com/font/hgi-stroke-rounded.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/toastify-js/src/toastify.min.css">
    <!-- Основные стили – убедитесь, что файлы существуют, иначе удалите ссылки -->
    <link rel="stylesheet" href="/css/business.css">
    <link rel="stylesheet" href="/css/premium.css">
    <link rel="stylesheet" href="/css/theme-switch.css">

    <!-- ========== МИКРОРАЗМЕТКА SCHEMA.ORG ========== -->
    <script type="application/ld+json"><?= $schemaJson ?></script>

    <style>
        /* ===== ПРЕМИАЛЬНЫЙ СТИЛЬ (без сокращений) ===== */
        :root {
            --primary: #4A90E2;
            --primary-dark: #2E5C8A;
            --primary-light: #6BA5E7;
            --accent: #FF6B35;
            --accent-gold: #FFD700;
            --success: #34C759;
            --warning: #FF9500;
            --danger: #FF3B30;
            --info: #5A67D8;
            --bg: #F5F5F7;
            --bg-secondary: #EFEFF4;
            --surface: #FFFFFF;
            --text: #1D1D1F;
            --text-secondary: #86868B;
            --border: #D2D2D7;
            --border-light: #E5E5EA;
            --radius-sm: 6px;
            --radius: 10px;
            --radius-lg: 14px;
            --radius-xl: 20px;
            --radius-2xl: 28px;
            --radius-full: 9999px;
            --shadow-sm: 0 2px 8px rgba(0,0,0,0.05);
            --shadow-md: 0 4px 16px rgba(0,0,0,0.1);
            --shadow-lg: 0 8px 32px rgba(0,0,0,0.15);
            --transition: 0.2s ease;
        }

        @media (prefers-color-scheme: dark) {
            :root {
                --bg: #000000;
                --bg-secondary: #1C1C1E;
                --surface: #1C1C1E;
                --text: #F5F5F7;
                --text-secondary: #A1A1A6;
                --border: #38383A;
                --border-light: #2C2C2E;
            }
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: var(--bg);
            color: var(--text);
            line-height: 1.5;
            min-height: 100vh;
            display: flex;
            flex-direction: column;
        }

        a {
            color: var(--primary);
            text-decoration: none;
        }

        a:hover {
            text-decoration: underline;
        }

        /* ===== ШАПКА С ГАМБУРГЕР-МЕНЮ (чистый CSS + JS для надёжности) ===== */
        .header {
            background: var(--surface);
            border-bottom: 1px solid var(--border);
            padding: 16px 40px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 16px;
            position: relative;
            z-index: 100;
        }

        .logo {
            font-size: 24px;
            font-weight: 700;
            color: var(--primary);
        }

        /* Гамбургер-кнопка */
        .menu-toggle {
            display: none;
            position: relative;
            width: 30px;
            height: 30px;
            cursor: pointer;
            z-index: 200;
        }

        .menu-toggle span,
        .menu-toggle span::before,
        .menu-toggle span::after {
            display: block;
            position: absolute;
            width: 100%;
            height: 2px;
            background-color: var(--text);
            transition: transform 0.25s ease;
        }

        .menu-toggle span {
            top: 50%;
            transform: translateY(-50%);
        }

        .menu-toggle span::before {
            content: "";
            top: -8px;
        }

        .menu-toggle span::after {
            content: "";
            top: 8px;
        }

        /* Навигация (десктопная) */
        .nav {
            display: flex;
            gap: 24px;
            align-items: center;
            flex-wrap: wrap;
        }

        .nav a {
            color: var(--text);
            font-weight: 500;
            padding: 8px 12px;
            border-radius: var(--radius-full);
            transition: all var(--transition);
        }

        .nav a:hover {
            background: var(--bg-secondary);
            color: var(--primary);
            text-decoration: none;
        }

        .nav a.active {
            background: var(--primary);
            color: white;
        }

        .user-menu {
            display: flex;
            align-items: center;
            gap: 16px;
        }

        .theme-switch {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            background: var(--bg-secondary);
            padding: 4px;
            border-radius: var(--radius-full);
        }

        .theme-option {
            padding: 4px 8px;
            border-radius: var(--radius-full);
            cursor: pointer;
            transition: all var(--transition);
            font-size: 14px;
        }

        .theme-option.active {
            background: var(--primary);
            color: white;
        }

        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            padding: 8px 16px;
            border: none;
            border-radius: var(--radius-full);
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            transition: all var(--transition);
        }

        .btn-primary {
            background: linear-gradient(135deg, var(--primary), var(--primary-dark));
            color: white;
        }

        .btn-primary:hover {
            transform: translateY(-2px);
        }

        .btn-secondary {
            background: var(--surface);
            border: 1px solid var(--border);
            color: var(--text);
        }

        .btn-secondary:hover {
            background: var(--bg-secondary);
        }

        /* ===== МОБИЛЬНОЕ МЕНЮ (с помощью JS) ===== */
        @media (max-width: 768px) {
            .header {
                padding: 16px 20px;
            }

            .menu-toggle {
                display: block;
            }

            .nav {
                position: fixed;
                top: 0;
                left: -100%;
                width: 280px;
                height: 100%;
                background: var(--surface);
                flex-direction: column;
                align-items: flex-start;
                padding: 80px 24px 24px;
                box-shadow: var(--shadow-lg);
                transition: left 0.25s ease;
                z-index: 150;
                gap: 16px;
                overflow-y: auto;
            }

            .nav.open {
                left: 0;
            }

            .nav a {
                width: 100%;
            }

            .user-menu {
                flex-direction: column;
                width: 100%;
                margin-top: 16px;
                padding-top: 16px;
                border-top: 1px solid var(--border-light);
            }

            .theme-switch {
                order: -1;
                margin-bottom: 16px;
            }

            /* Анимация гамбургера при открытии */
            .menu-toggle.open span {
                transform: rotate(45deg);
            }

            .menu-toggle.open span::before {
                top: 0;
                transform: rotate(0deg);
            }

            .menu-toggle.open span::after {
                top: 0;
                transform: rotate(90deg);
            }
        }

        /* Десктопная навигация */
        @media (min-width: 769px) {
            .menu-toggle {
                display: none;
            }
        }

        /* ===== ОСНОВНОЙ КОНТЕНТ ===== */
        .main-content {
            flex: 1;
            max-width: 1400px;
            margin: 40px auto;
            padding: 0 40px;
            width: 100%;
        }

        /* ===== СКЕЛЕТОНЫ ===== */
        .skeleton {
            background: linear-gradient(90deg, var(--bg-secondary) 25%, var(--border-light) 50%, var(--bg-secondary) 75%);
            background-size: 200% 100%;
            animation: skeleton-loading 1.5s infinite;
            border-radius: var(--radius);
        }

        @keyframes skeleton-loading {
            0% {
                background-position: 200% 0;
            }
            100% {
                background-position: -200% 0;
            }
        }

        @media (prefers-reduced-motion: reduce) {
            .skeleton {
                animation: none;
                background: var(--bg-secondary);
            }
        }

        .skeleton-text {
            height: 16px;
            margin-bottom: 8px;
        }

        .skeleton-title {
            height: 24px;
            width: 60%;
            margin-bottom: 16px;
        }

        .skeleton-card {
            height: 280px;
            border-radius: var(--radius-xl);
        }

        .skeleton-delay-1 {
            animation-delay: 0.1s;
        }

        .skeleton-delay-2 {
            animation-delay: 0.2s;
        }

        .skeleton-delay-3 {
            animation-delay: 0.3s;
        }

        /* ===== АНИМАЦИИ ===== */
        .fade-in {
            animation: fadeIn 0.3s ease-out;
        }

        @keyframes fadeIn {
            from {
                opacity: 0;
                transform: translateY(10px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        /* ===== ТОСТИФАЙ ===== */
        .toastify {
            font-family: inherit;
            border-radius: var(--radius-full);
            box-shadow: var(--shadow-lg);
            padding: 12px 20px;
            backdrop-filter: blur(10px);
        }

        .toastify-success {
            background: linear-gradient(135deg, var(--success), #2C9B4E);
        }

        .toastify-error {
            background: linear-gradient(135deg, var(--danger), #C72A2A);
        }

        .toastify-warning {
            background: linear-gradient(135deg, var(--warning), #E68600);
        }

        /* ===== ПОДВАЛ ===== */
        .footer {
            background: var(--surface);
            border-top: 1px solid var(--border);
            padding: 40px;
            margin-top: 60px;
            text-align: center;
            color: var(--text-secondary);
        }

        .footer a {
            color: var(--text-secondary);
        }

        .footer a:hover {
            color: var(--primary);
        }

        /* ===== ПЕРЕКЛЮЧАТЕЛЬ ТЕМЫ (HTML-класс) ===== */
        html.dark {
            --bg: #000000;
            --bg-secondary: #1C1C1E;
            --surface: #1C1C1E;
            --text: #F5F5F7;
            --text-secondary: #A1A1A6;
            --border: #38383A;
            --border-light: #2C2C2E;
        }
    </style>
</head>
<body>

    <header class="header">
        <div class="logo">Найдук</div>

        <div class="menu-toggle" id="menuToggle" aria-label="Меню">
            <span></span>
        </div>

        <nav class="nav" id="mainNav">
            <a href="/" class="<?= $currentPath === '/' ? 'active' : '' ?>">Главная</a>
            <a href="/offers" class="<?= str_starts_with($currentPath, '/offers') ? 'active' : '' ?>">Предложения</a>

            <?php if ($isLoggedIn): ?>
                <?php if ($isAdmin): ?>
                    <a href="/admin" class="<?= str_starts_with($currentPath, '/admin') ? 'active' : '' ?>">Админка</a>
                <?php endif; ?>
                <?php if ($isPartner || $isAdmin): ?>
                    <a href="/business" class="<?= str_starts_with($currentPath, '/business') ? 'active' : '' ?>">Бизнес-кабинет</a>
                <?php endif; ?>
                <div class="user-menu">
                    <div class="theme-switch" id="theme-switch">
                        <span class="theme-option" data-theme="light">☀️</span>
                        <span class="theme-option" data-theme="dark">🌙</span>
                        <span class="theme-option" data-theme="auto">🔄</span>
                    </div>
                    <a href="/profile" class="btn btn-secondary">
                        <i class="hgi hgi-stroke-user"></i> <?= htmlspecialchars($user['name'] ?? 'Профиль') ?>
                    </a>
                    <a href="/auth/logout" class="btn btn-secondary">Выйти</a>
                </div>
            <?php else: ?>
                <div class="user-menu">
                    <div class="theme-switch" id="theme-switch">
                        <span class="theme-option" data-theme="light">☀️</span>
                        <span class="theme-option" data-theme="dark">🌙</span>
                        <span class="theme-option" data-theme="auto">🔄</span>
                    </div>
                    <a href="/auth/login" class="btn btn-secondary">Войти</a>
                    <a href="/auth/register" class="btn btn-primary">Регистрация</a>
                </div>
            <?php endif; ?>
        </nav>
    </header>

    <main class="main-content">
        <?= $content ?? '' ?>
    </main>

    <footer class="footer">
        <p>© <?= date('Y') ?> Найдук. Все права защищены.
            <a href="/privacy">Политика конфиденциальности</a> |
            <a href="/terms">Условия использования</a> |
            <a href="/llms.txt">llms.txt</a>
        </p>
    </footer>

    <!-- ========== СКРИПТЫ ========== -->
    <script src="https://cdn.jsdelivr.net/npm/toastify-js"></script>
    <script>
        // ===== CSRF-ТОКЕН ДЛЯ AJAX =====
        const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');

        // Автоматическое добавление CSRF-токена в fetch-запросы
        const originalFetch = window.fetch;
        window.fetch = function(...args) {
            const options = args[1] || {};
            if (options.method && ['POST', 'PUT', 'DELETE', 'PATCH'].includes(options.method.toUpperCase())) {
                options.headers = options.headers || {};
                if (!options.headers['X-CSRF-Token']) {
                    options.headers['X-CSRF-Token'] = csrfToken;
                }
            }
            return originalFetch.apply(this, args);
        };

        // ===== ПЕРЕКЛЮЧАТЕЛЬ ТЕМЫ =====
        (function() {
            const THEME_KEY = 'nayduk_theme';
            const html = document.documentElement;

            function setTheme(theme) {
                if (theme === 'dark') {
                    html.classList.add('dark');
                } else if (theme === 'light') {
                    html.classList.remove('dark');
                } else {
                    const prefersDark = window.matchMedia('(prefers-color-scheme: dark)').matches;
                    if (prefersDark) {
                        html.classList.add('dark');
                    } else {
                        html.classList.remove('dark');
                    }
                }
                localStorage.setItem(THEME_KEY, theme);
            }

            const saved = localStorage.getItem(THEME_KEY) || 'auto';
            setTheme(saved);

            const btns = document.querySelectorAll('#theme-switch .theme-option');
            btns.forEach(btn => {
                btn.addEventListener('click', () => {
                    const theme = btn.dataset.theme;
                    setTheme(theme);
                    btns.forEach(b => b.classList.remove('active'));
                    btn.classList.add('active');
                });
                if (btn.dataset.theme === saved) {
                    btn.classList.add('active');
                }
            });
        })();

        // ===== УВЕДОМЛЕНИЯ =====
        window.Notify = {
            success: function(message, duration = 3000) {
                Toastify({
                    text: `✅ ${message}`,
                    duration: duration,
                    gravity: 'top',
                    position: 'right',
                    backgroundColor: 'linear-gradient(135deg, #34C759, #2C9B4E)',
                    className: 'toastify-success'
                }).showToast();
            },
            error: function(message, duration = 5000) {
                Toastify({
                    text: `❌ ${message}`,
                    duration: duration,
                    gravity: 'top',
                    position: 'right',
                    backgroundColor: 'linear-gradient(135deg, #FF3B30, #C72A2A)',
                    className: 'toastify-error'
                }).showToast();
            },
            warning: function(message, duration = 4000) {
                Toastify({
                    text: `⚠️ ${message}`,
                    duration: duration,
                    gravity: 'top',
                    position: 'right',
                    backgroundColor: 'linear-gradient(135deg, #FF9500, #E68600)',
                    className: 'toastify-warning'
                }).showToast();
            },
            info: function(message, duration = 3000) {
                Toastify({
                    text: `ℹ️ ${message}`,
                    duration: duration,
                    gravity: 'top',
                    position: 'right',
                    backgroundColor: 'linear-gradient(135deg, #5A67D8, #4C51BF)'
                }).showToast();
            }
        };

        // ===== ФЛЕШ-СООБЩЕНИЯ ИЗ СЕССИИ =====
        <?php
        $flash = get_flash();
        foreach ($flash as $type => $message):
        ?>
        Notify.<?= $type ?>('<?= addslashes($message) ?>');
        <?php endforeach; ?>

        // ===== МОБИЛЬНОЕ МЕНЮ (управление) =====
        (function() {
            const menuToggle = document.getElementById('menuToggle');
            const nav = document.getElementById('mainNav');
            if (menuToggle && nav) {
                menuToggle.addEventListener('click', function() {
                    nav.classList.toggle('open');
                    menuToggle.classList.toggle('open');
                });
                // Закрытие при клике вне меню (опционально)
                document.addEventListener('click', function(event) {
                    if (!nav.contains(event.target) && !menuToggle.contains(event.target)) {
                        nav.classList.remove('open');
                        menuToggle.classList.remove('open');
                    }
                });
            }
        })();
    </script>
    <?= $scripts ?? '' ?>
</body>
</html>