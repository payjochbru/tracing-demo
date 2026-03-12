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

class NotificationController extends AbstractController
{
    private const EMAIL_PROVIDERS = ['sendgrid', 'ses'];
    private const SMS_PROVIDERS = ['twilio', 'messagebird'];

    private CollectorRegistry $metrics;

    public function __construct()
    {
        $this->metrics = new CollectorRegistry(new APC());
    }

    #[Route('/notifications', methods: ['POST'])]
    public function send(Request $request): JsonResponse
    {
        $startTime = microtime(true);
        $tracer = Globals::tracerProvider()->getTracer('notification-service');

        $data = json_decode($request->getContent(), true) ?: [];
        $notificationId = 'ntf_' . bin2hex(random_bytes(6));
        $type = $data['type'] ?? 'generic';
        $channel = $data['channel'] ?? 'email';
        $recipient = $data['recipient'] ?? 'unknown';
        $orderId = $data['order_id'] ?? 'unknown';
        $priority = $data['priority'] ?? 'standard';
        $message = $data['message'] ?? '';

        // Template rendering
        $templateSpan = $tracer->spanBuilder('notification.render_template')->startSpan();
        $templateScope = $templateSpan->activate();
        try {
            $templateName = match ($type) {
                'order_confirmation' => 'emails/order_confirmed.html.twig',
                'payment_failed' => 'emails/payment_failed.html.twig',
                'fraud_alert' => 'emails/fraud_alert.html.twig',
                default => 'emails/generic.html.twig',
            };
            $templateSpan->setAttribute('template.name', $templateName);
            $templateSpan->setAttribute('template.type', $type);
            $templateSpan->setAttribute('notification.priority', $priority);
            $templateSpan->setAttribute('notification.channel', $channel);

            $renderTime = match ($type) {
                'order_confirmation' => random_int(5000, 15000),
                'fraud_alert' => random_int(2000, 5000),
                default => random_int(2000, 8000),
            };
            usleep($renderTime);

            $templateSpan->addEvent('template.rendered', [
                'render_time_ms' => round($renderTime / 1000, 1),
                'output_size_bytes' => random_int(1200, 8500),
            ]);
        } finally {
            $templateScope->detach();
            $templateSpan->end();
        }

        // Persist to "database"
        $dbSpan = $tracer->spanBuilder('db.query INSERT notifications')
            ->setSpanKind(SpanKind::KIND_CLIENT)
            ->startSpan();
        $dbScope = $dbSpan->activate();
        try {
            $dbSpan->setAttribute('db.system', 'postgresql');
            $dbSpan->setAttribute('db.name', 'notifications');
            $dbSpan->setAttribute('db.operation', 'INSERT');
            $dbSpan->setAttribute('db.statement', 'INSERT INTO notifications (id, type, channel, recipient, status) VALUES ($1, $2, $3, $4, $5)');
            usleep(random_int(2000, 8000));
        } finally {
            $dbScope->detach();
            $dbSpan->end();
        }

        // Deliver via provider
        $providers = $channel === 'sms' ? self::SMS_PROVIDERS : self::EMAIL_PROVIDERS;
        $primaryProvider = $providers[0];
        $fallbackProvider = $providers[1];

        $deliverSpan = $tracer->spanBuilder("notification.deliver ({$primaryProvider})")
            ->setSpanKind(SpanKind::KIND_CLIENT)
            ->startSpan();
        $deliverScope = $deliverSpan->activate();
        $deliveryFailed = false;
        try {
            $deliverSpan->setAttribute('notification.id', $notificationId);
            $deliverSpan->setAttribute('notification.channel', $channel);
            $deliverSpan->setAttribute('notification.provider', $primaryProvider);
            $deliverSpan->setAttribute('notification.recipient', $recipient);
            $deliverSpan->setAttribute('notification.type', $type);
            $deliverSpan->setAttribute('notification.order_id', $orderId);
            $deliverSpan->setAttribute('net.peer.name', "{$primaryProvider}-api.example.com");

            $deliveryTimeUs = match ($channel) {
                'sms' => random_int(30000, 80000),
                'push' => random_int(5000, 15000),
                default => random_int(10000, 40000),
            };
            usleep($deliveryTimeUs);

            $this->metrics->getOrRegisterHistogram(
                'notification', 'delivery_duration_seconds', 'Notification delivery duration', ['channel', 'provider'],
                [0.01, 0.025, 0.05, 0.1, 0.25, 0.5]
            )->observe($deliveryTimeUs / 1_000_000, [$channel, $primaryProvider]);

            if (random_int(1, 100) <= 8) {
                $deliveryFailed = true;
                $errorMsg = match (random_int(1, 3)) {
                    1 => "Connection refused to {$primaryProvider}-api.example.com:443",
                    2 => "{$primaryProvider} API returned HTTP 503 Service Unavailable",
                    3 => "Request to {$primaryProvider} timed out after 10s",
                };
                $deliverSpan->setStatus(StatusCode::STATUS_ERROR, $errorMsg);
                $deliverSpan->recordException(new \RuntimeException($errorMsg));
                $deliverSpan->addEvent('provider.delivery_failed', [
                    'provider' => $primaryProvider,
                    'will_retry' => true,
                    'fallback_provider' => $fallbackProvider,
                ]);

                $this->metrics->getOrRegisterCounter('notification', 'provider_errors_total', 'Provider delivery errors', ['channel', 'provider'])
                    ->incBy(1, [$channel, $primaryProvider]);
            } else {
                $deliverSpan->addEvent('notification.sent', [
                    'provider_message_id' => $primaryProvider . '_' . bin2hex(random_bytes(6)),
                    'delivery_time_ms' => round($deliveryTimeUs / 1000, 1),
                ]);
            }
        } finally {
            $deliverScope->detach();
            $deliverSpan->end();
        }

        // Fallback to secondary provider if primary failed
        if ($deliveryFailed) {
            $this->metrics->getOrRegisterCounter('notification', 'provider_failovers_total', 'Provider failover events', ['channel'])
                ->incBy(1, [$channel]);

            $retrySpan = $tracer->spanBuilder("notification.deliver ({$fallbackProvider})")
                ->setSpanKind(SpanKind::KIND_CLIENT)
                ->startSpan();
            $retryScope = $retrySpan->activate();
            try {
                $retrySpan->setAttribute('notification.id', $notificationId);
                $retrySpan->setAttribute('notification.channel', $channel);
                $retrySpan->setAttribute('notification.provider', $fallbackProvider);
                $retrySpan->setAttribute('notification.is_retry', true);
                $retrySpan->setAttribute('notification.retry_reason', 'primary_provider_failed');
                $retrySpan->setAttribute('net.peer.name', "{$fallbackProvider}-api.example.com");

                usleep(random_int(15000, 50000));

                if (random_int(1, 100) <= 2) {
                    $retrySpan->setStatus(StatusCode::STATUS_ERROR, 'All providers failed');
                    $retrySpan->recordException(
                        new \RuntimeException("Fallback provider {$fallbackProvider} also failed")
                    );

                    $this->recordRequest('POST', '/notifications', 502, microtime(true) - $startTime);
                    $this->metrics->getOrRegisterCounter('notification', 'total_failures', 'Complete delivery failures', ['channel'])
                        ->incBy(1, [$channel]);

                    return $this->json([
                        'notification_id' => $notificationId,
                        'status' => 'failed',
                        'reason' => 'all_providers_unavailable',
                    ], 502);
                }

                $retrySpan->addEvent('notification.sent_via_fallback', [
                    'primary_provider' => $primaryProvider,
                    'fallback_provider' => $fallbackProvider,
                    'provider_message_id' => $fallbackProvider . '_' . bin2hex(random_bytes(6)),
                ]);
            } finally {
                $retryScope->detach();
                $retrySpan->end();
            }
        }

        $this->metrics->getOrRegisterCounter('notification', 'sent_total', 'Successfully sent notifications', ['type', 'channel', 'provider'])
            ->incBy(1, [$type, $channel, $deliveryFailed ? $fallbackProvider : $primaryProvider]);
        $this->recordRequest('POST', '/notifications', 200, microtime(true) - $startTime);

        return $this->json([
            'notification_id' => $notificationId,
            'status' => 'delivered',
            'type' => $type,
            'channel' => $channel,
            'provider' => $deliveryFailed ? $fallbackProvider : $primaryProvider,
        ]);
    }

    #[Route('/health', methods: ['GET'])]
    public function health(): JsonResponse
    {
        return $this->json(['status' => 'healthy', 'service' => 'notification-service']);
    }

    private function recordRequest(string $method, string $endpoint, int $status, float $duration): void
    {
        $this->metrics->getOrRegisterCounter(
            'notification', 'http_requests_total', 'Total HTTP requests', ['method', 'endpoint', 'status']
        )->incBy(1, [$method, $endpoint, (string) $status]);

        $this->metrics->getOrRegisterHistogram(
            'notification', 'http_request_duration_seconds', 'HTTP request duration', ['method', 'endpoint'],
            [0.01, 0.025, 0.05, 0.1, 0.25, 0.5, 1.0, 2.5, 5.0]
        )->observe($duration, [$method, $endpoint]);
    }
}
