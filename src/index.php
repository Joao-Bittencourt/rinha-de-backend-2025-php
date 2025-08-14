<?php

$method = $_SERVER['REQUEST_METHOD'] ?: null;
$requestUri = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH);
$pdo = getPdo();

if ($method === 'POST' && $requestUri === '/payments') {
    
    $_REQUEST = json_decode(file_get_contents('php://input'), true);

    if (!isset($_REQUEST['correlationId'], $_REQUEST['amount'])) {
        exit;
    }

    http_response_code(200);
    fastcgi_finish_request();
    
    $preciseTimestamp = microtime(true);
    $date = DateTime::createFromFormat('U.u', sprintf('%.6f', $preciseTimestamp));
    $requestedAtString = $date->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.v\Z');
  
    $body = [
        'correlationId' => $_REQUEST['correlationId'],
        'amount' => (float)$_REQUEST['amount'],
        'requestedAt' => $requestedAtString,
    ];

    $processor = 'default';
    $success = sendPaymentRequest('default', $body);

    if (!$success) {
        $processor = 'fallback';
        $success = sendPaymentRequest('fallback', $body);
    }

    if ($success) {
        
        savePayment($pdo, $body+['processor' => $processor]);
        exit;
    } 
   
    exit;
}

if ($method === 'GET' && $requestUri === '/payments-summary') {
    
    header('Content-Type: application/json');
    http_response_code(200);
    
    $conditions = [];
    $params = [];

    if (!empty($_GET['from'])) {
        $conditions[] = "requested_at >= :from";
       $params[':from'] = rtrim(str_replace('T', ' ', $_GET['from']), 'Z');
    }

    if (!empty($_GET['to'])) {
        $conditions[] = "requested_at <= :to";
        $params[':to'] = rtrim(str_replace('T', ' ', $_GET['to']), 'Z');
    }

    $where = '';
    if ($conditions) {
        $where = " WHERE " . implode(" AND ", $conditions);
    }
    $sql = "
        SELECT
            processor,
            SUM(amount) AS totalAmount,
            COUNT(*) AS totalRequests
        FROM
            payments
        {$where}    
        GROUP BY
            processor
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $results = $stmt->fetchAll(\PDO::FETCH_OBJ);

    $data = [
        'default' => [
            'totalAmount' => 0,
            'totalRequests' => 0
        ],
        'fallback' => [
            'totalAmount' => 0,
            'totalRequests' => 0
        ]
    ];

    foreach($results as $result) {
        $data[$result->processor] = [
            'totalAmount' => $result->totalAmount,
            'totalRequests' => $result->totalRequests,
        ];
    }
    echo json_encode($data);
    exit;
}

if ($method === 'POST' && $requestUri === '/purge-payments') {
    header('Content-Type: application/json');
    http_response_code(200);
    $sql = "TRUNCATE payments;";
    $stmt = $pdo->prepare($sql);
    $stmt->execute();
    echo json_encode(['message' => 'All payments purged.']);
    exit;
}

function sendPaymentRequest($processor, $body): bool
{
    $ch = curl_init("http://payment-processor-{$processor}:8080/payments");
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
    curl_exec($ch);

    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return $httpCode >= 200 && $httpCode < 300;
}

function getPdo(): PDO {
    static $pdo;
    try {
        
        if ($pdo === null) {
            $host = 'db';
            $db = 'rinha';
            $user = 'root';
            $pass = 'root';
            $charset = 'utf8mb4';
            
            $dsn = "mysql:host=$host;dbname=$db;charset=$charset";
            $pdo = new PDO($dsn, $user, $pass, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);
        }
    } catch (\exception $e) {
        header('Content-Type: application/json');
        http_response_code(500);
        echo json_encode(['message' => $e->getMessage()]);
        exit;
    }
    
    return $pdo;
}

function savePayment($pdo, array $data): void {
    try {
        $sql = "INSERT INTO payments (amount, requested_at, processor) 
                VALUES (:amount, :requestedAt, :processor)";

        $stmt = $pdo->prepare($sql);
        $data['requestedAt'] = rtrim(str_replace('T', ' ', $data['requestedAt']), 'Z');
        $stmt->execute($data);
    } catch (\PDOException $e) {
        throw new \PDOException($e->getMessage(), (int)$e->getCode());
    }
}