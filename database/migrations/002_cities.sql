-- ============================================
   НАЙДУК — Таблица городов России
   Версия: 2026_03_20
   ============================================

CREATE TABLE IF NOT EXISTS russian_cities (
    id BIGSERIAL PRIMARY KEY,
    city_name VARCHAR(255) NOT NULL,
    region_name VARCHAR(255) NOT NULL,
    federal_district VARCHAR(100),
    population INT,
    latitude DECIMAL(10,8),
    longitude DECIMAL(11,8),
    is_active BOOLEAN DEFAULT TRUE,
    last_synced TIMESTAMPTZ,
    created_at TIMESTAMPTZ DEFAULT NOW()
);

CREATE INDEX idx_cities_name ON russian_cities(city_name);
CREATE INDEX idx_cities_region ON russian_cities(region_name);