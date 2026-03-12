<?php

$gatewayUrl = $_SERVER['GATEWAY_URL'] ?? 'http://gateway:8080';
$intervalMs = (int) ($_SERVER['REQUEST_INTERVAL_MS'] ?? 300);
$customers = ['cust_alice', 'cust_bob', 'cust_charlie', 'cust_diana', 'cust_eve', 'cust_frank'];

$scenarios = [
    ['name' => 'normal_checkout', 'weight' => 60],
    ['name' => 'high_value_checkout', 'weight' => 10],
    ['name' => 'express_checkout', 'weight' => 10],
    ['name' => 'order_lookup', 'weight' => 15],
    ['name' => 'empty_cart', 'weight' => 5],
];

$lastOrderIds = [];

echo "=== Traffic Generator ===\n";
echo "Target: {$gatewayUrl}\n";
echo "Interval: {$intervalMs}ms\n";
echo "Scenarios: normal, high_value, express, order_lookup, empty_cart\n\n";

while (true) {
    $customer = $customers[array_rand($customers)];
    $scenario = pickScenario($scenarios);
    $timestamp = date('H:i:s');

    switch ($scenario) {
        case 'normal_checkout':
            $response = doPost("{$gatewayUrl}/checkout", [
                'customer_id' => $customer,
            ]);
            $orderId = $response['data']['order']['order_id'] ?? null;
            if ($orderId) {
                $lastOrderIds[] = $orderId;
                if (count($lastOrderIds) > 20) {
                    array_shift($lastOrderIds);
                }
            }
            echo "[{$timestamp}] {$scenario} | HTTP {$response['code']} | {$customer}\n";
            break;

        case 'high_value_checkout':
            $items = [];
            for ($i = 0; $i < random_int(3, 6); $i++) {
                $items[] = [
                    'name' => 'Premium Item ' . ($i + 1),
                    'sku' => 'PREM-' . random_int(100, 999),
                    'price' => round(random_int(20000, 80000) / 100, 2),
                    'quantity' => random_int(1, 3),
                    'total' => round(random_int(20000, 80000) / 100 * random_int(1, 3), 2),
                ];
            }
            $response = doPost("{$gatewayUrl}/checkout", [
                'customer_id' => $customer,
                'items' => $items,
            ]);
            echo "[{$timestamp}] {$scenario} | HTTP {$response['code']} | {$customer}\n";
            break;

        case 'express_checkout':
            $response = doPost("{$gatewayUrl}/checkout", [
                'customer_id' => $customer,
                'priority' => 'express',
            ]);
            echo "[{$timestamp}] {$scenario} | HTTP {$response['code']} | {$customer}\n";
            break;

        case 'order_lookup':
            if (empty($lastOrderIds)) {
                $lookupId = 'ord_' . bin2hex(random_bytes(6));
            } else {
                $lookupId = random_int(1, 100) <= 70
                    ? $lastOrderIds[array_rand($lastOrderIds)]
                    : 'ord_' . bin2hex(random_bytes(6));
            }
            $response = doGet("{$gatewayUrl}/orders/{$lookupId}");
            echo "[{$timestamp}] {$scenario} | HTTP {$response['code']} | {$lookupId}\n";
            break;

        case 'empty_cart':
            $response = doPost("{$gatewayUrl}/checkout", [
                'customer_id' => $customer,
                'items' => [],
            ]);
            echo "[{$timestamp}] {$scenario} | HTTP {$response['code']} | {$customer}\n";
            break;
    }

    // Occasional burst: 40% chance of sending 3-8 rapid requests
    if (random_int(1, 100) <= 40) {
        $burstCount = random_int(3, 8);
        echo "[{$timestamp}] burst +{$burstCount} rapid requests\n";
        for ($b = 0; $b < $burstCount; $b++) {
            usleep(random_int(20000, 80000));
            $burstCustomer = $customers[array_rand($customers)];
            $response = doPost("{$gatewayUrl}/checkout", ['customer_id' => $burstCustomer]);
            echo "[{$timestamp}]   burst | HTTP {$response['code']} | {$burstCustomer}\n";
        }
    }

    usleep($intervalMs * 1000);
}

function pickScenario(array $scenarios): string
{
    $total = array_sum(array_column($scenarios, 'weight'));
    $rand = random_int(1, $total);
    $cumulative = 0;
    foreach ($scenarios as $s) {
        $cumulative += $s['weight'];
        if ($rand <= $cumulative) {
            return $s['name'];
        }
    }
    return $scenarios[0]['name'];
}

function doPost(string $url, array $payload): array
{
    $json = json_encode($payload);
    $context = stream_context_create([
        'http' => [
            'method' => 'POST',
            'header' => "Content-Type: application/json\r\nContent-Length: " . strlen($json) . "\r\n",
            'content' => $json,
            'timeout' => 30,
            'ignore_errors' => true,
        ],
    ]);

    $response = @file_get_contents($url, false, $context);
    $code = '???';
    if (isset($http_response_header[0])) {
        preg_match('/\d{3}/', $http_response_header[0], $m);
        $code = $m[0] ?? '???';
    }

    return [
        'code' => $code,
        'data' => $response !== false ? json_decode($response, true) : null,
    ];
}

function doGet(string $url): array
{
    $context = stream_context_create([
        'http' => [
            'method' => 'GET',
            'timeout' => 30,
            'ignore_errors' => true,
        ],
    ]);

    $response = @file_get_contents($url, false, $context);
    $code = '???';
    if (isset($http_response_header[0])) {
        preg_match('/\d{3}/', $http_response_header[0], $m);
        $code = $m[0] ?? '???';
    }

    return [
        'code' => $code,
        'data' => $response !== false ? json_decode($response, true) : null,
    ];
}
