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

class CheckoutController extends AbstractController
{
    private const PRODUCTS = [
        ['name' => 'Wireless Headphones', 'sku' => 'WH-100', 'price' => 79.99, 'weight_kg' => 0.3],
        ['name' => 'USB-C Hub', 'sku' => 'UCH-200', 'price' => 49.99, 'weight_kg' => 0.15],
        ['name' => 'Mechanical Keyboard', 'sku' => 'MK-300', 'price' => 149.99, 'weight_kg' => 0.9],
        ['name' => 'Webcam HD', 'sku' => 'WC-400', 'price' => 89.99, 'weight_kg' => 0.2],
        ['name' => 'Monitor Stand', 'sku' => 'MS-500', 'price' => 39.99, 'weight_kg' => 2.1],
        ['name' => 'Mouse Pad XL', 'sku' => 'MP-600', 'price' => 24.99, 'weight_kg' => 0.4],
        ['name' => 'Laptop Sleeve', 'sku' => 'LS-700', 'price' => 34.99, 'weight_kg' => 0.25],
        ['name' => 'Cable Management Kit', 'sku' => 'CMK-800', 'price' => 19.99, 'weight_kg' => 0.1],
    ];

    private const REGIONS = ['eu-west', 'eu-central', 'us-east', 'ap-southeast'];

    public function __construct(
        private readonly HttpClientInterface $httpClient,
    ) {
    }

    #[Route('/checkout', methods: ['POST'])]
    public function checkout(Request $request): JsonResponse
    {
        $tracer = Globals::tracerProvider()->getTracer('gateway');
        $requestId = 'req_' . bin2hex(random_bytes(8));
        $region = self::REGIONS[array_rand(self::REGIONS)];

        $data = json_decode($request->getContent(), true) ?: [];
        $customerId = $data['customer_id'] ?? 'cust_' . bin2hex(random_bytes(4));
        $items = $data['items'] ?? $this->generateRandomItems();
        $total = array_sum(array_column($items, 'total'));
        $priority = $data['priority'] ?? (random_int(1, 10) === 1 ? 'express' : 'standard');

        // Rate limiting check (~3% of requests)
        if (random_int(1, 100) <= 3) {
            $span = $tracer->spanBuilder('gateway.rate_limit_check')->startSpan();
            $scope = $span->activate();
            try {
                $span->setAttribute('request.id', $requestId);
                $span->setAttribute('customer.id', $customerId);
                $span->setAttribute('rate_limit.bucket', 'checkout');
                $span->setAttribute('rate_limit.remaining', 0);
                $span->setStatus(StatusCode::STATUS_ERROR, 'Rate limit exceeded');
                $span->recordException(new \RuntimeException('Rate limit exceeded for customer ' . $customerId));
            } finally {
                $scope->detach();
                $span->end();
            }

            return $this->json([
                'error' => 'rate_limit_exceeded',
                'request_id' => $requestId,
                'retry_after_seconds' => random_int(1, 5),
            ], 429);
        }

        // Validate input
        $validateSpan = $tracer->spanBuilder('checkout.validate_input')->startSpan();
        $validateScope = $validateSpan->activate();
        try {
            $validateSpan->setAttribute('request.id', $requestId);
            $validateSpan->setAttribute('customer.id', $customerId);
            $validateSpan->setAttribute('customer.region', $region);
            $validateSpan->setAttribute('cart.items_count', count($items));
            $validateSpan->setAttribute('cart.total', $total);
            $validateSpan->setAttribute('cart.currency', 'EUR');
            $validateSpan->setAttribute('order.priority', $priority);

            usleep(random_int(2000, 8000));

            if (empty($items)) {
                $validateSpan->setStatus(StatusCode::STATUS_ERROR, 'Empty cart');
                $validateSpan->recordException(new \InvalidArgumentException('Cannot checkout with an empty cart'));
                return $this->json([
                    'error' => 'validation_failed',
                    'message' => 'Cart is empty',
                    'request_id' => $requestId,
                ], 400);
            }

            if ($total > 10000) {
                $validateSpan->addEvent('checkout.high_value_order', [
                    'total' => $total,
                    'threshold' => 10000,
                ]);
            }

            $validateSpan->addEvent('input.validated');
        } finally {
            $validateScope->detach();
            $validateSpan->end();
        }

        // Geo-IP / customer location verification (external API)
        $httpbinUrl = rtrim($_SERVER['HTTPBIN_URL'] ?? 'https://httpbin.org', '/');
        $geoSpan = $tracer->spanBuilder('gateway.geo_ip_lookup')
            ->setSpanKind(SpanKind::KIND_CLIENT)
            ->startSpan();
        $geoScope = $geoSpan->activate();
        try {
            $geoSpan->setAttribute('customer.id', $customerId);
            $geoSpan->setAttribute('geo.provider', 'external-geo-api');
            $geoResponse = $this->httpClient->request('GET', $httpbinUrl . '/get', [
                'query' => ['customer_id' => $customerId, 'ip' => '203.0.113.' . random_int(1, 254)],
                'timeout' => 5,
            ]);
            $geoSpan->setAttribute('http.response.status_code', $geoResponse->getStatusCode());
            $geoSpan->setAttribute('geo.country', $region === 'eu-west' ? 'NL' : 'DE');
            $geoSpan->addEvent('geo.location_resolved', [
                'country' => $region === 'eu-west' ? 'NL' : 'DE',
                'region' => $region,
            ]);
        } catch (\Throwable $e) {
            $geoSpan->setStatus(StatusCode::STATUS_ERROR, 'Geo-IP lookup failed');
            $geoSpan->recordException($e);
        } finally {
            $geoScope->detach();
            $geoSpan->end();
        }

        // Idempotency check
        $idempotencySpan = $tracer->spanBuilder('gateway.idempotency_check')->startSpan();
        $idempotencyScope = $idempotencySpan->activate();
        try {
            $idempotencyKey = $data['idempotency_key'] ?? $requestId;
            $idempotencySpan->setAttribute('idempotency.key', $idempotencyKey);
            $idempotencySpan->setAttribute('idempotency.cache_hit', false);
            usleep(random_int(500, 2000));
            $idempotencySpan->addEvent('idempotency.key_stored');
        } finally {
            $idempotencyScope->detach();
            $idempotencySpan->end();
        }

        $orderServiceUrl = rtrim($_SERVER['ORDER_SERVICE_URL'] ?? 'http://order-service:8080', '/');
        $response = $this->httpClient->request('POST', $orderServiceUrl . '/orders', [
            'json' => [
                'customer_id' => $customerId,
                'items' => $items,
                'total' => $total,
                'request_id' => $requestId,
                'region' => $region,
                'priority' => $priority,
            ],
        ]);

        $result = $response->toArray(false);
        $statusCode = $response->getStatusCode();

        return $this->json([
            'status' => $statusCode >= 400 ? 'failed' : 'accepted',
            'request_id' => $requestId,
            'customer_id' => $customerId,
            'order' => $result,
        ], $statusCode >= 400 ? 502 : 200);
    }

    #[Route('/orders/{orderId}', methods: ['GET'])]
    public function getOrder(string $orderId): JsonResponse
    {
        $tracer = Globals::tracerProvider()->getTracer('gateway');

        $span = $tracer->spanBuilder('gateway.lookup_order')->startSpan();
        $scope = $span->activate();
        try {
            $span->setAttribute('order.id', $orderId);
            usleep(random_int(1000, 3000));
        } finally {
            $scope->detach();
            $span->end();
        }

        $orderServiceUrl = rtrim($_SERVER['ORDER_SERVICE_URL'] ?? 'http://order-service:8080', '/');
        $response = $this->httpClient->request('GET', $orderServiceUrl . '/orders/' . $orderId);

        return $this->json(
            $response->toArray(false),
            $response->getStatusCode(),
        );
    }

    #[Route('/health', methods: ['GET'])]
    public function health(): JsonResponse
    {
        return $this->json(['status' => 'healthy', 'service' => 'gateway']);
    }

    private function generateRandomItems(): array
    {
        $count = random_int(1, 4);
        $items = [];
        $keys = array_rand(self::PRODUCTS, $count);
        if (!is_array($keys)) {
            $keys = [$keys];
        }

        foreach ($keys as $key) {
            $product = self::PRODUCTS[$key];
            $qty = random_int(1, 3);
            $items[] = [
                'name' => $product['name'],
                'sku' => $product['sku'],
                'price' => $product['price'],
                'quantity' => $qty,
                'total' => round($product['price'] * $qty, 2),
            ];
        }

        return $items;
    }
}
