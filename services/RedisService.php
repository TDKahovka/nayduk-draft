<?php
/**
 * RedisService — синглтон для работы с Redis
 * - Подключение к localhost:6379
 * - Используется для Pub/Sub (SSE) и очередей задач
 */
class RedisService
{
    private static $instance = null;
    private $redis;

    private function __construct()
    {
        $this->redis = new Redis();
        $this->redis->connect('127.0.0.1', 6379);
    }

    public static function getInstance()
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance->redis;
    }
}