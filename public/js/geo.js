<?php
/* ============================================
   НАЙДУК — API для работы с геоданными (финальная версия)
   Версия 2.1 (март 2026)
   - Полный набор эндпоинтов: set_city, suggest, detect, detect_browser, update_profile_city
   - Единый формат ответов (success + data или error)
   - Проверка существования города перед обновлением профиля
   - Корректные HTTP-коды при ошибках
   - Безопасное хранение через GeoService
   ============================================ */

session_start();
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../services/GeoService.php';
require_once __DIR__ . '/../../services/Database.php';

header('Content-Type: application/json');

// Только POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
if (!$input) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid JSON']);
    exit;
}

$csrfToken = $input['csrf_token'] ?? '';
if (!verifyCsrfToken($csrfToken)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Invalid CSRF token']);
    exit;
}

$action = $input['action'] ?? '';
$geo = new GeoService();
$db = Database::getInstance();

switch ($action) {
    // === Ручной выбор города (доступен всем) ===
    case 'set_city':
        $cityId = isset($input['city_id']) ? (int)$input['city_id'] : 0;
        if (!$cityId) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'City ID required']);
            exit;
        }
        
        $city = $geo->getCityById($cityId);
        if (!$city) {
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => 'City not found']);
            exit;
        }
        
        $cityData = [
            'id' => $city['id'],
            'city' => $city['city_name'],
            'region' => $city['region_name'],
            'lat' => $city['latitude'],
            'lng' => $city['longitude'],
            'source' => 'manual'
        ];
        $geo->saveUserCity($cityData);
        echo json_encode(['success' => true, 'data' => $cityData]);
        break;

    // === Автодополнение городов (доступен всем) ===
    case 'suggest':
        $query = $input['query'] ?? '';
        $limit = isset($input['limit']) ? (int)$input['limit'] : 10;
        
        if (mb_strlen($query) < 2) {
            echo json_encode(['success' => true, 'data' => []]);
            exit;
        }
        
        $results = $geo->suggestCities($query, $limit);
        echo json_encode(['success' => true, 'data' => $results]);
        break;

    // === Определение города по IP (доступен всем) ===
    case 'detect':
        $ip = getUserIP();
        $city = $geo->getCityByIp($ip);
        echo json_encode(['success' => true, 'data' => $city]);
        break;

    // === Определение города по координатам (браузерная геолокация) ===
    case 'detect_browser':
        $lat = isset($input['lat']) ? (float)$input['lat'] : null;
        $lng = isset($input['lng']) ? (float)$input['lng'] : null;
        
        if ($lat === null || $lng === null) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Latitude and longitude required']);
            exit;
        }
        
        $city = $geo->reverseGeocode($lat, $lng);
        if ($city && !empty($city['city'])) {
            $geo->saveUserCity($city);
            echo json_encode(['success' => true, 'data' => $city]);
        } else {
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => 'Could not determine city from coordinates']);
        }
        break;

    // === Обновление города в профиле пользователя (только для авторизованных) ===
    case 'update_profile_city':
        if (!isset($_SESSION['user_id'])) {
            http_response_code(401);
            echo json_encode(['success' => false, 'error' => 'Unauthorized']);
            exit;
        }
        
        $userId = (int)$_SESSION['user_id'];
        $cityId = isset($input['city_id']) ? (int)$input['city_id'] : 0;
        $cityName = isset($input['city_name']) ? trim($input['city_name']) : '';

        if (!$cityId && !$cityName) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'City ID or name required']);
            exit;
        }

        // Если передан только city_name, пытаемся найти city_id
        if ($cityId === 0 && !empty($cityName)) {
            $found = $db->fetchOne(
                "SELECT id FROM russian_cities WHERE city_name LIKE ? ORDER BY population DESC LIMIT 1",
                [$cityName . '%']
            );
            if ($found) {
                $cityId = $found['id'];
            }
        }

        // Проверяем, что city_id существует, если он передан
        $cityInfo = null;
        if ($cityId) {
            $cityInfo = $db->fetchOne("SELECT id, city_name FROM russian_cities WHERE id = ?", [$cityId]);
            if (!$cityInfo) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'City not found in database']);
                exit;
            }
        }

        // Подготавливаем данные для обновления
        $updateData = [];
        if ($cityInfo) {
            $updateData['city_id'] = $cityId;
            $updateData['city_name'] = $cityInfo['city_name'];
        } elseif (!empty($cityName)) {
            $updateData['city_name'] = $cityName;
        }

        if (empty($updateData)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'No valid city data provided']);
            exit;
        }

        // Обновляем профиль
        $db->update('users', $updateData, 'id = ?', [$userId]);
        
        // Обновляем сессию и куку
        $cityData = [
            'id' => $cityInfo['id'] ?? null,
            'city' => $updateData['city_name'],
            'source' => 'profile'
        ];
        $geo->saveUserCity($cityData);
        
        echo json_encode(['success' => true, 'message' => 'Город обновлён в профиле']);
        break;

    default:
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Unknown action']);
        break;
}