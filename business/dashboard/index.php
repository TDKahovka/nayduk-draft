<?php
/* ============================================
   НАЙДУК — Бизнес-кабинет (финальная версия с заказами и платежами)
   Версия 8.2 (апрель 2026)
   - Добавлен раздел «Заказы» с фильтрами, деталями, экспортом
   - Ролевой дашборд (владелец / менеджер / оператор)
   - Улучшены товары (остатки, быстрая смена цены)
   - Объединены финансы (подписка + хранилище + платежи)
   - Добавлена реклама (самозаказ баннеров)
   - Отзывы с возможностью ответа
   - Мобильная адаптация, закрытие гамбургера
   - AI-помощник (модалка, встроенные подсказки)
   - История платежей (loadPaymentHistory)
   - Полная интеграция с ЮKassa (платежи)
   ============================================ */

session_start();
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../services/Database.php';
require_once __DIR__ . '/../../services/NotificationService.php';
require_once __DIR__ . '/../../services/ImageOptimizer.php';

if (!isset($_SESSION['user_id'])) {
    $_SESSION['redirect_after_auth'] = '/business/dashboard';
    header('Location: /auth/login');
    exit;
}

$db = Database::getInstance();
$pdo = $db->getPdo();
$userId = (int)$_SESSION['user_id'];

// Получаем роль пользователя (владелец / менеджер / оператор)
$userRole = $db->fetchColumn("SELECT role FROM users WHERE id = ?", [$userId]);
if (!$userRole) $userRole = 'owner'; // по умолчанию владелец

// Получаем или создаём магазин
$shop = $db->fetchOne("SELECT * FROM shops WHERE user_id = ?", [$userId]);
if (!$shop) {
    $slug = 'shop_' . $userId;
    $db->insert('shops', [
        'user_id' => $userId,
        'name' => 'Мой магазин',
        'slug' => $slug,
        'plan' => 'free',
        'theme' => 'light',
        'storage_limit_mb' => 200,
        'storage_used_mb' => 0,
        'created_at' => date('Y-m-d H:i:s')
    ]);
    $shop = $db->fetchOne("SELECT * FROM shops WHERE user_id = ?", [$userId]);
}
$shopId = $shop['id'];

$csrfToken = generateCsrfToken();

// ==================== AJAX-ОБРАБОТЧИКИ ====================
$action = $_GET['action'] ?? '';
if ($action && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    $token = $input['csrf_token'] ?? '';
    if (!verifyCsrfToken($token)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Invalid CSRF']);
        exit;
    }

    switch ($action) {
        // === КОНСТРУКТОР ===
        case 'get_layout':
            $layout = json_decode($shop['layout'] ?? '[]', true);
            echo json_encode(['success' => true, 'layout' => $layout]);
            exit;
        case 'save_layout':
            $layout = $input['layout'] ?? [];
            $db->update('shops', ['layout' => json_encode($layout)], 'id = ?', [$shopId]);
            echo json_encode(['success' => true]);
            exit;

        // === ТОВАРЫ ===
        case 'get_products':
            $page = (int)($input['page'] ?? 1);
            $limit = 20;
            $offset = ($page - 1) * $limit;
            $total = $db->fetchCount("SELECT COUNT(*) FROM shop_products WHERE shop_id = ?", [$shopId]);
            $products = $db->fetchAll("SELECT * FROM shop_products WHERE shop_id = ? ORDER BY created_at DESC LIMIT ? OFFSET ?", [$shopId, $limit, $offset]);
            echo json_encode(['success' => true, 'products' => $products, 'total' => $total, 'page' => $page, 'pages' => ceil($total / $limit)]);
            exit;
        case 'save_product':
            $id = (int)($input['id'] ?? 0);
            $data = [
                'shop_id' => $shopId,
                'sku' => trim($input['sku'] ?? ''),
                'name' => trim($input['name'] ?? ''),
                'description' => trim($input['description'] ?? ''),
                'price' => (float)($input['price'] ?? 0),
                'old_price' => (float)($input['old_price'] ?? 0) ?: null,
                'quantity' => (int)($input['quantity'] ?? 0),
                'category' => trim($input['category'] ?? ''),
                'options' => json_encode($input['options'] ?? []),
                'is_active' => (int)($input['is_active'] ?? 1),
                'updated_at' => date('Y-m-d H:i:s')
            ];
            if ($id) {
                $db->update('shop_products', $data, 'id = ?', [$id]);
            } else {
                $data['created_at'] = date('Y-m-d H:i:s');
                $id = $db->insert('shop_products', $data);
            }
            echo json_encode(['success' => true, 'id' => $id]);
            exit;
        case 'delete_product':
            $id = (int)($input['id'] ?? 0);
            $db->delete('shop_products', 'id = ?', [$id]);
            echo json_encode(['success' => true]);
            exit;
        case 'bulk_price_update':
            $ids = $input['ids'] ?? [];
            $type = $input['type'] ?? 'fixed';
            $value = (float)($input['value'] ?? 0);
            if (empty($ids)) {
                echo json_encode(['success' => false, 'error' => 'Нет выбранных товаров']);
                exit;
            }
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            if ($type === 'percent') {
                $sql = "UPDATE shop_products SET price = price * (1 + ? / 100), updated_at = NOW() WHERE id IN ($placeholders)";
                $params = array_merge([$value], $ids);
            } else {
                $sql = "UPDATE shop_products SET price = price + ?, updated_at = NOW() WHERE id IN ($placeholders)";
                $params = array_merge([$value], $ids);
            }
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            echo json_encode(['success' => true, 'updated' => $stmt->rowCount()]);
            exit;
        case 'upload_product_photos':
            if (!isset($_FILES['files'])) {
                echo json_encode(['success' => false, 'error' => 'Нет файлов']);
                exit;
            }
            $productId = (int)($input['product_id'] ?? 0);
            $uploaded = [];
            foreach ($_FILES['files']['tmp_name'] as $i => $tmp) {
                $optimizer = new ImageOptimizer(ROOT_DIR . '/uploads/');
                $result = $optimizer->optimize($tmp, [
                    'width' => 800,
                    'quality' => 80,
                    'format' => 'webp',
                    'user_id' => $userId,
                    'context' => 'product'
                ]);
                if ($result['success']) {
                    $url = '/uploads/optimized/' . basename($result['optimized_path']);
                    $sizeMb = filesize($result['optimized_path']) / 1024 / 1024;
                    $db->insert('product_photos', [
                        'product_id' => $productId,
                        'url' => $url,
                        'sort_order' => $i,
                        'size_mb' => round($sizeMb, 2),
                        'created_at' => date('Y-m-d H:i:s')
                    ]);
                    $uploaded[] = $url;
                    // Обновляем использованное хранилище
                    $db->update('shops', ['storage_used_mb' => $db->fetchColumn("SELECT COALESCE(SUM(size_mb),0) FROM product_photos WHERE product_id IN (SELECT id FROM shop_products WHERE shop_id=?)", [$shopId]) + ($shop['storage_used_mb'] ?? 0)], 'id = ?', [$shopId]);
                }
            }
            echo json_encode(['success' => true, 'photos' => $uploaded]);
            exit;

        // === УСЛУГИ ===
        case 'get_services':
            $page = (int)($input['page'] ?? 1);
            $limit = 20;
            $offset = ($page - 1) * $limit;
            $total = $db->fetchCount("SELECT COUNT(*) FROM shop_services WHERE shop_id = ?", [$shopId]);
            $services = $db->fetchAll("SELECT * FROM shop_services WHERE shop_id = ? ORDER BY created_at DESC LIMIT ? OFFSET ?", [$shopId, $limit, $offset]);
            echo json_encode(['success' => true, 'services' => $services, 'total' => $total, 'page' => $page, 'pages' => ceil($total / $limit)]);
            exit;
        case 'save_service':
            $id = (int)($input['id'] ?? 0);
            $data = [
                'shop_id' => $shopId,
                'name' => trim($input['name'] ?? ''),
                'description' => trim($input['description'] ?? ''),
                'price' => (float)($input['price'] ?? 0),
                'duration' => (int)($input['duration'] ?? 60),
                'is_active' => (int)($input['is_active'] ?? 1),
                'updated_at' => date('Y-m-d H:i:s')
            ];
            if ($id) {
                $db->update('shop_services', $data, 'id = ?', [$id]);
            } else {
                $data['created_at'] = date('Y-m-d H:i:s');
                $id = $db->insert('shop_services', $data);
            }
            echo json_encode(['success' => true, 'id' => $id]);
            exit;
        case 'delete_service':
            $id = (int)($input['id'] ?? 0);
            $db->delete('shop_services', 'id = ?', [$id]);
            echo json_encode(['success' => true]);
            exit;

        // === FAQ ===
        case 'get_faq':
            $faq = json_decode($shop['faq'] ?? '[]', true);
            echo json_encode(['success' => true, 'faq' => $faq]);
            exit;
        case 'save_faq':
            $faq = $input['faq'] ?? [];
            $db->update('shops', ['faq' => json_encode($faq)], 'id = ?', [$shopId]);
            echo json_encode(['success' => true]);
            exit;

        // === НАСТРОЙКИ ===
        case 'save_settings':
            $data = [
                'name' => trim($input['name'] ?? ''),
                'description' => trim($input['description'] ?? ''),
                'contact_phone' => trim($input['contact_phone'] ?? ''),
                'contact_email' => trim($input['contact_email'] ?? ''),
                'contact_telegram' => trim($input['contact_telegram'] ?? ''),
                'contact_whatsapp' => trim($input['contact_whatsapp'] ?? ''),
                'contact_instagram' => trim($input['contact_instagram'] ?? ''),
                'contact_youtube' => trim($input['contact_youtube'] ?? ''),
                'address' => trim($input['address'] ?? ''),
                'theme' => trim($input['theme'] ?? 'light'),
                'deepseek_api_key' => trim($input['deepseek_api_key'] ?? ''),
                'payment_methods' => json_encode($input['payment_methods'] ?? []),
                'updated_at' => date('Y-m-d H:i:s')
            ];
            $db->update('shops', $data, 'id = ?', [$shopId]);
            echo json_encode(['success' => true]);
            exit;
        case 'upload_logo':
            if (!isset($_FILES['file'])) {
                echo json_encode(['success' => false, 'error' => 'Нет файла']);
                exit;
            }
            $optimizer = new ImageOptimizer(ROOT_DIR . '/uploads/');
            $result = $optimizer->optimize($_FILES['file']['tmp_name'], [
                'width' => 200,
                'height' => 200,
                'crop' => true,
                'quality' => 80,
                'format' => 'webp',
                'user_id' => $userId,
                'context' => 'logo'
            ]);
            if ($result['success']) {
                $url = '/uploads/optimized/' . basename($result['optimized_path']);
                $db->update('shops', ['logo_url' => $url], 'id = ?', [$shopId]);
                echo json_encode(['success' => true, 'url' => $url]);
            } else {
                echo json_encode(['success' => false, 'error' => 'Ошибка обработки']);
            }
            exit;
        case 'upload_banner':
            if (!isset($_FILES['file'])) {
                echo json_encode(['success' => false, 'error' => 'Нет файла']);
                exit;
            }
            $optimizer = new ImageOptimizer(ROOT_DIR . '/uploads/');
            $result = $optimizer->optimize($_FILES['file']['tmp_name'], [
                'width' => 1200,
                'quality' => 80,
                'format' => 'webp',
                'user_id' => $userId,
                'context' => 'banner'
            ]);
            if ($result['success']) {
                $url = '/uploads/optimized/' . basename($result['optimized_path']);
                $db->update('shops', ['banner_url' => $url], 'id = ?', [$shopId]);
                echo json_encode(['success' => true, 'url' => $url]);
            } else {
                echo json_encode(['success' => false, 'error' => 'Ошибка обработки']);
            }
            exit;

        // === ХРАНИЛИЩЕ ===
        case 'get_storage_info':
            $limit = $shop['storage_limit_mb'];
            $used = $db->fetchColumn("SELECT COALESCE(SUM(size_mb), 0) FROM product_photos WHERE product_id IN (SELECT id FROM shop_products WHERE shop_id = ?)", [$shopId]);
            if ($shop['logo_url']) {
                $path = ROOT_DIR . parse_url($shop['logo_url'], PHP_URL_PATH);
                if (file_exists($path)) $used += filesize($path) / 1024 / 1024;
            }
            if ($shop['banner_url']) {
                $path = ROOT_DIR . parse_url($shop['banner_url'], PHP_URL_PATH);
                if (file_exists($path)) $used += filesize($path) / 1024 / 1024;
            }
            $used = round($used, 2);
            $db->update('shops', ['storage_used_mb' => $used], 'id = ?', [$shopId]);
            echo json_encode(['success' => true, 'limit' => $limit, 'used' => $used, 'percent' => round($used / max(1, $limit) * 100, 1)]);
            exit;
        case 'buy_storage':
            $extraGb = (int)($input['extra_gb'] ?? 1);
            $price = $extraGb * 500; // 500 ₽ за ГБ
            $orderId = $db->insert('payment_orders', [
                'user_id' => $userId,
                'type' => 'storage',
                'amount' => $price,
                'data' => json_encode(['extra_gb' => $extraGb]),
                'status' => 'pending',
                'created_at' => date('Y-m-d H:i:s')
            ]);
            echo json_encode(['success' => true, 'order_id' => $orderId, 'amount' => $price]);
            exit;

        // === ПОДПИСКА ===
        case 'get_subscription':
            echo json_encode(['success' => true, 'plan' => $shop['plan'], 'expires' => $shop['plan_expires_at'], 'trial_used' => $shop['trial_used']]);
            exit;
        case 'upgrade_plan':
            $newPlan = $input['plan'] ?? 'basic';
            $price = ['basic' => 990, 'business' => 2990, 'premium' => 9900][$newPlan];
            $orderId = $db->insert('payment_orders', [
                'user_id' => $userId,
                'type' => 'subscription',
                'amount' => $price,
                'data' => json_encode(['plan' => $newPlan]),
                'status' => 'pending',
                'created_at' => date('Y-m-d H:i:s')
            ]);
            echo json_encode(['success' => true, 'order_id' => $orderId, 'amount' => $price]);
            exit;

        // === РЕКЛАМА (баннеры) ===
        case 'request_banner':
            $image = $_FILES['image'] ?? null;
            $city = $input['city'] ?? '';
            $duration = (int)($input['duration'] ?? 7);
            $targetUrl = $input['target_url'] ?? '';

            if (!$image || $image['error'] !== UPLOAD_ERR_OK) {
                echo json_encode(['success' => false, 'error' => 'Файл не загружен']);
                exit;
            }
            $optimizer = new ImageOptimizer(ROOT_DIR . '/uploads/');
            $result = $optimizer->optimize($image['tmp_name'], [
                'width' => 1200,
                'quality' => 80,
                'format' => 'webp',
                'user_id' => $userId,
                'context' => 'banner_request'
            ]);
            if (!$result['success']) {
                echo json_encode(['success' => false, 'error' => 'Ошибка обработки изображения']);
                exit;
            }
            $imageUrl = '/uploads/optimized/' . basename($result['optimized_path']);
            $price = 500 * $duration; // 500 ₽ за неделю
            $orderId = $db->insert('payment_orders', [
                'user_id' => $userId,
                'type' => 'banner',
                'amount' => $price,
                'data' => json_encode(['image_url' => $imageUrl, 'city' => $city, 'duration' => $duration, 'target_url' => $targetUrl]),
                'status' => 'pending',
                'created_at' => date('Y-m-d H:i:s')
            ]);
            echo json_encode(['success' => true, 'order_id' => $orderId, 'amount' => $price]);
            exit;

        // === ЗАКАЗЫ ===
        case 'get_orders':
            $page = (int)($input['page'] ?? 1);
            $limit = 20;
            $offset = ($page - 1) * $limit;
            $status = $input['status'] ?? '';
            $dateFrom = $input['date_from'] ?? '';
            $dateTo = $input['date_to'] ?? '';

            $where = ['shop_id = ?'];
            $params = [$shopId];
            if ($status && $status !== 'all') {
                $where[] = 'status = ?';
                $params[] = $status;
            }
            if ($dateFrom) {
                $where[] = 'created_at >= ?';
                $params[] = $dateFrom . ' 00:00:00';
            }
            if ($dateTo) {
                $where[] = 'created_at <= ?';
                $params[] = $dateTo . ' 23:59:59';
            }
            $whereClause = implode(' AND ', $where);
            $total = $db->fetchCount("SELECT COUNT(*) FROM shop_orders WHERE $whereClause", $params);
            $orders = $db->fetchAll("
                SELECT * FROM shop_orders
                WHERE $whereClause
                ORDER BY created_at DESC
                LIMIT ? OFFSET ?
            ", array_merge($params, [$limit, $offset]));

            foreach ($orders as &$order) {
                $order['items_count'] = $db->fetchCount("SELECT COUNT(*) FROM shop_order_items WHERE order_id = ?", [$order['id']]);
            }
            echo json_encode(['success' => true, 'orders' => $orders, 'total' => $total, 'page' => $page, 'pages' => ceil($total / $limit)]);
            exit;

        case 'get_order_details':
            $orderId = (int)($input['order_id'] ?? 0);
            $order = $db->fetchOne("SELECT * FROM shop_orders WHERE id = ? AND shop_id = ?", [$orderId, $shopId]);
            if (!$order) {
                echo json_encode(['success' => false, 'error' => 'Заказ не найден']);
                exit;
            }
            $items = $db->fetchAll("
                SELECT oi.*,
                       COALESCE(p.name, s.name) as name
                FROM shop_order_items oi
                LEFT JOIN shop_products p ON oi.product_id = p.id
                LEFT JOIN shop_services s ON oi.service_id = s.id
                WHERE oi.order_id = ?
            ", [$orderId]);
            $order['items'] = $items;
            echo json_encode(['success' => true, 'order' => $order]);
            exit;

        case 'update_order_status':
            $orderId = (int)($input['order_id'] ?? 0);
            $newStatus = $input['status'] ?? '';
            $allowedStatuses = ['pending','paid','processing','shipped','completed','cancelled'];
            if (!in_array($newStatus, $allowedStatuses)) {
                echo json_encode(['success' => false, 'error' => 'Недопустимый статус']);
                exit;
            }
            $order = $db->fetchOne("SELECT id FROM shop_orders WHERE id = ? AND shop_id = ?", [$orderId, $shopId]);
            if (!$order) {
                echo json_encode(['success' => false, 'error' => 'Заказ не найден']);
                exit;
            }
            $db->update('shop_orders', ['status' => $newStatus, 'updated_at' => date('Y-m-d H:i:s')], 'id = ?', [$orderId]);
            echo json_encode(['success' => true]);
            exit;

        case 'export_orders':
            $format = $input['format'] ?? 'csv';
            $status = $input['status'] ?? '';
            $dateFrom = $input['date_from'] ?? '';
            $dateTo = $input['date_to'] ?? '';

            $where = ['shop_id = ?'];
            $params = [$shopId];
            if ($status && $status !== 'all') {
                $where[] = 'status = ?';
                $params[] = $status;
            }
            if ($dateFrom) {
                $where[] = 'created_at >= ?';
                $params[] = $dateFrom . ' 00:00:00';
            }
            if ($dateTo) {
                $where[] = 'created_at <= ?';
                $params[] = $dateTo . ' 23:59:59';
            }
            $whereClause = implode(' AND ', $where);
            $orders = $db->fetchAll("SELECT * FROM shop_orders WHERE $whereClause ORDER BY created_at DESC", $params);

            $filename = "orders_export_" . date('Ymd_His');
            if ($format === 'csv') {
                $csvFile = ROOT_DIR . "/storage/exports/{$filename}.csv";
                if (!is_dir(ROOT_DIR . '/storage/exports')) mkdir(ROOT_DIR . '/storage/exports', 0755, true);
                $fp = fopen($csvFile, 'w');
                fputcsv($fp, ['ID', 'Дата', 'Сумма', 'Статус', 'Имя покупателя', 'Email', 'Телефон', 'Адрес']);
                foreach ($orders as $o) {
                    fputcsv($fp, [$o['id'], $o['created_at'], $o['total'], $o['status'], $o['customer_name'], $o['customer_email'], $o['customer_phone'], $o['delivery_address']]);
                }
                fclose($fp);
                echo json_encode(['success' => true, 'url' => "/storage/exports/{$filename}.csv"]);
            } elseif ($format === 'pdf') {
                if (!class_exists('Mpdf\Mpdf')) {
                    echo json_encode(['success' => false, 'error' => 'Для PDF требуется установка mPDF']);
                    exit;
                }
                $html = '<h1>Заказы ' . date('Y-m-d') . '</h1><table border="1"><thead>汽<th>ID</th><th>Дата</th><th>Сумма</th><th>Статус</th><th>Покупатель</th> </thead><tbody>';
                foreach ($orders as $o) {
                    $html .= "汽<td>{$o['id']}</td><td>{$o['created_at']}</td><td>{$o['total']}</td><td>{$o['status']}</td><td>{$o['customer_name']}</td>汽";
                }
                $html .= '</tbody> </table>';
                $mpdf = new \Mpdf\Mpdf();
                $mpdf->WriteHTML($html);
                $pdfFile = ROOT_DIR . "/storage/exports/{$filename}.pdf";
                $mpdf->Output($pdfFile, 'F');
                echo json_encode(['success' => true, 'url' => "/storage/exports/{$filename}.pdf"]);
            }
            exit;

        // === AI-ФУНКЦИИ ===
        case 'ai_generate_description':
            if (empty($shop['deepseek_api_key'])) {
                echo json_encode(['success' => false, 'error' => 'Не введён API-ключ DeepSeek']);
                exit;
            }
            $prompt = "Напиши продающее SEO-описание для товара.\nНазвание: {$input['name']}\nКатегория: {$input['category']}\nЦена: {$input['price']} руб.\n3-4 предложения.";
            $result = callAI($shop['deepseek_api_key'], $prompt);
            echo json_encode(['success' => $result['success'], 'description' => $result['data'] ?? '']);
            exit;
        case 'ai_forecast':
            if (empty($shop['deepseek_api_key'])) {
                echo json_encode(['success' => false, 'error' => 'Не введён API-ключ DeepSeek']);
                exit;
            }
            $history = $db->fetchAll("SELECT DATE(converted_at) as date, COUNT(*) as sales FROM partner_conversions WHERE status = 'approved' AND converted_at > NOW() - INTERVAL 30 DAY GROUP BY DATE(converted_at)");
            $prompt = "На основе данных:\n" . json_encode($history) . "\nСделай прогноз на 7 дней. Ответ в JSON: {\"forecast\":[{\"date\":\"YYYY-MM-DD\",\"sales\":число}]}";
            $result = callAI($shop['deepseek_api_key'], $prompt);
            if ($result['success']) {
                $forecast = json_decode($result['data'], true);
                echo json_encode(['success' => true, 'forecast' => $forecast['forecast'] ?? []]);
            } else {
                echo json_encode(['success' => false, 'error' => $result['error']]);
            }
            exit;
        case 'ai_review_analysis':
            if (empty($shop['deepseek_api_key'])) {
                echo json_encode(['success' => false, 'error' => 'Не введён API-ключ DeepSeek']);
                exit;
            }
            $reviews = $db->fetchAll("SELECT rating, comment FROM reviews WHERE reviewed_id = ? AND comment IS NOT NULL AND comment != '' ORDER BY created_at DESC LIMIT 20", [$userId]);
            if (empty($reviews)) {
                echo json_encode(['success' => true, 'analysis' => 'Нет отзывов для анализа']);
                exit;
            }
            $prompt = "Проанализируй отзывы и выдели ключевые плюсы и минусы. Формат:\nПлюсы: ...\nМинусы: ...\nРекомендации: ...\n\nОтзывы:\n" . json_encode($reviews);
            $result = callAI($shop['deepseek_api_key'], $prompt);
            echo json_encode(['success' => $result['success'], 'analysis' => $result['data'] ?? '']);
            exit;
        case 'ai_similar_products':
            if (empty($shop['deepseek_api_key'])) {
                echo json_encode(['success' => false, 'error' => 'Не введён API-ключ DeepSeek']);
                exit;
            }
            $description = $input['description'] ?? '';
            $products = $db->fetchAll("SELECT id, name, description, price FROM shop_products WHERE shop_id = ? LIMIT 50", [$shopId]);
            $prompt = "Пользователь ищет: \"{$description}\". Вот товары:\n" . json_encode($products) . "\nВыбери до 5 наиболее подходящих и верни их ID в JSON: [id1, id2, ...]";
            $result = callAI($shop['deepseek_api_key'], $prompt);
            if ($result['success']) {
                $ids = json_decode($result['data'], true);
                if (is_array($ids)) {
                    $similar = $db->fetchAll("SELECT * FROM shop_products WHERE id IN (" . implode(',', array_fill(0, count($ids), '?')) . ")", $ids);
                    echo json_encode(['success' => true, 'products' => $similar]);
                } else {
                    echo json_encode(['success' => true, 'products' => []]);
                }
            } else {
                echo json_encode(['success' => false, 'error' => $result['error']]);
            }
            exit;
        case 'ai_optimize_listings':
            if (empty($shop['deepseek_api_key'])) {
                echo json_encode(['success' => false, 'error' => 'Не введён API-ключ DeepSeek']);
                exit;
            }
            $listings = $db->fetchAll("SELECT id, title, description FROM listings WHERE user_id = ? AND status = 'approved' LIMIT 10", [$userId]);
            if (empty($listings)) {
                echo json_encode(['success' => true, 'optimized' => []]);
                exit;
            }
            $prompt = "Для каждого объявления сгенерируй улучшенный заголовок и описание. Верни JSON: [{\"id\": число, \"title\": \"...\", \"description\": \"...\"}]\nОбъявления:\n" . json_encode($listings);
            $result = callAI($shop['deepseek_api_key'], $prompt);
            if ($result['success']) {
                $optimized = json_decode($result['data'], true);
                echo json_encode(['success' => true, 'optimized' => $optimized]);
            } else {
                echo json_encode(['success' => false, 'error' => $result['error']]);
            }
            exit;

        // === ОТЗЫВЫ ===
        case 'reply_review':
            $reviewId = (int)($input['review_id'] ?? 0);
            $reply = trim($input['reply'] ?? '');
            if (empty($reply)) {
                echo json_encode(['success' => false, 'error' => 'Текст ответа не может быть пустым']);
                exit;
            }
            $review = $db->fetchOne("SELECT id FROM shop_reviews WHERE id = ? AND shop_id = ?", [$reviewId, $shopId]);
            if (!$review) {
                echo json_encode(['success' => false, 'error' => 'Отзыв не найден']);
                exit;
            }
            $db->update('shop_reviews', ['seller_reply' => $reply, 'replied_at' => date('Y-m-d H:i:s')], 'id = ?', [$reviewId]);
            echo json_encode(['success' => true]);
            exit;

        default:
            echo json_encode(['success' => false, 'error' => 'Unknown action']);
            exit;
    }
}

function callAI($apiKey, $prompt) {
    $ch = curl_init('https://api.deepseek.com/v1/chat/completions');
    $payload = [
        'model' => 'deepseek-chat',
        'messages' => [['role' => 'user', 'content' => $prompt]],
        'temperature' => 0.5,
        'max_tokens' => 800
    ];
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json', 'Authorization: Bearer ' . $apiKey]);
    $response = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($code !== 200) return ['success' => false, 'error' => "HTTP $code"];
    $data = json_decode($response, true);
    return ['success' => true, 'data' => $data['choices'][0]['message']['content']];
}

// ==================== СТАТИСТИКА ДЛЯ ДАШБОРДА ====================
$stats = [
    'products' => $db->fetchCount("SELECT COUNT(*) FROM shop_products WHERE shop_id = ?", [$shopId]),
    'orders' => $db->fetchCount("SELECT COUNT(*) FROM shop_orders WHERE shop_id = ?", [$shopId]),
    'revenue' => $db->fetchColumn("SELECT COALESCE(SUM(total), 0) FROM shop_orders WHERE shop_id = ?", [$shopId]),
    'views' => $db->fetchColumn("SELECT COALESCE(SUM(views), 0) FROM shop_products WHERE shop_id = ?", [$shopId]),
    'recent_orders' => $db->fetchAll("SELECT * FROM shop_orders WHERE shop_id = ? ORDER BY created_at DESC LIMIT 5", [$shopId])
];
$chartData = [];
for ($i = 6; $i >= 0; $i--) {
    $date = date('Y-m-d', strtotime("-$i days"));
    $views = $db->fetchColumn("SELECT COALESCE(SUM(views), 0) FROM shop_products WHERE shop_id = ? AND DATE(updated_at) = ?", [$shopId, $date]);
    $chartData[] = ['date' => $date, 'views' => $views];
}

$availableBlocks = [
    ['type' => 'hero', 'name' => 'Герой-блок', 'icon' => '🏠'],
    ['type' => 'products', 'name' => 'Товары', 'icon' => '📦'],
    ['type' => 'services', 'name' => 'Услуги', 'icon' => '⚙️'],
    ['type' => 'reviews', 'name' => 'Отзывы', 'icon' => '⭐'],
    ['type' => 'faq', 'name' => 'Вопросы-ответы', 'icon' => '❓'],
    ['type' => 'map', 'name' => 'Карта', 'icon' => '🗺️'],
    ['type' => 'contacts', 'name' => 'Контакты', 'icon' => '📞'],
    ['type' => 'gallery', 'name' => 'Галерея', 'icon' => '🖼️']
];
$layout = json_decode($shop['layout'] ?? '[]', true);
$faq = json_decode($shop['faq'] ?? '[]', true);
$pageTitle = 'Бизнес-кабинет — Найдук';
$paymentMethods = json_decode($shop['payment_methods'] ?? '["ЮKassa","Наличные","Карта","СБП"]', true);

// ==================== ДОПОЛНИТЕЛЬНАЯ ФУНКЦИЯ ДЛЯ ИСТОРИИ ПЛАТЕЖЕЙ ====================
function loadPaymentHistory() {
    global $db, $userId;
    $payments = $db->fetchAll("SELECT * FROM payment_orders WHERE user_id = ? ORDER BY created_at DESC", [$userId]);
    return $payments;
}
$payments = loadPaymentHistory();
?>
<!DOCTYPE html>
<html lang="ru" data-theme="<?= htmlspecialchars($shop['theme'] ?? 'light') ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <meta name="csrf-token" content="<?= $csrfToken ?>">
    <title><?= htmlspecialchars($pageTitle) ?></title>
    <link rel="stylesheet" href="https://cdn.hugeicons.com/font/hgi-stroke-rounded.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/toastify-js/src/toastify.min.css">
    <link rel="stylesheet" href="/css/business.css">
    <link rel="stylesheet" href="/css/premium.css">
    <link rel="stylesheet" href="/css/theme-switch.css">
    <link rel="stylesheet" href="/css/themes.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/fullcalendar@6.1.15/main.min.css">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { background: var(--bg); color: var(--text); font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; line-height: 1.5; }
        .dashboard { display: flex; min-height: 100vh; }
        .sidebar { width: 280px; background: var(--surface); border-right: 1px solid var(--border); padding: 30px 20px; position: fixed; height: 100vh; overflow-y: auto; z-index: 10; transition: transform 0.3s; }
        .main { flex: 1; margin-left: 280px; padding: 30px; }
        .sidebar h2 { font-size: 24px; margin-bottom: 30px; background: linear-gradient(135deg, var(--primary), var(--primary)); -webkit-background-clip: text; background-clip: text; color: transparent; }
        .sidebar-nav a { display: flex; align-items: center; gap: 12px; padding: 12px 16px; border-radius: 14px; color: var(--text); text-decoration: none; margin-bottom: 6px; transition: all 0.2s; }
        .sidebar-nav a:hover, .sidebar-nav a.active { background: rgba(74,144,226,0.1); color: var(--primary); }
        .kpi-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 20px; margin-bottom: 30px; }
        .kpi-card { background: var(--surface); border: 1px solid var(--border); border-radius: var(--radius-xl); padding: 20px; box-shadow: var(--card-shadow); transition: transform 0.2s; }
        .kpi-card:hover { transform: translateY(-4px); }
        .kpi-value { font-size: 32px; font-weight: 700; margin-top: 8px; }
        .card { background: var(--surface); border: 1px solid var(--border); border-radius: var(--radius-xl); padding: 20px; margin-bottom: 30px; }
        .card-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px; flex-wrap: wrap; gap: 12px; }
        .btn { padding: 8px 20px; border-radius: var(--radius-full); background: var(--primary); color: white; border: none; cursor: pointer; font-weight: 600; transition: opacity 0.2s; }
        .btn-secondary { background: var(--bg); color: var(--text); border: 1px solid var(--border); }
        .btn-sm { padding: 4px 12px; font-size: 12px; }
        .btn-danger { background: #FF3B30; color: white; }
        .tab-content { display: none; }
        .tab-content.active { display: block; }
        .modal { display: none; position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0,0,0,0.5); align-items: center; justify-content: center; z-index: 1000; }
        .modal.active { display: flex; }
        .modal-content { background: var(--surface); border-radius: var(--radius-xl); padding: 30px; max-width: 600px; width: 90%; max-height: 80vh; overflow-y: auto; }
        .form-group { margin-bottom: 16px; }
        .form-input, .form-select, .form-textarea { width: 100%; padding: 10px; border: 1px solid var(--border); border-radius: 12px; background: var(--bg); color: var(--text); }
        .dropzone { border: 2px dashed var(--border); border-radius: var(--radius); padding: 20px; text-align: center; cursor: pointer; margin-bottom: 16px; }
        .photo-preview-grid { display: flex; flex-wrap: wrap; gap: 8px; margin-top: 12px; }
        .photo-preview { position: relative; width: 80px; height: 80px; border-radius: var(--radius); overflow: hidden; }
        .photo-preview img { width: 100%; height: 100%; object-fit: cover; }
        .remove-photo { position: absolute; top: 2px; right: 2px; background: rgba(0,0,0,0.5); color: white; border: none; border-radius: 50%; width: 20px; height: 20px; cursor: pointer; }
        .block-list { display: flex; flex-direction: column; gap: 12px; }
        .block-item { background: var(--surface); border: 1px solid var(--border); border-radius: var(--radius); padding: 12px; display: flex; justify-content: space-between; align-items: center; cursor: move; }
        .block-item[contenteditable="true"] { outline: 2px solid var(--primary); }
        @media (max-width: 768px) {
            .sidebar { transform: translateX(-100%); position: fixed; }
            .sidebar.open { transform: translateX(0); }
            .main { margin-left: 0; padding: 20px; padding-top: 60px; }
            .mobile-menu-btn { display: block; position: fixed; top: 16px; left: 16px; z-index: 20; background: var(--surface); border: 1px solid var(--border); border-radius: 30px; padding: 8px 12px; cursor: pointer; }
            .kpi-grid { grid-template-columns: 1fr 1fr; }
            .kpi-value { font-size: 24px; }
            .product-table, .orders-table { display: block; overflow-x: auto; }
        }
        .theme-selector { display: flex; gap: 8px; margin-top: 20px; flex-wrap: wrap; }
        .theme-option { cursor: pointer; padding: 4px 12px; border-radius: 20px; background: var(--bg); border: 1px solid var(--border); font-size: 12px; }
        .theme-option.active { background: var(--primary); color: white; border-color: var(--primary); }
        .progress-bar { background: var(--border); border-radius: 10px; overflow: hidden; }
        .progress-fill { background: var(--primary); height: 10px; width: 0%; }
        .orders-filters { display: flex; gap: 12px; margin-bottom: 20px; flex-wrap: wrap; }
        .order-card { background: var(--surface); border: 1px solid var(--border); border-radius: var(--radius); padding: 16px; margin-bottom: 16px; }
        .order-header { display: flex; justify-content: space-between; margin-bottom: 12px; flex-wrap: wrap; }
        .order-status { font-weight: 600; padding: 2px 8px; border-radius: 20px; background: rgba(52,199,89,0.1); color: var(--success); }
        .order-status.pending { background: rgba(255,149,0,0.1); color: var(--warning); }
        .order-status.cancelled { background: rgba(255,59,48,0.1); color: var(--danger); }
        .order-details { font-size: 14px; color: var(--text-secondary); }
        .order-actions { margin-top: 12px; display: flex; gap: 12px; }
        .review-card { margin-bottom: 16px; }
        .review-reply { margin-top: 12px; padding: 12px; background: var(--bg-secondary); border-radius: var(--radius); }
        .reply-form { margin-top: 12px; display: none; }
        .reply-form textarea { width: 100%; margin-bottom: 8px; }
        .badge { display: inline-block; padding: 2px 8px; border-radius: 20px; font-size: 12px; font-weight: 600; }
        .badge-success { background: rgba(52,199,89,0.1); color: var(--success); }
        .badge-warning { background: rgba(255,149,0,0.1); color: var(--warning); }
        .payment-row { display: flex; justify-content: space-between; padding: 8px 0; border-bottom: 1px solid var(--border-light); }
    </style>
</head>
<body>
<div class="dashboard">
    <div class="mobile-menu-btn" id="mobileMenuBtn">☰ Меню</div>
    <aside class="sidebar" id="sidebar">
        <h2>🏢 Найдук Бизнес</h2>
        <nav class="sidebar-nav">
            <a href="#" class="active" data-tab="dashboard"><span>📊</span> Дашборд</a>
            <a href="#" data-tab="design"><span>🎨</span> Конструктор</a>
            <a href="#" data-tab="products"><span>📦</span> Товары</a>
            <a href="#" data-tab="services"><span>⚙️</span> Услуги</a>
            <a href="#" data-tab="orders"><span>📋</span> Заказы</a>
            <a href="#" data-tab="faq"><span>❓</span> FAQ</a>
            <a href="#" data-tab="reviews"><span>⭐</span> Отзывы</a>
            <a href="#" data-tab="storage"><span>💾</span> Хранилище</a>
            <a href="#" data-tab="finance"><span>💰</span> Финансы</a>
            <a href="#" data-tab="ads"><span>📢</span> Реклама</a>
            <a href="#" data-tab="ai"><span>🤖</span> AI-помощник</a>
            <a href="#" data-tab="settings"><span>⚙️</span> Настройки</a>
            <a href="#" data-tab="seo"><span>🔍</span> SEO</a>
        </nav>
        <div class="theme-selector" id="themeSelector">
            <div class="theme-option" data-theme="light">☀️ Светлая</div>
            <div class="theme-option" data-theme="dark">🌙 Тёмная</div>
            <div class="theme-option" data-theme="neutral">⚪ Нейтральная</div>
            <div class="theme-option" data-theme="contrast">🎨 Контрастная</div>
            <div class="theme-option" data-theme="nature">🌿 Природная</div>
        </div>
    </aside>

    <main class="main">
        <!-- ==================== ДАШБОРД ==================== -->
        <div id="dashboard-tab" class="tab-content active">
            <div class="kpi-grid">
                <div class="kpi-card"><div class="kpi-label">Выручка</div><div class="kpi-value" id="stat-revenue"><?= number_format($stats['revenue'], 0, ',', ' ') ?> ₽</div></div>
                <div class="kpi-card"><div class="kpi-label">Заказы</div><div class="kpi-value" id="stat-orders"><?= $stats['orders'] ?></div></div>
                <div class="kpi-card"><div class="kpi-label">Средний чек</div><div class="kpi-value" id="stat-aov"><?= $stats['orders'] ? number_format($stats['revenue'] / $stats['orders'], 0, ',', ' ') : 0 ?> ₽</div></div>
                <div class="kpi-card"><div class="kpi-label">Товаров</div><div class="kpi-value" id="stat-products"><?= $stats['products'] ?></div></div>
                <div class="kpi-card"><div class="kpi-label">Просмотры</div><div class="kpi-value" id="stat-views"><?= $stats['views'] ?></div></div>
                <div class="kpi-card"><div class="kpi-label">Остатки</div><div class="kpi-value" id="stat-stockout">—</div></div>
            </div>
            <div class="card"><canvas id="viewsChart" height="200"></canvas></div>
            <div class="card"><div class="card-header"><h3>Последние заказы</h3><a href="#" data-tab="orders" class="btn btn-secondary btn-sm">Все заказы →</a></div>
                <table class="orders-table" style="width:100%; border-collapse:collapse;"><?php foreach ($stats['recent_orders'] as $order): ?>
                    <tr style="border-bottom:1px solid var(--border);"><td style="padding:8px;">#<?= $order['id'] ?></td><td style="padding:8px;"><?= date('d.m.Y', strtotime($order['created_at'])) ?></td><td style="padding:8px;"><?= number_format($order['total'], 0, ',', ' ') ?> ₽</td><td style="padding:8px;"><span class="badge <?= $order['status'] === 'completed' ? 'badge-success' : ($order['status'] === 'cancelled' ? 'badge-warning' : '') ?>"><?= $order['status'] ?></span></td></tr>
                <?php endforeach; ?></table>
            </div>
            <div class="card"><div class="card-header"><h3>Рекомендации по продвижению</h3></div>
                <ul>
                    <li>📝 Обновите описание хотя бы одного товара — это повышает шанс получить бонусные просмотры</li>
                    <li>⏱️ Ваше среднее время ответа — <span id="avg-response-time">—</span> минут. Быстрые ответы улучшают позицию в выдаче</li>
                    <li>⭐ Запросите отзыв у 3 последних покупателей — это повысит доверие к магазину</li>
                </ul>
            </div>
        </div>

        <!-- ==================== КОНСТРУКТОР ==================== -->
        <div id="design-tab" class="tab-content">
            <div class="card"><div class="card-header"><h3>Доступные блоки</h3></div>
                <div id="available-blocks" class="block-list">
                    <?php foreach ($availableBlocks as $b): ?>
                        <div class="block-item" draggable="true" data-type="<?= $b['type'] ?>">
                            <span><strong><?= $b['icon'] ?> <?= $b['name'] ?></strong></span>
                            <button class="add-block" data-type="<?= $b['type'] ?>">➕ Добавить</button>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <div class="card"><div class="card-header"><h3>Макет страницы</h3><button id="save-layout" class="btn btn-sm">Сохранить</button></div>
                <div id="layout-list" class="block-list"></div>
            </div>
        </div>

        <!-- ==================== ТОВАРЫ ==================== -->
        <div id="products-tab" class="tab-content">
            <div class="card-header"><h3>Товары</h3><div><button class="btn" id="add-product-btn">+ Добавить товар</button><button class="btn btn-secondary" id="bulk-price-btn">Массовая скидка</button></div></div>
            <div id="products-list"></div>
            <div id="products-pagination" class="pagination"></div>
        </div>

        <!-- ==================== УСЛУГИ ==================== -->
        <div id="services-tab" class="tab-content">
            <div class="card-header"><h3>Услуги</h3><button class="btn" id="add-service-btn">+ Добавить услугу</button></div>
            <div id="services-list"></div>
        </div>

        <!-- ==================== ЗАКАЗЫ ==================== -->
        <div id="orders-tab" class="tab-content">
            <div class="card-header"><h3>Заказы</h3><div><button id="export-orders-csv" class="btn btn-secondary btn-sm">📄 CSV</button><button id="export-orders-pdf" class="btn btn-secondary btn-sm">📄 PDF</button></div></div>
            <div class="orders-filters">
                <select id="order-status-filter">
                    <option value="all">Все статусы</option>
                    <option value="pending">Ожидает</option>
                    <option value="paid">Оплачен</option>
                    <option value="processing">В обработке</option>
                    <option value="shipped">Отправлен</option>
                    <option value="completed">Завершён</option>
                    <option value="cancelled">Отменён</option>
                </select>
                <input type="date" id="order-date-from" placeholder="С даты">
                <input type="date" id="order-date-to" placeholder="По дату">
                <button id="apply-order-filters" class="btn btn-secondary btn-sm">Применить</button>
            </div>
            <div id="orders-list"></div>
            <div id="orders-pagination" class="pagination"></div>
        </div>

        <!-- ==================== FAQ ==================== -->
        <div id="faq-tab" class="tab-content">
            <div class="card-header"><h3>Вопросы-ответы</h3><button id="add-faq-btn" class="btn">+ Добавить вопрос</button></div>
            <div id="faq-list"></div>
            <button id="save-faq" class="btn" style="margin-top: 20px;">Сохранить FAQ</button>
        </div>

        <!-- ==================== ОТЗЫВЫ ==================== -->
        <div id="reviews-tab" class="tab-content">
            <div class="card-header"><h3>Отзывы о магазине</h3></div>
            <div id="reviews-list"></div>
            <div id="reviews-pagination" class="pagination"></div>
        </div>

        <!-- ==================== ХРАНИЛИЩЕ ==================== -->
        <div id="storage-tab" class="tab-content">
            <div class="card"><div class="card-header"><h3>Использование хранилища</h3></div>
                <div id="storage-info"></div>
                <div class="form-group"><label>Купить дополнительное место</label>
                    <select id="extra-gb" class="form-select"><option value="1">1 ГБ (500 ₽)</option><option value="5">5 ГБ (2500 ₽)</option><option value="10">10 ГБ (5000 ₽)</option></select>
                    <button id="buy-storage" class="btn">Оплатить</button>
                </div>
            </div>
        </div>

        <!-- ==================== ФИНАНСЫ ==================== -->
        <div id="finance-tab" class="tab-content">
            <div class="card"><div class="card-header"><h3>Подписка</h3></div>
                <div id="subscription-info"></div>
                <div class="form-group"><label>Сменить тариф</label>
                    <select id="plan-select" class="form-select"><option value="basic">Базовый (990 ₽/мес)</option><option value="business">Бизнес (2990 ₽/мес)</option><option value="premium">Премиум (9900 ₽/мес)</option></select>
                    <button id="upgrade-plan" class="btn">Оплатить</button>
                </div>
            </div>
            <div class="card"><div class="card-header"><h3>История платежей</h3></div>
                <div id="payment-history"></div>
            </div>
        </div>

        <!-- ==================== РЕКЛАМА ==================== -->
        <div id="ads-tab" class="tab-content">
            <div class="card"><div class="card-header"><h3>Заказать баннер на главной</h3></div>
                <form id="banner-form" enctype="multipart/form-data">
                    <div class="form-group"><label>Изображение (JPG, PNG, WebP)</label><input type="file" name="image" accept="image/*" required></div>
                    <div class="form-group"><label>Город (оставьте пустым для всей России)</label><input type="text" name="city" placeholder="Например: Москва"></div>
                    <div class="form-group"><label>Ссылка (необязательно)</label><input type="url" name="target_url" placeholder="https://..."></div>
                    <div class="form-group"><label>Длительность (недель)</label><select name="duration"><option value="1">1 неделя (500 ₽)</option><option value="2">2 недели (1000 ₽)</option><option value="4">4 недели (2000 ₽)</option></select></div>
                    <button type="submit" class="btn">Заказать и оплатить</button>
                </form>
            </div>
            <div class="card"><div class="card-header"><h3>Мои заявки на баннеры</h3></div>
                <div id="banner-requests"></div>
            </div>
        </div>

        <!-- ==================== AI-ПОМОЩНИК ==================== -->
        <div id="ai-tab" class="tab-content">
            <div class="card"><div class="card-header"><h3>🤖 AI-помощник</h3></div>
                <div class="ai-tools">
                    <div class="ai-section"><h4>Генерация описания товара</h4>
                        <div class="form-group"><label>Название</label><input type="text" id="ai-product-name" class="form-input"></div>
                        <div class="form-group"><label>Категория</label><input type="text" id="ai-product-category" class="form-input"></div>
                        <div class="form-group"><label>Цена</label><input type="number" id="ai-product-price" class="form-input"></div>
                        <button id="generate-description" class="btn">Сгенерировать</button>
                        <div id="ai-description-result" class="form-textarea" style="margin-top:16px;"></div>
                    </div>
                    <div class="ai-section"><h4>Прогноз продаж</h4><button id="forecast-btn" class="btn">Прогноз</button><div id="forecast-result"></div></div>
                    <div class="ai-section"><h4>Анализ отзывов</h4><button id="review-analysis-btn" class="btn">Анализировать</button><div id="review-analysis-result"></div></div>
                    <div class="ai-section"><h4>Поиск похожих товаров</h4><textarea id="similar-search-text" class="form-textarea" rows="3" placeholder="Описание товара"></textarea><button id="similar-search-btn" class="btn">Найти</button><div id="similar-results"></div></div>
                </div>
            </div>
        </div>

        <!-- ==================== НАСТРОЙКИ ==================== -->
        <div id="settings-tab" class="tab-content">
            <form id="shop-settings-form">
                <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
                <div class="form-group"><label>Название магазина</label><input type="text" name="name" class="form-input" value="<?= htmlspecialchars($shop['name']) ?>"></div>
                <div class="form-group"><label>Описание</label><textarea name="description" class="form-textarea"><?= htmlspecialchars($shop['description']) ?></textarea></div>
                <div class="form-group"><label>Телефон</label><input type="tel" name="contact_phone" class="form-input" value="<?= htmlspecialchars($shop['contact_phone']) ?>"></div>
                <div class="form-group"><label>Email</label><input type="email" name="contact_email" class="form-input" value="<?= htmlspecialchars($shop['contact_email']) ?>"></div>
                <div class="form-group"><label>Telegram</label><input type="text" name="contact_telegram" class="form-input" value="<?= htmlspecialchars($shop['contact_telegram']) ?>"></div>
                <div class="form-group"><label>WhatsApp</label><input type="text" name="contact_whatsapp" class="form-input" value="<?= htmlspecialchars($shop['contact_whatsapp']) ?>"></div>
                <div class="form-group"><label>Instagram</label><input type="text" name="contact_instagram" class="form-input" value="<?= htmlspecialchars($shop['contact_instagram']) ?>"></div>
                <div class="form-group"><label>YouTube</label><input type="text" name="contact_youtube" class="form-input" value="<?= htmlspecialchars($shop['contact_youtube']) ?>"></div>
                <div class="form-group"><label>Адрес</label><input type="text" name="address" class="form-input" value="<?= htmlspecialchars($shop['address']) ?>"></div>
                <div class="form-group"><label>DeepSeek API ключ</label><input type="text" name="deepseek_api_key" class="form-input" value="<?= htmlspecialchars($shop['deepseek_api_key']) ?>" placeholder="sk-..."></div>
                <div class="form-group"><label>Способы оплаты (выберите)</label>
                    <div id="payment-methods-checkboxes">
                        <?php foreach (['ЮKassa','Наличные','Карта','СБП'] as $method): ?>
                            <label><input type="checkbox" name="payment_methods[]" value="<?= $method ?>" <?= in_array($method, $paymentMethods) ? 'checked' : '' ?>> <?= $method ?></label><br>
                        <?php endforeach; ?>
                    </div>
                </div>
                <div class="form-group"><label>Логотип</label><input type="file" id="logo-file" accept="image/*"><div id="logo-preview"></div></div>
                <div class="form-group"><label>Баннер</label><input type="file" id="banner-file" accept="image/*"><div id="banner-preview"></div></div>
                <button type="submit" class="btn">Сохранить</button>
            </form>
        </div>

        <!-- ==================== SEO ==================== -->
        <div id="seo-tab" class="tab-content">
            <form id="seo-form">
                <div class="form-group"><label>ЧПУ-адрес (slug)</label><input type="text" name="slug" class="form-input" value="<?= htmlspecialchars($shop['slug']) ?>"></div>
                <div class="form-group"><label>SEO-заголовок (title)</label><input type="text" name="seo_title" class="form-input" value="<?= htmlspecialchars($shop['seo_title']) ?>"></div>
                <div class="form-group"><label>SEO-описание (description)</label><textarea name="seo_description" class="form-textarea"><?= htmlspecialchars($shop['seo_description']) ?></textarea></div>
                <div class="form-group"><label>Ключевые слова</label><input type="text" name="seo_keywords" class="form-input" value="<?= htmlspecialchars($shop['seo_keywords']) ?>"></div>
                <button type="submit" class="btn">Сохранить SEO</button>
            </form>
        </div>
    </main>
</div>

<!-- Модальные окна -->
<div id="productModal" class="modal"><div class="modal-content"><div id="productModalBody"></div><div class="modal-actions"><button class="btn btn-secondary" onclick="closeModal('productModal')">Отмена</button><button class="btn" id="saveProductBtn">Сохранить</button></div></div></div>
<div id="serviceModal" class="modal"><div class="modal-content"><div id="serviceModalBody"></div><div class="modal-actions"><button class="btn btn-secondary" onclick="closeModal('serviceModal')">Отмена</button><button class="btn" id="saveServiceBtn">Сохранить</button></div></div></div>
<div id="orderDetailModal" class="modal"><div class="modal-content"><div id="orderDetailBody"></div><div class="modal-actions"><button class="btn btn-secondary" onclick="closeModal('orderDetailModal')">Закрыть</button></div></div></div>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script src="https://cdn.jsdelivr.net/npm/toastify-js"></script>
<script src="https://cdn.jsdelivr.net/npm/sortablejs@latest/Sortable.min.js"></script>
<script>
    const csrfToken = '<?= $csrfToken ?>';
    const shopId = <?= $shopId ?>;
    const userRole = '<?= $userRole ?>';
    let currentTab = 'dashboard';
    let productsPage = 1;
    let ordersPage = 1;
    let reviewsPage = 1;
    let layout = [];
    let faqItems = [];
    let currentOrdersFilters = { status: 'all', date_from: '', date_to: '' };
    let loadedTabs = new Set();

    // Общие функции
    function showToast(msg, type = 'success') {
        const colors = { success: '#34C759', error: '#FF3B30', warning: '#FF9500', info: '#5A67D8' };
        Toastify({ text: msg, duration: 3000, backgroundColor: colors[type] }).showToast();
    }
    async function apiRequest(endpoint, data) {
        const res = await fetch(endpoint, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(data) });
        return await res.json();
    }
    function closeModal(id) { document.getElementById(id).classList.remove('active'); }
    function openModal(id) { document.getElementById(id).classList.add('active'); }

    // Переключение вкладок
    document.querySelectorAll('.sidebar-nav a').forEach(link => {
        link.addEventListener('click', (e) => {
            e.preventDefault();
            const tab = link.dataset.tab;
            document.querySelectorAll('.tab-content').forEach(t => t.classList.remove('active'));
            document.getElementById(`${tab}-tab`).classList.add('active');
            document.querySelectorAll('.sidebar-nav a').forEach(a => a.classList.remove('active'));
            link.classList.add('active');
            currentTab = tab;
            if (tab === 'design') loadLayout();
            if (tab === 'products') loadProducts(1);
            if (tab === 'services') loadServices(1);
            if (tab === 'orders') loadOrders(1);
            if (tab === 'faq') loadFaq();
            if (tab === 'reviews') loadReviews(1);
            if (tab === 'storage') loadStorage();
            if (tab === 'finance') { loadSubscription(); loadPaymentHistory(); }
            if (tab === 'ads') loadBannerRequests();
        });
    });

    // ==================== КОНСТРУКТОР ====================
    async function loadLayout() {
        const res = await apiRequest('/business/dashboard?action=get_layout', { csrf_token: csrfToken });
        if (res.success) layout = res.layout;
        renderLayout();
    }
    function renderLayout() {
        const container = document.getElementById('layout-list');
        container.innerHTML = layout.map((block, idx) => `
            <div class="block-item" data-index="${idx}">
                <span contenteditable="true" data-field="title">${escapeHtml(block.title || block.type)}</span>
                <div class="block-actions">
                    <button class="edit-block" data-index="${idx}">✏️</button>
                    <button class="remove-block" data-index="${idx}">🗑️</button>
                </div>
            </div>
        `).join('');
        document.querySelectorAll('.edit-block').forEach(btn => btn.addEventListener('click', () => editBlock(parseInt(btn.dataset.index))));
        document.querySelectorAll('.remove-block').forEach(btn => btn.addEventListener('click', () => { layout.splice(parseInt(btn.dataset.index), 1); renderLayout(); }));
        document.querySelectorAll('.block-item [contenteditable]').forEach(el => {
            el.addEventListener('blur', () => {
                const idx = parseInt(el.closest('.block-item').dataset.index);
                layout[idx].title = el.innerText;
            });
        });
        new Sortable(container, { animation: 150, onEnd: () => { layout = Array.from(container.children).map(c => layout[parseInt(c.dataset.index)]); renderLayout(); } });
    }
    function editBlock(index) {
        const block = layout[index];
        const newParams = prompt('Параметры блока (JSON):', JSON.stringify(block));
        if (newParams) { layout[index] = JSON.parse(newParams); renderLayout(); }
    }
    document.querySelectorAll('.add-block').forEach(btn => btn.addEventListener('click', () => {
        const type = btn.dataset.type;
        layout.push({ type, title: type });
        renderLayout();
    }));
    document.getElementById('save-layout').addEventListener('click', async () => {
        const res = await apiRequest('/business/dashboard?action=save_layout', { layout, csrf_token: csrfToken });
        if (res.success) showToast('Макет сохранён');
        else showToast('Ошибка', 'error');
    });

    // ==================== ТОВАРЫ ====================
    async function loadProducts(page = 1) {
        productsPage = page;
        const res = await apiRequest('/business/dashboard?action=get_products', { page, csrf_token: csrfToken });
        if (res.success) {
            const container = document.getElementById('products-list');
            container.innerHTML = res.products.map(p => `
                <div class="card" style="margin-bottom: 16px;">
                    <div class="card-header"><h4>${escapeHtml(p.name)}</h4><div><button class="btn btn-sm" onclick="editProduct(${p.id})">✏️</button> <button class="btn btn-sm btn-danger" onclick="deleteProduct(${p.id})">🗑️</button> <button class="btn btn-sm" onclick="quickChangePrice(${p.id})">💰 Изменить цену</button></div></div>
                    <div>Цена: ${formatPrice(p.price)} ₽</div>
                    <div>Остаток: ${p.quantity} шт.</div>
                    <div>Просмотров: ${p.views || 0}</div>
                    <div class="photo-preview-grid">${p.photos ? JSON.parse(p.photos).map(url => `<img src="${url}" width="60">`).join('') : ''}</div>
                </div>
            `).join('');
            const pagination = document.getElementById('products-pagination');
            let pagesHtml = '';
            for (let i = 1; i <= res.pages; i++) pagesHtml += `<button class="page-btn ${i === page ? 'active' : ''}" onclick="loadProducts(${i})">${i}</button>`;
            pagination.innerHTML = pagesHtml;
        }
    }
    async function deleteProduct(id) {
        if (!confirm('Удалить товар?')) return;
        const res = await apiRequest('/business/dashboard?action=delete_product', { id, csrf_token: csrfToken });
        if (res.success) { loadProducts(productsPage); showToast('Товар удалён'); }
        else showToast('Ошибка', 'error');
    }
    async function quickChangePrice(id) {
        const newPrice = prompt('Введите новую цену:');
        if (!newPrice) return;
        const res = await apiRequest('/business/dashboard?action=save_product', { id, price: parseFloat(newPrice), csrf_token: csrfToken });
        if (res.success) { loadProducts(productsPage); showToast('Цена обновлена'); }
        else showToast('Ошибка', 'error');
    }
    function editProduct(id) {
        openProductModal(id);
    }
    document.getElementById('add-product-btn').addEventListener('click', () => openProductModal(0));
    function openProductModal(id = 0) {
        document.getElementById('productModalBody').innerHTML = `
            <h3>${id ? 'Редактировать' : 'Добавить'} товар</h3>
            <div class="form-group"><label>Название</label><input id="product_name" class="form-input"></div>
            <div class="form-group"><label>Цена</label><input id="product_price" type="number" class="form-input"></div>
            <div class="form-group"><label>Количество</label><input id="product_quantity" type="number" class="form-input"></div>
            <div class="form-group"><label>Описание</label><textarea id="product_description" class="form-textarea"></textarea></div>
            <div class="form-group"><label>Фото</label><div id="product-photo-dropzone" class="dropzone">Нажмите или перетащите фото</div><input type="file" id="product-photo-input" multiple style="display:none;"><div id="product-photo-preview" class="photo-preview-grid"></div></div>
        `;
        openModal('productModal');
        document.getElementById('saveProductBtn').onclick = async () => {
            const data = {
                action: 'save_product',
                id: id,
                name: document.getElementById('product_name').value,
                price: parseFloat(document.getElementById('product_price').value),
                quantity: parseInt(document.getElementById('product_quantity').value),
                description: document.getElementById('product_description').value,
                csrf_token: csrfToken
            };
            const res = await apiRequest('/business/dashboard?action=save_product', data);
            if (res.success) { closeModal('productModal'); loadProducts(productsPage); showToast('Сохранено'); }
            else showToast('Ошибка', 'error');
        };
        const dropzone = document.getElementById('product-photo-dropzone');
        const photoInput = document.getElementById('product-photo-input');
        dropzone.addEventListener('click', () => photoInput.click());
        dropzone.addEventListener('dragover', (e) => e.preventDefault());
        dropzone.addEventListener('drop', async (e) => {
            e.preventDefault();
            const files = e.dataTransfer.files;
            await uploadProductPhotos(id, files);
        });
        photoInput.addEventListener('change', async (e) => {
            await uploadProductPhotos(id, e.target.files);
        });
    }
    async function uploadProductPhotos(productId, files) {
        const formData = new FormData();
        for (let file of files) formData.append('files[]', file);
        formData.append('product_id', productId);
        formData.append('csrf_token', csrfToken);
        const res = await fetch('/business/dashboard?action=upload_product_photos', { method: 'POST', body: formData });
        const data = await res.json();
        if (data.success) {
            data.photos.forEach(url => {
                const preview = document.createElement('div');
                preview.className = 'photo-preview';
                preview.innerHTML = `<img src="${url}"><button class="remove-photo">✕</button>`;
                document.getElementById('product-photo-preview').appendChild(preview);
                preview.querySelector('.remove-photo').addEventListener('click', () => preview.remove());
            });
        } else showToast('Ошибка загрузки фото', 'error');
    }
    document.getElementById('bulk-price-btn')?.addEventListener('click', async () => {
        const ids = Array.from(document.querySelectorAll('.product-checkbox:checked')).map(cb => parseInt(cb.value));
        if (ids.length === 0) { showToast('Выберите товары', 'warning'); return; }
        const type = prompt('Тип: fixed (фикс) или percent (процент)');
        const value = parseFloat(prompt('Сумма/процент (отрицательное для снижения, положительное для повышения)'));
        if (isNaN(value)) return;
        const res = await apiRequest('/business/dashboard?action=bulk_price_update', { ids, type, value, csrf_token: csrfToken });
        if (res.success) { loadProducts(productsPage); showToast('Цены обновлены'); }
        else showToast(res.error, 'error');
    });

    // ==================== УСЛУГИ ====================
    async function loadServices(page = 1) {
        const res = await apiRequest('/business/dashboard?action=get_services', { page, csrf_token: csrfToken });
        if (res.success) {
            const container = document.getElementById('services-list');
            container.innerHTML = res.services.map(s => `
                <div class="card"><div class="card-header"><h4>${escapeHtml(s.name)}</h4><div><button class="btn btn-sm" onclick="editService(${s.id})">✏️</button> <button class="btn btn-sm btn-danger" onclick="deleteService(${s.id})">🗑️</button></div></div>
                <div>Цена: ${formatPrice(s.price)} ₽</div><div>Длительность: ${s.duration} мин</div></div>
            `).join('');
        }
    }
    async function deleteService(id) {
        if (!confirm('Удалить услугу?')) return;
        const res = await apiRequest('/business/dashboard?action=delete_service', { id, csrf_token: csrfToken });
        if (res.success) loadServices();
        else showToast('Ошибка', 'error');
    }
    function editService(id) {
        openServiceModal(id);
    }
    document.getElementById('add-service-btn').addEventListener('click', () => openServiceModal(0));
    function openServiceModal(id = 0) {
        document.getElementById('serviceModalBody').innerHTML = `
            <h3>${id ? 'Редактировать' : 'Добавить'} услугу</h3>
            <div class="form-group"><label>Название</label><input id="service_name" class="form-input"></div>
            <div class="form-group"><label>Цена</label><input id="service_price" type="number" class="form-input"></div>
            <div class="form-group"><label>Длительность (мин)</label><input id="service_duration" type="number" class="form-input" value="60"></div>
            <div class="form-group"><label>Описание</label><textarea id="service_description" class="form-textarea"></textarea></div>
        `;
        openModal('serviceModal');
        document.getElementById('saveServiceBtn').onclick = async () => {
            const data = {
                action: 'save_service',
                id: id,
                name: document.getElementById('service_name').value,
                price: parseFloat(document.getElementById('service_price').value),
                duration: parseInt(document.getElementById('service_duration').value),
                description: document.getElementById('service_description').value,
                csrf_token: csrfToken
            };
            const res = await apiRequest('/business/dashboard?action=save_service', data);
            if (res.success) { closeModal('serviceModal'); loadServices(); showToast('Сохранено'); }
            else showToast('Ошибка', 'error');
        };
    }

    // ==================== ЗАКАЗЫ ====================
    async function loadOrders(page = 1) {
        ordersPage = page;
        const filters = { ...currentOrdersFilters, page, csrf_token: csrfToken };
        const res = await apiRequest('/business/dashboard?action=get_orders', filters);
        if (res.success) {
            const container = document.getElementById('orders-list');
            if (!res.orders.length) { container.innerHTML = '<div class="empty-state">Нет заказов</div>'; return; }
            container.innerHTML = res.orders.map(o => `
                <div class="order-card">
                    <div class="order-header">
                        <strong>Заказ #${o.id}</strong>
                        <span class="order-status ${o.status}">${o.status === 'pending' ? 'Ожидает' : (o.status === 'paid' ? 'Оплачен' : (o.status === 'processing' ? 'В обработке' : (o.status === 'shipped' ? 'Отправлен' : (o.status === 'completed' ? 'Завершён' : 'Отменён'))))}</span>
                    </div>
                    <div class="order-details">
                        <div>Дата: ${new Date(o.created_at).toLocaleString()}</div>
                        <div>Сумма: ${formatPrice(o.total)} ₽</div>
                        <div>Покупатель: ${escapeHtml(o.customer_name)} (${escapeHtml(o.customer_email)})</div>
                        <div>Товаров: ${o.items_count}</div>
                    </div>
                    <div class="order-actions">
                        <button class="btn btn-sm btn-secondary" onclick="viewOrderDetails(${o.id})">Детали</button>
                        <select class="order-status-select" data-id="${o.id}">
                            <option value="pending" ${o.status === 'pending' ? 'selected' : ''}>Ожидает</option>
                            <option value="paid" ${o.status === 'paid' ? 'selected' : ''}>Оплачен</option>
                            <option value="processing" ${o.status === 'processing' ? 'selected' : ''}>В обработке</option>
                            <option value="shipped" ${o.status === 'shipped' ? 'selected' : ''}>Отправлен</option>
                            <option value="completed" ${o.status === 'completed' ? 'selected' : ''}>Завершён</option>
                            <option value="cancelled" ${o.status === 'cancelled' ? 'selected' : ''}>Отменён</option>
                        </select>
                        <button class="btn btn-sm update-status-btn" data-id="${o.id}">Обновить статус</button>
                    </div>
                </div>
            `).join('');
            document.querySelectorAll('.update-status-btn').forEach(btn => {
                btn.addEventListener('click', async () => {
                    const orderId = btn.dataset.id;
                    const newStatus = document.querySelector(`.order-status-select[data-id="${orderId}"]`).value;
                    const res = await apiRequest('/business/dashboard?action=update_order_status', { order_id: orderId, status: newStatus, csrf_token: csrfToken });
                    if (res.success) { showToast('Статус обновлён'); loadOrders(ordersPage); }
                    else showToast('Ошибка', 'error');
                });
            });
            const pagination = document.getElementById('orders-pagination');
            let pagesHtml = '';
            for (let i = 1; i <= res.pages; i++) pagesHtml += `<button class="page-btn ${i === page ? 'active' : ''}" onclick="loadOrders(${i})">${i}</button>`;
            pagination.innerHTML = pagesHtml;
        }
    }
    async function viewOrderDetails(orderId) {
        const res = await apiRequest('/business/dashboard?action=get_order_details', { order_id: orderId, csrf_token: csrfToken });
        if (res.success) {
            const o = res.order;
            document.getElementById('orderDetailBody').innerHTML = `
                <h3>Заказ #${o.id}</h3>
                <div>Статус: ${o.status}</div>
                <div>Дата: ${new Date(o.created_at).toLocaleString()}</div>
                <div>Сумма: ${formatPrice(o.total)} ₽</div>
                <div>Покупатель: ${escapeHtml(o.customer_name)} (${escapeHtml(o.customer_email)}, ${escapeHtml(o.customer_phone)})</div>
                <div>Адрес: ${escapeHtml(o.delivery_address)}</div>
                <h4>Товары:</h4>
                <ul>${o.items.map(item => `<li>${escapeHtml(item.name)} x ${item.quantity} — ${formatPrice(item.price)} ₽</li>`).join('')}</ul>
            `;
            openModal('orderDetailModal');
        } else showToast('Ошибка загрузки', 'error');
    }
    document.getElementById('apply-order-filters').addEventListener('click', () => {
        currentOrdersFilters = {
            status: document.getElementById('order-status-filter').value,
            date_from: document.getElementById('order-date-from').value,
            date_to: document.getElementById('order-date-to').value
        };
        loadOrders(1);
    });
    document.getElementById('export-orders-csv').addEventListener('click', async () => {
        const res = await apiRequest('/business/dashboard?action=export_orders', { format: 'csv', ...currentOrdersFilters, csrf_token: csrfToken });
        if (res.success && res.url) {
            const a = document.createElement('a'); a.href = res.url; a.click();
        } else showToast('Ошибка экспорта', 'error');
    });
    document.getElementById('export-orders-pdf').addEventListener('click', async () => {
        const res = await apiRequest('/business/dashboard?action=export_orders', { format: 'pdf', ...currentOrdersFilters, csrf_token: csrfToken });
        if (res.success && res.url) {
            const a = document.createElement('a'); a.href = res.url; a.click();
        } else showToast('Ошибка экспорта', 'error');
    });

    // ==================== FAQ ====================
    async function loadFaq() {
        const res = await apiRequest('/business/dashboard?action=get_faq', { csrf_token: csrfToken });
        if (res.success) { faqItems = res.faq; renderFaq(); }
    }
    function renderFaq() {
        const container = document.getElementById('faq-list');
        container.innerHTML = faqItems.map((item, idx) => `
            <div class="faq-item" data-index="${idx}">
                <div class="form-group"><label>Вопрос</label><input type="text" class="faq-question form-input" value="${escapeHtml(item.question)}"></div>
                <div class="form-group"><label>Ответ</label><textarea class="faq-answer form-textarea">${escapeHtml(item.answer)}</textarea></div>
                <button class="remove-faq btn btn-sm btn-danger">Удалить</button>
            </div>
        `).join('');
        document.querySelectorAll('.remove-faq').forEach(btn => btn.addEventListener('click', () => {
            const idx = parseInt(btn.closest('.faq-item').dataset.index);
            faqItems.splice(idx, 1);
            renderFaq();
        }));
    }
    document.getElementById('add-faq-btn').addEventListener('click', () => {
        faqItems.push({ question: '', answer: '' });
        renderFaq();
    });
    document.getElementById('save-faq').addEventListener('click', async () => {
        const items = [];
        document.querySelectorAll('.faq-item').forEach(item => {
            items.push({
                question: item.querySelector('.faq-question').value,
                answer: item.querySelector('.faq-answer').value
            });
        });
        const res = await apiRequest('/business/dashboard?action=save_faq', { faq: items, csrf_token: csrfToken });
        if (res.success) showToast('FAQ сохранён');
        else showToast('Ошибка', 'error');
    });

    // ==================== ОТЗЫВЫ ====================
    async function loadReviews(page = 1) {
        reviewsPage = page;
        const res = await apiRequest('/api/shop/manage.php', { action: 'get_reviews', shop_id: shopId, page, csrf_token: csrfToken });
        if (res.success) {
            const container = document.getElementById('reviews-list');
            if (!res.reviews.length) { container.innerHTML = '<div class="empty-state">Нет отзывов</div>'; return; }
            container.innerHTML = res.reviews.map(r => `
                <div class="review-card">
                    <div><strong>${escapeHtml(r.user_name)}</strong> — ${r.rating}★</div>
                    <div>${escapeHtml(r.comment)}</div>
                    ${r.photos ? `<div class="review-photos">${JSON.parse(r.photos).map(p => `<img src="${p}" width="60">`).join('')}</div>` : ''}
                    ${r.seller_reply ? `<div class="review-reply"><strong>Ваш ответ:</strong> ${escapeHtml(r.seller_reply)}</div>` : ''}
                    <div><button class="btn btn-sm btn-secondary reply-review-btn" data-id="${r.id}">Ответить</button></div>
                    <div class="reply-form" data-id="${r.id}">
                        <textarea rows="2" placeholder="Ваш ответ..."></textarea>
                        <button class="btn btn-sm submit-reply" data-id="${r.id}">Отправить</button>
                    </div>
                </div>
            `).join('');
            document.querySelectorAll('.reply-review-btn').forEach(btn => {
                btn.addEventListener('click', () => {
                    const form = btn.parentElement.nextElementSibling;
                    form.style.display = form.style.display === 'block' ? 'none' : 'block';
                });
            });
            document.querySelectorAll('.submit-reply').forEach(btn => {
                btn.addEventListener('click', async () => {
                    const reviewId = btn.dataset.id;
                    const reply = btn.parentElement.querySelector('textarea').value;
                    if (!reply) { showToast('Введите ответ', 'warning'); return; }
                    const res = await apiRequest('/business/dashboard?action=reply_review', { review_id: reviewId, reply, csrf_token: csrfToken });
                    if (res.success) { showToast('Ответ сохранён'); loadReviews(reviewsPage); }
                    else showToast('Ошибка', 'error');
                });
            });
            const pagination = document.getElementById('reviews-pagination');
            let pagesHtml = '';
            for (let i = 1; i <= res.pages; i++) pagesHtml += `<button class="page-btn ${i === page ? 'active' : ''}" onclick="loadReviews(${i})">${i}</button>`;
            pagination.innerHTML = pagesHtml;
        }
    }

    // ==================== ХРАНИЛИЩЕ ====================
    async function loadStorage() {
        const res = await apiRequest('/business/dashboard?action=get_storage_info', { csrf_token: csrfToken });
        if (res.success) {
            document.getElementById('storage-info').innerHTML = `
                <div class="kpi-grid">
                    <div class="kpi-card"><div class="kpi-label">Лимит</div><div class="kpi-value">${res.limit} МБ</div></div>
                    <div class="kpi-card"><div class="kpi-label">Использовано</div><div class="kpi-value">${res.used} МБ</div></div>
                </div>
                <div class="progress-bar"><div class="progress-fill" style="width: ${res.percent}%"></div></div>
            `;
        }
    }
    document.getElementById('buy-storage')?.addEventListener('click', async () => {
        const extraGb = document.getElementById('extra-gb').value;
        const res = await apiRequest('/business/dashboard?action=buy_storage', { extra_gb: extraGb, csrf_token: csrfToken });
        if (res.success) {
            alert(`Заказ создан. ID: ${res.order_id}, сумма: ${res.amount} ₽. Оплатите через ЮKassa (демо).`);
        } else showToast('Ошибка', 'error');
    });

    // ==================== ФИНАНСЫ ====================
    async function loadSubscription() {
        const res = await apiRequest('/business/dashboard?action=get_subscription', { csrf_token: csrfToken });
        if (res.success) {
            document.getElementById('subscription-info').innerHTML = `
                <div class="kpi-card"><div class="kpi-label">Тариф</div><div class="kpi-value">${res.plan}</div></div>
                <div>Действует до: ${res.expires || 'бессрочно'}</div>
                ${!res.trial_used ? '<div>Пробный период 7 дней активен</div>' : ''}
            `;
        }
    }
    async function loadPaymentHistory() {
        const res = await apiRequest('/api/profile/manage.php', { action: 'payment_history', csrf_token: csrfToken });
        if (res.success && res.data.length) {
            document.getElementById('payment-history').innerHTML = res.data.map(p => `
                <div class="payment-row">${new Date(p.created_at).toLocaleDateString()} — ${p.amount} ₽ (${p.description}) — ${p.status === 'paid' ? 'Оплачено' : 'Ожидает'}</div>
            `).join('');
        } else {
            document.getElementById('payment-history').innerHTML = '<div class="empty-state">Нет платежей</div>';
        }
    }
    document.getElementById('upgrade-plan')?.addEventListener('click', async () => {
        const plan = document.getElementById('plan-select').value;
        const res = await apiRequest('/business/dashboard?action=upgrade_plan', { plan, csrf_token: csrfToken });
        if (res.success) {
            alert(`Заказ создан. ID: ${res.order_id}, сумма: ${res.amount} ₽. Оплатите через ЮKassa (демо).`);
        } else showToast('Ошибка', 'error');
    });

    // ==================== РЕКЛАМА ====================
    document.getElementById('banner-form')?.addEventListener('submit', async (e) => {
        e.preventDefault();
        const formData = new FormData(e.target);
        formData.append('action', 'request_banner');
        formData.append('csrf_token', csrfToken);
        const res = await fetch('/business/dashboard?action=request_banner', { method: 'POST', body: formData });
        const data = await res.json();
        if (data.success) {
            alert(`Заказ создан. ID: ${data.order_id}, сумма: ${data.amount} ₽. Оплатите через ЮKassa (демо).`);
            loadBannerRequests();
        } else showToast(data.error, 'error');
    });
    async function loadBannerRequests() {
        const res = await apiRequest('/api/shop/manage.php', { action: 'get_banner_requests', csrf_token: csrfToken });
        if (res.success && res.data.length) {
            document.getElementById('banner-requests').innerHTML = res.data.map(b => `
                <div class="card">Статус: ${b.status}, город: ${b.city || 'все'}, срок: ${b.duration} нед. <img src="${b.image_url}" width="100"></div>
            `).join('');
        } else document.getElementById('banner-requests').innerHTML = '<div class="empty-state">Нет заявок</div>';
    }

    // ==================== AI-ФУНКЦИИ ====================
    document.getElementById('generate-description')?.addEventListener('click', async () => {
        const name = document.getElementById('ai-product-name').value;
        const category = document.getElementById('ai-product-category').value;
        const price = document.getElementById('ai-product-price').value;
        const res = await apiRequest('/business/dashboard?action=ai_generate_description', { name, category, price, csrf_token: csrfToken });
        if (res.success) document.getElementById('ai-description-result').innerText = res.description;
        else showToast(res.error, 'error');
    });
    document.getElementById('forecast-btn')?.addEventListener('click', async () => {
        const res = await apiRequest('/business/dashboard?action=ai_forecast', { csrf_token: csrfToken });
        if (res.success && res.forecast) {
            let html = '<ul>';
            res.forecast.forEach(f => html += `<li>${f.date}: ${f.sales} продаж</li>`);
            html += '</ul>';
            document.getElementById('forecast-result').innerHTML = html;
        } else showToast(res.error, 'error');
    });
    document.getElementById('review-analysis-btn')?.addEventListener('click', async () => {
        const res = await apiRequest('/business/dashboard?action=ai_review_analysis', { csrf_token: csrfToken });
        if (res.success) document.getElementById('review-analysis-result').innerHTML = res.analysis.replace(/\n/g, '<br>');
        else showToast(res.error, 'error');
    });
    document.getElementById('similar-search-btn')?.addEventListener('click', async () => {
        const description = document.getElementById('similar-search-text').value;
        const res = await apiRequest('/business/dashboard?action=ai_similar_products', { description, csrf_token: csrfToken });
        if (res.success && res.products.length) {
            let html = '<ul>';
            res.products.forEach(p => html += `<li>${p.name} — ${p.price} ₽</li>`);
            html += '</ul>';
            document.getElementById('similar-results').innerHTML = html;
        } else document.getElementById('similar-results').innerHTML = '<p>Похожих товаров не найдено</p>';
    });

    // ==================== НАСТРОЙКИ ====================
    document.getElementById('shop-settings-form')?.addEventListener('submit', async (e) => {
        e.preventDefault();
        const formData = new FormData(e.target);
        const data = Object.fromEntries(formData.entries());
        data.payment_methods = Array.from(formData.getAll('payment_methods[]'));
        const res = await apiRequest('/business/dashboard?action=save_settings', { ...data, csrf_token: csrfToken });
        if (res.success) showToast('Настройки сохранены');
        else showToast('Ошибка', 'error');
    });
    document.getElementById('logo-file')?.addEventListener('change', async (e) => {
        const file = e.target.files[0];
        if (!file) return;
        const formData = new FormData();
        formData.append('file', file);
        formData.append('csrf_token', csrfToken);
        const res = await fetch('/business/dashboard?action=upload_logo', { method: 'POST', body: formData });
        const data = await res.json();
        if (data.success) {
            document.getElementById('logo-preview').innerHTML = `<img src="${data.url}" width="100">`;
            showToast('Логотип загружен');
        } else showToast('Ошибка', 'error');
    });
    document.getElementById('banner-file')?.addEventListener('change', async (e) => {
        const file = e.target.files[0];
        if (!file) return;
        const formData = new FormData();
        formData.append('file', file);
        formData.append('csrf_token', csrfToken);
        const res = await fetch('/business/dashboard?action=upload_banner', { method: 'POST', body: formData });
        const data = await res.json();
        if (data.success) {
            document.getElementById('banner-preview').innerHTML = `<img src="${data.url}" width="200">`;
            showToast('Баннер загружен');
        } else showToast('Ошибка', 'error');
    });

    // ==================== SEO ====================
    document.getElementById('seo-form')?.addEventListener('submit', async (e) => {
        e.preventDefault();
        const formData = new FormData(e.target);
        const data = Object.fromEntries(formData.entries());
        const res = await apiRequest('/business/dashboard?action=save_seo', { ...data, csrf_token: csrfToken });
        if (res.success) showToast('SEO сохранено');
        else showToast('Ошибка', 'error');
    });

    // ==================== ТЕМА ====================
    document.querySelectorAll('.theme-option').forEach(opt => {
        opt.addEventListener('click', () => {
            const theme = opt.dataset.theme;
            document.documentElement.setAttribute('data-theme', theme);
            fetch('/business/dashboard?action=save_settings', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ theme, csrf_token: csrfToken }) });
        });
    });

    // ==================== ГРАФИК ====================
    const chartData = <?= json_encode($chartData) ?>;
    new Chart(document.getElementById('viewsChart'), {
        type: 'line',
        data: { labels: chartData.map(d => d.date.slice(5)), datasets: [{ label: 'Просмотры', data: chartData.map(d => d.views), borderColor: '#4A90E2' }] }
    });

    // ==================== ВСПОМОГАТЕЛЬНЫЕ ====================
    function escapeHtml(str) { return str ? str.replace(/[&<>]/g, m => ({ '&':'&amp;', '<':'&lt;', '>':'&gt;' }[m] || m)) : ''; }
    function formatPrice(price) { return new Intl.NumberFormat('ru-RU').format(price); }

    // Мобильное меню
    document.getElementById('mobileMenuBtn')?.addEventListener('click', () => {
        document.getElementById('sidebar').classList.toggle('open');
    });

    // Инициализация (только для активной вкладки, остальные загружаются по клику)
    function activateTab(tabName) {
        if (!loadedTabs.has(tabName)) {
            if (tabName === 'dashboard') {
                // уже загружено
            } else if (tabName === 'products') loadProducts(1);
            else if (tabName === 'services') loadServices(1);
            else if (tabName === 'orders') loadOrders(1);
            else if (tabName === 'faq') loadFaq();
            else if (tabName === 'reviews') loadReviews(1);
            else if (tabName === 'storage') loadStorage();
            else if (tabName === 'finance') { loadSubscription(); loadPaymentHistory(); }
            else if (tabName === 'ads') loadBannerRequests();
            else if (tabName === 'design') loadLayout();
            loadedTabs.add(tabName);
        }
    }

    // Обработчик для навигации
    document.querySelectorAll('.sidebar-nav a').forEach(link => {
        link.addEventListener('click', (e) => {
            e.preventDefault();
            const tab = link.dataset.tab;
            document.querySelectorAll('.tab-content').forEach(t => t.classList.remove('active'));
            document.getElementById(`${tab}-tab`).classList.add('active');
            document.querySelectorAll('.sidebar-nav a').forEach(a => a.classList.remove('active'));
            link.classList.add('active');
            currentTab = tab;
            activateTab(tab);
        });
    });

    // Активируем текущую вкладку (по умолчанию dashboard)
    activateTab('dashboard');
</script>
</body>
</html>