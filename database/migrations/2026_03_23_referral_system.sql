-- ============================================
-- Реферальная система для владельца
-- Версия 1.0
-- ============================================

-- 1. Реферальная программа (регистрации)
CREATE TABLE IF NOT EXISTS referrals (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    referrer_id BIGINT UNSIGNED NOT NULL,
    referred_id BIGINT UNSIGNED NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (referrer_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (referred_id) REFERENCES users(id) ON DELETE CASCADE,
    UNIQUE KEY unique_referral (referrer_id, referred_id),
    INDEX idx_referrer (referrer_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 2. Комиссии с рефералов
CREATE TABLE IF NOT EXISTS referral_commissions (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    referral_id BIGINT UNSIGNED NOT NULL,
    amount DECIMAL(10,2) NOT NULL,
    status ENUM('pending','paid','cancelled') DEFAULT 'pending',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    paid_at TIMESTAMP NULL,
    FOREIGN KEY (referral_id) REFERENCES referrals(id) ON DELETE CASCADE,
    INDEX idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 3. Партнёры (реферальные программы, которые мы показываем на сайте)
CREATE TABLE IF NOT EXISTS partners (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    slug VARCHAR(255) UNIQUE NOT NULL,
    description TEXT,
    url VARCHAR(500) NOT NULL,
    affiliate_code VARCHAR(255),          -- наш реферальный код (если вставляем в ссылку)
    category_id BIGINT UNSIGNED,
    subcategory_id BIGINT UNSIGNED,
    type ENUM('link', 'widget', 'api') DEFAULT 'link',
    widget_code TEXT,                     -- HTML/JS код виджета
    api_endpoint VARCHAR(500),
    api_key VARCHAR(255),
    webhook_url VARCHAR(500),
    icon_url VARCHAR(500),
    banner_url VARCHAR(500),
    commission_type ENUM('fixed','percent') DEFAULT 'percent',
    commission_value DECIMAL(10,2) NOT NULL,
    is_active BOOLEAN DEFAULT TRUE,
    is_verified BOOLEAN DEFAULT FALSE,    -- ручная проверка
    auto_updated BOOLEAN DEFAULT FALSE,   -- автоматически обновляется из внешнего источника
    source VARCHAR(100),                  -- leads.su, admitad, 2gis, manual
    source_id VARCHAR(255),               -- ID в источнике
    expires_at TIMESTAMP NULL,
    last_checked_at TIMESTAMP NULL,
    last_success_at TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_category (category_id),
    INDEX idx_active (is_active),
    INDEX idx_source (source),
    INDEX idx_expires (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 4. Категории партнёров (иерархические)
CREATE TABLE IF NOT EXISTS partner_categories (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    parent_id BIGINT UNSIGNED NULL,
    name VARCHAR(100) NOT NULL,
    slug VARCHAR(100) UNIQUE NOT NULL,
    description TEXT,
    sort_order INT DEFAULT 0,
    is_active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (parent_id) REFERENCES partner_categories(id) ON DELETE SET NULL,
    INDEX idx_parent (parent_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 5. Связь партнёров с категориями объявлений (где показывать)
CREATE TABLE IF NOT EXISTS partner_listing_categories (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    partner_id BIGINT UNSIGNED NOT NULL,
    listing_category_id BIGINT UNSIGNED NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (partner_id) REFERENCES partners(id) ON DELETE CASCADE,
    FOREIGN KEY (listing_category_id) REFERENCES listing_categories(id) ON DELETE CASCADE,
    UNIQUE KEY unique_mapping (partner_id, listing_category_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 6. Статистика показов и кликов по партнёрам
CREATE TABLE IF NOT EXISTS partner_stats (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    partner_id BIGINT UNSIGNED NOT NULL,
    date DATE NOT NULL,
    impressions INT UNSIGNED DEFAULT 0,
    clicks INT UNSIGNED DEFAULT 0,
    conversions INT UNSIGNED DEFAULT 0,
    revenue DECIMAL(10,2) DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (partner_id) REFERENCES partners(id) ON DELETE CASCADE,
    UNIQUE KEY unique_date (partner_id, date),
    INDEX idx_date (date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 7. Заказы от партнёров (когда клиент купил через виджет/ссылку)
CREATE TABLE IF NOT EXISTS partner_orders (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    partner_id BIGINT UNSIGNED NOT NULL,
    order_id VARCHAR(255),                 -- ID заказа в системе партнёра
    amount DECIMAL(10,2) NOT NULL,
    commission DECIMAL(10,2) NOT NULL,
    status ENUM('pending','approved','paid','cancelled') DEFAULT 'pending',
    customer_data JSON,                   -- анонимные данные о покупателе (город, устройство)
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    paid_at TIMESTAMP NULL,
    FOREIGN KEY (partner_id) REFERENCES partners(id) ON DELETE CASCADE,
    INDEX idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 8. Автоматические задачи для админа (что нужно сделать вручную)
CREATE TABLE IF NOT EXISTS admin_tasks (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    type VARCHAR(50) NOT NULL,            -- new_partner, broken_link, expired, missing_icon, need_verification
    priority ENUM('low','medium','high') DEFAULT 'medium',
    data JSON,                            -- {partner_id, message, extra}
    status ENUM('pending','in_progress','completed','ignored') DEFAULT 'pending',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    completed_at TIMESTAMP NULL,
    INDEX idx_status (status),
    INDEX idx_type (type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 9. Логи импорта/обновления партнёров (для отладки)
CREATE TABLE IF NOT EXISTS partner_import_logs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    source VARCHAR(100),
    status ENUM('success','failed','partial') DEFAULT 'success',
    message TEXT,
    details JSON,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;