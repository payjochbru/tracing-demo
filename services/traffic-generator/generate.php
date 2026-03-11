<?php

$gatewayUrl = $_SERVER['GATEWAY_URL'] ?? 'http://gateway:8080';
$intervalMs = (int) ($_SERVER['REQUEST_INTERVAL_MS'] ?? 3000);
$customers = ['cust_alice', 'cust_bob', 'cust_charlie', 'cust_diana', 'cust_eve'];

echo "=== Traffic Generator ===\n";
echo "Target: {$gatewayUrl}/checkout\n";
echo "Interval: {$intervalMs}ms\n\n";

while (true) {
    $customer = $customers[array_rand($customers)];

    $payload = json_encode([
        'customer_id' => $customer,
    ]);

    $context = stream_context_create([
        'http' => [
            'method' => 'POST',
            'header' => "Content-Type: application/json\r\nContent-Length: " . strlen($payload) . "\r\n",
            'content' => $payload,
            'timeout' => 30,
            'ignore_errors' => true,
        ],
    ]);

    $timestamp = date('H:i:s');
    $response = @file_get_contents("{$gatewayUrl}/checkout", false, $context);

    if ($response === false) {
        echo "[{$timestamp}] ERROR connecting to gateway\n";
    } else {
        $statusLine = $http_response_header[0] ?? 'unknown';
        preg_match('/\d{3}/', $statusLine, $matches);
        $httpCode = $matches[0] ?? '???';
        echo "[{$timestamp}] HTTP {$httpCode} | Customer: {$customer}\n";
    }

    usleep($intervalMs * 1000);
}
