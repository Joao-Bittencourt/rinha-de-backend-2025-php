<?php

$method = $_SERVER['REQUEST_METHOD'] ?: null;
$requestUri = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH);
$redis = getRedisClient();

if ($method === 'POST' && $requestUri === '/payments') {
    
    $_REQUEST = json_decode(file_get_contents('php://input'), true);

    if (!isset($_REQUEST['correlationId'], $_REQUEST['amount'])) {
        exit;
    }
    
    $preciseTimestamp = microtime(true);
    $date = DateTime::createFromFormat('U.u', sprintf('%.6f', $preciseTimestamp));
    $requestedAtString = $date->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.v\Z');
  
    $body = [
        'correlationId' => $_REQUEST['correlationId'],
        'amount' => $_REQUEST['amount'],
        'requestedAt' => $requestedAtString,
    ];

    $processor = 'default';
    $success = false;
    while ($success === false) {
        
        $success = sendPaymentRequest($processor, $body);
        
        if ($success) {
            http_response_code(200);
            fastcgi_finish_request();
            
            savePayment($redis, $body+['processor' => $processor]);
            exit;
        }
        $processor =  $processor === 'default' ? 'fallback' : 'default';
    } 
   
    exit;
}

if ($method === 'GET' && $requestUri === '/payments-summary') {
    header('Content-Type: application/json');

    $summaryData = [
        'default' => [
            'totalRequests' => 0,
            'totalAmount' => 0
        ],
        'fallback' => [
            'totalRequests' => 0,
            'totalAmount' => 0
        ]
    ];

    if (!$redis) {
        http_response_code(500);
        echo json_encode(['message' => 'Erro ao conectar ao Redis.']);
        exit;
    }

    $paymentsJson = $redis->lrange('payments', 0, -1);
    
    if ($paymentsJson === false || $paymentsJson === null) {
        http_response_code(200);
        echo json_encode($summaryData);
        exit;
    }
    
    $from = $_GET['from'] ?? null;
    $to = $_GET['to'] ?? null;
    
    foreach ($paymentsJson as $payment) {
        $payment = json_decode($payment, true);
        
        if (($from && $payment['requested_at'] < $from) || ($to && $payment['requested_at'] > $to)) {
            continue; 
        }

        $processor = $payment['processor'];
        $amount = $payment['amount'];

        $summaryData[$processor]['totalRequests']++;
        $summaryData[$processor]['totalAmount'] += $amount;
        
    }

    $summaryData['default']['totalAmount'] = (float) number_format($summaryData['default']['totalAmount'], 2, '.', '');
    $summaryData['fallback']['totalAmount'] = (float) number_format($summaryData['fallback']['totalAmount'], 2, '.', '');

    http_response_code(200);
    echo json_encode($summaryData);
    exit;   
}

if ($method === 'POST' && $requestUri === '/purge-payments') {
    header('Content-Type: application/json');
    http_response_code(200);

    $redis->del('payments');
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

function getRedisClient() {
    static $redis;

    if ($redis === null) {
        try {
            $redis = new Redis();
            $redis->connect('redis', 6379); 
        } catch (RedisException $e) {
            header('Content-Type: application/json');
            http_response_code(500);
            echo json_encode(['message' => 'Erro ao conectar ao Redis: ' . $e->getMessage()]);
            exit;
        }
    }

    return $redis;
}

function savePayment($redis, array $data): void {
    
    try {
        $redisData = [
            'amount'       => $data['amount'],
            'requested_at' => $data['requested_at'],
            'processor'    => $data['processor'],
        ];

        $redis->lpush('payments', json_encode($redisData));

    } catch (RedisException $e) {
        // OK
    }
}