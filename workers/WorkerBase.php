<?php
/* ============================================
   НАЙДУК — Базовый класс для воркеров (heartbeat)
   ============================================ */

abstract class WorkerBase {
    protected $workerName;
    protected $redis;
    protected $redisAvailable = false;
    protected $stopFlag = false;

    public function __construct($workerName) {
        $this->workerName = $workerName;
        $this->initRedis();
        $this->setupSignalHandlers();
    }

    private function initRedis() {
        if (class_exists('Redis')) {
            try {
                $this->redis = new Redis();
                $this->redisAvailable = $this->redis->connect('127.0.0.1', 6379, 1);
                if ($this->redisAvailable) $this->redis->ping();
            } catch (Exception $e) {
                $this->redisAvailable = false;
            }
        }
    }

    private function setupSignalHandlers() {
        pcntl_signal(SIGTERM, [$this, 'shutdown']);
        pcntl_signal(SIGINT, [$this, 'shutdown']);
        pcntl_signal(SIGQUIT, [$this, 'shutdown']);
    }

    public function shutdown($signo) {
        $this->log("Received signal $signo, shutting down...");
        $this->stopFlag = true;
    }

    protected function sendHeartbeat() {
        if (!$this->redisAvailable) return;
        $key = "worker:{$this->workerName}:heartbeat";
        $this->redis->setex($key, 300, time()); // TTL 5 минут
    }

    protected function log($message) {
        echo "[" . date('Y-m-d H:i:s') . "] {$message}\n";
        // Можно также писать в файл или syslog
    }

    abstract public function run();

    public function start() {
        $this->log("Worker {$this->workerName} started");
        while (!$this->stopFlag) {
            $this->sendHeartbeat();
            $this->run();
            pcntl_signal_dispatch();
            sleep(1);
        }
        $this->log("Worker {$this->workerName} stopped");
    }
}