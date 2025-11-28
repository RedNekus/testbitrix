<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Только POST-запросы разрешены']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$webhookUrl = trim($input['webhookUrl'] ?? '');

if (!$webhookUrl || !filter_var($webhookUrl, FILTER_VALIDATE_URL)) {
    http_response_code(400);
    echo json_encode(['error' => 'Некорректный URL вебхука']);
    exit;
}

if (strpos($webhookUrl, 'crm.company.list') === false) {
    http_response_code(400);
    echo json_encode(['error' => 'URL должен быть для crm.company.list — только компании']);
    exit;
}

$result = [];
$start = 0;
$limit = 10000;

while (count($result) < $limit) {
    $postData = json_encode([
        'start' => $start,
        'select' => ['ID', 'TITLE', 'PHONE', 'EMAIL', 'ADDRESS', 'DATE_CREATE']
    ]);

    $ch = curl_init($webhookUrl);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $postData);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);

    $response = curl_exec($ch);
    curl_close($ch);

    $data = json_decode($response, true);
    if (!isset($data['result']) || !is_array($data['result'])) {
        break;
    }

    $result = array_merge($result, $data['result']);
    if (count($result) >= $limit) {
        $result = array_slice($result, 0, $limit);
        break;
    }

    $start = $data['next'] ?? null;
    if ($start === null) break;
}

echo json_encode(['companies' => $result, 'total' => count($result)], JSON_UNESCAPED_UNICODE);