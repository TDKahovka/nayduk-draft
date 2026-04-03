-- Добавляем поля в таблицу listings
ALTER TABLE listings ADD COLUMN IF NOT EXISTS next_auto_boost_at TIMESTAMP NULL;
ALTER TABLE listings ADD COLUMN IF NOT EXISTS views_last_30_days INT DEFAULT 0;
CREATE INDEX IF NOT EXISTS idx_next_auto_boost ON listings(next_auto_boost_at);

-- Таблица для логирования просмотров (если ещё нет)
CREATE TABLE IF NOT EXISTS listing_views (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    listing_id BIGINT UNSIGNED NOT NULL,
    user_id BIGINT UNSIGNED NULL,
    viewed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_listing_id (listing_id),
    INDEX idx_viewed_at (viewed_at),
    FOREIGN KEY (listing_id) REFERENCES listings(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Инициализируем next_auto_boost_at для существующих объявлений:
-- активные (были просмотры) – через 3 дня, неактивные – через 10
UPDATE listings l
LEFT JOIN (
    SELECT listing_id, COUNT(*) as cnt
    FROM listing_views
    WHERE viewed_at > NOW() - INTERVAL 30 DAY
    GROUP BY listing_id
) v ON l.id = v.listing_id
SET l.views_last_30_days = COALESCE(v.cnt, 0),
    l.next_auto_boost_at = NOW() + INTERVAL (CASE WHEN COALESCE(v.cnt, 0) > 0 THEN 3 ELSE 10 END) DAY
WHERE l.status IN ('approved', 'featured') AND l.next_auto_boost_at IS NULL;