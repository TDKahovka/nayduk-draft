-- ============================================
   НАЙДУК — Таблица партнёрских офферов
   Версия: 2026_03_20
   ============================================

CREATE TABLE IF NOT EXISTS partner_offers (
    id BIGSERIAL PRIMARY KEY,
    owner_id BIGINT REFERENCES users(id) ON DELETE CASCADE,
    is_admin_offer BOOLEAN DEFAULT FALSE,
    
    -- Основная информация
    category VARCHAR(50) NOT NULL,
    partner_name VARCHAR(255) NOT NULL,
    offer_name VARCHAR(255) NOT NULL,
    description TEXT,
    
    -- Комиссия
    commission_type VARCHAR(20) NOT NULL CHECK (commission_type IN ('cpa', 'cpl', 'cps', 'recurring', 'fixed', 'percent')),
    commission_value DECIMAL(12,2),
    currency VARCHAR(10) DEFAULT 'RUB',
    
    -- Ссылка и параметры
    url_template TEXT,
    our_parameter VARCHAR(100),
    is_smartlink BOOLEAN DEFAULT FALSE,
    
    -- Для CPA-сетей
    cpa_network_id BIGINT,
    cpa_offer_id VARCHAR(255),
    
    -- Для локального бизнеса
    city_id BIGINT REFERENCES russian_cities(id) ON DELETE SET NULL,
    address TEXT,
    phone VARCHAR(50),
    website TEXT,
    working_hours JSONB,
    geo_coordinates POINT,
    logo_url TEXT,
    
    -- B2B расширения
    price_visibility VARCHAR(20) DEFAULT 'public', -- public, registered, private, request
    wholesale_prices JSONB, -- [{min_qty:10, price:500}, ...]
    price_on_request BOOLEAN DEFAULT FALSE,
    quantity INT DEFAULT 0,
    quantity_unit VARCHAR(20) DEFAULT 'шт',
    low_stock_threshold INT DEFAULT 0,
    allow_backorder BOOLEAN DEFAULT FALSE,
    
    -- Статусы
    is_active BOOLEAN DEFAULT TRUE,
    is_approved BOOLEAN DEFAULT FALSE,
    approved_at TIMESTAMPTZ,
    is_featured BOOLEAN DEFAULT FALSE,
    featured_until TIMESTAMPTZ,
    priority INT DEFAULT 0,
    
    -- SEO
    seo_url VARCHAR(255) UNIQUE,
    meta_title VARCHAR(255),
    meta_description TEXT,
    
    -- Внешние идентификаторы
    external_id VARCHAR(255),
    source VARCHAR(50),
    rating DECIMAL(3,2),
    reviews_count INT DEFAULT 0,
    
    -- Метки времени
    created_at TIMESTAMPTZ DEFAULT NOW(),
    updated_at TIMESTAMPTZ DEFAULT NOW()
);

CREATE INDEX idx_partner_offers_category ON partner_offers(category);
CREATE INDEX idx_partner_offers_city ON partner_offers(city_id);
CREATE INDEX idx_partner_offers_owner ON partner_offers(owner_id);
CREATE INDEX idx_partner_offers_active ON partner_offers(is_active);
CREATE INDEX idx_partner_offers_approved ON partner_offers(is_approved);
CREATE INDEX idx_partner_offers_seo ON partner_offers(seo_url);
CREATE INDEX idx_partner_offers_source ON partner_offers(source);