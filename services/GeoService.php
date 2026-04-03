<?php
/* ============================================
   НАЙДУК — Геосервис (полностью автономный)
   Версия 3.1 (март 2026)
   - Исправлено получение города из профиля (отдельный запрос)
   - Безопасные куки с динамическим Secure флагом
   - Валидация IP перед запросом к GeoIP
   - Улучшенное логирование
   - Полная поддержка всех методов без рекурсии
   ============================================ */

class GeoService
{
    private $db;
    private $redis;
    private $redisAvailable = false;
    private $cacheDir;
    private $logFile;
    private $geoDbReader = null;
    private $geoDbPath;
    private $hasGeoDb = false;
    private $nominatimUrl = 'https://nominatim.openstreetmap.org/reverse';
    private $lastNominatimRequest = 0; // для rate limiting

    public function __construct()
    {
        $this->db = Database::getInstance();
        $this->cacheDir = __DIR__ . '/../storage/cache/';
        $this->logFile = __DIR__ . '/../logs/geoservice.log';
        $this->geoDbPath = __DIR__ . '/../storage/geo/GeoLite2-City.mmdb';

        $this->ensureDirectories();
        $this->initRedis();
        $this->initGeoDb();

        // Проверяем наличие полей в users (на случай, если их ещё нет)
        $this->ensureUserFields();
    }

    private function ensureDirectories()
    {
        $dirs = [dirname($this->logFile), $this->cacheDir, dirname($this->geoDbPath)];
        foreach ($dirs as $dir) {
            if (!is_dir($dir)) mkdir($dir, 0755, true);
        }
    }

    private function initRedis()
    {
        if (class_exists('Redis')) {
            try {
                $this->redis = new Redis();
                $this->redisAvailable = $this->redis->connect('127.0.0.1', 6379, 1);
                if ($this->redisAvailable) $this->redis->ping();
            } catch (Exception $e) {
                $this->redisAvailable = false;
                $this->log("Redis connection failed: " . $e->getMessage());
            }
        }
    }

    private function initGeoDb()
    {
        if (!class_exists('GeoIp2\Database\Reader')) {
            $this->log("GeoIP2 library not installed, using fallback mode");
            $this->hasGeoDb = false;
            return;
        }

        if (!file_exists($this->geoDbPath) || filesize($this->geoDbPath) < 1000000) {
            $this->log("GeoIP database not found or too small, using fallback");
            $this->hasGeoDb = false;
            return;
        }

        try {
            $this->geoDbReader = new GeoIp2\Database\Reader($this->geoDbPath);
            $this->hasGeoDb = true;
            $this->log("GeoIP database loaded successfully");
        } catch (Exception $e) {
            $this->log("Failed to load GeoIP database: " . $e->getMessage());
            $this->hasGeoDb = false;
        }
    }

    private function ensureUserFields()
    {
        $columns = $this->db->fetchAll("SHOW COLUMNS FROM users");
        $existing = array_column($columns, 'Field');
        if (!in_array('city_id', $existing)) {
            $this->db->query("ALTER TABLE users ADD COLUMN city_id BIGINT UNSIGNED NULL");
            $this->log("Added city_id column to users");
        }
        if (!in_array('city_name', $existing)) {
            $this->db->query("ALTER TABLE users ADD COLUMN city_name VARCHAR(255) NULL");
            $this->log("Added city_name column to users");
        }
    }

    private function log($message, $level = 'INFO')
    {
        $log = '[' . date('Y-m-d H:i:s') . "] [$level] " . $message . PHP_EOL;
        file_put_contents($this->logFile, $log, FILE_APPEND | LOCK_EX);
    }

    // ==================== КЭШИРОВАНИЕ ====================

    private function getCache($key)
    {
        if ($this->redisAvailable) {
            try {
                $val = $this->redis->get($key);
                if ($val !== false) return json_decode($val, true);
            } catch (Exception $e) {
                $this->log("Redis get error: " . $e->getMessage(), 'WARNING');
            }
        }

        $file = $this->cacheDir . md5($key) . '.json';
        if (!file_exists($file)) return null;
        $data = json_decode(file_get_contents($file), true);
        if ($data && isset($data['expires']) && $data['expires'] > time()) {
            return $data['value'];
        }
        @unlink($file);
        return null;
    }

    private function setCache($key, $value, $ttl = 3600)
    {
        $encoded = json_encode($value);
        if ($this->redisAvailable) {
            try {
                $this->redis->setex($key, $ttl, $encoded);
                return;
            } catch (Exception $e) {
                $this->log("Redis set error: " . $e->getMessage(), 'WARNING');
            }
        }

        $file = $this->cacheDir . md5($key) . '.json';
        $data = ['expires' => time() + $ttl, 'value' => $value];
        file_put_contents($file, json_encode($data), LOCK_EX);
    }

    // ==================== ОПРЕДЕЛЕНИЕ ГОРОДА ====================

    /**
     * Универсальный метод определения города пользователя
     * @param int|null $userId ID авторизованного пользователя (если есть)
     * @return array|null ['id', 'city', 'region', 'lat', 'lng', 'source']
     */
    public function getUserCity($userId = null)
    {
        // 1. Если передан ID пользователя — берём из БД
        if ($userId) {
            $userCity = $this->db->fetchOne(
                "SELECT city_id, city_name FROM users WHERE id = ?",
                [$userId]
            );
            if ($userCity && !empty($userCity['city_id']) && !empty($userCity['city_name'])) {
                $city = $this->getCityById($userCity['city_id']);
                if ($city) {
                    return [
                        'id' => $city['id'],
                        'city' => $city['city_name'],
                        'region' => $city['region_name'],
                        'lat' => $city['latitude'],
                        'lng' => $city['longitude'],
                        'source' => 'profile'
                    ];
                }
                // fallback: если city_id есть, но city_name остался
                if ($userCity['city_name']) {
                    return [
                        'city' => $userCity['city_name'],
                        'source' => 'profile'
                    ];
                }
            }
        }

        // 2. Сессия
        if (isset($_SESSION['user_city']) && is_array($_SESSION['user_city'])) {
            return $_SESSION['user_city'];
        }

        // 3. Кука
        if (isset($_COOKIE['user_city'])) {
            $cityData = json_decode($_COOKIE['user_city'], true);
            if ($cityData && isset($cityData['city'])) {
                $_SESSION['user_city'] = $cityData;
                return $cityData;
            }
        }

        // 4. IP-геолокация (через MaxMind или fallback)
        $ip = getUserIP();
        $cityData = $this->getCityByIp($ip);
        if ($cityData && !empty($cityData['city'])) {
            $this->saveUserCityToSession($cityData);
            return $cityData;
        }

        // 5. Fallback — самый крупный город (Москва или любой из топ-5)
        $defaultCity = $this->db->fetchOne("
            SELECT id, city_name, region_name, latitude, longitude
            FROM russian_cities
            WHERE population > 1000000
            ORDER BY population DESC
            LIMIT 1
        ");
        if ($defaultCity) {
            $cityData = [
                'id' => $defaultCity['id'],
                'city' => $defaultCity['city_name'],
                'region' => $defaultCity['region_name'],
                'lat' => $defaultCity['latitude'],
                'lng' => $defaultCity['longitude'],
                'source' => 'fallback'
            ];
            $this->saveUserCityToSession($cityData);
            return $cityData;
        }

        return null;
    }

    /**
     * Определение города по IP-адресу
     * @param string $ip
     * @return array|null
     */
    public function getCityByIp($ip)
    {
        // Валидация IP
        if (!filter_var($ip, FILTER_VALIDATE_IP)) {
            $this->log("Invalid IP address: $ip", 'WARNING');
            return null;
        }

        // Кэшируем на сутки
        $cacheKey = 'geo_ip_' . md5($ip);
        $cached = $this->getCache($cacheKey);
        if ($cached !== null) return $cached;

        if (!$this->hasGeoDb || !$this->geoDbReader) {
            return null;
        }

        try {
            $record = $this->geoDbReader->city($ip);
            $cityNames = $record->city->names ?? [];
            $cityName = $cityNames['ru'] ?? $cityNames['en'] ?? null;
            $regionNames = $record->mostSpecificSubdivision->names ?? [];
            $regionName = $regionNames['ru'] ?? $regionNames['en'] ?? null;

            $result = [
                'city' => $cityName,
                'region' => $regionName,
                'country' => $record->country->isoCode ?? null,
                'lat' => $record->location->latitude ?? null,
                'lng' => $record->location->longitude ?? null,
                'source' => 'maxmind'
            ];

            // Поиск id города в нашей таблице
            if ($cityName) {
                $cityRecord = $this->db->fetchOne(
                    "SELECT id, city_name, region_name, latitude, longitude
                     FROM russian_cities
                     WHERE city_name LIKE ?
                     ORDER BY population DESC
                     LIMIT 1",
                    [$cityName . '%']
                );
                if ($cityRecord) {
                    $result['id'] = $cityRecord['id'];
                    $result['city'] = $cityRecord['city_name'];
                    $result['region'] = $cityRecord['region_name'] ?? $regionName;
                }
            }

            $this->setCache($cacheKey, $result, 86400);
            return $result;

        } catch (GeoIp2\Exception\AddressNotFoundException $e) {
            $this->log("IP $ip not found in database", 'DEBUG');
        } catch (Exception $e) {
            $this->log("Error reading GeoIP: " . $e->getMessage(), 'ERROR');
        }

        return null;
    }

    /**
     * Обратный геокодинг через Nominatim (с кэшем и rate limiting)
     * @param float $lat
     * @param float $lng
     * @return array|null
     */
    public function reverseGeocode($lat, $lng)
    {
        $cacheKey = 'geo_reverse_' . round($lat, 4) . '_' . round($lng, 4);
        $cached = $this->getCache($cacheKey);
        if ($cached !== null) return $cached;

        // Rate limiting: не чаще 1 запроса в секунду
        $now = microtime(true);
        $sinceLast = $now - $this->lastNominatimRequest;
        if ($sinceLast < 1.0) {
            usleep((int)((1.0 - $sinceLast) * 1000000));
        }
        $this->lastNominatimRequest = microtime(true);

        $url = $this->nominatimUrl . "?lat={$lat}&lon={$lng}&format=json&addressdetails=1";
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_USERAGENT => 'Nayduk/3.0 (https://nayduk.ru)',
            CURLOPT_TIMEOUT => 5,
            CURLOPT_SSL_VERIFYPEER => true
        ]);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200 || !$response) {
            $this->log("Nominatim request failed: HTTP $httpCode", 'ERROR');
            return null;
        }

        $data = json_decode($response, true);
        if (empty($data['address'])) return null;

        $city = $data['address']['city'] ?? $data['address']['town'] ?? $data['address']['village'] ?? null;
        $region = $data['address']['state'] ?? null;
        if (!$city) return null;

        $result = [
            'city' => $city,
            'region' => $region,
            'country' => $data['address']['country_code'] ?? 'RU',
            'lat' => $lat,
            'lng' => $lng,
            'source' => 'browser'
        ];

        // Поиск id в нашей таблице
        $cityRecord = $this->db->fetchOne(
            "SELECT id, city_name, region_name, latitude, longitude
             FROM russian_cities
             WHERE city_name LIKE ?
             ORDER BY population DESC
             LIMIT 1",
            [$city . '%']
        );
        if ($cityRecord) {
            $result['id'] = $cityRecord['id'];
            $result['city'] = $cityRecord['city_name'];
            $result['region'] = $cityRecord['region_name'] ?? $region;
        }

        $this->setCache($cacheKey, $result, 86400);
        return $result;
    }

    // ==================== РАБОТА С ПРОФИЛЕМ ПОЛЬЗОВАТЕЛЯ ====================

    /**
     * Обновить город в профиле пользователя
     * @param int $userId
     * @param int $cityId
     * @return bool
     */
    public function updateUserCityInProfile($userId, $cityId)
    {
        $city = $this->getCityById($cityId);
        if (!$city) return false;

        $updated = $this->db->update(
            'users',
            [
                'city_id' => $cityId,
                'city_name' => $city['city_name'],
                'updated_at' => date('Y-m-d H:i:s')
            ],
            'id = ?',
            [$userId]
        );
        if ($updated) {
            $this->log("User $userId city updated to {$city['city_name']} (id $cityId)");
            return true;
        }
        return false;
    }

    /**
     * Установить город для текущего сеанса (неавторизованный пользователь)
     * @param int $cityId
     * @return bool
     */
    public function setUserCity($cityId)
    {
        $city = $this->getCityById($cityId);
        if (!$city) return false;

        $cityData = [
            'id' => $city['id'],
            'city' => $city['city_name'],
            'region' => $city['region_name'],
            'lat' => $city['latitude'],
            'lng' => $city['longitude'],
            'source' => 'manual'
        ];
        $this->saveUserCityToSession($cityData);
        return true;
    }

    /**
     * Сохранить город в сессию и куку
     * @param array $cityData
     */
    public function saveUserCityToSession($cityData)
    {
        $_SESSION['user_city'] = $cityData;
        $secure = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
        setcookie(
            'user_city',
            json_encode($cityData),
            time() + 86400 * 30,
            '/',
            '',
            $secure,  // secure
            true      // httponly
        );
    }

    /**
     * Получить город по ID из таблицы russian_cities
     * @param int $id
     * @return array|null
     */
    public function getCityById($id)
    {
        return $this->db->fetchOne(
            "SELECT id, city_name, region_name, latitude, longitude
             FROM russian_cities
             WHERE id = ?",
            [$id]
        );
    }

    /**
     * Поиск городов по названию (автодополнение)
     * @param string $query
     * @param int $limit
     * @return array
     */
    public function suggestCities($query, $limit = 10)
    {
        if (empty($query) || mb_strlen($query) < 2) return [];

        $cacheKey = 'geo_suggest_' . md5($query . '_' . $limit);
        $cached = $this->getCache($cacheKey);
        if ($cached !== null) return $cached;

        $stmt = $this->db->getPdo()->prepare("
            SELECT id, city_name, region_name, latitude, longitude, population
            FROM russian_cities
            WHERE city_name LIKE :query
            ORDER BY population DESC
            LIMIT :limit
        ");
        $stmt->bindValue(':query', $query . '%', PDO::PARAM_STR);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $this->setCache($cacheKey, $results, 3600);
        return $results;
    }

    /**
     * Получить координаты города из таблицы
     * @param string $cityName
     * @return array|null
     */
    public function getCityCoordinates($cityName)
    {
        $city = $this->db->fetchOne(
            "SELECT latitude, longitude FROM russian_cities WHERE city_name = ?",
            [$cityName]
        );
        return $city ? ['lat' => $city['latitude'], 'lng' => $city['longitude']] : null;
    }
}