<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST');
header('Access-Control-Allow-Headers: Content-Type');

$errorMap = [
    'insufficient_scope' => 'У вебхука нет прав на просмотр компаний. Обновите права в Bitrix24: Настройки → REST API → ваш токен → права на CRM → Компании → Просмотр.',
    'invalid_token' => 'Токен вебхука недействителен. Скопируйте актуальный URL из Bitrix24: Настройки → REST API.',
    'no_permission' => 'Недостаточно прав для выполнения операции. Обратитесь к администратору портала.',
    'not_found' => 'Метод crm.company.list не найден. Убедитесь, что URL заканчивается на `/crm.company.list.json`.',
    'rate_limit' => 'Слишком много запросов. Bitrix24 ограничивает частоту вызовов. Попробуйте позже.',
    'default' => 'Ошибка Bitrix24: [error]. Проверьте корректность вебхука и права доступа.'
];

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Только POST-запросы разрешены']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$webhookUrl = trim($input['webhookUrl'] ?? '');
$postData = $input['postData'] ?? [];

if (!$webhookUrl || !filter_var($webhookUrl, FILTER_VALIDATE_URL)) {
    http_response_code(400);
    echo json_encode(['error' => 'Некорректный URL. Пример правильного: https://ваш.bitrix24.ru/rest/123/токен/crm.company.list.json']);
    exit;
}

// Гибкая проверка: разрешаем любые домены, но требуем путь /rest/.../crm.company.list.json
$parsedUrl = parse_url($webhookUrl);
$path = $parsedUrl['path'] ?? '';

if (strpos($path, '/rest/') === false || strpos($path, '/crm.company.list.json') === false) {
    http_response_code(400);
    echo json_encode(['error' => 'URL должен содержать путь `/rest/.../crm.company.list.json`. Пример: https://yoow.bitrix24.by/rest/123/токен/crm.company.list.json']);
    exit;
}

// Дополнительная проверка: если домен НЕ содержит bitrix24 — предупреждаем (но не блокируем)
$host = strtolower($parsedUrl['host'] ?? '');
$isBitrixHost = preg_match('/bitrix24\.(ru|com|by|kz|ua|com\.tr|com\.br|de|fr|es|it|pl|cz|in|sg|jp|au|ca)/i', $host);

if (!$isBitrixHost) {
    // Логируем подозрительный запрос (в реальном проекте — в файл или мониторинг)
    error_log('Подозрительный домен Bitrix24: ' . $host);
    // Но НЕ прерываем выполнение — может быть корпоративный портал
}

$ch = curl_init($webhookUrl);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($postData));
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 45);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);

$response = curl_exec($ch);
$curlError = curl_error($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($response === false) {
    $errorMsg = 'Ошибка соединения с Bitrix24';
    if (strpos($curlError, 'timeout') !== false) {
        $errorMsg = 'Таймаут запроса. Bitrix24 не ответил за 45 секунд. Попробуйте позже или уменьшите объём данных.';
    } elseif (strpos($curlError, 'SSL') !== false) {
        $errorMsg = 'Ошибка SSL-соединения. Убедитесь, что URL начинается с https://';
    } else {
        $errorMsg .= ': ' . $curlError;
    }
    http_response_code(502);
    echo json_encode(['error' => $errorMsg]);
    exit;
}

if ($httpCode >= 500) {
    http_response_code(502);
    echo json_encode(['error' => 'Сервер Bitrix24 вернул ошибку ' . $httpCode . '. Сервис может быть временно недоступен. Проверьте статус: https://status.bitrix24.ru']);
    exit;
}

if ($httpCode >= 400) {
    $data = json_decode($response, true);
    if (isset($data['error'])) {
        $bitrixError = $data['error'];
        $errorMsg = $errorMap[$bitrixError] ?? str_replace('[error]', $bitrixError, $errorMap['default']);
        if (isset($data['error_description'])) {
            $errorMsg .= ' Детали: ' . $data['error_description'];
        }
        echo json_encode(['error' => $errorMsg], JSON_UNESCAPED_UNICODE);
        exit;
    } else {
        http_response_code(400);
        echo json_encode(['error' => 'Bitrix24 вернул ошибку ' . $httpCode . ': ' . substr($response, 0, 200) . '...']);
        exit;
    }
}

$data = json_decode($response, true);
if (json_last_error() !== JSON_ERROR_NONE) {
    http_response_code(502);
    echo json_encode(['error' => 'Bitrix24 вернул некорректный JSON: ' . substr($response, 0, 200) . '...']);
    exit;
}

if (!isset($data['result']) || !is_array($data['result'])) {
    if (isset($data['error'])) {
        $bitrixError = $data['error'];
        $errorMsg = $errorMap[$bitrixError] ?? str_replace('[error]', $bitrixError, $errorMap['default']);
        echo json_encode(['error' => $errorMsg], JSON_UNESCAPED_UNICODE);
        exit;
    }
    http_response_code(502);
    echo json_encode(['error' => 'Bitrix24 вернул ответ без поля "result". Возможно, проблема с правами или методом.']);
    exit;
}

echo $response;