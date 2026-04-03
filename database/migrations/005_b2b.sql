-- ============================================
   НАЙДУК — B2B таблицы (аукцион, цены, отношения)
   Версия: 2026_03_20
   ============================================

-- Предложения покупателей (обратный аукцион)
CREATE TABLE IF NOT EXISTS price_offers (
    id BIGSERIAL PRIMARY KEY,
    listing_id BIGINT NOT NULL REFERENCES partner_offers(id) ON DELETE CASCADE,
    user_id BIGINT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    discount_percent INT NOT NULL CHECK (discount_percent BETWEEN 1 AND 30),
    proposed_price DECIMAL(12,2) NOT NULL,
    status VARCHAR(20) DEFAULT 'active' CHECK (status IN ('active', 'accepted', 'expired', 'cancelled')),
    created_at TIMESTAMPTZ DEFAULT NOW(),
    updated_at TIMESTAMPTZ DEFAULT NOW(),
    UNIQUE(listing_id, user_id)
);

CREATE INDEX idx_price_offers_listing ON price_offers(listing_id);
CREATE INDEX idx_price_offers_user ON price_offers(user_id);

-- Индивидуальные цены для покупателей
CREATE TABLE IF NOT EXISTS customer_prices (
    id BIGSERIAL PRIMARY KEY,
    user_id BIGINT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    listing_id BIGINT NOT NULL REFERENCES partner_offers(id) ON DELETE CASCADE,
    price DECIMAL(12,2) NOT NULL,
    created_at TIMESTAMPTZ DEFAULT NOW(),
    updated_at TIMESTAMPTZ DEFAULT NOW(),
    UNIQUE(user_id, listing_id)
);

CREATE INDEX idx_customer_prices_user ON customer_prices(user_id);
CREATE INDEX idx_customer_prices_listing ON customer_prices(listing_id);

-- Отношения продавец-покупатель (закрытые кабинеты)
CREATE TABLE IF NOT EXISTS supplier_buyer_relations (
    id BIGSERIAL PRIMARY KEY,
    supplier_id BIGINT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    buyer_id BIGINT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    approved_at TIMESTAMPTZ DEFAULT NOW(),
    expires_at TIMESTAMPTZ,
    created_at TIMESTAMPTZ DEFAULT NOW(),
    UNIQUE(supplier_id, buyer_id)
);

CREATE INDEX idx_supplier_buyer_supplier ON supplier_buyer_relations(supplier_id);
CREATE INDEX idx_supplier_buyer_buyer ON supplier_buyer_relations(buyer_id);

-- Логи импорта прайс-листов
CREATE TABLE IF NOT EXISTS price_import_logs (
    id BIGSERIAL PRIMARY KEY,
    user_id BIGINT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    file_name VARCHAR(255),
    rows_imported INT,
    rows_failed INT,
    errors TEXT,
    created_at TIMESTAMPTZ DEFAULT NOW()
);