<?php

namespace App\Controller;

use OpenTelemetry\API\Globals;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;
use Prometheus\CollectorRegistry;
use Prometheus\Storage\APC;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class OrderController extends AbstractController
{
    private const OUT_OF_STOCK_SKUS = ['MK-300', 'WC-400'];

    private CollectorRegistry $metrics;

    public function __construct(
        private readonly HttpClientInterface $httpClient,
    ) {
        $this->metrics = new CollectorRegistry(new APC());
    }

    #[Route('/orders', methods: ['POST'])]
    public function createOrder(Request $request): JsonResponse
    {
        $startTime = microtime(true);
        $tracer = Globals::tracerProvider()->getTracer('order-service');

        $data = json_decode($request->getContent(), true) ?: [];
        $orderId = 'ord_' . bin2hex(random_bytes(6));
        $customerId = $data['customer_id'] ?? 'unknown';
        $items = $data['items'] ?? [];
        $total = $data['total'] ?? 0;
        $requestId = $data['request_id'] ?? 'unknown';
        $region = $data['region'] ?? 'eu-west';
        $priority = $data['priority'] ?? 'standard';

        $this->metrics->getOrRegisterHistogram(
            'order', 'order_value_euros', 'Order value distribution in EUR', [],
            [10, 25, 50, 100, 250, 500, 1000, 2500, 5000]
        )->observe($total, []);

        // Persist order to "database"
        $dbSpan = $tracer->spanBuilder('db.query INSERT orders')
            ->setSpanKind(SpanKind::KIND_CLIENT)
            ->startSpan();
        $dbScope = $dbSpan->activate();
        try {
            $dbSpan->setAttribute('db.system', 'postgresql');
            $dbSpan->setAttribute('db.name', 'orders');
            $dbSpan->setAttribute('db.operation', 'INSERT');
            $dbSpan->setAttribute('db.statement', 'INSERT INTO orders (id, customer_id, total, status, region, priority) VALUES ($1, $2, $3, $4, $5, $6)');
            $dbSpan->setAttribute('order.id', $orderId);

            $queryTime = random_int(1, 100) <= 5
                ? random_int(200000, 500000)
                : random_int(3000, 15000);
            usleep($queryTime);

            if ($queryTime > 200000) {
                $dbSpan->addEvent('db.slow_query', [
                    'duration_ms' => round($queryTime / 1000, 1),
                    'threshold_ms' => 100,
                ]);
                $this->metrics->getOrRegisterCounter('order', 'db_slow_queries_total', 'Slow database queries', ['operation'])
                    ->incBy(1, ['INSERT']);
            }

            $dbSpan->addEvent('db.query_completed', ['rows_affected' => 1]);
        } finally {
            $dbScope->detach();
            $dbSpan->end();
        }

        // Check inventory
        $inventorySpan = $tracer->spanBuilder('inventory.check')->startSpan();
        $inventoryScope = $inventorySpan->activate();
        try {
            $inventorySpan->setAttribute('order.id', $orderId);
            $inventorySpan->setAttribute('inventory.items_count', count($items));
            $outOfStock = false;
            $outOfStockItem = null;

            foreach ($items as $item) {
                $sku = $item['sku'] ?? 'unknown';
                $qty = $item['quantity'] ?? 1;

                $cacheHit = random_int(1, 100) <= 70;
                $stockCheckSpan = $tracer->spanBuilder('inventory.check_sku')->startSpan();
                $stockCheckScope = $stockCheckSpan->activate();
                try {
                    $stockCheckSpan->setAttribute('inventory.sku', $sku);
                    $stockCheckSpan->setAttribute('inventory.requested_qty', $qty);
                    $stockCheckSpan->setAttribute('cache.hit', $cacheHit);

                    $this->metrics->getOrRegisterCounter('order', 'inventory_cache_lookups_total', 'Inventory cache lookups', ['result'])
                        ->incBy(1, [$cacheHit ? 'hit' : 'miss']);

                    if ($cacheHit) {
                        usleep(random_int(500, 2000));
                        $stockCheckSpan->addEvent('inventory.cache_hit', ['sku' => $sku]);
                    } else {
                        $stockDbSpan = $tracer->spanBuilder('db.query SELECT stock')
                            ->setSpanKind(SpanKind::KIND_CLIENT)
                            ->startSpan();
                        $stockDbScope = $stockDbSpan->activate();
                        try {
                            $stockDbSpan->setAttribute('db.system', 'postgresql');
                            $stockDbSpan->setAttribute('db.name', 'inventory');
                            $stockDbSpan->setAttribute('db.operation', 'SELECT');
                            $stockDbSpan->setAttribute('db.statement', 'SELECT available_qty FROM stock WHERE sku = $1 FOR UPDATE');
                            usleep(random_int(5000, 20000));
                        } finally {
                            $stockDbScope->detach();
                            $stockDbSpan->end();
                        }
                        $stockCheckSpan->addEvent('inventory.cache_miss', ['sku' => $sku]);
                    }

                    if (in_array($sku, self::OUT_OF_STOCK_SKUS) && random_int(1, 100) <= 8) {
                        $outOfStock = true;
                        $outOfStockItem = $sku;
                        $stockCheckSpan->setAttribute('inventory.available', false);
                        $stockCheckSpan->setStatus(StatusCode::STATUS_ERROR, "SKU {$sku} out of stock");
                    } else {
                        $availableQty = random_int($qty, $qty + 50);
                        $stockCheckSpan->setAttribute('inventory.available', true);
                        $stockCheckSpan->setAttribute('inventory.available_qty', $availableQty);
                    }
                } finally {
                    $stockCheckScope->detach();
                    $stockCheckSpan->end();
                }
            }

            if ($outOfStock) {
                $inventorySpan->setStatus(StatusCode::STATUS_ERROR, "Item {$outOfStockItem} out of stock");
                $inventorySpan->recordException(
                    new \RuntimeException("Insufficient stock for SKU {$outOfStockItem}")
                );

                $this->metrics->getOrRegisterCounter('order', 'inventory_out_of_stock_total', 'Out of stock events', ['sku'])
                    ->incBy(1, [$outOfStockItem]);
                $this->recordRequest('POST', '/orders', 409, microtime(true) - $startTime);

                return $this->json([
                    'order_id' => $orderId,
                    'status' => 'rejected',
                    'reason' => 'out_of_stock',
                    'sku' => $outOfStockItem,
                ], 409);
            }

            $inventorySpan->setAttribute('inventory.all_available', true);
            $inventorySpan->addEvent('inventory.reserved');
        } finally {
            $inventoryScope->detach();
            $inventorySpan->end();
        }

        // Calculate shipping cost via external API
        $httpbinUrl = rtrim($_SERVER['HTTPBIN_URL'] ?? 'https://httpbin.org', '/');
        $shippingSpan = $tracer->spanBuilder('shipping.calculate_cost')
            ->setSpanKind(SpanKind::KIND_CLIENT)
            ->startSpan();
        $shippingScope = $shippingSpan->activate();
        try {
            $totalWeight = array_sum(array_map(fn($i) => ($i['weight_kg'] ?? 0.5) * ($i['quantity'] ?? 1), $items));
            $shippingSpan->setAttribute('shipping.region', $region);
            $shippingSpan->setAttribute('shipping.total_weight_kg', round($totalWeight, 2));
            $shippingSpan->setAttribute('shipping.priority', $priority);
            $shippingSpan->setAttribute('shipping.provider', 'external-shipping-api');

            $shippingResponse = $this->httpClient->request('POST', $httpbinUrl . '/post', [
                'json' => [
                    'region' => $region,
                    'weight_kg' => $totalWeight,
                    'priority' => $priority,
                ],
                'timeout' => 5,
            ]);
            $shippingSpan->setAttribute('http.response.status_code', $shippingResponse->getStatusCode());

            $shippingCost = $priority === 'express'
                ? round($totalWeight * 8.50, 2)
                : round($totalWeight * 4.95, 2);
            $shippingSpan->setAttribute('shipping.cost', $shippingCost);
            $shippingSpan->setAttribute('shipping.currency', 'EUR');
            $shippingSpan->addEvent('shipping.cost_calculated', [
                'cost' => $shippingCost,
                'method' => $priority === 'express' ? 'next_day' : 'standard_3_5_days',
            ]);
        } catch (\Throwable $e) {
            $shippingSpan->setStatus(StatusCode::STATUS_ERROR, 'Shipping calculation failed');
            $shippingSpan->recordException($e);
        } finally {
            $shippingScope->detach();
            $shippingSpan->end();
        }

        // Process payment
        $paymentUrl = rtrim($_SERVER['PAYMENT_SERVICE_URL'] ?? 'http://payment-service:8080', '/');
        $paymentResponse = $this->httpClient->request('POST', $paymentUrl . '/payments', [
            'json' => [
                'order_id' => $orderId,
                'customer_id' => $customerId,
                'amount' => $total,
                'currency' => 'EUR',
                'request_id' => $requestId,
                'region' => $region,
            ],
        ]);
        $paymentResult = $paymentResponse->toArray(false);

        if ($paymentResponse->getStatusCode() >= 400) {
            $rollbackSpan = $tracer->spanBuilder('inventory.rollback')->startSpan();
            $rollbackScope = $rollbackSpan->activate();
            try {
                $rollbackSpan->setAttribute('order.id', $orderId);
                $rollbackSpan->setAttribute('rollback.reason', 'payment_failed');
                usleep(random_int(2000, 8000));
                $rollbackSpan->addEvent('inventory.reservation_released');
            } finally {
                $rollbackScope->detach();
                $rollbackSpan->end();
            }

            $this->recordRequest('POST', '/orders', 422, microtime(true) - $startTime);

            return $this->json([
                'order_id' => $orderId,
                'status' => 'payment_failed',
                'payment' => $paymentResult,
            ], 422);
        }

        // Update order status in "database"
        $updateSpan = $tracer->spanBuilder('db.query UPDATE orders')
            ->setSpanKind(SpanKind::KIND_CLIENT)
            ->startSpan();
        $updateScope = $updateSpan->activate();
        try {
            $updateSpan->setAttribute('db.system', 'postgresql');
            $updateSpan->setAttribute('db.name', 'orders');
            $updateSpan->setAttribute('db.operation', 'UPDATE');
            $updateSpan->setAttribute('db.statement', 'UPDATE orders SET status = $1, payment_id = $2, updated_at = NOW() WHERE id = $3');
            usleep(random_int(2000, 10000));
        } finally {
            $updateScope->detach();
            $updateSpan->end();
        }

        // Send confirmation notification
        $notificationUrl = rtrim($_SERVER['NOTIFICATION_SERVICE_URL'] ?? 'http://notification-service:8080', '/');
        $channels = $priority === 'express' ? ['email', 'sms'] : ['email'];
        foreach ($channels as $channel) {
            $this->httpClient->request('POST', $notificationUrl . '/notifications', [
                'json' => [
                    'type' => 'order_confirmation',
                    'channel' => $channel,
                    'recipient' => $customerId,
                    'order_id' => $orderId,
                    'priority' => $priority,
                    'message' => "Your order {$orderId} has been confirmed. Total: EUR {$total}",
                ],
            ]);
        }

        $this->metrics->getOrRegisterCounter('order', 'orders_created_total', 'Successfully created orders', ['priority', 'region'])
            ->incBy(1, [$priority, $region]);
        $this->recordRequest('POST', '/orders', 200, microtime(true) - $startTime);

        return $this->json([
            'order_id' => $orderId,
            'status' => 'confirmed',
            'customer_id' => $customerId,
            'items' => $items,
            'total' => $total,
            'priority' => $priority,
            'payment' => $paymentResult,
        ]);
    }

    #[Route('/orders/{orderId}', methods: ['GET'])]
    public function getOrder(string $orderId): JsonResponse
    {
        $startTime = microtime(true);
        $tracer = Globals::tracerProvider()->getTracer('order-service');

        $dbSpan = $tracer->spanBuilder('db.query SELECT orders')
            ->setSpanKind(SpanKind::KIND_CLIENT)
            ->startSpan();
        $dbScope = $dbSpan->activate();
        try {
            $dbSpan->setAttribute('db.system', 'postgresql');
            $dbSpan->setAttribute('db.name', 'orders');
            $dbSpan->setAttribute('db.operation', 'SELECT');
            $dbSpan->setAttribute('db.statement', 'SELECT * FROM orders WHERE id = $1');
            $dbSpan->setAttribute('order.id', $orderId);
            usleep(random_int(3000, 15000));

            if (random_int(1, 100) <= 10) {
                $dbSpan->addEvent('db.query_completed', ['rows_returned' => 0]);
                $dbSpan->setStatus(StatusCode::STATUS_ERROR, 'Order not found');

                $this->recordRequest('GET', '/orders/{id}', 404, microtime(true) - $startTime);

                return $this->json([
                    'error' => 'not_found',
                    'order_id' => $orderId,
                ], 404);
            }

            $dbSpan->addEvent('db.query_completed', ['rows_returned' => 1]);
        } finally {
            $dbScope->detach();
            $dbSpan->end();
        }

        $this->recordRequest('GET', '/orders/{id}', 200, microtime(true) - $startTime);

        return $this->json([
            'order_id' => $orderId,
            'status' => 'confirmed',
            'customer_id' => 'cust_' . substr($orderId, 4, 8),
            'total' => round(random_int(2000, 50000) / 100, 2),
            'created_at' => date('c'),
        ]);
    }

    #[Route('/health', methods: ['GET'])]
    public function health(): JsonResponse
    {
        return $this->json(['status' => 'healthy', 'service' => 'order-service']);
    }

    private function recordRequest(string $method, string $endpoint, int $status, float $duration): void
    {
        $this->metrics->getOrRegisterCounter(
            'order', 'http_requests_total', 'Total HTTP requests', ['method', 'endpoint', 'status']
        )->incBy(1, [$method, $endpoint, (string) $status]);

        $this->metrics->getOrRegisterHistogram(
            'order', 'http_request_duration_seconds', 'HTTP request duration', ['method', 'endpoint'],
            [0.01, 0.025, 0.05, 0.1, 0.25, 0.5, 1.0, 2.5, 5.0]
        )->observe($duration, [$method, $endpoint]);
    }
}
