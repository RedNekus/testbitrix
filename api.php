<?php
// Включаем вывод ошибок для отладки
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/vendor/autoload.php';

use Dotenv\Dotenv;

// Загрузка .env
$dotenv = Dotenv::createImmutable(__DIR__);
$dotenv->load();

// Настройки из .env
$webhookUrl = $_ENV['BITRIX24_WEBHOOK_URL'] ?? '';
$maxCompanies = (int) ($_ENV['MAX_COMPANIES'] ?? 10000);
$requestTimeout = (int) ($_ENV['REQUEST_TIMEOUT'] ?? 45);
$debugMode = filter_var($_ENV['DEBUG_MODE'] ?? false, FILTER_VALIDATE_BOOLEAN);

// Rate limiting (1 запрос в 2 секунды)
session_start();
if (isset($_SESSION['last_request']) && time() - $_SESSION['last_request'] < 2) {
    http_response_code(429);
    echo json_encode(['error' => 'Слишком частые запросы. Попробуйте через 2 секунды.']);
    exit;
}
$_SESSION['last_request'] = time();

// CORS заголовки
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: ' . ($_SERVER['HTTP_ORIGIN'] ?? '*'));
header('Access-Control-Allow-Methods: GET');
header('Access-Control-Allow-Headers: Content-Type');

// Только GET-запросы
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['error' => 'Только GET-запросы разрешены']);
    exit;
}

// Проверка конфигурации
if (!$webhookUrl) {
    $errorMsg = 'BITRIX24_WEBHOOK_URL не настроен в .env';
    error_log("Конфигурационная ошибка: " . $errorMsg);
    http_response_code(500);
    echo json_encode(['error' => $errorMsg]);
    exit;
}

// Серверное кэширование (5 минут)
$cacheDir = sys_get_temp_dir() . '/bitrix_cache';
$cacheFile = $cacheDir . '/' . md5($webhookUrl) . '.json';

// Создаём директорию для кэша
if (!file_exists($cacheDir)) {
    mkdir($cacheDir, 0755, true);
}

if (file_exists($cacheFile) && time() - filemtime($cacheFile) < 300) {
    if ($debugMode) {
        error_log("Кэш используется: " . $cacheFile);
    }
    echo file_get_contents($cacheFile);
    exit;
}

// Загрузка данных из Bitrix24
$result = [];
$start = 0;
$totalRequests = 0;
$maxRequests = ceil($maxCompanies / 50);
$errorOccurred = false;

while (count($result) < $maxCompanies) {
    // Защита от бесконечных циклов
    if ($totalRequests >= $maxRequests) {
        break;
    }
    $totalRequests++;

    $postData = json_encode([
        'start' => $start,
        'select' => ['ID', 'TITLE', 'PHONE', 'EMAIL', 'ADDRESS', 'DATE_CREATE']
    ]);

    $ch = curl_init($webhookUrl);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $postData);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, $requestTimeout);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    // Обработка сетевых ошибок
    if ($response === false) {
        $errorMsg = 'Ошибка соединения с Bitrix24';
        if (strpos($curlError, 'timeout') !== false) {
            $errorMsg = 'Таймаут запроса к Bitrix24 (' . $requestTimeout . ' сек)';
        } elseif (strpos($curlError, 'SSL') !== false) {
            $errorMsg = 'Ошибка SSL-соединения с Bitrix24';
        } else {
            $errorMsg .= ': ' . $curlError;
        }
        error_log("Bitrix24 API error: " . $errorMsg);
        $errorOccurred = true;
        break;
    }

    // Обработка HTTP ошибок
    if ($httpCode >= 500) {
        $errorMsg = 'Сервер Bitrix24 вернул ошибку ' . $httpCode . '. Сервис может быть временно недоступен.';
        error_log("Bitrix24 HTTP error: " . $errorMsg);
        $errorOccurred = true;
        break;
    }

    $data = json_decode($response, true);
    $jsonError = json_last_error();

    // Обработка ошибок Bitrix24
    if (isset($data['error'])) {
        $bitrixError = $data['error'];
        $errorMsg = $data['error_description'] ?? 'Неизвестная ошибка Bitrix24';
        
        // Локализация частых ошибок
        $errorMap = [
            'insufficient_scope' => 'У вебхука нет прав на просмотр компаний. Обновите права в Bitrix24: Настройки → REST API → ваш токен → права на CRM → Компании → Просмотр.',
            'invalid_token' => 'Токен вебхука недействителен. Скопируйте актуальный URL из Bitrix24: Настройки → REST API.',
        ];
        
        if (isset($errorMap[$bitrixError])) {
            $errorMsg = $errorMap[$bitrixError];
        }
        
        error_log("Bitrix24 API error [$bitrixError]: " . $errorMsg);
        $errorOccurred = true;
        break;
    }

    // Обработка ошибок JSON
    if ($jsonError !== JSON_ERROR_NONE) {
        $errorMsg = 'Bitrix24 вернул некорректный JSON: ' . substr($response, 0, 200) . '...';
        error_log("JSON error: " . $errorMsg);
        $errorOccurred = true;
        break;
    }

    // Обработка отсутствия данных
    if (!isset($data['result']) || !is_array($data['result'])) {
        $errorMsg = 'Bitrix24 вернул ответ без поля "result"';
        if ($totalRequests === 1 && empty($data)) {
            $errorMsg = 'Пустой ответ от Bitrix24. Проверьте корректность вебхука.';
        }
        error_log("Bitrix24 data error: " . $errorMsg);
        $errorOccurred = true;
        break;
    }

    $result = array_merge($result, $data['result']);
    if (count($result) >= $maxCompanies) {
        $result = array_slice($result, 0, $maxCompanies);
        break;
    }

    $start = $data['next'] ?? null;
    if ($start === null) break;
}

// Формирование ответа
if ($errorOccurred) {
    http_response_code(502);
    echo json_encode(['error' => $errorMsg ?? 'Неизвестная ошибка при работе с Bitrix24']);
    exit;
}

$responseData = [
    'companies' => $result,
    'total' => count($result),
    'cached' => false
];

// Сохранение в кэш
if (!empty($result)) {
    try {
        file_put_contents($cacheFile, json_encode($responseData, JSON_UNESCAPED_UNICODE));
        if ($debugMode) {
            error_log("Кэш сохранён: " . $cacheFile);
        }
        $responseData['cached'] = true;
    } catch (Exception $e) {
        error_log("Ошибка кэширования: " . $e->getMessage());
    }
}

// Отдача результата
echo json_encode($responseData, JSON_UNESCAPED_UNICODE);