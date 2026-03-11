<?php

namespace App\Controller;

use OpenTelemetry\API\Globals;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

class NotificationController extends AbstractController
{
    #[Route('/notifications', methods: ['POST'])]
    public function send(Request $request): JsonResponse
    {
        $tracer = Globals::tracerProvider()->getTracer('notification-service');

        $data = json_decode($request->getContent(), true) ?: [];
        $notificationId = 'ntf_' . bin2hex(random_bytes(6));
        $type = $data['type'] ?? 'generic';
        $channel = $data['channel'] ?? 'email';
        $recipient = $data['recipient'] ?? 'unknown';
        $orderId = $data['order_id'] ?? 'unknown';
        $message = $data['message'] ?? '';

        $prepareSpan = $tracer->spanBuilder('notification.prepare')->startSpan();
        $prepareScope = $prepareSpan->activate();
        try {
            $prepareSpan->setAttribute('notification.id', $notificationId);
            $prepareSpan->setAttribute('notification.type', $type);
            $prepareSpan->setAttribute('notification.channel', $channel);
            $prepareSpan->setAttribute('notification.recipient', $recipient);
            $prepareSpan->setAttribute('notification.order_id', $orderId);
            $prepareSpan->setAttribute('notification.message_length', strlen($message));
            usleep(random_int(2000, 8000));
            $prepareSpan->addEvent('notification.template_rendered');
        } finally {
            $prepareScope->detach();
            $prepareSpan->end();
        }

        $sendSpan = $tracer->spanBuilder('notification.deliver')->startSpan();
        $sendScope = $sendSpan->activate();
        try {
            $sendSpan->setAttribute('notification.id', $notificationId);
            $sendSpan->setAttribute('notification.channel', $channel);

            $deliveryTimeUs = match ($channel) {
                'sms' => random_int(30000, 80000),
                'push' => random_int(5000, 15000),
                default => random_int(10000, 40000),
            };
            usleep($deliveryTimeUs);

            $sendSpan->addEvent('notification.delivered', [
                'delivery_time_ms' => round($deliveryTimeUs / 1000, 1),
            ]);
        } finally {
            $sendScope->detach();
            $sendSpan->end();
        }

        return $this->json([
            'notification_id' => $notificationId,
            'status' => 'delivered',
            'type' => $type,
            'channel' => $channel,
        ]);
    }

    #[Route('/health', methods: ['GET'])]
    public function health(): JsonResponse
    {
        return $this->json(['status' => 'healthy', 'service' => 'notification-service']);
    }
}
