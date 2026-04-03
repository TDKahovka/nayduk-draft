-- ============================================
   НАЙДУК — Таблица users (расширенная)
   Версия: 2026_03_20
   ============================================

CREATE TABLE IF NOT EXISTS users (
    id BIGSERIAL PRIMARY KEY,
    name VARCHAR(255),
    email VARCHAR(255) UNIQUE NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    phone VARCHAR(50),
    role VARCHAR(50) DEFAULT 'user' CHECK (role IN ('user', 'admin')),
    is_partner BOOLEAN DEFAULT FALSE,
    is_2fa_enabled BOOLEAN DEFAULT FALSE,
    is_active BOOLEAN DEFAULT TRUE,
    
    -- B2B поля
    company_name VARCHAR(255),
    tax_id VARCHAR(100),
    legal_address TEXT,
    
    -- Настройки
    notification_settings JSONB DEFAULT '{}',
    notification_email VARCHAR(255),
    preferred_currency VARCHAR(10) DEFAULT 'RUB',
    payout_method VARCHAR(50),
    payout_details JSONB,
    min_payout DECIMAL(12,2) DEFAULT 1000,
    saved_payment_method_id VARCHAR(255),
    
    -- Telegram
    telegram_chat_id VARCHAR(100),
    telegram_notifications BOOLEAN DEFAULT FALSE,
    
    -- API ключи (для внешних интеграций)
    api_keys JSONB DEFAULT '[]',
    
    -- Мета
    created_at TIMESTAMPTZ DEFAULT NOW(),
    updated_at TIMESTAMPTZ DEFAULT NOW()
);

CREATE INDEX idx_users_email ON users(email);
CREATE INDEX idx_users_role ON users(role);
CREATE INDEX idx_users_partner ON users(is_partner);