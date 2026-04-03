-- ============================================
   НАЙДУК — Базовые офферы (примеры)
   Версия: 2026_03_20
   ============================================

-- Финансы
INSERT INTO partner_offers (category, partner_name, offer_name, description, commission_type, commission_value, url_template, is_admin_offer, is_approved, external_id, source) VALUES
('finance', 'Т-Банк', 'Кредитная карта Platinum', 'Кешбэк до 30%, бесплатное обслуживание', 'cpa', 3932, 'https://www.tbank.ru/credit-cards/platinum/?ref={our_id}', TRUE, TRUE, 'tbank_platinum', 'manual'),
('finance', 'Альфа-Банк', 'Кредитная карта 100 дней без %', 'Льготный период 100 дней', 'cpa', 3550, 'https://alfabank.ru/credit-cards/100-days/?ref={our_id}', TRUE, TRUE, 'alfa_100days', 'manual');

-- Такси
INSERT INTO partner_offers (category, partner_name, offer_name, description, commission_type, commission_value, url_template, is_admin_offer, is_approved, external_id, source) VALUES
('taxi', 'Яндекс Go', 'Первая поездка со скидкой', 'Скидка на первый заказ', 'cpa', 200, 'https://go.yandex/ru_ru/?ref={our_id}', TRUE, TRUE, 'yandex_taxi', 'manual');

-- Доставка еды
INSERT INTO partner_offers (category, partner_name, offer_name, description, commission_type, commission_value, url_template, is_admin_offer, is_approved, external_id, source) VALUES
('food_delivery', 'Delivery Club', 'Первый заказ со скидкой', 'Доставка из ресторанов', 'cpa', 300, 'https://delivery-club.ru/?ref={our_id}', TRUE, TRUE, 'delivery_club', 'manual');

-- E-commerce
INSERT INTO partner_offers (category, partner_name, offer_name, description, commission_type, commission_value, url_template, is_admin_offer, is_approved, external_id, source) VALUES
('ecom', 'Wildberries', 'Покупка на Wildberries', 'Комиссия с каждой покупки', 'cps', 10, 'https://www.wildberries.ru/?ref={our_id}', TRUE, TRUE, 'wildberries_cps', 'manual');

-- B2B
INSERT INTO partner_offers (category, partner_name, offer_name, description, commission_type, commission_value, url_template, is_admin_offer, is_approved, external_id, source) VALUES
('b2b', 'СДЭК', 'Логистика для бизнеса', 'Доставка для интернет-магазинов', 'recurring', 5, 'https://www.cdek.ru/business/?ref={our_id}', TRUE, TRUE, 'cdek_b2b', 'manual');