#!/usr/bin/env php
<?php
/* ============================================
   НАЙДУК — Автообновление GeoLite2 City базы
   Версия 3.0 (март 2026)
   - Атомарное обновление, проверка сигнатуры MMDB
   - Повторные попытки, логирование, обработка ошибок
   - Не требует ключей, работает через CDN
   ============================================ */

if (php_sapi_name() !== 'cli') {
    die("CLI only");
}

define('GEO_DB_DIR', __DIR__ . '/../storage/geo/');
define('GEO_DB_FILE', GEO_DB_DIR . 'GeoLite2-City.mmdb');
define('GEO_DB_URL', 'https://cdn.jsdelivr.net/npm/geolite2-city@latest/GeoLite2-City.mmdb');
define('LOG_FILE', __DIR__ . '/../logs/update_geodb.log');
define('MAX_RETRIES', 3);
define('RETRY_DELAY', 5); // секунд

function logMessage($message, $level = 'INFO') {
    $date = date('Y-m-d H:i:s');
    $log = "[$date] [$level] $message" . PHP_EOL;
    file_put_contents(LOG_FILE, $log, FILE_APPEND | LOCK_EX);
}

function isMmdb($file) {
    if (!file_exists($file) || filesize($file) < 1024) return false;
    $fp = fopen($file, 'rb');
    if (!$fp) return false;
    $magic = fread($fp, 4);
    fclose($fp);
    // MMDB магическое число: 0xabcdefba (в little-endian)
    $expected = "\xab\xcd\xef\xba";
    return $magic === $expected;
}

function downloadWithRetry($url, $dest, $maxRetries = MAX_RETRIES) {
    for ($attempt = 1; $attempt <= $maxRetries; $attempt++) {
        $fp = fopen($dest, 'w+');
        if (!$fp) return false;

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_FILE, $fp);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 60);
        curl_setopt($ch, CURLOPT_USERAGENT, 'Nayduk/GeoUpdater/3.0');
        // Заголовок If-Modified-Since, если файл уже существует
        if (file_exists(GEO_DB_FILE)) {
            $lastModified = gmdate('D, d M Y H:i:s', filemtime(GEO_DB_FILE)) . ' GMT';
            curl_setopt($ch, CURLOPT_HTTPHEADER, ["If-Modified-Since: $lastModified"]);
        }
        curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $contentLength = curl_getinfo($ch, CURLINFO_CONTENT_LENGTH_DOWNLOAD);
        $error = curl_error($ch);
        curl_close($ch);
        fclose($fp);

        if ($httpCode === 304) {
            logMessage("Database unchanged (HTTP 304), skipping update");
            return true; // не ошибка, просто нет обновлений
        }

        if ($httpCode !== 200 || filesize($dest) < 1000000) {
            logMessage("Attempt $attempt: download failed (HTTP $httpCode, error: $error)", 'WARNING');
            @unlink($dest);
            if ($attempt < $maxRetries) {
                sleep(RETRY_DELAY);
                continue;
            }
            return false;
        }

        // Проверяем, что размер совпадает с ожидаемым (если CDN отдал Content-Length)
        if ($contentLength > 0 && filesize($dest) != $contentLength) {
            logMessage("Attempt $attempt: file size mismatch (expected $contentLength, got " . filesize($dest) . ")", 'WARNING');
            @unlink($dest);
            if ($attempt < $maxRetries) {
                sleep(RETRY_DELAY);
                continue;
            }
            return false;
        }

        return true;
    }
    return false;
}

logMessage("=== GeoIP database updater started ===");

// Проверяем директорию
if (!is_dir(GEO_DB_DIR)) {
    if (!mkdir(GEO_DB_DIR, 0755, true)) {
        logMessage("Failed to create directory: " . GEO_DB_DIR, 'ERROR');
        exit(1);
    }
    logMessage("Created directory: " . GEO_DB_DIR);
}

// Проверяем, нужно ли обновление
if (file_exists(GEO_DB_FILE)) {
    $fileAge = time() - filemtime(GEO_DB_FILE);
    if ($fileAge < 30 * 86400) {
        logMessage("Database is up to date (age: " . round($fileAge / 86400) . " days)");
        exit(0);
    }
    logMessage("Database is outdated (age: " . round($fileAge / 86400) . " days), downloading new version...");
} else {
    logMessage("Database not found, downloading...");
}

// Скачиваем во временный файл
$tmpFile = GEO_DB_DIR . 'GeoLite2-City.mmdb.tmp';
if (!downloadWithRetry(GEO_DB_URL, $tmpFile)) {
    logMessage("Download failed after " . MAX_RETRIES . " attempts", 'ERROR');
    exit(1);
}

// Проверяем сигнатуру MMDB
if (!isMmdb($tmpFile)) {
    logMessage("Downloaded file is not a valid MMDB database", 'ERROR');
    @unlink($tmpFile);
    exit(1);
}

// Атомарно заменяем
if (!rename($tmpFile, GEO_DB_FILE)) {
    logMessage("Failed to rename temporary file", 'ERROR');
    @unlink($tmpFile);
    exit(1);
}

logMessage("Database updated successfully (size: " . number_format(filesize(GEO_DB_FILE)) . " bytes)");
logMessage("=== Update completed ===");
exit(0);