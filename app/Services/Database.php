<?php
/* ============================================
   НАЙДУК — Универсальный сервис базы данных
   Версия 3.0 (март 2026)
   - Полный CRUD с кэшированием и защитой от stampede
   - Система тегов для инвалидации
   - Автоматическая очистка устаревших данных
   - Логирование медленных запросов
   - Работает даже при недоступном Redis
   ============================================ */

class Database
{
    private static $instance = null;
    private $pdo;
    private $redis = null;
    private $redisEnabled = false;
    private $slowQueryThreshold = 0.5; // секунды
    private $cacheTags = [];

    private function __construct()
    {
        // Загрузка конфигурации
        $config = $this->loadConfig();

        try {
            $dsn = "mysql:host={$config['host']};dbname={$config['database']};charset={$config['charset']}";
            $this->pdo = new PDO($dsn, $config['username'], $config['password'], [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::ATTR_TIMEOUT => 5,
            ]);
        } catch (PDOException $e) {
            error_log("Database connection failed: " . $e->getMessage());
            die('Ошибка подключения к базе данных');
        }

        // Подключаем Redis, если доступен
        try {
            if (class_exists('Redis')) {
                $this->redis = new Redis();
                $connected = $this->redis->connect(
                    $config['redis_host'] ?? '127.0.0.1',
                    $config['redis_port'] ?? 6379,
                    0.5 // таймаут
                );
                if ($connected) {
                    $this->redisEnabled = true;
                    $this->redis->setOption(Redis::OPT_SERIALIZER, Redis::SERIALIZER_NONE);
                } else {
                    error_log("Redis connection refused, caching disabled");
                }
            }
        } catch (Exception $e) {
            error_log("Redis error: " . $e->getMessage());
        }
    }

    private function loadConfig()
    {
        $configFile = __DIR__ . '/../../config/database.php';
        if (file_exists($configFile)) {
            $config = require $configFile;
        } else {
            // Фолбэк для совместимости – используем .env напрямую
            $envFile = __DIR__ . '/../../.env';
            $config = [];
            if (file_exists($envFile)) {
                $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
                foreach ($lines as $line) {
                    if (strpos($line, '=') !== false && strpos($line, '#') !== 0) {
                        list($key, $value) = explode('=', $line, 2);
                        $config[strtolower(trim($key))] = trim($value);
                    }
                }
            }
            $config = [
                'host' => $config['db_host'] ?? 'localhost',
                'database' => $config['db_name'] ?? 'nayduk',
                'username' => $config['db_user'] ?? 'root',
                'password' => $config['db_pass'] ?? '',
                'charset' => 'utf8mb4',
                'redis_host' => $config['redis_host'] ?? '127.0.0.1',
                'redis_port' => (int)($config['redis_port'] ?? 6379),
            ];
        }
        return $config;
    }

    public static function getInstance()
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function getConnection()
    {
        return $this->pdo;
    }

    // ===== БАЗОВЫЕ ЗАПРОСЫ =====
    public function query($sql, $params = [])
    {
        $start = microtime(true);
        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);
            $time = microtime(true) - $start;
            if ($time > $this->slowQueryThreshold) {
                $this->logSlowQuery($sql, $params, $time);
            }
            return $stmt;
        } catch (PDOException $e) {
            error_log("Database query failed: " . $e->getMessage());
            error_log("SQL: " . $sql);
            error_log("Params: " . json_encode($params));
            throw $e;
        }
    }

    public function fetchOne($sql, $params = [])
    {
        return $this->query($sql, $params)->fetch();
    }

    public function fetchAll($sql, $params = [])
    {
        return $this->query($sql, $params)->fetchAll();
    }

    public function fetchColumn($sql, $params = [])
    {
        return $this->query($sql, $params)->fetchColumn();
    }

    public function fetchCount($sql, $params = [])
    {
        return (int)$this->fetchColumn($sql, $params);
    }

    public function insert($table, $data)
    {
        $columns = implode(', ', array_keys($data));
        $placeholders = implode(', ', array_fill(0, count($data), '?'));
        $sql = "INSERT INTO {$table} ({$columns}) VALUES ({$placeholders})";
        $this->query($sql, array_values($data));
        $id = $this->pdo->lastInsertId();
        $this->invalidateByTag($table);
        return $id;
    }

    public function update($table, $data, $where, $whereParams = [])
    {
        $set = implode(' = ?, ', array_keys($data)) . ' = ?';
        $sql = "UPDATE {$table} SET {$set} WHERE {$where}";
        $params = array_merge(array_values($data), $whereParams);
        $stmt = $this->query($sql, $params);
        $affected = $stmt->rowCount();
        $this->invalidateByTag($table);
        return $affected;
    }

    public function delete($table, $where, $params = [])
    {
        $sql = "DELETE FROM {$table} WHERE {$where}";
        $stmt = $this->query($sql, $params);
        $affected = $stmt->rowCount();
        $this->invalidateByTag($table);
        return $affected;
    }

    public function exists($table, $where, $params = [])
    {
        $sql = "SELECT 1 FROM {$table} WHERE {$where} LIMIT 1";
        return $this->fetchOne($sql, $params) !== false;
    }

    public function transaction(callable $callback)
    {
        try {
            $this->pdo->beginTransaction();
            $result = $callback($this);
            $this->pdo->commit();
            return $result;
        } catch (Exception $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    // ===== КЭШИРОВАНИЕ (с защитой от stampede и тегами) =====
    private function cacheKey($key, $tags = [])
    {
        $tagPart = '';
        if (!empty($tags)) {
            $tagPart = ':' . implode(':', $tags);
        }
        return "db:{$key}{$tagPart}";
    }

    public function cacheGet($key)
    {
        if (!$this->redisEnabled) return null;
        try {
            $value = $this->redis->get($key);
            // Redis возвращает false при отсутствии ключа
            if ($value === false) return null;
            return json_decode($value, true);
        } catch (Exception $e) {
            error_log("Redis get error: " . $e->getMessage());
            return null;
        }
    }

    public function cacheSet($key, $value, $ttl = 3600, $tags = [])
    {
        if (!$this->redisEnabled) return false;
        try {
            $fullKey = $this->cacheKey($key, $tags);
            $encoded = json_encode($value);
            // Добавляем джиттер, чтобы ключи не истекали одновременно
            $jitter = random_int(0, 600);
            $ttl += $jitter;
            return $this->redis->setex($fullKey, $ttl, $encoded);
        } catch (Exception $e) {
            error_log("Redis set error: " . $e->getMessage());
            return false;
        }
    }

    public function cacheDelete($key)
    {
        if (!$this->redisEnabled) return false;
        try {
            return $this->redis->del($key) > 0;
        } catch (Exception $e) {
            error_log("Redis delete error: " . $e->getMessage());
            return false;
        }
    }

    public function cacheRemember($key, $ttl, $callback, $tags = [])
    {
        $fullKey = $this->cacheKey($key, $tags);
        $cached = $this->cacheGet($fullKey);
        if ($cached !== null) {
            return $cached;
        }

        // Защита от cache stampede: блокировка
        $lockKey = "lock:{$fullKey}";
        $lock = $this->redisEnabled ? $this->redis->set($lockKey, 1, ['nx', 'ex' => 5]) : false;

        if ($lock) {
            // Этот процесс регенерирует кэш
            $data = $callback();
            $this->cacheSet($key, $data, $ttl, $tags);
            $this->redis->del($lockKey);
            return $data;
        } else {
            // Ждём, пока другой процесс обновит кэш
            $attempts = 0;
            while ($attempts < 10) {
                usleep(100000); // 100ms
                $cached = $this->cacheGet($fullKey);
                if ($cached !== null) {
                    return $cached;
                }
                $attempts++;
            }
            // Если так и не дождались, идём в БД без блокировки (fallback)
            return $callback();
        }
    }

    public function invalidateByTag($tag)
    {
        if (!$this->redisEnabled) return;
        // Удаляем все ключи, содержащие тег (простейшая реализация — сканирование)
        // В реальном проекте лучше хранить список ключей по тегам, но для простоты так
        try {
            $pattern = $this->cacheKey('*', [$tag]);
            $keys = $this->redis->keys($pattern);
            foreach ($keys as $key) {
                $this->redis->del($key);
            }
        } catch (Exception $e) {
            error_log("Invalidate tag error: " . $e->getMessage());
        }
    }

    // ===== ЛОГИРОВАНИЕ МЕДЛЕННЫХ ЗАПРОСОВ =====
    private function logSlowQuery($sql, $params, $time)
    {
        $logFile = __DIR__ . '/../../storage/logs/slow_queries.log';
        $line = sprintf(
            "[%s] %.3f sec | SQL: %s | PARAMS: %s\n",
            date('Y-m-d H:i:s'),
            $time,
            $sql,
            json_encode($params)
        );
        file_put_contents($logFile, $line, FILE_APPEND);
    }

    // ===== МЕТОДЫ ДЛЯ ПОЛЬЗОВАТЕЛЕЙ (сохранены из старой версии, с добавлением кэширования) =====
    public function getUserById($id)
    {
        $cacheKey = "user:{$id}";
        return $this->cacheRemember($cacheKey, 3600, function() use ($id) {
            return $this->fetchOne("SELECT * FROM users WHERE id = ?", [$id]);
        }, ['users']);
    }

    public function getUserByEmail($email)
    {
        $cacheKey = "user:email:{$email}";
        return $this->cacheRemember($cacheKey, 3600, function() use ($email) {
            return $this->fetchOne("SELECT * FROM users WHERE email = ?", [$email]);
        }, ['users']);
    }

    public function createUser($data)
    {
        $data['created_at'] = date('Y-m-d H:i:s');
        $data['updated_at'] = date('Y-m-d H:i:s');
        $id = $this->insert('users', $data);
        $this->invalidateByTag('users');
        return $id;
    }

    public function updateUser($id, $data)
    {
        $data['updated_at'] = date('Y-m-d H:i:s');
        $res = $this->update('users', $data, 'id = ?', [$id]);
        $this->invalidateByTag('users');
        return $res;
    }

    // ===== МЕТОДЫ ДЛЯ ПАРТНЁРСКИХ ОФФЕРОВ (НОВЫЕ) =====
    public function getPartnerOffers($filters = [], $limit = 20, $offset = 0)
    {
        $sql = "SELECT po.*, c.city_name, c.region_name
                FROM partner_offers po
                LEFT JOIN russian_cities c ON po.city_id = c.id
                WHERE po.is_approved = 1 AND po.is_active = 1";
        $params = [];

        if (!empty($filters['category'])) {
            $sql .= " AND po.category = ?";
            $params[] = $filters['category'];
        }
        if (!empty($filters['city_id'])) {
            $sql .= " AND po.city_id = ?";
            $params[] = $filters['city_id'];
        }
        if (!empty($filters['search'])) {
            $sql .= " AND (po.partner_name LIKE ? OR po.offer_name LIKE ?)";
            $search = "%{$filters['search']}%";
            $params[] = $search;
            $params[] = $search;
        }

        $sql .= " ORDER BY po.priority DESC, po.created_at DESC LIMIT ? OFFSET ?";
        $params[] = $limit;
        $params[] = $offset;

        return $this->fetchAll($sql, $params);
    }

    public function getPartnerOfferById($id)
    {
        $cacheKey = "partner_offer:{$id}";
        return $this->cacheRemember($cacheKey, 3600, function() use ($id) {
            return $this->fetchOne("
                SELECT po.*, c.city_name, c.region_name
                FROM partner_offers po
                LEFT JOIN russian_cities c ON po.city_id = c.id
                WHERE po.id = ? AND po.is_approved = 1 AND po.is_active = 1
            ", [$id]);
        }, ['partner_offers']);
    }

    public function createPartnerOffer($data)
    {
        $data['created_at'] = date('Y-m-d H:i:s');
        $data['updated_at'] = date('Y-m-d H:i:s');
        $id = $this->insert('partner_offers', $data);
        $this->invalidateByTag('partner_offers');
        return $id;
    }

    public function updatePartnerOffer($id, $data)
    {
        $data['updated_at'] = date('Y-m-d H:i:s');
        $res = $this->update('partner_offers', $data, 'id = ?', [$id]);
        $this->invalidateByTag('partner_offers');
        return $res;
    }

    // ===== МЕТОДЫ ДЛЯ ЦЕНОВЫХ ПРЕДЛОЖЕНИЙ (ОБРАТНЫЙ АУКЦИОН) =====
    public function createPriceOffer($listingId, $userId, $discountPercent, $proposedPrice)
    {
        $data = [
            'listing_id' => $listingId,
            'user_id' => $userId,
            'discount_percent' => $discountPercent,
            'proposed_price' => $proposedPrice,
            'status' => 'active',
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s')
        ];
        $id = $this->insert('price_offers', $data);
        $this->invalidateByTag('price_offers');
        return $id;
    }

    public function getPriceOffersByListing($listingId)
    {
        $cacheKey = "price_offers:listing:{$listingId}";
        return $this->cacheRemember($cacheKey, 300, function() use ($listingId) {
            return $this->fetchAll("
                SELECT po.*, u.name as buyer_name
                FROM price_offers po
                JOIN users u ON po.user_id = u.id
                WHERE po.listing_id = ? AND po.status = 'active'
                ORDER BY po.discount_percent ASC
            ", [$listingId]);
        }, ['price_offers']);
    }

    public function acceptPriceOffer($offerId)
    {
        $this->transaction(function() use ($offerId) {
            $offer = $this->fetchOne("SELECT listing_id FROM price_offers WHERE id = ?", [$offerId]);
            if (!$offer) return false;
            $this->update('price_offers', ['status' => 'accepted', 'updated_at' => date('Y-m-d H:i:s')], 'id = ?', [$offerId]);
            $this->update('price_offers', ['status' => 'expired', 'updated_at' => date('Y-m-d H:i:s')], 'listing_id = ? AND id != ?', [$offer['listing_id'], $offerId]);
            $this->invalidateByTag('price_offers');
        });
        return true;
    }

    // ===== МЕТОДЫ ДЛЯ ИНДИВИДУАЛЬНЫХ ЦЕН =====
    public function setCustomerPrice($userId, $listingId, $price)
    {
        $data = ['user_id' => $userId, 'listing_id' => $listingId, 'price' => $price];
        $this->delete('customer_prices', 'user_id = ? AND listing_id = ?', [$userId, $listingId]);
        $id = $this->insert('customer_prices', $data);
        $this->invalidateByTag('customer_prices');
        return $id;
    }

    public function getCustomerPrice($userId, $listingId)
    {
        $cacheKey = "customer_price:{$userId}:{$listingId}";
        return $this->cacheRemember($cacheKey, 3600, function() use ($userId, $listingId) {
            return $this->fetchOne("SELECT price FROM customer_prices WHERE user_id = ? AND listing_id = ?", [$userId, $listingId]);
        }, ['customer_prices']);
    }

    // ===== МЕТОДЫ ДЛЯ ОТНОШЕНИЙ ПОСТАВЩИК-ПОКУПАТЕЛЬ =====
    public function addBuyerRelation($supplierId, $buyerId)
    {
        $data = [
            'supplier_id' => $supplierId,
            'buyer_id' => $buyerId,
            'approved_at' => date('Y-m-d H:i:s')
        ];
        $id = $this->insert('supplier_buyer_relations', $data);
        $this->invalidateByTag('supplier_buyer_relations');
        return $id;
    }

    public function hasBuyerAccess($supplierId, $buyerId)
    {
        $cacheKey = "supplier_access:{$supplierId}:{$buyerId}";
        return $this->cacheRemember($cacheKey, 3600, function() use ($supplierId, $buyerId) {
            return $this->exists('supplier_buyer_relations', 'supplier_id = ? AND buyer_id = ?', [$supplierId, $buyerId]);
        }, ['supplier_buyer_relations']);
    }

    // ===== МЕТОДЫ ДЛЯ CPA-СЕТЕЙ =====
    public function getCpaNetworks($activeOnly = true)
    {
        $sql = "SELECT * FROM cpa_networks";
        if ($activeOnly) $sql .= " WHERE is_active = 1";
        $sql .= " ORDER BY name";
        return $this->fetchAll($sql);
    }

    // ===== МЕТОДЫ ДЛЯ ВЕБХУКОВ =====
    public function getWebhooksByUser($userId)
    {
        $cacheKey = "webhooks:user:{$userId}";
        return $this->cacheRemember($cacheKey, 300, function() use ($userId) {
            return $this->fetchAll("SELECT * FROM webhooks WHERE user_id = ? ORDER BY created_at DESC", [$userId]);
        }, ['webhooks']);
    }

    public function createWebhook($userId, $name, $url, $events, $secret, $isActive = 1)
    {
        $data = [
            'user_id' => $userId,
            'name' => $name,
            'url' => $url,
            'events' => json_encode($events),
            'secret' => $secret,
            'is_active' => $isActive,
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s')
        ];
        $id = $this->insert('webhooks', $data);
        $this->invalidateByTag('webhooks');
        return $id;
    }

    // ===== АВТОМАТИЧЕСКАЯ ОЧИСТКА ДАННЫХ =====
    public function cleanup()
    {
        $this->transaction(function() {
            // Архивация старых объявлений (старше 90 дней)
            $this->query("
                UPDATE listings
                SET is_active = 0, status = 'archived'
                WHERE created_at < DATE_SUB(NOW(), INTERVAL 90 DAY)
                AND status = 'approved'
            ");
            // Удаление старых сообщений (старше 90 дней)
            $this->query("
                DELETE FROM messages
                WHERE created_at < DATE_SUB(NOW(), INTERVAL 90 DAY)
            ");
            // Удаление старых кэш-файлов (не используем, т.к. Redis сам управляет)
            // Удаление старых сессий (если таблица sessions есть)
            $this->query("
                DELETE FROM sessions
                WHERE expires_at < NOW() OR created_at < DATE_SUB(NOW(), INTERVAL 30 DAY)
            ");
            // Удаление старых логов (старше 30 дней)
            $logFiles = glob(__DIR__ . '/../../storage/logs/*.log');
            foreach ($logFiles as $file) {
                if (filemtime($file) < time() - 30 * 86400) {
                    unlink($file);
                }
            }
            $this->invalidateByTag('cleanup');
        });
        return true;
    }

    // ===== СТАТИСТИКА (с кэшированием) =====
    public function getStats()
    {
        return $this->cacheRemember('admin_stats', 60, function() {
            return [
                'total_users' => $this->fetchCount("SELECT COUNT(*) FROM users"),
                'total_offers' => $this->fetchCount("SELECT COUNT(*) FROM partner_offers WHERE is_approved = 1"),
                'total_clicks' => $this->fetchCount("SELECT COUNT(*) FROM partner_clicks"),
                'total_revenue' => $this->fetchColumn("SELECT COALESCE(SUM(amount), 0) FROM partner_conversions")
            ];
        }, ['admin_stats']);
    }
}