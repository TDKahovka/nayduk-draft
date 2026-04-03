<?php
/* ============================================
   НАЙДУК — Оптимизация изображений (v6.0)
   - Redis‑семафор + файловый fallback
   - Повторные попытки при ошибках (retries)
   - Очередь для массовой обработки (Redis)
   - Защита от анимированных GIF
   - Ограничение максимального разрешения (8000x8000)
   - Логирование ошибок (syslog + файл)
   ============================================ */

class ImageOptimizer {
    public const MAX_FILE_SIZE_MB = 20;
    public const MAX_DIMENSION = 1600;
    public const MAX_SOURCE_DIMENSION = 8000; // ограничение исходника
    public const THUMB_DIMENSION = 400;
    public const RESPONSIVE_SIZES = [480, 1000, 1600];
    public const QUALITY_AVIF = 75;
    public const QUALITY_WEBP = 82;
    public const QUALITY_JPEG = 85;
    public const QUALITY_LQIP = 30;
    public const LQIP_SIZE = 16;
    public const CACHE_DIR = '/uploads/optimized/';
    public const CACHE_TTL = 2592000; // 30 дней
    public const MAX_LOG_SIZE = 10485760; // 10 МБ
    public const ALLOWED_MIMES = [
        'image/jpeg',
        'image/png',
        'image/webp',
        'image/gif',
        'image/avif',
    ];
    public const SEMAPHORE_KEY = 'img_opt_semaphore';
    public const QUEUE_KEY = 'queue:image_optimization';

    private string $uploadDir;
    private string $cacheDir;
    private string $logFile;
    private bool $useImagick = false;
    private array $driverInfo = [];
    private $redis = null;
    private bool $redisAvailable = false;

    public function __construct(string $uploadDir) {
        $this->uploadDir = rtrim($uploadDir, '/') . '/';
        $this->cacheDir = $this->uploadDir . ltrim(self::CACHE_DIR, '/');
        $this->logFile = $this->uploadDir . 'optimizer.log';
        $this->detectDriver();
        $this->ensureDirectories();
        $this->initRedis();
    }

    private function initRedis(): void {
        if (class_exists('Redis')) {
            try {
                $this->redis = new Redis();
                $this->redisAvailable = $this->redis->connect('127.0.0.1', 6379, 1);
                if ($this->redisAvailable) {
                    $this->redis->ping();
                }
            } catch (Exception $e) {
                $this->redisAvailable = false;
                $this->logError("Redis connection failed: " . $e->getMessage());
            }
        }
    }

    private function detectDriver(): void {
        if (extension_loaded('imagick') && class_exists('Imagick')) {
            $this->useImagick = true;
            $this->driverInfo = ['driver' => 'Imagick', 'version' => phpversion('imagick')];
        } elseif (extension_loaded('gd')) {
            $this->useImagick = false;
            $gdInfo = gd_info();
            $this->driverInfo = ['driver' => 'GD', 'version' => $gdInfo['GD Version'] ?? 'unknown'];
        } else {
            $this->logError('No image driver (Imagick/GD) available');
            throw new RuntimeException('Image optimization requires GD or Imagick');
        }
    }

    private function ensureDirectories(): void {
        if (!is_dir($this->cacheDir)) {
            mkdir($this->cacheDir, 0755, true);
        }
        $logDir = dirname($this->logFile);
        if (!is_dir($logDir)) {
            mkdir($logDir, 0755, true);
        }
    }

    // ==================== СЕМАФОР (Redis + файловый fallback) ====================
    private function acquireSemaphore(int $maxProcesses = 2): bool {
        if ($this->redisAvailable) {
            try {
                $count = $this->redis->incr(self::SEMAPHORE_KEY);
                if ($count == 1) $this->redis->expire(self::SEMAPHORE_KEY, 5);
                if ($count <= $maxProcesses) return true;
                $this->redis->decr(self::SEMAPHORE_KEY);
                return false;
            } catch (Exception $e) {
                $this->logError("Redis semaphore error: " . $e->getMessage());
                // fallback на файловый
            }
        }
        // Файловый fallback
        $lockFile = sys_get_temp_dir() . '/img_opt_semaphore.lock';
        $fp = fopen($lockFile, 'c+');
        if (!$fp) return false;
        $attempts = 0;
        while ($attempts < 10) {
            if (flock($fp, LOCK_EX | LOCK_NB)) {
                $data = fread($fp, 1024);
                $count = (int)$data;
                if ($count < $maxProcesses) {
                    ftruncate($fp, 0);
                    fwrite($fp, $count + 1);
                    fflush($fp);
                    flock($fp, LOCK_UN);
                    fclose($fp);
                    return true;
                }
                flock($fp, LOCK_UN);
            }
            usleep(50000);
            $attempts++;
        }
        fclose($fp);
        return false;
    }

    private function releaseSemaphore(): void {
        if ($this->redisAvailable) {
            try {
                $this->redis->decr(self::SEMAPHORE_KEY);
                return;
            } catch (Exception $e) {}
        }
        $lockFile = sys_get_temp_dir() . '/img_opt_semaphore.lock';
        $fp = fopen($lockFile, 'c+');
        if ($fp && flock($fp, LOCK_EX)) {
            $data = fread($fp, 1024);
            $count = (int)$data;
            if ($count > 0) $count--;
            ftruncate($fp, 0);
            fwrite($fp, $count);
            fflush($fp);
            flock($fp, LOCK_UN);
            fclose($fp);
        }
    }

    // ==================== ОЧЕРЕДЬ ДЛЯ МАССОВОЙ ОПТИМИЗАЦИИ ====================
    public function enqueueOptimization(string $sourcePath, array $options = []): bool {
        if (!$this->redisAvailable) {
            // Если Redis нет, делаем синхронно
            $result = $this->optimize($sourcePath, $options);
            return $result['success'];
        }
        $job = json_encode([
            'sourcePath' => $sourcePath,
            'options' => $options,
            'time' => time()
        ]);
        return $this->redis->rpush(self::QUEUE_KEY, $job) !== false;
    }

    public function processQueue(): void {
        if (!$this->redisAvailable) return;
        while ($job = $this->redis->lpop(self::QUEUE_KEY)) {
            $data = json_decode($job, true);
            if ($data) {
                $this->optimize($data['sourcePath'], $data['options']);
            }
        }
    }

    // ==================== ОСНОВНОЙ МЕТОД (с повторными попытками) ====================
    public function optimize(string $sourcePath, array $options = []): array {
        $maxRetries = 2;
        $lastError = null;
        for ($attempt = 0; $attempt <= $maxRetries; $attempt++) {
            try {
                $result = $this->doOptimize($sourcePath, $options);
                if ($result['success']) return $result;
                $lastError = $result['error'] ?? 'Unknown error';
            } catch (Exception $e) {
                $lastError = $e->getMessage();
            }
            if ($attempt < $maxRetries) usleep(100000 * ($attempt + 1));
        }
        return ['success' => false, 'error' => $lastError];
    }

    private function doOptimize(string $sourcePath, array $options = []): array {
        set_time_limit(120);
        $semaphoreAcquired = $this->acquireSemaphore(2);
        if (!$semaphoreAcquired) {
            return ['success' => false, 'error' => 'Слишком много одновременных оптимизаций. Попробуйте позже.'];
        }
        try {
            return $this->innerOptimize($sourcePath, $options);
        } finally {
            $this->releaseSemaphore();
        }
    }

    private function innerOptimize(string $sourcePath, array $options = []): array {
        $defaults = [
            'user_id'         => 0,
            'generate_sizes'  => self::RESPONSIVE_SIZES,
            'generate_lqip'   => true,
            'force_format'    => null,
            'quality_profile' => 'product',
        ];
        $options = array_merge($defaults, $options);

        if (!file_exists($sourcePath)) {
            $this->logError("File not found: $sourcePath");
            return ['success' => false, 'error' => 'Файл не найден'];
        }

        if (!$this->isPathSafe($sourcePath, $this->uploadDir)) {
            $this->logError("Path traversal attempt: $sourcePath");
            return ['success' => false, 'error' => 'Недопустимый путь к файлу'];
        }

        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime = finfo_file($finfo, $sourcePath);
        finfo_close($finfo);
        if (!in_array($mime, self::ALLOWED_MIMES, true)) {
            return ['success' => false, 'error' => 'Неподдерживаемый формат изображения'];
        }

        // Защита от анимированных GIF
        if ($mime === 'image/gif' && $this->isAnimatedGif($sourcePath)) {
            $this->logError("Animated GIF detected, skipping optimization: $sourcePath");
            // Просто копируем оригинал в кэш, без обработки
            $baseName = $this->generateSafeFilename($options['user_id']);
            $targetPath = $this->cacheDir . $baseName . '_original.gif';
            copy($sourcePath, $targetPath);
            return [
                'success' => true,
                'optimized_path' => $targetPath,
                'original_size' => filesize($sourcePath),
                'optimized_size' => filesize($targetPath),
                'saved_percent' => 0,
                'format' => 'gif',
                'from_cache' => false
            ];
        }

        $imageInfo = getimagesize($sourcePath);
        if (!$imageInfo) {
            return ['success' => false, 'error' => 'Не удалось определить размеры изображения'];
        }
        $srcWidth = $imageInfo[0];
        $srcHeight = $imageInfo[1];

        // Ограничение максимального разрешения
        if ($srcWidth > self::MAX_SOURCE_DIMENSION || $srcHeight > self::MAX_SOURCE_DIMENSION) {
            return ['success' => false, 'error' => 'Изображение слишком большое (макс. ' . self::MAX_SOURCE_DIMENSION . 'x' . self::MAX_SOURCE_DIMENSION . ')'];
        }

        if (!$this->checkMemoryLimit($sourcePath)) {
            return ['success' => false, 'error' => 'Изображение слишком большое для обработки'];
        }

        $originalSize = filesize($sourcePath);
        $baseName = $this->generateSafeFilename($options['user_id']);
        $results = [];
        $quality = $this->getQualityForProfile($options['quality_profile']);
        $formats = $this->detectFormatsToGenerate($options['force_format']);

        foreach ($options['generate_sizes'] as $size) {
            foreach ($formats as $format) {
                $targetPath = $this->cacheDir . $baseName . "_{$size}w.{$format}";
                $success = $this->resizeAndSave($sourcePath, $targetPath, $size, $size, false, $format, $quality[$format] ?? 80);
                if ($success) {
                    $results['sizes'][$size][$format] = $targetPath;
                }
            }
        }

        if ($options['generate_lqip']) {
            $lqipPath = $this->cacheDir . $baseName . '_lqip.jpg';
            $success = $this->resizeAndSave($sourcePath, $lqipPath, self::LQIP_SIZE, self::LQIP_SIZE, true, 'jpeg', self::QUALITY_LQIP);
            if ($success && file_exists($lqipPath)) {
                $imageData = file_get_contents($lqipPath);
                $results['lqip_base64'] = 'data:image/jpeg;base64,' . base64_encode($imageData);
                @unlink($lqipPath);
            }
        }

        if (empty($results['sizes'])) {
            return ['success' => false, 'error' => 'Не удалось создать ни одного варианта изображения'];
        }

        return [
            'success'         => true,
            'base_name'       => $baseName,
            'sizes'           => $results['sizes'],
            'lqip_base64'     => $results['lqip_base64'] ?? null,
            'original_size'   => $originalSize,
            'driver'          => $this->driverInfo,
        ];
    }

    public function createThumbnail(string $sourcePath, int $size = self::THUMB_DIMENSION, bool $crop = true, int $userId = 0): ?string {
        $result = $this->optimize($sourcePath, [
            'user_id'        => $userId,
            'generate_sizes' => [$size],
            'generate_lqip'  => false,
            'force_format'   => 'webp',
            'quality_profile'=> 'product',
        ]);
        if ($result['success'] && isset($result['sizes'][$size]['webp'])) {
            return $result['sizes'][$size]['webp'];
        }
        return null;
    }

    // ==================== ВСПОМОГАТЕЛЬНЫЕ МЕТОДЫ ====================

    private function isAnimatedGif(string $path): bool {
        if (!($fh = @fopen($path, 'rb'))) return false;
        $count = 0;
        while (!feof($fh) && $count < 2) {
            $chunk = fread($fh, 1024 * 100);
            $count += preg_match_all('#\x00\x21\xF9\x04.{4}\x00[\x2C\x21]#s', $chunk, $matches);
        }
        fclose($fh);
        return $count > 1;
    }

    private function getQualityForProfile(string $profile): array {
        $profiles = [
            'product'       => ['avif' => 75, 'webp' => 82, 'jpeg' => 85],
            'banner'        => ['avif' => 70, 'webp' => 78, 'jpeg' => 80],
            'logo'          => ['avif' => 90, 'webp' => 92, 'jpeg' => 95],
            'logo_lossless' => ['avif' => 100, 'webp' => 100, 'jpeg' => 100],
        ];
        return $profiles[$profile] ?? $profiles['product'];
    }

    private function detectFormatsToGenerate(?string $forceFormat): array {
        if ($forceFormat) return [$forceFormat];
        $formats = ['jpeg'];
        if ($this->useImagick) {
            $imagick = new Imagick();
            if (in_array('WEBP', $imagick->queryFormats('WEBP'))) $formats[] = 'webp';
            if (in_array('AVIF', $imagick->queryFormats('AVIF'))) $formats[] = 'avif';
        } else {
            if (function_exists('imagewebp')) $formats[] = 'webp';
            if (function_exists('imageavif')) $formats[] = 'avif';
        }
        return $formats;
    }

    private function resizeAndSave(string $sourcePath, string $targetPath, int $maxWidth, int $maxHeight, bool $crop, string $format, int $quality): bool {
        if (!$this->isPathSafe(dirname($targetPath), $this->cacheDir)) {
            $this->logError("Unsafe target path: $targetPath");
            return false;
        }
        if ($this->useImagick) {
            return $this->resizeImagick($sourcePath, $targetPath, $maxWidth, $maxHeight, $crop, $format, $quality);
        }
        return $this->resizeGD($sourcePath, $targetPath, $maxWidth, $maxHeight, $crop, $format, $quality);
    }

    private function resizeImagick(string $sourcePath, string $targetPath, int $maxWidth, int $maxHeight, bool $crop, string $format, int $quality): bool {
        try {
            $image = new Imagick($sourcePath);
            $image->stripImage();
            $image->setImageOrientation(Imagick::ORIENTATION_UNDEFINED);
            if ($crop) {
                $image->cropThumbnailImage($maxWidth, $maxHeight);
            } else {
                $image->resizeImage($maxWidth, $maxHeight, Imagick::FILTER_LANCZOS, 1, true);
            }
            $image->setImageFormat($format);
            $image->setImageCompressionQuality($quality);
            if ($format === 'jpeg') {
                $image->setImageCompression(Imagick::COMPRESSION_JPEG);
                $image->setSamplingFactors(['2x2', '1x1', '1x1']);
            }
            $image->writeImage($targetPath);
            $image->clear();
            return true;
        } catch (Exception $e) {
            $this->logError("Imagick error: " . $e->getMessage());
            return false;
        }
    }

    private function resizeGD(string $sourcePath, string $targetPath, int $maxWidth, int $maxHeight, bool $crop, string $format, int $quality): bool {
        $info = getimagesize($sourcePath);
        if (!$info) return false;
        [$srcWidth, $srcHeight] = $info;
        $mime = $info['mime'];
        $src = $this->createImageFromFile($sourcePath, $mime);
        if (!$src) return false;

        if ($crop) {
            $ratio = max($maxWidth / $srcWidth, $maxHeight / $srcHeight);
            $newWidth = (int)($srcWidth * $ratio);
            $newHeight = (int)($srcHeight * $ratio);
            $dst = imagecreatetruecolor($maxWidth, $maxHeight);
            imagecopyresampled($dst, $src, 0, 0, ($newWidth - $maxWidth) / 2, ($newHeight - $maxHeight) / 2, $maxWidth, $maxHeight, $newWidth, $newHeight);
        } else {
            $ratio = min($maxWidth / $srcWidth, $maxHeight / $srcHeight);
            $newWidth = (int)($srcWidth * $ratio);
            $newHeight = (int)($srcHeight * $ratio);
            $dst = imagecreatetruecolor($newWidth, $newHeight);
            imagecopyresampled($dst, $src, 0, 0, 0, 0, $newWidth, $newHeight, $srcWidth, $srcHeight);
        }

        $result = false;
        switch ($format) {
            case 'jpeg': $result = imagejpeg($dst, $targetPath, $quality); break;
            case 'webp': $result = imagewebp($dst, $targetPath, $quality); break;
            case 'avif': if (function_exists('imageavif')) $result = imageavif($dst, $targetPath, $quality); break;
        }
        imagedestroy($src);
        imagedestroy($dst);
        return $result;
    }

    private function createImageFromFile(string $path, string $mime) {
        switch ($mime) {
            case 'image/jpeg': return @imagecreatefromjpeg($path);
            case 'image/png':  return @imagecreatefrompng($path);
            case 'image/gif':  return @imagecreatefromgif($path);
            case 'image/webp': return @imagecreatefromwebp($path);
            case 'image/avif': return function_exists('imagecreatefromavif') ? @imagecreatefromavif($path) : false;
            default: return false;
        }
    }

    public function validatePhoto(array $file): array {
        $errors = [];
        if ($file['error'] !== UPLOAD_ERR_OK) {
            $errors[] = $this->uploadErrorToMessage($file['error']);
            return ['valid' => false, 'errors' => $errors];
        }
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $realMime = finfo_file($finfo, $file['tmp_name']);
        finfo_close($finfo);
        if (!in_array($realMime, self::ALLOWED_MIMES, true)) {
            $errors[] = 'Недопустимый формат. Разрешены: JPEG, PNG, WebP, GIF, AVIF';
        }
        if ($file['size'] > self::MAX_FILE_SIZE_MB * 1024 * 1024) {
            $errors[] = 'Файл слишком большой. Максимум ' . self::MAX_FILE_SIZE_MB . ' МБ';
        }
        $check = @getimagesize($file['tmp_name']);
        if ($check === false) {
            $errors[] = 'Файл повреждён или не является изображением';
        }
        return ['valid' => empty($errors), 'errors' => $errors];
    }

    private function isPathSafe(string $path, string $baseDir): bool {
        $realBase = realpath($baseDir);
        $realPath = realpath($path);
        return $realPath !== false && strpos($realPath, $realBase) === 0;
    }

    private function checkMemoryLimit(string $path): bool {
        $info = getimagesize($path);
        if (!$info) return false;
        $width = $info[0];
        $height = $info[1];
        $bits = $info['bits'] ?? 8;
        $channels = $info['channels'] ?? 3;
        $needed = $width * $height * $channels * ($bits / 8) * 2;
        $limit = $this->returnBytes(ini_get('memory_limit'));
        return $needed < $limit * 0.8;
    }

    private function returnBytes(string $val): int {
        $val = trim($val);
        $last = strtolower($val[strlen($val)-1] ?? '');
        $val = (int)$val;
        switch ($last) {
            case 'g': $val *= 1024;
            case 'm': $val *= 1024;
            case 'k': $val *= 1024;
        }
        return $val;
    }

    private function generateSafeFilename(int $userId = 0): string {
        $bytes = random_bytes(16);
        $unique = bin2hex($bytes) . '_' . uniqid('', true) . '_user_' . $userId;
        return preg_replace('/[^a-zA-Z0-9_-]/', '', $unique);
    }

    public function getPictureHtml(array $sizesData, string $alt, ?string $lqip = null, array $attrs = []): string {
        $html = '<picture>';
        ksort($sizesData);
        foreach ($sizesData as $width => $formats) {
            if (isset($formats['avif'])) {
                $html .= '<source srcset="' . htmlspecialchars($formats['avif']) . '" type="image/avif" media="(max-width: ' . $width . 'px)">';
            }
            if (isset($formats['webp'])) {
                $html .= '<source srcset="' . htmlspecialchars($formats['webp']) . '" type="image/webp" media="(max-width: ' . $width . 'px)">';
            }
        }
        $fallbackWidth = max(array_keys($sizesData));
        $fallbackSrc = $sizesData[$fallbackWidth]['jpeg'] ?? $sizesData[$fallbackWidth]['webp'] ?? $sizesData[$fallbackWidth]['avif'] ?? '';
        $attrString = '';
        foreach ($attrs as $key => $value) {
            $attrString .= ' ' . $key . '="' . htmlspecialchars($value) . '"';
        }
        $lqipAttr = $lqip ? ' style="background-image: url(\'' . $lqip . '\'); background-size: cover;"' : '';
        $html .= '<img src="' . htmlspecialchars($fallbackSrc) . '" alt="' . htmlspecialchars($alt) . '" loading="lazy"' . $attrString . $lqipAttr . '>';
        $html .= '</picture>';
        return $html;
    }

    public function cleanupCache(): void {
        $files = glob($this->cacheDir . '*');
        $now = time();
        $deleted = 0;
        foreach ($files as $file) {
            if (is_file($file) && $now - filemtime($file) > self::CACHE_TTL) {
                if (@unlink($file)) $deleted++;
            }
        }
        $this->logError("Cleaned up $deleted old cache files");
    }

    private function logError(string $message): void {
        // Пишем в syslog (для централизованного сбора)
        if (function_exists('syslog')) {
            syslog(LOG_ERR, "[ImageOptimizer] " . $message);
        }
        // И в файл (fallback)
        if (file_exists($this->logFile) && filesize($this->logFile) > self::MAX_LOG_SIZE) {
            $backup = $this->logFile . '.' . date('Ymd-His');
            @rename($this->logFile, $backup);
        }
        $trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 2);
        $caller = $trace[1] ?? ['function' => 'unknown', 'line' => 0];
        $logLine = sprintf("[%s] ERROR: %s in %s:%d\n", date('Y-m-d H:i:s'), $message, $caller['function'], $caller['line']);
        file_put_contents($this->logFile, $logLine, FILE_APPEND | LOCK_EX);
    }

    private function uploadErrorToMessage(int $errorCode): string {
        $messages = [
            UPLOAD_ERR_INI_SIZE   => 'Файл превышает допустимый размер',
            UPLOAD_ERR_FORM_SIZE  => 'Файл превышает допустимый размер',
            UPLOAD_ERR_PARTIAL    => 'Файл был загружен только частично',
            UPLOAD_ERR_NO_FILE    => 'Файл не был загружен',
            UPLOAD_ERR_NO_TMP_DIR => 'Отсутствует временная папка',
            UPLOAD_ERR_CANT_WRITE => 'Не удалось записать файл на диск',
            UPLOAD_ERR_EXTENSION  => 'Загрузка файла остановлена расширением',
        ];
        return $messages[$errorCode] ?? 'Неизвестная ошибка загрузки';
    }

    private function __clone() {}
    public function __wakeup() {
        throw new Exception('Cannot unserialize singleton');
    }
}