<?php

namespace App\Controller;

use OpenTelemetry\API\Globals;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class CheckoutController extends AbstractController
{
    private const PRODUCTS = [
        ['name' => 'Wireless Headphones', 'price' => 79.99],
        ['name' => 'USB-C Hub', 'price' => 49.99],
        ['name' => 'Mechanical Keyboard', 'price' => 149.99],
        ['name' => 'Webcam HD', 'price' => 89.99],
        ['name' => 'Monitor Stand', 'price' => 39.99],
        ['name' => 'Mouse Pad XL', 'price' => 24.99],
        ['name' => 'Laptop Sleeve', 'price' => 34.99],
        ['name' => 'Cable Management Kit', 'price' => 19.99],
    ];

    public function __construct(
        private readonly HttpClientInterface $httpClient,
    ) {
    }

    #[Route('/checkout', methods: ['POST'])]
    public function checkout(Request $request): JsonResponse
    {
        $tracer = Globals::tracerProvider()->getTracer('gateway');

        $data = json_decode($request->getContent(), true) ?: [];
        $customerId = $data['customer_id'] ?? 'cust_' . bin2hex(random_bytes(4));
        $items = $data['items'] ?? $this->generateRandomItems();
        $total = array_sum(array_column($items, 'total'));

        $validateSpan = $tracer->spanBuilder('checkout.validate_input')->startSpan();
        $scope = $validateSpan->activate();
        try {
            $validateSpan->setAttribute('customer.id', $customerId);
            $validateSpan->setAttribute('cart.items_count', count($items));
            $validateSpan->setAttribute('cart.total', $total);
            $validateSpan->setAttribute('cart.currency', 'EUR');
            usleep(random_int(2000, 8000));
            $validateSpan->addEvent('input.validated');
        } finally {
            $scope->detach();
            $validateSpan->end();
        }

        $orderServiceUrl = rtrim($_SERVER['ORDER_SERVICE_URL'] ?? 'http://order-service:8080', '/');
        $response = $this->httpClient->request('POST', $orderServiceUrl . '/orders', [
            'json' => [
                'customer_id' => $customerId,
                'items' => $items,
                'total' => $total,
            ],
        ]);

        $result = $response->toArray(false);
        $statusCode = $response->getStatusCode();

        return $this->json([
            'status' => $statusCode >= 400 ? 'failed' : 'accepted',
            'customer_id' => $customerId,
            'order' => $result,
        ], $statusCode >= 400 ? 502 : 200);
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
                'price' => $product['price'],
                'quantity' => $qty,
                'total' => round($product['price'] * $qty, 2),
            ];
        }

        return $items;
    }
}
