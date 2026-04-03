<?php
/* ============================================
   НАЙДУК — Основной шаблон (layout)
   Использует Plates
   ============================================ */

use League\Plates\Template\Template;

/** @var Template $this */
/** @var string $title */
/** @var string $description */
/** @var string $content */
/** @var array $styles */
/** @var array $scripts */

$title = $title ?? 'Найдук — партнёрская платформа';
$description = $description ?? 'Зарабатывайте на партнёрских программах, размещайте объявления, находите клиентов.';
$themeClass = theme_class();
?>
<!DOCTYPE html>
<html lang="ru" class="<?= $themeClass ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <meta name="csrf-token" content="<?= csrf_token() ?>">
    <title><?= htmlspecialchars($title) ?></title>
    <meta name="description" content="<?= htmlspecialchars($description) ?>">
    <link rel="canonical" href="https://<?= $_SERVER['HTTP_HOST'] . parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?>">
    
    <!-- Hugeicons CDN -->
    <link rel="stylesheet" href="https://cdn.hugeicons.com/font/hgi-stroke-rounded.css">
    <!-- Toastify -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/toastify-js/src/toastify.min.css">
    <!-- Основные стили -->
    <link rel="stylesheet" href="/css/business.css">
    <link rel="stylesheet" href="/css/premium.css">
    <link rel="stylesheet" href="/css/theme-switch.css">
    
    <?php if (!empty($styles)): foreach ($styles as $style): ?>
        <link rel="stylesheet" href="<?= $style ?>">
    <?php endforeach; endif; ?>
    
    <style>
        /* Скелетоны */
        .skeleton { background: linear-gradient(90deg, var(--bg-secondary) 25%, var(--border-light) 50%, var(--bg-secondary) 75%); background-size: 200% 100%; animation: skeleton-loading 1.5s infinite; border-radius: var(--radius); }
        .skeleton-text { height: 16px; margin-bottom: 8px; }
        .skeleton-title { height: 24px; width: 60%; }
        .skeleton-card { height: 200px; border-radius: var(--radius-xl); }
        @keyframes skeleton-loading { 0% { background-position: 200% 0; } 100% { background-position: -200% 0; } }
        
        /* Анимации */
        .fade-in { animation: fadeIn 0.3s ease-out; }
        @keyframes fadeIn { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: translateY(0); } }
    </style>
</head>
<body>
    <header class="header">
        <div class="logo">Найдук</div>
        <nav class="nav">
            <a href="/" class="<?= $_SERVER['REQUEST_URI'] === '/' ? 'active' : '' ?>">Главная</a>
            <a href="/offers" class="<?= strpos($_SERVER['REQUEST_URI'], '/offers') === 0 ? 'active' : '' ?>">Предложения</a>
            <?php if (isset($_SESSION['user_id'])): ?>
                <?php if ($_SESSION['role'] === 'admin'): ?>
                    <a href="/admin" class="<?= strpos($_SERVER['REQUEST_URI'], '/admin') === 0 ? 'active' : '' ?>">Админка</a>
                <?php endif; ?>
                <?php if ($_SESSION['is_partner'] ?? false): ?>
                    <a href="/business" class="<?= strpos($_SERVER['REQUEST_URI'], '/business') === 0 ? 'active' : '' ?>">Бизнес-кабинет</a>
                <?php endif; ?>
                <div class="user-menu">
                    <div class="theme-switch">
                        <span class="theme-option" data-theme="light">☀️</span>
                        <span class="theme-option" data-theme="dark">🌙</span>
                        <span class="theme-option" data-theme="auto">🔄</span>
                    </div>
                    <a href="/profile" class="btn btn-secondary">
                        <i class="hgi hgi-stroke-user"></i> <?= htmlspecialchars($_SESSION['user_name'] ?? 'Профиль') ?>
                    </a>
                    <a href="/auth/logout" class="btn btn-secondary">Выйти</a>
                </div>
            <?php else: ?>
                <div class="theme-switch">
                    <span class="theme-option" data-theme="light">☀️</span>
                    <span class="theme-option" data-theme="dark">🌙</span>
                    <span class="theme-option" data-theme="auto">🔄</span>
                </div>
                <a href="/auth/login" class="btn btn-secondary">Войти</a>
                <a href="/auth/register" class="btn btn-primary">Регистрация</a>
            <?php endif; ?>
        </nav>
    </header>

    <main class="main-content">
        <?= $content ?>
    </main>

    <footer class="footer">
        <p>© <?= date('Y') ?> Найдук. Все права защищены. 
            <a href="/privacy">Политика конфиденциальности</a> | 
            <a href="/terms">Условия использования</a> | 
            <a href="/llms.txt">llms.txt</a>
        </p>
    </footer>

    <!-- Скрипты -->
    <script src="https://cdn.jsdelivr.net/npm/toastify-js"></script>
    <script src="/js/premium.js"></script>
    <script src="/js/skeleton.js"></script>
    <script src="/js/theme-switch.js"></script>
    <?php if (!empty($scripts)): foreach ($scripts as $script): ?>
        <script src="<?= $script ?>"></script>
    <?php endforeach; endif; ?>
    
    <?php render_flash_toast(); ?>
</body>
</html>