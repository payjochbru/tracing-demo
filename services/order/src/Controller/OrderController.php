<?php

namespace App\Controller;

use OpenTelemetry\API\Globals;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class OrderController extends AbstractController
{
    private const OUT_OF_STOCK_SKUS = ['MK-300', 'WC-400'];

    public function __construct(
        private readonly HttpClientInterface $httpClient,
    ) {
    }

    #[Route('/orders', methods: ['POST'])]
    public function createOrder(Request $request): JsonResponse
    {
        $tracer = Globals::tracerProvider()->getTracer('order-service');

        $data = json_decode($request->getContent(), true) ?: [];
        $orderId = 'ord_' . bin2hex(random_bytes(6));
        $customerId = $data['customer_id'] ?? 'unknown';
        $items = $data['items'] ?? [];
        $total = $data['total'] ?? 0;
        $requestId = $data['request_id'] ?? 'unknown';
        $region = $data['region'] ?? 'eu-west';
        $priority = $data['priority'] ?? 'standard';

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

            // ~5% chance of slow query
            $queryTime = random_int(1, 100) <= 5
                ? random_int(200000, 500000)
                : random_int(3000, 15000);
            usleep($queryTime);

            if ($queryTime > 200000) {
                $dbSpan->addEvent('db.slow_query', [
                    'duration_ms' => round($queryTime / 1000, 1),
                    'threshold_ms' => 100,
                ]);
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

                // Simulated cache lookup for stock level
                $cacheHit = random_int(1, 100) <= 70;
                $stockCheckSpan = $tracer->spanBuilder('inventory.check_sku')->startSpan();
                $stockCheckScope = $stockCheckSpan->activate();
                try {
                    $stockCheckSpan->setAttribute('inventory.sku', $sku);
                    $stockCheckSpan->setAttribute('inventory.requested_qty', $qty);
                    $stockCheckSpan->setAttribute('cache.hit', $cacheHit);

                    if ($cacheHit) {
                        usleep(random_int(500, 2000));
                        $stockCheckSpan->addEvent('inventory.cache_hit', ['sku' => $sku]);
                    } else {
                        // Cache miss: query "database"
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

                    // ~8% chance of out-of-stock on specific SKUs
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
            // Rollback inventory reservation
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

            // ~10% chance order not found
            if (random_int(1, 100) <= 10) {
                $dbSpan->addEvent('db.query_completed', ['rows_returned' => 0]);
                $dbSpan->setStatus(StatusCode::STATUS_ERROR, 'Order not found');
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
}
