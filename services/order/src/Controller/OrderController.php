<?php

namespace App\Controller;

use OpenTelemetry\API\Globals;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class OrderController extends AbstractController
{
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

        $orderSpan = $tracer->spanBuilder('order.create')->startSpan();
        $orderScope = $orderSpan->activate();
        try {
            $orderSpan->setAttribute('order.id', $orderId);
            $orderSpan->setAttribute('order.customer_id', $customerId);
            $orderSpan->setAttribute('order.items_count', count($items));
            $orderSpan->setAttribute('order.total', $total);
            $orderSpan->addEvent('order.received');

            $inventorySpan = $tracer->spanBuilder('inventory.check')->startSpan();
            $inventoryScope = $inventorySpan->activate();
            try {
                foreach ($items as $item) {
                    $inventorySpan->addEvent('inventory.check_item', [
                        'item.name' => $item['name'] ?? 'unknown',
                        'item.quantity' => $item['quantity'] ?? 1,
                    ]);
                    usleep(random_int(5000, 15000));
                }
                $inventorySpan->setAttribute('inventory.all_available', true);
                $inventorySpan->addEvent('inventory.reserved');
            } finally {
                $inventoryScope->detach();
                $inventorySpan->end();
            }

            $orderSpan->addEvent('order.validated');
        } finally {
            $orderScope->detach();
            $orderSpan->end();
        }

        $paymentUrl = rtrim($_SERVER['PAYMENT_SERVICE_URL'] ?? 'http://payment-service:8080', '/');
        $paymentResponse = $this->httpClient->request('POST', $paymentUrl . '/payments', [
            'json' => [
                'order_id' => $orderId,
                'customer_id' => $customerId,
                'amount' => $total,
                'currency' => 'EUR',
            ],
        ]);
        $paymentResult = $paymentResponse->toArray(false);

        if ($paymentResponse->getStatusCode() >= 400) {
            return $this->json([
                'order_id' => $orderId,
                'status' => 'payment_failed',
                'payment' => $paymentResult,
            ], 422);
        }

        $notificationUrl = rtrim($_SERVER['NOTIFICATION_SERVICE_URL'] ?? 'http://notification-service:8080', '/');
        $this->httpClient->request('POST', $notificationUrl . '/notifications', [
            'json' => [
                'type' => 'order_confirmation',
                'channel' => 'email',
                'recipient' => $customerId,
                'order_id' => $orderId,
                'message' => "Your order {$orderId} has been confirmed. Total: EUR {$total}",
            ],
        ]);

        $confirmSpan = $tracer->spanBuilder('order.confirm')->startSpan();
        $confirmScope = $confirmSpan->activate();
        try {
            $confirmSpan->setAttribute('order.id', $orderId);
            $confirmSpan->setAttribute('order.status', 'confirmed');
            $confirmSpan->addEvent('order.confirmed');
            usleep(random_int(1000, 3000));
        } finally {
            $confirmScope->detach();
            $confirmSpan->end();
        }

        return $this->json([
            'order_id' => $orderId,
            'status' => 'confirmed',
            'customer_id' => $customerId,
            'items' => $items,
            'total' => $total,
            'payment' => $paymentResult,
        ]);
    }

    #[Route('/health', methods: ['GET'])]
    public function health(): JsonResponse
    {
        return $this->json(['status' => 'healthy', 'service' => 'order-service']);
    }
}
