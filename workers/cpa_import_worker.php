#!/usr/bin/env php
<?php
/* ============================================
   НАЙДУК — Воркер импорта офферов
   Версия 2.0 (март 2026)
   - Обрабатывает задачи из очереди import_queue
   - Поддерживает CSV, Excel, JSON, XML, ZIP, GZ, URL
   - Автоопределение кодировки, разделителей
   - Маппинг полей, валидация, дедупликация
   - Обновление прогресса в import_jobs
   ============================================ */

if (PHP_SAPI !== 'cli') {
    die("Этот скрипт только для командной строки.\n");
}

set_time_limit(0);
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../services/Database.php';

// Подключаем PhpSpreadsheet, если есть
if (file_exists(__DIR__ . '/../vendor/autoload.php')) {
    require_once __DIR__ . '/../vendor/autoload.php';
}

class ImportWorker {
    private $db;
    private $pdo;
    private $redis;
    private $redisAvailable = false;
    private $queueDir;
    private $tempDir;
    private $logFile;

    public function __construct() {
        $this->db = Database::getInstance();
        $this->pdo = $this->db->getPdo();
        $this->queueDir = __DIR__ . '/../storage/queue/';
        $this->tempDir = __DIR__ . '/../uploads/import_temp/';
        $this->logFile = __DIR__ . '/../storage/logs/cpa_import.log';
        $this->initRedis();
        $this->ensureTables();
    }

    private function initRedis() {
        if (class_exists('Redis')) {
            try {
                $this->redis = new Redis();
                $this->redisAvailable = $this->redis->connect('127.0.0.1', 6379, 1);
                if ($this->redisAvailable) $this->redis->ping();
            } catch (Exception $e) {}
        }
    }

    private function ensureTables() {
        // import_jobs уже создаётся в API, но на всякий случай
        $this->pdo->exec("
            CREATE TABLE IF NOT EXISTS import_jobs (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                job_id VARCHAR(255) UNIQUE NOT NULL,
                user_id BIGINT UNSIGNED NOT NULL,
                status VARCHAR(50) DEFAULT 'pending',
                file_name VARCHAR(255),
                file_path TEXT,
                file_type VARCHAR(20),
                source_url TEXT,
                provider VARCHAR(100),
                mapping JSON,
                total_rows INT DEFAULT 0,
                processed_rows INT DEFAULT 0,
                failed_rows INT DEFAULT 0,
                errors JSON,
                started_at TIMESTAMP NULL,
                finished_at TIMESTAMP NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_user (user_id),
                INDEX idx_status (status)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
        ");
    }

    private function log($message) {
        $line = '[' . date('Y-m-d H:i:s') . '] ' . $message . PHP_EOL;
        file_put_contents($this->logFile, $line, FILE_APPEND | LOCK_EX);
    }

    private function updateJob($jobId, $data) {
        $set = [];
        $params = [];
        foreach ($data as $key => $value) {
            $set[] = "$key = ?";
            $params[] = $value;
        }
        $params[] = $jobId;
        $sql = "UPDATE import_jobs SET " . implode(', ', $set) . " WHERE job_id = ?";
        $stmt = $this->pdo->prepare($sql);
        return $stmt->execute($params);
    }

    // ==================== ДЕТЕКЦИЯ ФОРМАТОВ ====================

    private function detectFileType($path) {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime = finfo_file($finfo, $path);
        finfo_close($finfo);
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        if (in_array($ext, ['zip', 'gz', 'tar'])) return 'archive';
        if (in_array($ext, ['xls', 'xlsx']) || $mime === 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet') return 'excel';
        if ($ext === 'csv' || $mime === 'text/csv') return 'csv';
        if ($ext === 'json' || $mime === 'application/json') return 'json';
        if ($ext === 'xml' || $mime === 'application/xml') return 'xml';
        return 'unknown';
    }

    private function detectDelimiter($firstLine) {
        $delimiters = [',', ';', "\t"];
        $counts = [];
        foreach ($delimiters as $delim) {
            $counts[$delim] = substr_count($firstLine, $delim);
        }
        arsort($counts);
        return key($counts);
    }

    private function decodeEncoding($content) {
        $encoding = mb_detect_encoding($content, ['UTF-8', 'Windows-1251', 'ISO-8859-1'], true);
        if ($encoding !== 'UTF-8') {
            $content = mb_convert_encoding($content, 'UTF-8', $encoding);
        }
        return $content;
    }

    // ==================== ПАРСИНГ ====================

    private function parseCSV($path, $delimiter = null) {
        $handle = fopen($path, 'r');
        if (!$handle) throw new Exception('Не удалось открыть CSV файл');
        $firstLine = fgets($handle);
        rewind($handle);
        if ($delimiter === null) {
            $delimiter = $this->detectDelimiter($firstLine);
        }
        $rows = [];
        while (($row = fgetcsv($handle, 0, $delimiter)) !== false) {
            if (count(array_filter($row)) === 0) continue; // пропускаем пустые
            $rows[] = $row;
        }
        fclose($handle);
        return $rows;
    }

    private function parseExcel($path) {
        if (!class_exists('PhpOffice\PhpSpreadsheet\IOFactory')) {
            throw new Exception('PhpSpreadsheet не установлен. Установите через composer require phpoffice/phpspreadsheet');
        }
        $spreadsheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($path);
        $worksheet = $spreadsheet->getActiveSheet();
        $rows = [];
        foreach ($worksheet->getRowIterator() as $row) {
            $cells = [];
            foreach ($row->getCellIterator() as $cell) {
                $cells[] = $cell->getValue();
            }
            if (count(array_filter($cells)) > 0) {
                $rows[] = $cells;
            }
        }
        return $rows;
    }

    private function parseJSON($path) {
        $content = file_get_contents($path);
        $content = $this->decodeEncoding($content);
        $data = json_decode($content, true);
        if (!is_array($data)) {
            throw new Exception('Некорректный JSON');
        }
        // Если это ассоциативный массив с ключом 'offers' или 'data'
        if (isset($data['offers']) && is_array($data['offers'])) $data = $data['offers'];
        if (isset($data['data']) && is_array($data['data'])) $data = $data['data'];
        return $data;
    }

    private function parseXML($path) {
        $content = file_get_contents($path);
        $content = $this->decodeEncoding($content);
        $xml = simplexml_load_string($content);
        if ($xml === false) {
            throw new Exception('Некорректный XML');
        }
        // Ищем элементы offer, item, product
        $items = $xml->xpath('//offer') ?: $xml->xpath('//item') ?: $xml->xpath('//product');
        if (!$items) {
            throw new Exception('Не найдены элементы offer/item/product');
        }
        $rows = [];
        foreach ($items as $item) {
            $row = [];
            foreach ($item->children() as $child) {
                $row[$child->getName()] = (string)$child;
            }
            $rows[] = $row;
        }
        return $rows;
    }

    private function extractArchive($path) {
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        $extractDir = $this->tempDir . uniqid('extract_', true);
        mkdir($extractDir, 0755, true);
        if ($ext === 'zip') {
            $zip = new ZipArchive();
            if ($zip->open($path) === true) {
                $zip->extractTo($extractDir);
                $zip->close();
            } else {
                throw new Exception('Не удалось распаковать ZIP');
            }
        } elseif ($ext === 'gz') {
            $outPath = $extractDir . '/extracted';
            $gz = gzopen($path, 'rb');
            $out = fopen($outPath, 'wb');
            while (!gzeof($gz)) {
                fwrite($out, gzread($gz, 4096));
            }
            gzclose($gz);
            fclose($out);
        } else {
            throw new Exception('Неподдерживаемый архив');
        }
        // Ищем первый файл (не директорию)
        $files = scandir($extractDir);
        foreach ($files as $file) {
            if ($file !== '.' && $file !== '..') {
                return $extractDir . '/' . $file;
            }
        }
        throw new Exception('Архив пуст');
    }

    // ==================== МАППИНГ ====================

    private function mapRow($row, $columns, $mapping) {
        $offer = [];
        foreach ($mapping as $field => $colIndex) {
            if (isset($row[$colIndex])) {
                $offer[$field] = trim($row[$colIndex]);
            } else {
                $offer[$field] = null;
            }
        }
        return $offer;
    }

    private function guessMapping($columns) {
        $map = [];
        $fields = ['name', 'partner_name', 'url_template', 'commission_value', 'commission_type', 'category', 'city', 'external_id'];
        foreach ($fields as $field) {
            foreach ($columns as $idx => $col) {
                $colLower = strtolower($col);
                if (strpos($colLower, $field) !== false) {
                    $map[$field] = $idx;
                    break;
                }
            }
        }
        return $map;
    }

    private function validateOffer($offer, &$errors) {
        $required = ['name', 'partner_name', 'url_template', 'commission_value'];
        $valid = true;
        foreach ($required as $field) {
            if (empty($offer[$field])) {
                $errors[] = "Поле '$field' обязательно";
                $valid = false;
            }
        }
        if (!is_numeric($offer['commission_value'])) {
            $errors[] = "Commission value должно быть числом";
            $valid = false;
        }
        if (!empty($offer['commission_type']) && !in_array($offer['commission_type'], ['percent', 'fixed'])) {
            $errors[] = "commission_type может быть 'percent' или 'fixed'";
            $valid = false;
        }
        return $valid;
    }

    // ==================== ВСТАВКА В БД ====================

    private function upsertOffer($offer) {
        $externalId = $offer['external_id'] ?? null;
        $partnerName = $offer['partner_name'];
        $name = $offer['name'];
        $urlTemplate = $offer['url_template'];
        $commissionType = $offer['commission_type'] ?? 'percent';
        $commissionValue = (float)$offer['commission_value'];
        $category = $offer['category'] ?? null;
        $city = $offer['city'] ?? null;

        // Поиск существующего
        if ($externalId) {
            $existing = $this->db->fetchOne("SELECT id FROM partner_offers WHERE external_id = ?", [$externalId]);
        } else {
            $existing = $this->db->fetchOne("SELECT id FROM partner_offers WHERE partner_name = ? AND name = ?", [$partnerName, $name]);
        }

        if ($existing) {
            $this->db->update('partner_offers', [
                'url_template' => $urlTemplate,
                'commission_type' => $commissionType,
                'commission_value' => $commissionValue,
                'category' => $category,
                'city' => $city,
                'updated_at' => date('Y-m-d H:i:s')
            ], 'id = ?', [$existing['id']]);
            return $existing['id'];
        } else {
            return $this->db->insert('partner_offers', [
                'partner_name' => $partnerName,
                'name' => $name,
                'url_template' => $urlTemplate,
                'commission_type' => $commissionType,
                'commission_value' => $commissionValue,
                'category' => $category,
                'city' => $city,
                'external_id' => $externalId,
                'is_active' => 1,
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s')
            ]);
        }
    }

    // ==================== ОБРАБОТКА ЗАДАЧИ ====================

    public function processJob($jobData) {
        $jobId = $jobData['job_id'];
        $filePath = $jobData['file_path'];
        $fileType = $jobData['file_type'];
        $mapping = $jobData['mapping'] ?? null;
        $dryRun = $jobData['dry_run'] ?? false;

        $this->log("Начало обработки задачи $jobId, файл: $filePath");

        $this->updateJob($jobId, [
            'status' => 'processing',
            'started_at' => date('Y-m-d H:i:s')
        ]);

        if (!file_exists($filePath)) {
            $this->updateJob($jobId, ['status' => 'failed', 'errors' => json_encode(['Файл не найден']), 'finished_at' => date('Y-m-d H:i:s')]);
            $this->log("Ошибка: файл не найден $filePath");
            return;
        }

        try {
            // Определяем реальный тип, если архив
            if ($fileType === 'archive') {
                $realFile = $this->extractArchive($filePath);
                $fileType = $this->detectFileType($realFile);
                $filePath = $realFile;
            }

            // Парсинг
            $rows = [];
            if ($fileType === 'csv') {
                $rows = $this->parseCSV($filePath);
            } elseif ($fileType === 'excel') {
                $rows = $this->parseExcel($filePath);
            } elseif ($fileType === 'json') {
                $rows = $this->parseJSON($filePath);
            } elseif ($fileType === 'xml') {
                $rows = $this->parseXML($filePath);
            } else {
                throw new Exception("Неподдерживаемый тип файла: $fileType");
            }

            if (empty($rows)) {
                throw new Exception('Файл не содержит данных');
            }

            $headers = array_shift($rows); // первая строка – заголовки
            $totalRows = count($rows);

            $this->updateJob($jobId, ['total_rows' => $totalRows]);

            // Маппинг
            if (!$mapping) {
                $mapping = $this->guessMapping($headers);
                $this->updateJob($jobId, ['mapping' => json_encode($mapping)]);
            }

            $processed = 0;
            $failed = 0;
            $errors = [];

            $this->pdo->beginTransaction();
            try {
                foreach ($rows as $idx => $row) {
                    $rowNumber = $idx + 2;
                    $rowAssoc = [];
                    foreach ($headers as $colIdx => $colName) {
                        $rowAssoc[$colIdx] = $row[$colIdx] ?? '';
                    }
                    $offer = $this->mapRow($rowAssoc, $headers, $mapping);
                    $rowErrors = [];
                    if ($this->validateOffer($offer, $rowErrors)) {
                        if (!$dryRun) {
                            $this->upsertOffer($offer);
                        }
                        $processed++;
                    } else {
                        $failed++;
                        $errors[] = "Строка $rowNumber: " . implode(', ', $rowErrors);
                    }
                    if ($processed % 100 == 0) {
                        $this->updateJob($jobId, ['processed_rows' => $processed, 'failed_rows' => $failed, 'errors' => json_encode($errors)]);
                    }
                }
                $this->pdo->commit();
            } catch (Exception $e) {
                $this->pdo->rollBack();
                throw $e;
            }

            $status = ($failed > 0) ? 'completed_with_errors' : 'completed';
            $this->updateJob($jobId, [
                'status' => $status,
                'processed_rows' => $processed,
                'failed_rows' => $failed,
                'errors' => json_encode($errors),
                'finished_at' => date('Y-m-d H:i:s')
            ]);

            $this->log("Задача $jobId завершена. Успешно: $processed, ошибок: $failed");

            // Удаляем временный файл
            @unlink($filePath);

        } catch (Exception $e) {
            $this->updateJob($jobId, [
                'status' => 'failed',
                'errors' => json_encode([$e->getMessage()]),
                'finished_at' => date('Y-m-d H:i:s')
            ]);
            $this->log("Ошибка обработки задачи $jobId: " . $e->getMessage());
        }
    }

    // ==================== ОСНОВНОЙ ЦИКЛ ====================

    public function run() {
        $this->log("Воркер запущен");

        while (true) {
            $job = null;
            if ($this->redisAvailable) {
                $job = $this->redis->lpop('import_queue');
                if ($job) $job = json_decode($job, true);
            } else {
                $queueFile = $this->queueDir . 'import_queue.queue';
                if (file_exists($queueFile)) {
                    $lines = file($queueFile, FILE_IGNORE_NEW_LINES);
                    if (!empty($lines)) {
                        $job = json_decode(array_shift($lines), true);
                        file_put_contents($queueFile, implode(PHP_EOL, $lines), LOCK_EX);
                    }
                }
            }

            if ($job) {
                $this->processJob($job);
            } else {
                sleep(5);
            }
        }
    }
}

$worker = new ImportWorker();
$worker->run();