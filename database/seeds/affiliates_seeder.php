<?php
/* ============================================
   НАЙДУК — Seeder для таблицы affiliates
   Версия 1.0 — начальное наполнение (100+ офферов)
   Запуск: через браузер или консоль
   ============================================ */

// Защита от прямого доступа (можно убрать, если нужно запустить через консоль)
if (php_sapi_name() !== 'cli') {
    // Разрешаем запуск из браузера только если сайт ещё не в продакшене
    if (file_exists(__DIR__ . '/../../storage/install.lock')) {
        die('Seeder уже выполнен или установка завершена.');
    }
}

require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../services/Database.php';

$db = Database::getInstance();
$pdo = $db->getPdo();

echo "Запуск наполнения таблицы affiliates...\n";

// ==================== ВСПОМОГАТЕЛЬНАЯ ФУНКЦИЯ ====================
function insertAffiliate($pdo, $data) {
    // Генерируем slug
    $slugBase = transliterate($data['partner_name'] . '-' . $data['offer_name']);
    $slug = preg_replace('/[^a-z0-9-]/', '-', strtolower($slugBase));
    $slug = trim($slug, '-');
    // Проверяем уникальность
    $stmt = $pdo->prepare("SELECT id FROM affiliates WHERE slug = ?");
    $stmt->execute([$slug]);
    if ($stmt->fetch()) {
        echo "  Пропуск (уже существует): {$data['partner_name']} - {$data['offer_name']}\n";
        return false;
    }

    // Подготовка полей
    $fields = [
        'name' => $data['partner_name'] . ' — ' . $data['offer_name'],
        'partner_name' => $data['partner_name'],
        'offer_name' => $data['offer_name'],
        'slug' => $slug,
        'category' => $data['category'],
        'commission_type' => $data['commission_type'],
        'commission_value' => $data['commission_value'],
        'url_template' => $data['url_template'],
        'is_smartlink' => $data['is_smartlink'] ?? 0,
        'description' => $data['description'] ?? null,
        'icon_url' => $data['icon_url'] ?? null,
        'categories' => json_encode(explode(',', $data['category'])),
        'source' => $data['source'] ?? 'manual',
        'cpa_network' => $data['cpa_network'] ?? null,
        'geo_availability' => $data['geo_availability'] ?? 'Россия',
        'notes' => $data['notes'] ?? null,
        'is_active' => 1,
        'is_approved' => 1,
        'priority' => $data['priority'] ?? 0,
        'created_at' => date('Y-m-d H:i:s'),
        'updated_at' => date('Y-m-d H:i:s')
    ];

    $columns = implode(', ', array_keys($fields));
    $placeholders = implode(', ', array_fill(0, count($fields), '?'));
    $sql = "INSERT INTO affiliates ($columns) VALUES ($placeholders)";
    $stmt = $pdo->prepare($sql);
    $stmt->execute(array_values($fields));
    echo "  Добавлен: {$data['partner_name']} - {$data['offer_name']}\n";
    return true;
}

// ==================== МАССИВ ОФФЕРОВ ====================
$offers = [
    // ========== 1. CPA-СЕТИ ==========
    [
        'partner_name' => 'Admitad',
        'offer_name' => 'Все офферы',
        'category' => 'ecom,finance,travel',
        'commission_type' => 'cpa',
        'commission_value' => 0,
        'url_template' => 'https://ad.admitad.com/go/?ref={our_id}',
        'is_smartlink' => 1,
        'source' => 'cpa_network',
        'geo_availability' => 'Россия, СНГ',
        'notes' => 'Крупнейшая сеть в РФ, доля 78%'
    ],
    [
        'partner_name' => 'AdvCake',
        'offer_name' => 'Все офферы',
        'category' => 'ecom,education,finance',
        'commission_type' => 'cpa',
        'commission_value' => 0,
        'url_template' => 'https://advcake.ru/?ref={our_id}',
        'is_smartlink' => 1,
        'source' => 'cpa_network',
        'geo_availability' => 'Россия',
        'notes' => 'Специализация: товарные офферы, образование'
    ],
    [
        'partner_name' => 'Mixmarket',
        'offer_name' => 'Все офферы',
        'category' => 'ecom,retargeting',
        'commission_type' => 'cpa',
        'commission_value' => 0,
        'url_template' => 'https://mixmarket.biz/?ref={our_id}',
        'is_smartlink' => 1,
        'source' => 'cpa_network',
        'geo_availability' => 'Россия',
        'notes' => '10+ лет на рынке, ретаргетинг'
    ],
    [
        'partner_name' => 'Awin',
        'offer_name' => 'Все офферы',
        'category' => 'ecom,fashion',
        'commission_type' => 'cps',
        'commission_value' => 0,
        'url_template' => 'https://www.awin.com/?ref={our_id}',
        'is_smartlink' => 1,
        'source' => 'cpa_network',
        'geo_availability' => 'Международная',
        'notes' => 'Глобальная сеть'
    ],
    [
        'partner_name' => 'Webgains',
        'offer_name' => 'Все офферы',
        'category' => 'ecom,travel',
        'commission_type' => 'cps',
        'commission_value' => 0,
        'url_template' => 'https://www.webgains.com/?ref={our_id}',
        'is_smartlink' => 1,
        'source' => 'cpa_network',
        'geo_availability' => 'Международная',
        'notes' => ''
    ],
    [
        'partner_name' => 'CrakRevenue',
        'offer_name' => 'Все офферы',
        'category' => 'adult,ai,health',
        'commission_type' => 'cpa',
        'commission_value' => 0,
        'url_template' => 'https://crakrevenue.com/?a={our_id}&c=0&s1=',
        'is_smartlink' => 1,
        'source' => 'cpa_network',
        'geo_availability' => 'Международная',
        'notes' => 'Специализация: adult, AI, health'
    ],
    [
        'partner_name' => 'MyLead',
        'offer_name' => 'Все офферы',
        'category' => 'ecom,finance,games,health',
        'commission_type' => 'cpa',
        'commission_value' => 0,
        'url_template' => 'https://mylead.global/?a={our_id}&c=0&s1=',
        'is_smartlink' => 1,
        'source' => 'cpa_network',
        'geo_availability' => 'Международная',
        'notes' => '4300+ офферов'
    ],
    [
        'partner_name' => 'Mobidea',
        'offer_name' => 'Мобильные офферы',
        'category' => 'mobile,cpa',
        'commission_type' => 'cpa',
        'commission_value' => 0,
        'url_template' => 'https://mobidea.com/?a={our_id}&c=0&s1=',
        'is_smartlink' => 1,
        'source' => 'cpa_network',
        'geo_availability' => 'Международная',
        'notes' => 'Мобильный трафик'
    ],
    [
        'partner_name' => 'ShareASale',
        'offer_name' => 'Все офферы',
        'category' => 'ecom,fashion,home',
        'commission_type' => 'cps',
        'commission_value' => 0,
        'url_template' => 'https://shareasale.com/r.cfm?b=0&u={our_id}&m=0&urllink=&afftrack=',
        'is_smartlink' => 1,
        'source' => 'cpa_network',
        'geo_availability' => 'США, Европа',
        'notes' => '25000+ мерчантов'
    ],
    [
        'partner_name' => 'PartnerStack',
        'offer_name' => 'B2B SaaS',
        'category' => 'saas,b2b',
        'commission_type' => 'recurring',
        'commission_value' => 0,
        'url_template' => 'https://partnerstack.com/?ref={our_id}',
        'is_smartlink' => 1,
        'source' => 'cpa_network',
        'geo_availability' => 'Международная',
        'notes' => 'HubSpot, Monday.com, Shopify'
    ],
    [
        'partner_name' => 'CJ Affiliate',
        'offer_name' => 'Премиальные бренды',
        'category' => 'ecom,travel,finance',
        'commission_type' => 'cps',
        'commission_value' => 0,
        'url_template' => 'https://www.cj.com/?ref={our_id}',
        'is_smartlink' => 1,
        'source' => 'cpa_network',
        'geo_availability' => 'США, Европа',
        'notes' => 'Zappos, GoDaddy'
    ],

    // ========== 2. МАРКЕТПЛЕЙСЫ И МАГАЗИНЫ ==========
    [
        'partner_name' => 'Яндекс.Маркет',
        'offer_name' => 'Товарные кампании (CPS)',
        'category' => 'ecom',
        'commission_type' => 'cps',
        'commission_value' => 5,
        'url_template' => 'https://market.yandex.ru/partner?ref={our_id}',
        'is_smartlink' => 1,
        'source' => 'partner_program',
        'geo_availability' => 'Россия (доставка по РФ)',
        'notes' => 'Можно продвигать конкретные товары'
    ],
    [
        'partner_name' => 'Яндекс.Маркет',
        'offer_name' => 'ПВЗ (партнёрская точка выдачи)',
        'category' => 'delivery',
        'commission_type' => 'recurring',
        'commission_value' => 3,
        'url_template' => 'https://partner.market.yandex.ru/pvz?ref={our_id}',
        'source' => 'partner_program',
        'geo_availability' => 'Россия',
        'notes' => 'Требуется регистрация юрлица/ИП'
    ],
    [
        'partner_name' => 'Ozon',
        'offer_name' => 'Партнёрская программа',
        'category' => 'ecom',
        'commission_type' => 'cps',
        'commission_value' => 8,
        'url_template' => 'https://ozon.ru/?partner={our_id}',
        'is_smartlink' => 1,
        'source' => 'partner_program',
        'geo_availability' => 'Россия, Беларусь, Казахстан'
    ],
    [
        'partner_name' => 'Wildberries',
        'offer_name' => 'Партнёрская программа',
        'category' => 'ecom',
        'commission_type' => 'cps',
        'commission_value' => 10,
        'url_template' => 'https://www.wildberries.ru/?utm_source=nayduk&ref={our_id}',
        'is_smartlink' => 1,
        'source' => 'partner_program',
        'geo_availability' => 'Россия, СНГ'
    ],
    [
        'partner_name' => 'AliExpress Россия',
        'offer_name' => 'Партнёрская программа',
        'category' => 'ecom',
        'commission_type' => 'cps',
        'commission_value' => 15,
        'url_template' => 'https://aliexpress.ru/?aff_id={our_id}',
        'is_smartlink' => 1,
        'source' => 'partner_program',
        'geo_availability' => 'Россия'
    ],
    [
        'partner_name' => 'Lamoda',
        'offer_name' => 'Партнёрская программа',
        'category' => 'fashion',
        'commission_type' => 'cps',
        'commission_value' => 10,
        'url_template' => 'https://www.lamoda.ru/?ref={our_id}',
        'is_smartlink' => 1,
        'source' => 'partner_program',
        'geo_availability' => 'Россия'
    ],
    [
        'partner_name' => 'ЛЭТУАЛЬ',
        'offer_name' => 'Партнёрская программа',
        'category' => 'beauty',
        'commission_type' => 'cps',
        'commission_value' => 14,
        'url_template' => 'https://www.letu.ru/?ref={our_id}',
        'is_smartlink' => 1,
        'source' => 'partner_program',
        'geo_availability' => 'Россия'
    ],
    [
        'partner_name' => 'Лемана ПРО',
        'offer_name' => 'Партнёрская программа',
        'category' => 'diy',
        'commission_type' => 'cps',
        'commission_value' => 6.5,
        'url_template' => 'https://www.lemanapro.ru/?ref={our_id}',
        'is_smartlink' => 1,
        'source' => 'partner_program',
        'geo_availability' => 'Россия'
    ],
    [
        'partner_name' => 'Vprok Перекрёсток',
        'offer_name' => 'Доставка продуктов',
        'category' => 'food',
        'commission_type' => 'cps',
        'commission_value' => 17.25,
        'url_template' => 'https://vprok.ru/?ref={our_id}',
        'is_smartlink' => 1,
        'source' => 'partner_program',
        'geo_availability' => 'Москва, СПб, крупные города'
    ],
    [
        'partner_name' => 'Лабиринт',
        'offer_name' => 'Книги, игрушки',
        'category' => 'ecom',
        'commission_type' => 'cps',
        'commission_value' => 15,
        'url_template' => 'https://www.labirint.ru/?ref={our_id}',
        'is_smartlink' => 1,
        'source' => 'partner_program',
        'geo_availability' => 'Россия'
    ],
    [
        'partner_name' => 'Gold Apple',
        'offer_name' => 'Партнёрская программа',
        'category' => 'beauty',
        'commission_type' => 'cps',
        'commission_value' => 6.5,
        'url_template' => 'https://advcake.ru/go/goldapple?ref={our_id}',
        'source' => 'cpa_network',
        'cpa_network' => 'AdvCake',
        'geo_availability' => 'Россия',
        'notes' => 'Виджеты, товарные фиды, акции'
    ],
    [
        'partner_name' => 'ASOS',
        'offer_name' => 'Партнёрская программа',
        'category' => 'fashion',
        'commission_type' => 'cps',
        'commission_value' => 12,
        'url_template' => 'https://ad.admitad.com/go/asos?ref={our_id}',
        'source' => 'cpa_network',
        'cpa_network' => 'Admitad',
        'geo_availability' => 'Международная'
    ],
    [
        'partner_name' => 'Nike',
        'offer_name' => 'Партнёрская программа',
        'category' => 'fashion',
        'commission_type' => 'cps',
        'commission_value' => 10,
        'url_template' => 'https://ad.admitad.com/go/nike?ref={our_id}',
        'source' => 'cpa_network',
        'cpa_network' => 'Admitad',
        'geo_availability' => 'Международная'
    ],
    [
        'partner_name' => 'Adidas',
        'offer_name' => 'Партнёрская программа',
        'category' => 'fashion',
        'commission_type' => 'cps',
        'commission_value' => 10,
        'url_template' => 'https://ad.admitad.com/go/adidas?ref={our_id}',
        'source' => 'cpa_network',
        'cpa_network' => 'Admitad',
        'geo_availability' => 'Международная'
    ],
    [
        'partner_name' => 'eBay',
        'offer_name' => 'Партнёрская программа',
        'category' => 'ecom',
        'commission_type' => 'cps',
        'commission_value' => 8,
        'url_template' => 'https://ad.admitad.com/go/ebay?ref={our_id}',
        'source' => 'cpa_network',
        'cpa_network' => 'Admitad',
        'geo_availability' => 'Международная'
    ],
    [
        'partner_name' => 'iHerb',
        'offer_name' => 'Партнёрская программа',
        'category' => 'health',
        'commission_type' => 'cps',
        'commission_value' => 12,
        'url_template' => 'https://ad.admitad.com/go/iherb?ref={our_id}',
        'source' => 'cpa_network',
        'cpa_network' => 'Admitad',
        'geo_availability' => 'Международная'
    ],

    // ========== 3. ЛОГИСТИКА, ТАКСИ, ДОСТАВКА ==========
    [
        'partner_name' => 'Яндекс.Еда',
        'offer_name' => 'Привлечение ресторанов (eLama)',
        'category' => 'delivery',
        'commission_type' => 'cpa',
        'commission_value' => 9,
        'url_template' => 'https://elama.ru/?ref={our_id}',
        'source' => 'partner_program',
        'geo_availability' => 'Москва, СПб, города-миллионники',
        'notes' => 'Регистрация как агента eLama'
    ],
    [
        'partner_name' => 'Яндекс.Доставка',
        'offer_name' => 'Пункт выдачи заказов',
        'category' => 'delivery',
        'commission_type' => 'recurring',
        'commission_value' => 3,
        'url_template' => 'https://partner.market.yandex.ru/pvz?ref={our_id}',
        'source' => 'partner_program',
        'geo_availability' => 'Россия',
        'notes' => 'Требуется юрлицо'
    ],
    [
        'partner_name' => 'Яндекс Go',
        'offer_name' => 'Такси, каршеринг',
        'category' => 'taxi',
        'commission_type' => 'cpa',
        'commission_value' => 200,
        'url_template' => 'https://go.yandex/ru_ru/?ref={our_id}',
        'source' => 'partner_program',
        'geo_availability' => 'Россия',
        'notes' => 'За первую поездку'
    ],
    [
        'partner_name' => 'Ситимобил',
        'offer_name' => 'Такси',
        'category' => 'taxi',
        'commission_type' => 'cpa',
        'commission_value' => 150,
        'url_template' => 'https://city-mobil.ru/?ref={our_id}',
        'source' => 'partner_program',
        'geo_availability' => 'Россия'
    ],
    [
        'partner_name' => 'Delivery Club',
        'offer_name' => 'Доставка еды',
        'category' => 'food',
        'commission_type' => 'cpa',
        'commission_value' => 300,
        'url_template' => 'https://delivery-club.ru/?ref={our_id}',
        'source' => 'partner_program',
        'geo_availability' => 'Крупные города РФ'
    ],

    // ========== 4. B2B-СЕРВИСЫ ==========
    [
        'partner_name' => 'СДЭК',
        'offer_name' => 'Логистика для бизнеса',
        'category' => 'b2b',
        'commission_type' => 'recurring',
        'commission_value' => 5,
        'url_template' => 'https://www.cdek.ru/business/?ref={our_id}',
        'source' => 'partner_program',
        'geo_availability' => 'Россия',
        'notes' => 'Экономия на отправках'
    ],
    [
        'partner_name' => 'Selectel',
        'offer_name' => 'Облачная инфраструктура',
        'category' => 'b2b',
        'commission_type' => 'recurring',
        'commission_value' => 10,
        'url_template' => 'https://selectel.ru/?ref={our_id}',
        'source' => 'partner_program',
        'geo_availability' => 'Россия'
    ],
    [
        'partner_name' => 'Timeweb Cloud',
        'offer_name' => 'Облачная платформа',
        'category' => 'b2b',
        'commission_type' => 'recurring',
        'commission_value' => 15,
        'url_template' => 'https://timeweb.cloud/?ref={our_id}',
        'source' => 'partner_program',
        'geo_availability' => 'Россия'
    ],
    [
        'partner_name' => 'Контур.Диадок',
        'offer_name' => 'Электронный документооборот',
        'category' => 'b2b',
        'commission_type' => 'recurring',
        'commission_value' => 15,
        'url_template' => 'https://diadoc.ru/?ref={our_id}',
        'source' => 'partner_program',
        'geo_availability' => 'Россия'
    ],
    [
        'partner_name' => 'Моё дело',
        'offer_name' => 'Бухгалтерия для ИП и ООО',
        'category' => 'b2b',
        'commission_type' => 'recurring',
        'commission_value' => 20,
        'url_template' => 'https://www.moedelo.org/?ref={our_id}',
        'source' => 'partner_program',
        'geo_availability' => 'Россия',
        'notes' => 'На первые платежи'
    ],
    [
        'partner_name' => 'Честный знак',
        'offer_name' => 'Маркировка товаров',
        'category' => 'b2b',
        'commission_type' => 'cpa',
        'commission_value' => 0,
        'url_template' => 'https://честныйзнак.рф/?ref={our_id}',
        'source' => 'partner_program',
        'geo_availability' => 'Россия',
        'notes' => 'Господдержка, консультации'
    ],

    // ========== 5. ФИНАНСЫ, БАНКИ, МФО ==========
    [
        'partner_name' => 'Т-Банк',
        'offer_name' => 'Кредитная карта Platinum',
        'category' => 'finance',
        'commission_type' => 'cpa',
        'commission_value' => 3932,
        'url_template' => 'https://www.tbank.ru/credit-cards/platinum/?ref={our_id}',
        'source' => 'partner_program',
        'geo_availability' => 'Россия'
    ],
    [
        'partner_name' => 'Т-Банк',
        'offer_name' => 'Дебетовая карта Black',
        'category' => 'finance',
        'commission_type' => 'cpa',
        'commission_value' => 1500,
        'url_template' => 'https://www.tbank.ru/debit-cards/black/?ref={our_id}',
        'source' => 'partner_program',
        'geo_availability' => 'Россия'
    ],
    [
        'partner_name' => 'Т-Банк',
        'offer_name' => 'РКО для бизнеса',
        'category' => 'finance',
        'commission_type' => 'cpa',
        'commission_value' => 3058,
        'url_template' => 'https://www.tbank.ru/business/?ref={our_id}',
        'source' => 'partner_program',
        'geo_availability' => 'Россия'
    ],
    [
        'partner_name' => 'Альфа-Банк',
        'offer_name' => 'Кредитная карта 100 дней',
        'category' => 'finance',
        'commission_type' => 'cpa',
        'commission_value' => 3550,
        'url_template' => 'https://alfabank.ru/credit-cards/100-days/?ref={our_id}',
        'source' => 'partner_program',
        'geo_availability' => 'Россия'
    ],
    [
        'partner_name' => 'Альфа-Банк',
        'offer_name' => 'Дебетовая карта Альфа-Карта',
        'category' => 'finance',
        'commission_type' => 'cpa',
        'commission_value' => 1500,
        'url_template' => 'https://alfabank.ru/debit-cards/alfa-card/?ref={our_id}',
        'source' => 'partner_program',
        'geo_availability' => 'Россия'
    ],
    [
        'partner_name' => 'ВТБ',
        'offer_name' => 'Кредитная карта Мультикарта',
        'category' => 'finance',
        'commission_type' => 'cpa',
        'commission_value' => 3500,
        'url_template' => 'https://www.vtb.ru/personal/credit-cards/multicard/?ref={our_id}',
        'source' => 'partner_program',
        'geo_availability' => 'Россия'
    ],
    [
        'partner_name' => 'Совкомбанк',
        'offer_name' => 'Карта Халва',
        'category' => 'finance',
        'commission_type' => 'cpa',
        'commission_value' => 1800,
        'url_template' => 'https://sovcombank.ru/halva/?ref={our_id}',
        'source' => 'partner_program',
        'geo_availability' => 'Россия'
    ],
    [
        'partner_name' => 'Moneyman',
        'offer_name' => 'Займ на карту',
        'category' => 'finance',
        'commission_type' => 'cpa',
        'commission_value' => 800,
        'url_template' => 'https://moneyman.ru/?ref={our_id}',
        'source' => 'partner_program',
        'geo_availability' => 'Россия'
    ],
    [
        'partner_name' => 'Займер',
        'offer_name' => 'Займ на карту',
        'category' => 'finance',
        'commission_type' => 'cpa',
        'commission_value' => 700,
        'url_template' => 'https://zaimer.ru/?ref={our_id}',
        'source' => 'partner_program',
        'geo_availability' => 'Россия'
    ],

    // ========== 6. СТРАХОВАНИЕ ==========
    [
        'partner_name' => 'СОГАЗ',
        'offer_name' => 'Страхование для МСП',
        'category' => 'insurance',
        'commission_type' => 'cpa',
        'commission_value' => 3000,
        'url_template' => 'https://sogaz.ru/msb/?ref={our_id}',
        'source' => 'partner_program',
        'geo_availability' => 'Россия'
    ],
    [
        'partner_name' => 'Ингосстрах',
        'offer_name' => 'ОСАГО',
        'category' => 'insurance',
        'commission_type' => 'cpa',
        'commission_value' => 500,
        'url_template' => 'https://www.ingos.ru/osago/?ref={our_id}',
        'source' => 'partner_program',
        'geo_availability' => 'Россия'
    ],
    [
        'partner_name' => 'Ренессанс Страхование',
        'offer_name' => 'Страхование здоровья',
        'category' => 'insurance',
        'commission_type' => 'cpa',
        'commission_value' => 1000,
        'url_template' => 'https://renins.ru/health/?ref={our_id}',
        'source' => 'partner_program',
        'geo_availability' => 'Россия'
    ],
    [
        'partner_name' => 'АльфаСтрахование',
        'offer_name' => 'Страхование квартир',
        'category' => 'insurance',
        'commission_type' => 'cpa',
        'commission_value' => 800,
        'url_template' => 'https://alfastrah.ru/flat/?ref={our_id}',
        'source' => 'partner_program',
        'geo_availability' => 'Россия'
    ],

    // ========== 7. ОПЕРАТОРЫ СВЯЗИ ==========
    [
        'partner_name' => 'МТС',
        'offer_name' => 'Мобильная связь',
        'category' => 'telecom',
        'commission_type' => 'cpa',
        'commission_value' => 500,
        'url_template' => 'https://moskva.mts.ru/personal/mobilnaya-svyaz/tarifi/?ref={our_id}',
        'source' => 'partner_program',
        'geo_availability' => 'Россия'
    ],
    [
        'partner_name' => 'Билайн',
        'offer_name' => 'Мобильная связь',
        'category' => 'telecom',
        'commission_type' => 'cpa',
        'commission_value' => 400,
        'url_template' => 'https://moskva.beeline.ru/customers/products/mobile/tariffs/?ref={our_id}',
        'source' => 'partner_program',
        'geo_availability' => 'Россия'
    ],
    [
        'partner_name' => 'МегаФон',
        'offer_name' => 'Мобильная связь',
        'category' => 'telecom',
        'commission_type' => 'cpa',
        'commission_value' => 450,
        'url_template' => 'https://moscow.megafon.ru/tariffs/?ref={our_id}',
        'source' => 'partner_program',
        'geo_availability' => 'Россия'
    ],
    [
        'partner_name' => 'Т2 (Tele2)',
        'offer_name' => 'Мобильная связь',
        'category' => 'telecom',
        'commission_type' => 'cpa',
        'commission_value' => 300,
        'url_template' => 'https://msk.tele2.ru/tariffs/?ref={our_id}',
        'source' => 'partner_program',
        'geo_availability' => 'Россия'
    ],
    [
        'partner_name' => 'Ростелеком',
        'offer_name' => 'Домашний интернет',
        'category' => 'telecom',
        'commission_type' => 'cpa',
        'commission_value' => 1000,
        'url_template' => 'https://rt.ru/internet/?ref={our_id}',
        'source' => 'partner_program',
        'geo_availability' => 'Россия'
    ],

    // ========== 8. HR, ОБРАЗОВАНИЕ ==========
    [
        'partner_name' => 'HeadHunter',
        'offer_name' => 'Размещение вакансий',
        'category' => 'hr',
        'commission_type' => 'cpl',
        'commission_value' => 2000,
        'url_template' => 'https://hh.ru/?ref={our_id}',
        'source' => 'partner_program',
        'geo_availability' => 'Россия'
    ],
    [
        'partner_name' => 'SuperJob',
        'offer_name' => 'Размещение вакансий',
        'category' => 'hr',
        'commission_type' => 'cpl',
        'commission_value' => 1500,
        'url_template' => 'https://www.superjob.ru/?ref={our_id}',
        'source' => 'partner_program',
        'geo_availability' => 'Россия'
    ],
    [
        'partner_name' => 'Skillbox',
        'offer_name' => 'Курсы IT и дизайна',
        'category' => 'education',
        'commission_type' => 'cpa',
        'commission_value' => 5000,
        'url_template' => 'https://skillbox.ru/?ref={our_id}',
        'source' => 'partner_program',
        'geo_availability' => 'Россия'
    ],
    [
        'partner_name' => 'Нетология',
        'offer_name' => 'Профессиональная переподготовка',
        'category' => 'education',
        'commission_type' => 'cpa',
        'commission_value' => 4500,
        'url_template' => 'https://netology.ru/?ref={our_id}',
        'source' => 'partner_program',
        'geo_availability' => 'Россия'
    ],
    [
        'partner_name' => 'GeekBrains',
        'offer_name' => 'Образование в IT',
        'category' => 'education',
        'commission_type' => 'cpa',
        'commission_value' => 4000,
        'url_template' => 'https://geekbrains.ru/?ref={our_id}',
        'source' => 'partner_program',
        'geo_availability' => 'Россия'
    ],
    [
        'partner_name' => 'Skyeng',
        'offer_name' => 'Онлайн-школа английского',
        'category' => 'education',
        'commission_type' => 'cpa',
        'commission_value' => 0,
        'url_template' => 'https://skyeng.ru/?ref={our_id}',
        'source' => 'cpa_network',
        'cpa_network' => 'AdvCake',
        'geo_availability' => 'Россия',
        'notes' => 'До 30% через AdvCake'
    ],
    [
        'partner_name' => 'Яндекс.Практикум',
        'offer_name' => 'IT-образование',
        'category' => 'education',
        'commission_type' => 'cpa',
        'commission_value' => 0,
        'url_template' => 'https://practicum.yandex.ru/?ref={our_id}',
        'source' => 'cpa_network',
        'cpa_network' => 'AdvCake',
        'geo_availability' => 'Россия',
        'notes' => 'До 30% через AdvCake'
    ],

    // ========== 9. ТУРИЗМ ==========
    [
        'partner_name' => 'Ostrovok',
        'offer_name' => 'Бронирование отелей',
        'category' => 'travel',
        'commission_type' => 'cps',
        'commission_value' => 5,
        'url_template' => 'https://ostrovok.ru/?ref={our_id}',
        'source' => 'partner_program',
        'geo_availability' => 'Россия'
    ],
    [
        'partner_name' => 'OneTwoTrip',
        'offer_name' => 'Авиабилеты и отели',
        'category' => 'travel',
        'commission_type' => 'cps',
        'commission_value' => 4,
        'url_template' => 'https://www.onetwotrip.com/?ref={our_id}',
        'source' => 'partner_program',
        'geo_availability' => 'Россия'
    ],
    [
        'partner_name' => 'Travelpayouts',
        'offer_name' => 'Aviasales, Hotellook',
        'category' => 'travel',
        'commission_type' => 'cps',
        'commission_value' => 4,
        'url_template' => 'https://www.travelpayouts.com/?ref={our_id}',
        'source' => 'partner_program',
        'geo_availability' => 'Россия, СНГ'
    ],
    [
        'partner_name' => 'Booking.com',
        'offer_name' => 'Бронирование отелей',
        'category' => 'travel',
        'commission_type' => 'cps',
        'commission_value' => 5,
        'url_template' => 'https://www.booking.com/?ref={our_id}',
        'source' => 'cpa_network',
        'cpa_network' => 'Admitad',
        'geo_availability' => 'Международная'
    ],
    [
        'partner_name' => 'Hotels.com',
        'offer_name' => 'Бронирование отелей',
        'category' => 'travel',
        'commission_type' => 'cps',
        'commission_value' => 5,
        'url_template' => 'https://www.hotels.com/?ref={our_id}',
        'source' => 'cpa_network',
        'cpa_network' => 'Admitad',
        'geo_availability' => 'Международная'
    ],

    // ========== 10. АПТЕКИ, КЛИНИКИ ==========
    [
        'partner_name' => 'Аптека.ру',
        'offer_name' => 'Покупка лекарств',
        'category' => 'pharmacy',
        'commission_type' => 'cpa',
        'commission_value' => 100,
        'url_template' => 'https://apteka.ru/?ref={our_id}',
        'source' => 'partner_program',
        'geo_availability' => 'Россия'
    ],
    [
        'partner_name' => 'Еаптека',
        'offer_name' => 'Покупка лекарств',
        'category' => 'pharmacy',
        'commission_type' => 'cpa',
        'commission_value' => 100,
        'url_template' => 'https://www.eapteka.ru/?ref={our_id}',
        'source' => 'partner_program',
        'geo_availability' => 'Россия'
    ],
    [
        'partner_name' => 'Инвитро',
        'offer_name' => 'Запись на анализы',
        'category' => 'clinic',
        'commission_type' => 'cpl',
        'commission_value' => 500,
        'url_template' => 'https://www.invitro.ru/?ref={our_id}',
        'source' => 'partner_program',
        'geo_availability' => 'Россия'
    ],
    [
        'partner_name' => 'Гемотест',
        'offer_name' => 'Запись на анализы',
        'category' => 'clinic',
        'commission_type' => 'cpl',
        'commission_value' => 400,
        'url_template' => 'https://www.gemotest.ru/?ref={our_id}',
        'source' => 'partner_program',
        'geo_availability' => 'Россия'
    ],
];

echo "Всего офферов для вставки: " . count($offers) . "\n";

foreach ($offers as $offer) {
    insertAffiliate($pdo, $offer);
}

echo "\n✅ Наполнение таблицы affiliates завершено!\n";