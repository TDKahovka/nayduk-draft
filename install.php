<?php
/* ============================================
   НАЙДУК — Полная автоматическая установка v4.0
   (март 2026)
   - Все таблицы: пользователи, объявления, категории, фото
   - Реферальная система, партнёры, CPA
   - Бизнес-кабинеты (магазины, товары, заказы)
   - AI-генерация, очереди, health
   - АУКЦИОННЫЙ МОДУЛЬ (прямые, обратные, ставки, сделки, блокировки, аудит, согласия)
   - Автоматическая настройка cron и supervisor (опционально)
   ============================================ */

define('ROOT_DIR', __DIR__);
define('INSTALL_LOCK', ROOT_DIR . '/storage/install.lock');
define('ASSETS_ICON_DIR', ROOT_DIR . '/public/assets/icons');
define('LOG_FILE', ROOT_DIR . '/storage/logs/install.log');

if (file_exists(INSTALL_LOCK)) {
    header('Location: /');
    exit;
}

function install_log($message) {
    $log = '[' . date('Y-m-d H:i:s') . '] ' . $message . PHP_EOL;
    file_put_contents(LOG_FILE, $log, FILE_APPEND | LOCK_EX);
}

// Создаём необходимые папки
$folders = [
    '/storage', '/storage/logs', '/storage/cache', '/storage/sessions', '/storage/promotions',
    '/uploads', '/uploads/import_temp', '/uploads/offers_logos', '/uploads/qrcodes',
    '/uploads/placeholder', '/public/assets', '/public/assets/icons', '/config', '/logs'
];
foreach ($folders as $folder) {
    $path = ROOT_DIR . $folder;
    if (!is_dir($path)) {
        mkdir($path, 0755, true);
        install_log("Created folder: $folder");
    }
}

// Placeholder-изображение
$placeholderDir = ROOT_DIR . '/uploads/placeholder/';
$placeholderFile = $placeholderDir . 'naiduk_smile.png';
if (!file_exists($placeholderFile)) {
    $img = imagecreatetruecolor(400, 400);
    $bg = imagecolorallocate($img, 240, 240, 245);
    $textColor = imagecolorallocate($img, 74, 144, 226);
    imagefilledrectangle($img, 0, 0, 400, 400, $bg);
    $font = ROOT_DIR . '/public/assets/fonts/arial.ttf';
    if (file_exists($font)) {
        imagettftext($img, 20, 0, 100, 200, $textColor, $font, 'Найдук');
    } else {
        imagefilledellipse($img, 200, 200, 150, 150, $textColor);
        imagefilledellipse($img, 160, 170, 20, 20, $bg);
        imagefilledellipse($img, 240, 170, 20, 20, $bg);
        imagearc($img, 200, 240, 100, 50, 0, 180, $textColor);
    }
    imagepng($img, $placeholderFile);
    imagedestroy($img);
    install_log("Placeholder image created: $placeholderFile");
}

$writable_folders = ['/storage', '/uploads', '/public/assets', '/config'];
$all_writable = true;
foreach ($writable_folders as $folder) {
    if (!is_writable(ROOT_DIR . $folder)) {
        $all_writable = false;
        break;
    }
}

$step = isset($_GET['step']) ? (int)$_GET['step'] : 1;
$error = '';
$message = '';

if ($step === 1) {
    $errors = [];
    if (version_compare(PHP_VERSION, '8.1.0', '<')) {
        $errors[] = 'Требуется PHP 8.1 или выше. У вас ' . PHP_VERSION;
    }
    $required_extensions = ['pdo_mysql', 'json', 'fileinfo', 'gd', 'curl', 'openssl'];
    foreach ($required_extensions as $ext) {
        if (!extension_loaded($ext)) {
            $errors[] = "Расширение $ext не установлено";
        }
    }
    $recommended = ['redis', 'imagick'];
    foreach ($recommended as $ext) {
        if (!extension_loaded($ext)) {
            install_log("Recommended extension $ext is missing, will use fallback");
        }
    }
    if (!$all_writable) {
        $errors[] = 'Некоторые папки недоступны для записи. Установите права 755 на storage, uploads, public/assets, config.';
    }
    if (!empty($errors)) {
        $error = implode('<br>', $errors);
    } else {
        header('Location: ?step=2');
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Установка Найдук</title>
    <style>
        * { margin:0; padding:0; box-sizing:border-box; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: #F5F5F7;
            color: #1D1D1F;
            padding: 40px;
        }
        .container {
            max-width: 700px;
            margin: 0 auto;
            background: white;
            border-radius: 28px;
            padding: 40px;
            box-shadow: 0 8px 32px rgba(0,0,0,0.1);
        }
        h1 { font-size: 28px; margin-bottom: 20px; }
        .step { color: #86868B; margin-bottom: 20px; }
        .error { background: #FFE5E5; color: #FF3B30; padding: 12px; border-radius: 12px; margin-bottom: 20px; }
        .message { background: #E5F5E5; color: #34C759; padding: 12px; border-radius: 12px; margin-bottom: 20px; }
        .form-group { margin-bottom: 20px; }
        label { display: block; font-weight: 600; margin-bottom: 6px; }
        input, textarea { width: 100%; padding: 12px; border: 1px solid #D2D2D7; border-radius: 12px; font-size: 14px; }
        button {
            background: linear-gradient(135deg, #4A90E2, #2E5C8A);
            color: white;
            border: none;
            padding: 12px 24px;
            border-radius: 9999px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
        }
        pre { background: #F5F5F7; padding: 16px; border-radius: 12px; overflow-x: auto; font-size: 12px; margin: 20px 0; }
        .info-box { background: #E8F0FE; padding: 16px; border-radius: 12px; margin: 20px 0; font-size: 14px; }
        .success-box { background: #E5F5E5; border-left: 4px solid #34C759; }
        .warning-box { background: #FFF4E5; border-left: 4px solid #FF9500; }
    </style>
</head>
<body>
<div class="container">
    <h1>🔧 Установка Найдук</h1>
    <div class="step">Шаг <?= $step ?> из 4</div>

    <?php if ($error): ?>
        <div class="error">❌ <?= htmlspecialchars($error) ?></div>
    <?php endif; ?>
    <?php if ($message): ?>
        <div class="message">✅ <?= htmlspecialchars($message) ?></div>
    <?php endif; ?>

    <?php if ($step === 1): ?>
        <div class="info-box">
            <p>✅ PHP версия: <?= PHP_VERSION ?></p>
            <p>✅ Расширения: все необходимые загружены.</p>
            <?php if (!$all_writable): ?>
                <p class="warning-box">⚠️ Некоторые папки недоступны для записи. Установите права 755 на storage, uploads, public/assets, config.</p>
            <?php else: ?>
                <p>✅ Права на папки установлены.</p>
            <?php endif; ?>
        </div>
        <a href="?step=2" style="display:inline-block; background:#4A90E2; color:white; padding:12px 24px; border-radius:9999px; text-decoration:none;">Продолжить</a>
    <?php endif; ?>

    <?php if ($step === 2): ?>
        <form method="post" action="?step=3" id="install-form">
            <h3>Настройки базы данных</h3>
            <div class="form-group">
                <label>Хост (обычно localhost)</label>
                <input type="text" name="db_host" value="localhost" required>
            </div>
            <div class="form-group">
                <label>Имя базы данных</label>
                <input type="text" name="db_name" required>
            </div>
            <div class="form-group">
                <label>Пользователь БД</label>
                <input type="text" name="db_user" required>
            </div>
            <div class="form-group">
                <label>Пароль БД</label>
                <input type="password" name="db_pass">
            </div>

            <h3 style="margin-top: 30px;">OAuth (опционально)</h3>
            <div class="info-box">
                <p>Вы можете оставить поля пустыми, вход через соцсети работать не будет.</p>
            </div>
            <div class="form-group"><label>Яндекс Client ID</label><input type="text" name="yandex_client_id"></div>
            <div class="form-group"><label>Яндекс Client Secret</label><input type="text" name="yandex_client_secret"></div>
            <div class="form-group"><label>VK Client ID</label><input type="text" name="vk_client_id"></div>
            <div class="form-group"><label>VK Client Secret</label><input type="text" name="vk_client_secret"></div>
            <div class="form-group"><label>Mail.ru Client ID</label><input type="text" name="mailru_client_id"></div>
            <div class="form-group"><label>Mail.ru Client Secret</label><input type="text" name="mailru_client_secret"></div>
            <div class="form-group"><label>Google Client ID</label><input type="text" name="google_client_id"></div>
            <div class="form-group"><label>Google Client Secret</label><input type="text" name="google_client_secret"></div>

            <div class="form-group"><label>Администратор: email</label><input type="email" name="admin_email" value="admin@nayduk.ru" required></div>
            <div class="form-group"><label>Администратор: имя</label><input type="text" name="admin_name" value="Администратор" required></div>

            <button type="submit">Начать установку</button>
        </form>
    <?php endif; ?>

    <?php if ($step === 3 && $_SERVER['REQUEST_METHOD'] === 'POST'): ?>
        <?php
        $start_time = microtime(true);
        install_log("=== Installation started ===");

        // .env
        $app_url = ($_SERVER['HTTPS'] ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'];
        $env = "APP_ENV=production\nAPP_DEBUG=false\nAPP_URL=" . $app_url . "\n";
        $env .= "APP_SECRET=" . bin2hex(random_bytes(32)) . "\n";
        $env .= "DB_HOST=" . trim($_POST['db_host']) . "\n";
        $env .= "DB_NAME=" . trim($_POST['db_name']) . "\n";
        $env .= "DB_USER=" . trim($_POST['db_user']) . "\n";
        $env .= "DB_PASS=" . trim($_POST['db_pass']) . "\n";
        $env .= "REDIS_HOST=127.0.0.1\nREDIS_PORT=6379\n";
        $env .= "YANDEX_CLIENT_ID=" . trim($_POST['yandex_client_id']) . "\n";
        $env .= "YANDEX_CLIENT_SECRET=" . trim($_POST['yandex_client_secret']) . "\n";
        $env .= "VK_CLIENT_ID=" . trim($_POST['vk_client_id']) . "\n";
        $env .= "VK_CLIENT_SECRET=" . trim($_POST['vk_client_secret']) . "\n";
        $env .= "MAILRU_CLIENT_ID=" . trim($_POST['mailru_client_id']) . "\n";
        $env .= "MAILRU_CLIENT_SECRET=" . trim($_POST['mailru_client_secret']) . "\n";
        $env .= "GOOGLE_CLIENT_ID=" . trim($_POST['google_client_id']) . "\n";
        $env .= "GOOGLE_CLIENT_SECRET=" . trim($_POST['google_client_secret']) . "\n";
        file_put_contents(ROOT_DIR . '/.env', $env);
        install_log(".env file created");

        // config/database.php
        $db_config = "<?php\nreturn [\n";
        $db_config .= "    'host' => '" . addslashes($_POST['db_host']) . "',\n";
        $db_config .= "    'database' => '" . addslashes($_POST['db_name']) . "',\n";
        $db_config .= "    'username' => '" . addslashes($_POST['db_user']) . "',\n";
        $db_config .= "    'password' => '" . addslashes($_POST['db_pass']) . "',\n";
        $db_config .= "    'charset' => 'utf8mb4',\n";
        $db_config .= "    'options' => [\n";
        $db_config .= "        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,\n";
        $db_config .= "        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,\n";
        $db_config .= "        PDO::ATTR_EMULATE_PREPARES => false,\n";
        $db_config .= "    ],\n];\n";
        file_put_contents(ROOT_DIR . '/config/database.php', $db_config);
        install_log("Database config created");

        try {
            $pdo = new PDO(
                "mysql:host={$_POST['db_host']};dbname={$_POST['db_name']}",
                $_POST['db_user'],
                $_POST['db_pass'],
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
            );
            install_log("Database connection successful");
        } catch (Exception $e) {
            $error = "Ошибка подключения к БД: " . $e->getMessage();
            install_log("Database connection failed: " . $e->getMessage());
            $step = 2;
        }

        if (!isset($error)) {
            install_log("Creating base tables (if not exist)...");

            // Проверка наличия таблиц и их создание (основные)
            $baseTables = [
                "CREATE TABLE IF NOT EXISTS users (
                    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    email VARCHAR(255) NOT NULL UNIQUE,
                    name VARCHAR(255),
                    password_hash VARCHAR(255),
                    role VARCHAR(50) DEFAULT 'user',
                    is_partner TINYINT DEFAULT 0,
                    phone VARCHAR(20),
                    phone_visible TINYINT DEFAULT 0,
                    extra_bids_balance INT UNSIGNED DEFAULT 0,
                    phone_verified TINYINT DEFAULT 0,
                    city VARCHAR(100),
                    lat DECIMAL(10,8),
                    lng DECIMAL(11,8),
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    deleted_at TIMESTAMP NULL,
                    INDEX idx_email (email),
                    INDEX idx_role (role)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
                "CREATE TABLE IF NOT EXISTS listings (
                    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    user_id BIGINT UNSIGNED NOT NULL,
                    title VARCHAR(255) NOT NULL,
                    description TEXT,
                    price DECIMAL(12,2),
                    type ENUM('sell','buy','service','resume') DEFAULT 'sell',
                    status ENUM('pending','active','sold','archived') DEFAULT 'pending',
                    city VARCHAR(100),
                    category_id BIGINT UNSIGNED,
                    phone VARCHAR(20),
                    phone_visible TINYINT DEFAULT 1,
                    views INT DEFAULT 0,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    deleted_at TIMESTAMP NULL,
                    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
                    INDEX idx_user (user_id),
                    INDEX idx_status (status),
                    INDEX idx_city (city),
                    INDEX idx_category (category_id)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
                "CREATE TABLE IF NOT EXISTS listing_categories (
                    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    name VARCHAR(255) NOT NULL,
                    slug VARCHAR(255) UNIQUE,
                    parent_id BIGINT UNSIGNED DEFAULT NULL,
                    is_active TINYINT DEFAULT 1,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
                "CREATE TABLE IF NOT EXISTS listing_photos (
                    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    listing_id BIGINT UNSIGNED NOT NULL,
                    photo_url VARCHAR(500) NOT NULL,
                    sort_order INT DEFAULT 0,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    FOREIGN KEY (listing_id) REFERENCES listings(id) ON DELETE CASCADE,
                    INDEX idx_listing (listing_id)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
            ];
            foreach ($baseTables as $sql) {
                try {
                    $pdo->exec($sql);
                } catch (Exception $e) {
                    install_log("Error creating base table: " . $e->getMessage());
                }
            }

            // ========== АУКЦИОННЫЙ МОДУЛЬ ==========
            install_log("Creating auction module tables and fields...");

            // Добавление колонок в users (если нет)
            $pdo->exec("ALTER TABLE users ADD COLUMN IF NOT EXISTS extra_bids_balance INT UNSIGNED DEFAULT 0");
            $pdo->exec("ALTER TABLE users ADD COLUMN IF NOT EXISTS phone_verified TINYINT DEFAULT 0");

            // Расширение listings для аукционов
            $pdo->exec("ALTER TABLE listings ADD COLUMN IF NOT EXISTS auction_type TINYINT DEFAULT 0");
            $pdo->exec("ALTER TABLE listings ADD COLUMN IF NOT EXISTS start_bid DECIMAL(12,2) DEFAULT NULL");
            $pdo->exec("ALTER TABLE listings ADD COLUMN IF NOT EXISTS reserve_price DECIMAL(12,2) DEFAULT NULL");
            $pdo->exec("ALTER TABLE listings ADD COLUMN IF NOT EXISTS min_bid_increment DECIMAL(12,2) DEFAULT 0");
            $pdo->exec("ALTER TABLE listings ADD COLUMN IF NOT EXISTS auction_end_at TIMESTAMP NULL");
            $pdo->exec("ALTER TABLE listings ADD COLUMN IF NOT EXISTS hidden_bids TINYINT DEFAULT 0");
            $pdo->exec("ALTER TABLE listings ADD COLUMN IF NOT EXISTS auto_offer_second TINYINT DEFAULT 1");
            $pdo->exec("ALTER TABLE listings ADD COLUMN IF NOT EXISTS listing_fee_paid TINYINT DEFAULT 0");
            $pdo->exec("ALTER TABLE listings ADD COLUMN IF NOT EXISTS winner_id BIGINT UNSIGNED NULL");
            $pdo->exec("ALTER TABLE listings ADD COLUMN IF NOT EXISTS final_price DECIMAL(12,2) NULL");
            $pdo->exec("ALTER TABLE listings ADD COLUMN IF NOT EXISTS auction_status ENUM('draft','active','payment_pending','completed','closed_no_bids','reserve_not_met','cancelled') DEFAULT 'draft'");
            $pdo->exec("ALTER TABLE listings ADD COLUMN IF NOT EXISTS enable_3d_effect TINYINT DEFAULT 0");

            // Таблица ставок
            $pdo->exec("CREATE TABLE IF NOT EXISTS auction_bids (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                listing_id BIGINT UNSIGNED NOT NULL,
                user_id BIGINT UNSIGNED NOT NULL,
                bid_price DECIMAL(12,2) NOT NULL,
                anonymous_id CHAR(4) NOT NULL,
                color_code CHAR(7) NOT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_listing (listing_id),
                INDEX idx_user (user_id),
                FOREIGN KEY (listing_id) REFERENCES listings(id) ON DELETE CASCADE,
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

            // Участники аукциона (счётчики)
            $pdo->exec("CREATE TABLE IF NOT EXISTS auction_participants (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                listing_id BIGINT UNSIGNED NOT NULL,
                user_id BIGINT UNSIGNED NOT NULL,
                free_bids_used TINYINT DEFAULT 0,
                extra_bids_used TINYINT DEFAULT 0,
                UNIQUE KEY unique_participant (listing_id, user_id),
                FOREIGN KEY (listing_id) REFERENCES listings(id) ON DELETE CASCADE,
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

            // Сделки
            $pdo->exec("CREATE TABLE IF NOT EXISTS deals (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                listing_id BIGINT UNSIGNED NOT NULL,
                seller_id BIGINT UNSIGNED NOT NULL,
                buyer_id BIGINT UNSIGNED NOT NULL,
                price DECIMAL(12,2) NOT NULL,
                seller_confirmed TINYINT DEFAULT 0,
                buyer_confirmed TINYINT DEFAULT 0,
                status ENUM('awaiting_confirmation','confirmed','disputed','cancelled') DEFAULT 'awaiting_confirmation',
                commission_charged TINYINT DEFAULT 0,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                expires_at TIMESTAMP NOT NULL,
                FOREIGN KEY (listing_id) REFERENCES listings(id),
                FOREIGN KEY (seller_id) REFERENCES users(id),
                FOREIGN KEY (buyer_id) REFERENCES users(id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

            // Предложения для обратного аукциона
            $pdo->exec("CREATE TABLE IF NOT EXISTS reverse_offers (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                listing_id BIGINT UNSIGNED NOT NULL,
                seller_id BIGINT UNSIGNED NOT NULL,
                price DECIMAL(12,2) NOT NULL,
                status ENUM('pending','accepted','declined','expired') DEFAULT 'pending',
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                expires_at TIMESTAMP NULL,
                FOREIGN KEY (listing_id) REFERENCES listings(id) ON DELETE CASCADE,
                FOREIGN KEY (seller_id) REFERENCES users(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

            // Предложения второму участнику
            $pdo->exec("CREATE TABLE IF NOT EXISTS second_offers (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                listing_id BIGINT UNSIGNED NOT NULL,
                seller_id BIGINT UNSIGNED NOT NULL,
                buyer_id BIGINT UNSIGNED NOT NULL,
                price DECIMAL(12,2) NOT NULL,
                status ENUM('pending','accepted','declined','expired') DEFAULT 'pending',
                expires_at TIMESTAMP NOT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (listing_id) REFERENCES listings(id),
                FOREIGN KEY (seller_id) REFERENCES users(id),
                FOREIGN KEY (buyer_id) REFERENCES users(id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

            // Блокировки
            $pdo->exec("CREATE TABLE IF NOT EXISTS user_blocks (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                user_id BIGINT UNSIGNED NOT NULL,
                block_type ENUM('auction') NOT NULL,
                expires_at TIMESTAMP NOT NULL,
                reason VARCHAR(255),
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
                INDEX idx_user (user_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

            // Версии правил
            $pdo->exec("CREATE TABLE IF NOT EXISTS auction_consent_versions (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                version VARCHAR(20) NOT NULL,
                full_text TEXT NOT NULL,
                hash VARCHAR(64) NOT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

            // Согласия пользователей
            $pdo->exec("CREATE TABLE IF NOT EXISTS user_auction_consents (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                user_id BIGINT UNSIGNED NOT NULL,
                consent_version VARCHAR(20) NOT NULL,
                consent_text TEXT NOT NULL,
                ip_address VARCHAR(45),
                user_agent TEXT,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
                INDEX idx_user_version (user_id, consent_version)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

            // Логи аудита
            $pdo->exec("CREATE TABLE IF NOT EXISTS audit_log (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                event_type VARCHAR(50) NOT NULL,
                user_id BIGINT UNSIGNED NULL,
                listing_id BIGINT UNSIGNED NULL,
                ip_address VARCHAR(45),
                user_agent TEXT,
                details JSON,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_event (event_type),
                INDEX idx_user (user_id),
                INDEX idx_listing (listing_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

            // Логи уведомлений
            $pdo->exec("CREATE TABLE IF NOT EXISTS notification_logs (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                user_id BIGINT UNSIGNED NOT NULL,
                type VARCHAR(50) NOT NULL,
                channel VARCHAR(20) NOT NULL,
                subject VARCHAR(255),
                content TEXT,
                status ENUM('sent','failed','read') DEFAULT 'sent',
                read_at TIMESTAMP NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (user_id) REFERENCES users(id),
                INDEX idx_user_type (user_id, type)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

            // Комиссии продавцов
            $pdo->exec("CREATE TABLE IF NOT EXISTS seller_commissions (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                seller_id BIGINT UNSIGNED NOT NULL,
                amount DECIMAL(12,2) NOT NULL,
                status ENUM('pending','paid','overdue') DEFAULT 'pending',
                due_date DATE NOT NULL,
                paid_at TIMESTAMP NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (seller_id) REFERENCES users(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

            // Начальная версия правил
            $pdo->exec("INSERT IGNORE INTO auction_consent_versions (version, full_text, hash) VALUES (
                '1.0',
                'Правила участия в аукционах на Найдук (версия 1.0 от 01.04.2026)\n\n1. Участие бесплатное, но для ставок нужен подтверждённый телефон.\n2. Сделав ставку, вы обязуетесь купить товар в случае выигрыша. Неоплата влечёт блокировку на 30 дней.\n3. Продавец обязан продать товар, если достигнута резервная цена или она не указана.\n4. Ставки нельзя отозвать.\n5. Платформа не является стороной сделки.\n6. С продавца взимается комиссия 2% (min 50, max 2000 ₽) после успешной сделки.\n7. Спорные ситуации разрешаются администрацией на основе доказательств.\n\nНажимая «Подтверждаю», вы соглашаетесь с полной офертой (ссылка).',
                SHA2('Правила участия в аукционах на Найдук (версия 1.0 от 01.04.2026)...', 256)
            )");

            install_log("Auction module created");

            // ========== ОСТАЛЬНЫЕ ТАБЛИЦЫ (рефералы, бизнес, AI, города) ==========
            install_log("Creating affiliate system tables...");

            $pdo->exec("CREATE TABLE IF NOT EXISTS affiliates (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(255) NOT NULL,
                partner_name VARCHAR(255),
                offer_name VARCHAR(255),
                slug VARCHAR(255) UNIQUE NOT NULL,
                category VARCHAR(50) NOT NULL,
                commission_type VARCHAR(20) NOT NULL DEFAULT 'cpa' CHECK (commission_type IN ('cpa','cps','cpl','recurring','fixed')),
                commission_value DECIMAL(12,2),
                currency VARCHAR(10) DEFAULT 'RUB',
                url_template TEXT,
                our_parameter VARCHAR(100),
                is_smartlink BOOLEAN DEFAULT FALSE,
                description TEXT,
                icon_url TEXT,
                categories JSON,
                city_id BIGINT UNSIGNED,
                address TEXT,
                phone VARCHAR(50),
                website TEXT,
                working_hours JSON,
                priority INT DEFAULT 0,
                expires_at TIMESTAMP NULL,
                budget DECIMAL(12,2),
                display_rule JSON,
                keywords JSON,
                source VARCHAR(50) DEFAULT 'manual',
                cpa_network VARCHAR(100),
                geo_availability VARCHAR(255),
                notes TEXT,
                is_active BOOLEAN DEFAULT TRUE,
                is_approved BOOLEAN DEFAULT FALSE,
                approved_at TIMESTAMP NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_active (is_active),
                INDEX idx_city (city_id),
                INDEX idx_category (category),
                INDEX idx_priority (priority),
                INDEX idx_slug (slug),
                INDEX idx_expires (expires_at),
                FULLTEXT INDEX idx_keywords (keywords)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

            $pdo->exec("CREATE TABLE IF NOT EXISTS affiliate_clicks (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                affiliate_id BIGINT UNSIGNED NOT NULL,
                user_id BIGINT UNSIGNED,
                click_hash VARCHAR(64) NOT NULL,
                original_click_id VARCHAR(255),
                ip VARCHAR(45),
                user_agent TEXT,
                clicked_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_click_hash (click_hash),
                INDEX idx_affiliate (affiliate_id),
                INDEX idx_clicked (clicked_at),
                FOREIGN KEY (affiliate_id) REFERENCES affiliates(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

            $pdo->exec("CREATE TABLE IF NOT EXISTS affiliate_display_log (
                id BIGINT UNSIGNED AUTO_INCREMENT,
                affiliate_id BIGINT UNSIGNED NOT NULL,
                user_id BIGINT UNSIGNED,
                city_id BIGINT UNSIGNED,
                category VARCHAR(50),
                displayed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id, displayed_at),
                FOREIGN KEY (affiliate_id) REFERENCES affiliates(id) ON DELETE CASCADE,
                INDEX idx_affiliate (affiliate_id),
                INDEX idx_category (category)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            PARTITION BY RANGE (UNIX_TIMESTAMP(displayed_at)) (
                PARTITION p202601 VALUES LESS THAN (UNIX_TIMESTAMP('2026-02-01')),
                PARTITION p202602 VALUES LESS THAN (UNIX_TIMESTAMP('2026-03-01')),
                PARTITION p202603 VALUES LESS THAN (UNIX_TIMESTAMP('2026-04-01')),
                PARTITION p_future VALUES LESS THAN MAXVALUE
            )");

            $pdo->exec("CREATE TABLE IF NOT EXISTS affiliate_import_errors (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                source VARCHAR(50) NOT NULL,
                error_message TEXT,
                raw_data JSON,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_source (source),
                INDEX idx_created (created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

            $pdo->exec("CREATE TABLE IF NOT EXISTS partner_imports (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                source VARCHAR(50) NOT NULL,
                external_id VARCHAR(255) NOT NULL,
                name VARCHAR(255),
                category VARCHAR(100),
                city VARCHAR(100),
                address TEXT,
                phone VARCHAR(50),
                website TEXT,
                lat DECIMAL(10,8),
                lng DECIMAL(11,8),
                raw_data JSON,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY unique_source_external (source, external_id),
                INDEX idx_source (source),
                INDEX idx_city (city)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

            $pdo->exec("CREATE TABLE IF NOT EXISTS referrals (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                referrer_id BIGINT UNSIGNED NOT NULL,
                referred_id BIGINT UNSIGNED NOT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (referrer_id) REFERENCES users(id) ON DELETE CASCADE,
                FOREIGN KEY (referred_id) REFERENCES users(id) ON DELETE CASCADE,
                UNIQUE KEY unique_referral (referrer_id, referred_id),
                INDEX idx_referrer (referrer_id),
                INDEX idx_referred (referred_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

            $pdo->exec("CREATE TABLE IF NOT EXISTS referral_commissions (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                referral_id BIGINT UNSIGNED NOT NULL,
                amount DECIMAL(12,2) NOT NULL,
                status ENUM('pending','paid','cancelled') DEFAULT 'pending',
                paid_at TIMESTAMP NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (referral_id) REFERENCES referrals(id) ON DELETE CASCADE,
                INDEX idx_referral (referral_id),
                INDEX idx_status (status)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

            $pdo->exec("CREATE TABLE IF NOT EXISTS admin_tasks (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                type VARCHAR(50) NOT NULL,
                data JSON,
                status ENUM('pending','in_progress','completed','ignored') DEFAULT 'pending',
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                resolved_at TIMESTAMP NULL,
                INDEX idx_status (status),
                INDEX idx_type (type)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

            // ===== owner_settings =====
            $pdo->exec("CREATE TABLE IF NOT EXISTS owner_settings (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                key_name VARCHAR(255) UNIQUE NOT NULL,
                value TEXT,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            $defaultKeys = [
                '2gis_api_key'      => '',
                'admitad_api_key'   => '',
                'advcake_api_key'   => '',
                'deepseek_api_key'  => '',
                'leads_su_api_key'  => '',
                'actionpay_api_key' => '',
                'hmac_secret'       => bin2hex(random_bytes(32)),
            ];
            $stmt = $pdo->prepare("INSERT INTO owner_settings (key_name, value) VALUES (?, ?) ON DUPLICATE KEY UPDATE value = VALUES(value)");
            foreach ($defaultKeys as $key => $value) {
                $stmt->execute([$key, $value]);
            }
            install_log("Owner settings created");

            // ===== БИЗНЕС-КАБИНЕТ (МАГАЗИНЫ) =====
            install_log("Creating business/shop tables...");

            $pdo->exec("CREATE TABLE IF NOT EXISTS shops (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                user_id BIGINT UNSIGNED NOT NULL,
                name VARCHAR(255) NOT NULL,
                slug VARCHAR(255) UNIQUE NOT NULL,
                description TEXT,
                logo_url TEXT,
                banner_url TEXT,
                address TEXT,
                contact_phone VARCHAR(50),
                contact_email VARCHAR(100),
                contact_telegram VARCHAR(100),
                contact_whatsapp VARCHAR(100),
                contact_instagram VARCHAR(100),
                contact_youtube VARCHAR(100),
                plan VARCHAR(50) DEFAULT 'free',
                plan_expires_at TIMESTAMP NULL,
                trial_used BOOLEAN DEFAULT FALSE,
                theme VARCHAR(50) DEFAULT 'light',
                layout JSON,
                faq JSON,
                seo_title VARCHAR(255),
                seo_description TEXT,
                seo_keywords VARCHAR(255),
                deepseek_api_key VARCHAR(255),
                storage_limit_mb INT DEFAULT 200,
                storage_used_mb DECIMAL(10,2) DEFAULT 0,
                is_active BOOLEAN DEFAULT TRUE,
                setup_complete BOOLEAN DEFAULT FALSE,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
                INDEX idx_user (user_id),
                INDEX idx_slug (slug),
                INDEX idx_plan (plan),
                INDEX idx_active (is_active)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

            $pdo->exec("CREATE TABLE IF NOT EXISTS shop_products (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                shop_id BIGINT UNSIGNED NOT NULL,
                sku VARCHAR(100),
                name VARCHAR(255) NOT NULL,
                description TEXT,
                price DECIMAL(12,2) NOT NULL,
                old_price DECIMAL(12,2),
                quantity INT DEFAULT 0,
                category VARCHAR(100),
                options JSON,
                is_active BOOLEAN DEFAULT TRUE,
                views INT DEFAULT 0,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                FOREIGN KEY (shop_id) REFERENCES shops(id) ON DELETE CASCADE,
                INDEX idx_shop (shop_id),
                INDEX idx_active (is_active),
                INDEX idx_category (category)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

            $pdo->exec("CREATE TABLE IF NOT EXISTS product_photos (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                product_id BIGINT UNSIGNED NOT NULL,
                url VARCHAR(500) NOT NULL,
                sort_order INT DEFAULT 0,
                size_mb DECIMAL(10,2) DEFAULT 0,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (product_id) REFERENCES shop_products(id) ON DELETE CASCADE,
                INDEX idx_product (product_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

            $pdo->exec("CREATE TABLE IF NOT EXISTS shop_orders (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                shop_id BIGINT UNSIGNED NOT NULL,
                user_id BIGINT UNSIGNED,
                total DECIMAL(12,2) NOT NULL,
                status VARCHAR(50) DEFAULT 'pending',
                payment_id VARCHAR(100),
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                FOREIGN KEY (shop_id) REFERENCES shops(id) ON DELETE CASCADE,
                INDEX idx_shop (shop_id),
                INDEX idx_user (user_id),
                INDEX idx_status (status)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

            $pdo->exec("CREATE TABLE IF NOT EXISTS payment_orders (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                user_id BIGINT UNSIGNED NOT NULL,
                type VARCHAR(50) NOT NULL,
                amount DECIMAL(12,2) NOT NULL,
                status VARCHAR(50) DEFAULT 'pending',
                data JSON,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                paid_at TIMESTAMP NULL,
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
                INDEX idx_user (user_id),
                INDEX idx_status (status)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

            $pdo->exec("CREATE TABLE IF NOT EXISTS storage_usage (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                shop_id BIGINT UNSIGNED NOT NULL,
                file_path VARCHAR(500),
                size_mb DECIMAL(10,2),
                recorded_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (shop_id) REFERENCES shops(id) ON DELETE CASCADE,
                INDEX idx_shop (shop_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

            install_log("Business/shop tables created");

            // ===== ГОРОДА =====
            $pdo->exec("CREATE TABLE IF NOT EXISTS russian_cities (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                city_name VARCHAR(255) NOT NULL,
                region_name VARCHAR(255),
                federal_district VARCHAR(100),
                population INT,
                latitude DECIMAL(10,8),
                longitude DECIMAL(11,8),
                is_active BOOLEAN DEFAULT TRUE,
                INDEX idx_name (city_name),
                INDEX idx_region (region_name),
                INDEX idx_coords (latitude, longitude)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            install_log("Russian cities table created");

            // ===== AI =====
            install_log("Creating AI-related tables...");
            $ai_tables = [
                "CREATE TABLE IF NOT EXISTS ai_generation_jobs (
                    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    job_id VARCHAR(64) UNIQUE NOT NULL,
                    listing_id BIGINT UNSIGNED,
                    generator VARCHAR(50) NOT NULL,
                    prompt TEXT NOT NULL,
                    status ENUM('pending', 'processing', 'completed', 'failed') DEFAULT 'pending',
                    result_path TEXT,
                    error TEXT,
                    attempts TINYINT UNSIGNED DEFAULT 0,
                    idempotency_key VARCHAR(128) UNIQUE,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    started_at TIMESTAMP NULL,
                    finished_at TIMESTAMP NULL,
                    FOREIGN KEY (listing_id) REFERENCES listings(id) ON DELETE SET NULL,
                    INDEX idx_status (status),
                    INDEX idx_idempotency (idempotency_key),
                    INDEX idx_created (created_at)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
                "CREATE TABLE IF NOT EXISTS ai_api_keys (
                    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    provider VARCHAR(50) NOT NULL UNIQUE,
                    api_key TEXT NOT NULL,
                    is_active BOOLEAN DEFAULT TRUE,
                    daily_limit INT DEFAULT 0,
                    used_today INT DEFAULT 0,
                    last_used_at TIMESTAMP NULL,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    INDEX idx_provider (provider),
                    INDEX idx_active (is_active)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
                "CREATE TABLE IF NOT EXISTS ai_generation_logs (
                    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    job_id VARCHAR(64) NOT NULL,
                    provider VARCHAR(50),
                    event VARCHAR(100),
                    details JSON,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    INDEX idx_job (job_id)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
                "CREATE TABLE IF NOT EXISTS rate_limits (
                    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    key_name VARCHAR(255) NOT NULL,
                    count INT UNSIGNED NOT NULL DEFAULT 0,
                    expires_at TIMESTAMP NOT NULL,
                    INDEX idx_key (key_name),
                    INDEX idx_expires (expires_at)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
                "CREATE TABLE IF NOT EXISTS system_health (
                    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    metric VARCHAR(100) NOT NULL,
                    value DECIMAL(12,2),
                    recorded_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    INDEX idx_metric (metric),
                    INDEX idx_recorded (recorded_at)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
            ];
            foreach ($ai_tables as $sql) {
                try {
                    $pdo->exec($sql);
                } catch (Exception $e) {
                    install_log("Error creating AI table: " . $e->getMessage());
                }
            }
            install_log("AI tables created");

            // ===== АДМИНИСТРАТОР =====
            $admin_email = trim($_POST['admin_email']);
            $admin_name = trim($_POST['admin_name']);
            $admin_password = bin2hex(random_bytes(4));
            $admin_hash = password_hash($admin_password, PASSWORD_DEFAULT);
            $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ?");
            $stmt->execute([$admin_email]);
            if (!$stmt->fetch()) {
                $pdo->prepare("INSERT INTO users (email, name, password_hash, role, is_partner, created_at, updated_at) VALUES (?, ?, ?, 'admin', 1, NOW(), NOW())")->execute([$admin_email, $admin_name, $admin_hash]);
            } else {
                $pdo->prepare("UPDATE users SET password_hash = ?, role = 'admin', is_partner = 1 WHERE email = ?")->execute([$admin_hash, $admin_email]);
            }
            install_log("Admin user created: $admin_email");

            // ===== OAuth ИКОНКИ =====
            install_log("Downloading OAuth icons...");
            $icons = [
                'ya.svg' => 'https://upload.wikimedia.org/wikipedia/commons/5/51/Yandex_icon.svg',
                'vk.svg'  => 'https://upload.wikimedia.org/wikipedia/commons/2/21/VK.com_logo.svg',
                'mail.svg' => 'https://upload.wikimedia.org/wikipedia/commons/5/5f/Mail.ru_Logo_2019.svg',
                'g.svg'   => 'https://upload.wikimedia.org/wikipedia/commons/5/53/Google_%22G%22_Logo.svg'
            ];
            if (!is_dir(ASSETS_ICON_DIR)) mkdir(ASSETS_ICON_DIR, 0755, true);
            foreach ($icons as $filename => $url) {
                $target = ASSETS_ICON_DIR . '/' . $filename;
                if (!file_exists($target)) {
                    $content = @file_get_contents($url);
                    if ($content) {
                        file_put_contents($target, $content);
                    } else {
                        $fallback = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 32 32"><circle cx="16" cy="16" r="16" fill="#ccc"/><text x="16" y="22" text-anchor="middle" fill="white" font-size="14">' . strtoupper(pathinfo($filename, PATHINFO_FILENAME)) . '</text></svg>';
                        file_put_contents($target, $fallback);
                    }
                    install_log("Icon: $filename");
                }
            }

            // ===== CRON =====
            $cron_jobs = [
                "0 2 * * * php " . ROOT_DIR . "/workers/cpa_import.php >> " . ROOT_DIR . "/storage/logs/cpa_import.log 2>&1",
                "0 3 * * 0 php " . ROOT_DIR . "/workers/2gis_import.php >> " . ROOT_DIR . "/storage/logs/2gis_import.log 2>&1",
                "* * * * * php " . ROOT_DIR . "/workers/webhook_sender_launcher.php >> " . ROOT_DIR . "/storage/logs/webhook_launcher.log 2>&1",
                "0 4 * * * php " . ROOT_DIR . "/workers/cleanup.php >> " . ROOT_DIR . "/storage/logs/cleanup.log 2>&1",
                "* * * * * php " . ROOT_DIR . "/workers/process_promotion_impressions.php >> " . ROOT_DIR . "/storage/logs/promotion_worker.log 2>&1",
                "*/10 * * * * php " . ROOT_DIR . "/workers/auto_boost.php >> " . ROOT_DIR . "/storage/logs/auto_boost.log 2>&1",
                "0 0 * * * php " . ROOT_DIR . "/workers/update_listing_views.php >> " . ROOT_DIR . "/storage/logs/update_views.log 2>&1",
                "*/5 * * * * php " . ROOT_DIR . "/workers/queue_monitor.php >> " . ROOT_DIR . "/storage/logs/queue_monitor.log 2>&1",
                "0 3 * * * php " . ROOT_DIR . "/workers/cleanup_reviews.php >> " . ROOT_DIR . "/storage/logs/cleanup_reviews.log 2>&1",
                "0 5 * * * php " . ROOT_DIR . "/workers/import_2gis.php >> " . ROOT_DIR . "/storage/logs/2gis_import.log 2>&1",
                "0 4 * * * php " . ROOT_DIR . "/workers/import_cpa.php >> " . ROOT_DIR . "/storage/logs/cpa_import.log 2>&1",
                "0 6 * * * php " . ROOT_DIR . "/workers/check_affiliates.php >> " . ROOT_DIR . "/storage/logs/check_affiliates.log 2>&1",
                "0 7 * * * php " . ROOT_DIR . "/workers/generate_affiliate_content.php >> " . ROOT_DIR . "/storage/logs/affiliate_content.log 2>&1",
                "0 0 1 * * php " . ROOT_DIR . "/workers/referral_payout.php >> " . ROOT_DIR . "/storage/logs/referral_payout.log 2>&1"
            ];
            $cron_installed = false;
            if (function_exists('exec') && !ini_get('safe_mode')) {
                $cron_output = [];
                exec('crontab -l 2>/dev/null', $cron_output, $cron_return);
                $current_crontab = implode("\n", $cron_output);
                $new_crontab = $current_crontab;
                foreach ($cron_jobs as $job) {
                    if (strpos($current_crontab, $job) === false) {
                        $new_crontab .= "\n" . $job;
                    }
                }
                if ($new_crontab !== $current_crontab) {
                    exec('echo "' . addslashes($new_crontab) . '" | crontab -', $out, $ret);
                    if ($ret === 0) {
                        $cron_installed = true;
                        install_log("Cron jobs added successfully");
                    } else {
                        install_log("Failed to add cron jobs automatically");
                    }
                } else {
                    $cron_installed = true;
                }
            }

            // ===== SUPERVISOR =====
            $supervisor_conf = "[program:nayduk-worker]\n";
            $supervisor_conf .= "command=php " . ROOT_DIR . "/workers/worker_import.php\n";
            $supervisor_conf .= "directory=" . ROOT_DIR . "\n";
            $supervisor_conf .= "user=www-data\n";
            $supervisor_conf .= "autostart=true\n";
            $supervisor_conf .= "autorestart=true\n";
            $supervisor_conf .= "stderr_logfile=/var/log/supervisor/nayduk-worker.err.log\n";
            $supervisor_conf .= "stdout_logfile=/var/log/supervisor/nayduk-worker.out.log\n";
            $supervisor_conf .= "environment=ENVIRONMENT=production\n\n";

            $supervisor_conf .= "[program:nayduk-promotion-worker]\n";
            $supervisor_conf .= "command=php " . ROOT_DIR . "/workers/process_promotion_impressions.php\n";
            $supervisor_conf .= "directory=" . ROOT_DIR . "\n";
            $supervisor_conf .= "user=www-data\n";
            $supervisor_conf .= "autostart=true\n";
            $supervisor_conf .= "autorestart=true\n";
            $supervisor_conf .= "stderr_logfile=/var/log/supervisor/promotion-worker.err.log\n";
            $supervisor_conf .= "stdout_logfile=/var/log/supervisor/promotion-worker.out.log\n";
            $supervisor_conf .= "environment=ENVIRONMENT=production\n\n";

            $supervisor_conf .= "[program:ai_worker]\n";
            $supervisor_conf .= "command=php " . ROOT_DIR . "/workers/ai_worker.php\n";
            $supervisor_conf .= "directory=" . ROOT_DIR . "\n";
            $supervisor_conf .= "user=www-data\n";
            $supervisor_conf .= "autostart=true\n";
            $supervisor_conf .= "autorestart=true\n";
            $supervisor_conf .= "startretries=3\n";
            $supervisor_conf .= "stderr_logfile=/var/log/supervisor/ai_worker.err.log\n";
            $supervisor_conf .= "stdout_logfile=/var/log/supervisor/ai_worker.out.log\n";
            $supervisor_conf .= "environment=ENVIRONMENT=production\n\n";

            $supervisor_conf .= "[program:rating_recalc_worker]\n";
            $supervisor_conf .= "command=php " . ROOT_DIR . "/workers/rating_recalc_worker.php\n";
            $supervisor_conf .= "directory=" . ROOT_DIR . "\n";
            $supervisor_conf .= "user=www-data\n";
            $supervisor_conf .= "autostart=true\n";
            $supervisor_conf .= "autorestart=true\n";
            $supervisor_conf .= "startretries=3\n";
            $supervisor_conf .= "stderr_logfile=/var/log/supervisor/rating_recalc_worker.err.log\n";
            $supervisor_conf .= "stdout_logfile=/var/log/supervisor/rating_recalc_worker.out.log\n";
            $supervisor_conf .= "environment=ENVIRONMENT=production\n\n";

            $supervisor_conf .= "[program:import_2gis]\n";
            $supervisor_conf .= "command=php " . ROOT_DIR . "/workers/import_2gis.php\n";
            $supervisor_conf .= "directory=" . ROOT_DIR . "\n";
            $supervisor_conf .= "user=www-data\n";
            $supervisor_conf .= "autostart=true\n";
            $supervisor_conf .= "autorestart=true\n";
            $supervisor_conf .= "startretries=3\n";
            $supervisor_conf .= "stderr_logfile=/var/log/supervisor/import_2gis.err.log\n";
            $supervisor_conf .= "stdout_logfile=/var/log/supervisor/import_2gis.out.log\n";
            $supervisor_conf .= "environment=ENVIRONMENT=production\n\n";

            $supervisor_conf .= "[program:import_cpa]\n";
            $supervisor_conf .= "command=php " . ROOT_DIR . "/workers/import_cpa.php\n";
            $supervisor_conf .= "directory=" . ROOT_DIR . "\n";
            $supervisor_conf .= "user=www-data\n";
            $supervisor_conf .= "autostart=true\n";
            $supervisor_conf .= "autorestart=true\n";
            $supervisor_conf .= "startretries=3\n";
            $supervisor_conf .= "stderr_logfile=/var/log/supervisor/import_cpa.err.log\n";
            $supervisor_conf .= "stdout_logfile=/var/log/supervisor/import_cpa.out.log\n";
            $supervisor_conf .= "environment=ENVIRONMENT=production\n\n";

            $supervisor_conf .= "[program:check_affiliates]\n";
            $supervisor_conf .= "command=php " . ROOT_DIR . "/workers/check_affiliates.php\n";
            $supervisor_conf .= "directory=" . ROOT_DIR . "\n";
            $supervisor_conf .= "user=www-data\n";
            $supervisor_conf .= "autostart=true\n";
            $supervisor_conf .= "autorestart=true\n";
            $supervisor_conf .= "startretries=3\n";
            $supervisor_conf .= "stderr_logfile=/var/log/supervisor/check_affiliates.err.log\n";
            $supervisor_conf .= "stdout_logfile=/var/log/supervisor/check_affiliates.out.log\n";
            $supervisor_conf .= "environment=ENVIRONMENT=production\n\n";

            $supervisor_conf .= "[program:generate_affiliate_content]\n";
            $supervisor_conf .= "command=php " . ROOT_DIR . "/workers/generate_affiliate_content.php\n";
            $supervisor_conf .= "directory=" . ROOT_DIR . "\n";
            $supervisor_conf .= "user=www-data\n";
            $supervisor_conf .= "autostart=true\n";
            $supervisor_conf .= "autorestart=true\n";
            $supervisor_conf .= "startretries=3\n";
            $supervisor_conf .= "stderr_logfile=/var/log/supervisor/generate_affiliate_content.err.log\n";
            $supervisor_conf .= "stdout_logfile=/var/log/supervisor/generate_affiliate_content.out.log\n";
            $supervisor_conf .= "environment=ENVIRONMENT=production\n";

            $supervisor_file = '/etc/supervisor/conf.d/nayduk-worker.conf';
            if (is_writable(dirname($supervisor_file))) {
                file_put_contents($supervisor_file, $supervisor_conf);
                install_log("Supervisor config saved to $supervisor_file");
                exec('supervisorctl reread && supervisorctl update', $out, $ret);
                if ($ret === 0) install_log("Supervisor reloaded");
            } else {
                install_log("Supervisor config not writable, will display instructions");
            }

            // ===== HEALTH.PHP =====
            $health = '<?php
header("Content-Type: application/json");
$results = ["status" => "ok", "checks" => []];
$results["checks"]["php_version"] = ["ok" => version_compare(PHP_VERSION, "8.1.0", ">="), "value" => PHP_VERSION];
$db = @new PDO("mysql:host=" . ($_ENV["DB_HOST"] ?? "localhost") . ";dbname=" . ($_ENV["DB_NAME"] ?? "nayduk"), $_ENV["DB_USER"] ?? "root", $_ENV["DB_PASS"] ?? "");
$results["checks"]["database"] = ["ok" => (bool)$db, "value" => $db ? "connected" : "failed"];
$redis = @new Redis();
$redis_ok = $redis->connect("127.0.0.1", 6379);
$results["checks"]["redis"] = ["ok" => $redis_ok, "value" => $redis_ok ? "connected" : "not available (performance may degrade)"];
$folders = ["storage", "uploads", "public/assets"];
foreach ($folders as $folder) {
    $writable = is_writable(__DIR__ . "/" . $folder);
    $results["checks"]["folder_$folder"] = ["ok" => $writable, "value" => $writable ? "writable" : "not writable"];
}
$promo_log = __DIR__ . "/storage/logs/promotion_worker.log";
$worker_ok = file_exists($promo_log) && (time() - filemtime($promo_log) < 3600);
$results["checks"]["promotion_worker"] = ["ok" => $worker_ok, "value" => $worker_ok ? "active" : "no activity in last hour"];

$ai_log = __DIR__ . "/storage/logs/ai_worker.log";
$ai_worker_ok = file_exists($ai_log) && (time() - filemtime($ai_log) < 3600);
$results["checks"]["ai_worker"] = ["ok" => $ai_worker_ok, "value" => $ai_worker_ok ? "active" : "no activity in last hour"];

$rating_log = __DIR__ . "/storage/logs/rating_worker.log";
$rating_worker_ok = file_exists($rating_log) && (time() - filemtime($rating_log) < 3600);
$results["checks"]["rating_worker"] = ["ok" => $rating_worker_ok, "value" => $rating_worker_ok ? "active" : "no activity in last hour"];

$results["timestamp"] = date("Y-m-d H:i:s");
echo json_encode($results, JSON_PRETTY_PRINT);
';
            file_put_contents(ROOT_DIR . '/public/health.php', $health);
            install_log("Health check script created");

            file_put_contents(INSTALL_LOCK, date('Y-m-d H:i:s'));
            install_log("Installation completed in " . round(microtime(true) - $start_time, 2) . "s");

            $message = "Установка завершена успешно!";
            $step = 4;
        }
        ?>
        <?php if (isset($error)): ?>
            <div class="error">❌ <?= htmlspecialchars($error) ?></div>
            <a href="?step=2" class="button">Вернуться к настройкам</a>
        <?php endif; ?>
    <?php endif; ?>

    <?php if ($step === 4): ?>
        <div class="message success-box">✅ Установка завершена!</div>
        <div class="info-box">
            <p><strong>Администратор:</strong> <?= htmlspecialchars($admin_email) ?></p>
            <p><strong>Пароль:</strong> <code><?= htmlspecialchars($admin_password) ?></code></p>
            <p class="warning-box">⚠️ <strong>Сохраните пароль!</strong></p>
        </div>
        <p>🔗 <a href="/" target="_blank">Перейти на сайт</a> | <a href="/admin" target="_blank">Админка</a></p>
        <?php if (!$cron_installed): ?>
        <div class="warning-box">
            <p>⚠️ Не удалось автоматически добавить задания в cron. Добавьте вручную:</p>
            <pre><?php foreach ($cron_jobs as $job) echo $job . "\n"; ?></pre>
        </div>
        <?php endif; ?>
        <?php if (!file_exists('/etc/supervisor/conf.d/nayduk-worker.conf')): ?>
        <div class="warning-box">
            <p>⚠️ Supervisor не настроен. Для фоновой обработки очередей создайте конфиг:</p>
            <pre><?= $supervisor_conf ?></pre>
            <p>Затем выполните: <code>sudo supervisorctl reread && sudo supervisorctl update</code></p>
        </div>
        <?php endif; ?>
        <div class="info-box"><p>📊 <a href="/health.php" target="_blank">Проверить состояние системы</a></p></div>
        <a href="/" style="display:inline-block; background:#4A90E2; color:white; padding:12px 24px; border-radius:9999px; text-decoration:none; margin-top:20px;">На главную</a>
    <?php endif; ?>
</div>
</body>
</html>