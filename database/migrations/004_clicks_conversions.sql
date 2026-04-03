-- ============================================
   НАЙДУК — Таблицы кликов и конверсий
   Версия: 2026_03_20
   ============================================

-- Клики по партнёрским ссылкам
CREATE TABLE IF NOT EXISTS partner_clicks (
    id BIGSERIAL PRIMARY KEY,
    click_id VARCHAR(100) UNIQUE NOT NULL,
    offer_id BIGINT NOT NULL REFERENCES partner_offers(id) ON DELETE CASCADE,
    click_owner_id BIGINT REFERENCES users(id) ON DELETE CASCADE,
    clicker_user_id BIGINT REFERENCES users(id) ON DELETE SET NULL,
    ip VARCHAR(45),
    user_agent TEXT,
    country_code CHAR(2),
    city VARCHAR(255),
    clicked_at TIMESTAMPTZ DEFAULT NOW()
);

CREATE INDEX idx_clicks_offer ON partner_clicks(offer_id);
CREATE INDEX idx_clicks_clicked ON partner_clicks(clicked_at);
CREATE INDEX idx_clicks_click_id ON partner_clicks(click_id);

-- Конверсии (доход)
CREATE TABLE IF NOT EXISTS partner_conversions (
    id BIGSERIAL PRIMARY KEY,
    click_id VARCHAR(100) NOT NULL REFERENCES partner_clicks(click_id) ON DELETE CASCADE,
    conversion_id VARCHAR(255) UNIQUE NOT NULL,
    amount DECIMAL(12,2),
    commission DECIMAL(12,2),
    currency VARCHAR(10) DEFAULT 'RUB',
    status VARCHAR(50) DEFAULT 'pending' CHECK (status IN ('pending', 'approved', 'rejected', 'paid')),
    converted_at TIMESTAMPTZ,
    payload JSONB,
    created_at TIMESTAMPTZ DEFAULT NOW()
);

CREATE INDEX idx_conversions_click ON partner_conversions(click_id);
CREATE INDEX idx_conversions_status ON partner_conversions(status);