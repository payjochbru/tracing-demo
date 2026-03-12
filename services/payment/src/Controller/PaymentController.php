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

class PaymentController extends AbstractController
{
    private const PAYMENT_METHODS = ['visa', 'mastercard', 'ideal', 'bancontact', 'sepa_direct_debit', 'apple_pay'];

    private const PSP_ROUTING = [
        'visa' => 'pay.nl',
        'mastercard' => 'pay.nl',
        'ideal' => 'mollie',
        'bancontact' => 'mollie',
        'sepa_direct_debit' => 'adyen',
        'apple_pay' => 'pay.nl',
    ];

    private const DECLINE_REASONS = [
        ['code' => 'insufficient_funds', 'message' => 'Card has insufficient funds'],
        ['code' => 'card_expired', 'message' => 'Card has expired'],
        ['code' => 'do_not_honor', 'message' => 'Issuer declined without reason'],
        ['code' => 'suspected_fraud', 'message' => 'Transaction flagged as suspicious'],
        ['code' => 'invalid_card', 'message' => 'Card number is invalid'],
    ];

    private CollectorRegistry $metrics;

    public function __construct(
        private readonly HttpClientInterface $httpClient,
    ) {
        $this->metrics = new CollectorRegistry(new APC());
    }

    #[Route('/payments', methods: ['POST'])]
    public function processPayment(Request $request): JsonResponse
    {
        $startTime = microtime(true);
        $tracer = Globals::tracerProvider()->getTracer('payment-service');

        $data = json_decode($request->getContent(), true) ?: [];
        $paymentId = 'pay_' . bin2hex(random_bytes(6));
        $orderId = $data['order_id'] ?? 'unknown';
        $customerId = $data['customer_id'] ?? 'unknown';
        $amount = $data['amount'] ?? 0;
        $currency = $data['currency'] ?? 'EUR';
        $requestId = $data['request_id'] ?? 'unknown';
        $region = $data['region'] ?? 'eu-west';
        $method = self::PAYMENT_METHODS[array_rand(self::PAYMENT_METHODS)];
        $psp = self::PSP_ROUTING[$method] ?? 'pay.nl';
        $cardLast4 = (string) random_int(1000, 9999);

        $this->metrics->getOrRegisterHistogram(
            'payment', 'amount_euros', 'Payment amount distribution', ['method', 'currency'],
            [10, 25, 50, 100, 250, 500, 1000, 2500, 5000]
        )->observe($amount, [$method, $currency]);

        // External compliance / sanctions check
        $httpbinUrl = rtrim($_SERVER['HTTPBIN_URL'] ?? 'https://httpbin.org', '/');
        $complianceSpan = $tracer->spanBuilder('payment.compliance_check')
            ->setSpanKind(SpanKind::KIND_CLIENT)
            ->startSpan();
        $complianceScope = $complianceSpan->activate();
        try {
            $complianceSpan->setAttribute('compliance.provider', 'external-sanctions-api');
            $complianceSpan->setAttribute('compliance.customer_id', $customerId);
            $complianceSpan->setAttribute('compliance.region', $region);

            $complianceResponse = $this->httpClient->request('GET', $httpbinUrl . '/get', [
                'query' => [
                    'customer_id' => $customerId,
                    'check_type' => 'sanctions,pep,adverse_media',
                    'region' => $region,
                ],
                'timeout' => 5,
            ]);
            $complianceSpan->setAttribute('http.response.status_code', $complianceResponse->getStatusCode());
            $complianceSpan->setAttribute('compliance.result', 'clear');
            $complianceSpan->addEvent('compliance.check_passed', [
                'lists_checked' => 'OFAC,EU_SANCTIONS,UN_SANCTIONS',
                'matches_found' => 0,
            ]);
        } catch (\Throwable $e) {
            $complianceSpan->setStatus(StatusCode::STATUS_ERROR, 'Compliance check failed');
            $complianceSpan->recordException($e);
        } finally {
            $complianceScope->detach();
            $complianceSpan->end();
        }

        // Exchange rate lookup (for multi-currency display)
        $fxSpan = $tracer->spanBuilder('payment.fx_rate_lookup')
            ->setSpanKind(SpanKind::KIND_CLIENT)
            ->startSpan();
        $fxScope = $fxSpan->activate();
        try {
            $fxSpan->setAttribute('fx.base_currency', $currency);
            $fxSpan->setAttribute('fx.provider', 'external-fx-api');
            $fxResponse = $this->httpClient->request('GET', $httpbinUrl . '/get', [
                'query' => ['base' => $currency, 'symbols' => 'USD,GBP'],
                'timeout' => 5,
            ]);
            $fxSpan->setAttribute('http.response.status_code', $fxResponse->getStatusCode());
            $fxSpan->setAttribute('fx.rate_eur_usd', 1.0847);
            $fxSpan->setAttribute('fx.rate_eur_gbp', 0.8612);
            $fxSpan->addEvent('fx.rates_fetched');
        } catch (\Throwable $e) {
            $fxSpan->setStatus(StatusCode::STATUS_ERROR, 'FX rate lookup failed');
            $fxSpan->recordException($e);
        } finally {
            $fxScope->detach();
            $fxSpan->end();
        }

        // Fraud check
        $fraudSpan = $tracer->spanBuilder('payment.fraud_check')->startSpan();
        $fraudScope = $fraudSpan->activate();
        try {
            $riskScore = random_int(0, 100);
            $fraudSpan->setAttribute('fraud.risk_score', $riskScore);
            $fraudSpan->setAttribute('fraud.customer_id', $customerId);
            $fraudSpan->setAttribute('fraud.amount', $amount);
            $fraudSpan->setAttribute('fraud.method', $method);
            $fraudSpan->setAttribute('fraud.region', $region);
            $fraudSpan->setAttribute('fraud.model_version', 'v3.2.1');

            $this->metrics->getOrRegisterHistogram(
                'payment', 'fraud_risk_score', 'Fraud risk score distribution', [],
                [10, 20, 30, 40, 50, 60, 70, 80, 90, 100]
            )->observe($riskScore, []);

            $fraudCheckTime = $riskScore > 60
                ? random_int(40000, 100000)
                : random_int(10000, 30000);
            usleep($fraudCheckTime);

            if ($riskScore > 60) {
                $fraudSpan->addEvent('fraud.additional_checks_triggered', [
                    'checks' => 'velocity,geolocation,device_fingerprint',
                ]);
            }

            if ($riskScore > 90) {
                $fraudSpan->setStatus(StatusCode::STATUS_ERROR, 'Blocked by fraud detection');
                $fraudSpan->recordException(
                    new \RuntimeException("Transaction blocked: risk score {$riskScore} exceeds threshold 90")
                );

                $this->metrics->getOrRegisterCounter('payment', 'fraud_blocks_total', 'Transactions blocked by fraud detection', [])
                    ->incBy(1, []);

                $notificationUrl = rtrim($_SERVER['NOTIFICATION_SERVICE_URL'] ?? 'http://notification-service:8080', '/');
                $this->httpClient->request('POST', $notificationUrl . '/notifications', [
                    'json' => [
                        'type' => 'fraud_alert',
                        'channel' => 'email',
                        'recipient' => $customerId,
                        'order_id' => $orderId,
                        'priority' => 'high',
                        'message' => "Suspicious transaction blocked for order {$orderId}.",
                    ],
                ]);

                $this->recordRequest('POST', '/payments', 403, microtime(true) - $startTime);

                return $this->json([
                    'payment_id' => $paymentId,
                    'status' => 'blocked',
                    'reason' => 'fraud_detected',
                    'risk_score' => $riskScore,
                ], 403);
            }

            $fraudSpan->addEvent('fraud_check.passed', [
                'risk_score' => $riskScore,
                'threshold' => 90,
                'check_duration_ms' => round($fraudCheckTime / 1000, 1),
            ]);
        } finally {
            $fraudScope->detach();
            $fraudSpan->end();
        }

        // 3D Secure check for card payments with amount > 250
        if (in_array($method, ['visa', 'mastercard']) && $amount > 250) {
            $threeDsSpan = $tracer->spanBuilder('payment.3ds_verification')->startSpan();
            $threeDsScope = $threeDsSpan->activate();
            try {
                $threeDsSpan->setAttribute('payment.3ds.version', '2.2');
                $threeDsSpan->setAttribute('payment.3ds.required', true);
                $threeDsSpan->setAttribute('payment.amount', $amount);

                usleep(random_int(50000, 150000));

                if (random_int(1, 100) <= 2) {
                    $threeDsSpan->setStatus(StatusCode::STATUS_ERROR, '3DS verification timed out');
                    $threeDsSpan->recordException(
                        new \RuntimeException('3D Secure verification timed out after 30s')
                    );

                    $this->recordRequest('POST', '/payments', 504, microtime(true) - $startTime);

                    return $this->json([
                        'payment_id' => $paymentId,
                        'status' => 'failed',
                        'reason' => '3ds_timeout',
                    ], 504);
                }

                $threeDsSpan->addEvent('3ds.verification_completed', [
                    'result' => 'authenticated',
                    'eci' => '05',
                ]);
            } finally {
                $threeDsScope->detach();
                $threeDsSpan->end();
            }
        }

        // PSP authorization
        $authSpan = $tracer->spanBuilder("psp.authorize ({$psp})")
            ->setSpanKind(SpanKind::KIND_CLIENT)
            ->startSpan();
        $authScope = $authSpan->activate();
        try {
            $authSpan->setAttribute('payment.id', $paymentId);
            $authSpan->setAttribute('payment.order_id', $orderId);
            $authSpan->setAttribute('payment.method', $method);
            $authSpan->setAttribute('payment.amount', $amount);
            $authSpan->setAttribute('payment.currency', $currency);
            $authSpan->setAttribute('payment.card_last4', $cardLast4);
            $authSpan->setAttribute('psp.name', $psp);
            $authSpan->setAttribute('psp.environment', 'production');
            $authSpan->setAttribute('net.peer.name', "{$psp}-api.example.com");

            $pspLatency = match ($psp) {
                'pay.nl' => random_int(20000, 60000),
                'mollie' => random_int(30000, 90000),
                'adyen' => random_int(25000, 70000),
                default => random_int(30000, 80000),
            };
            usleep($pspLatency);

            $this->metrics->getOrRegisterHistogram(
                'payment', 'psp_latency_seconds', 'PSP authorization latency', ['psp'],
                [0.02, 0.05, 0.1, 0.15, 0.2, 0.5]
            )->observe($pspLatency / 1_000_000, [$psp]);

            if (random_int(1, 100) <= 3) {
                $authSpan->setStatus(StatusCode::STATUS_ERROR, "PSP {$psp} request timed out");
                $authSpan->recordException(
                    new \RuntimeException("Connection to {$psp}-api.example.com timed out after 30000ms")
                );
                $authSpan->addEvent('psp.timeout', [
                    'psp' => $psp,
                    'timeout_ms' => 30000,
                ]);

                $this->recordRequest('POST', '/payments', 504, microtime(true) - $startTime);

                return $this->json([
                    'payment_id' => $paymentId,
                    'status' => 'failed',
                    'reason' => 'psp_timeout',
                    'psp' => $psp,
                ], 504);
            }

            if (random_int(1, 100) <= 10) {
                $decline = self::DECLINE_REASONS[array_rand(self::DECLINE_REASONS)];
                $authSpan->setStatus(StatusCode::STATUS_ERROR, $decline['message']);
                $authSpan->setAttribute('payment.decline_code', $decline['code']);
                $authSpan->addEvent('authorization.declined', [
                    'code' => $decline['code'],
                    'message' => $decline['message'],
                    'psp' => $psp,
                ]);
            } else {
                $authSpan->setAttribute('payment.authorization_code', strtoupper(bin2hex(random_bytes(3))));
                $authSpan->addEvent('authorization.approved', [
                    'psp_reference' => $psp . '_' . bin2hex(random_bytes(8)),
                ]);
            }

            $shouldFail = $authSpan->isRecording() && $authSpan->getAttribute('payment.decline_code') !== null;
        } finally {
            $authScope->detach();
            $authSpan->end();
        }

        $shouldFail = random_int(1, 100) <= 10;
        if ($shouldFail) {
            $decline = self::DECLINE_REASONS[array_rand(self::DECLINE_REASONS)];

            $this->metrics->getOrRegisterCounter('payment', 'declined_total', 'Declined payments', ['method', 'psp', 'reason'])
                ->incBy(1, [$method, $psp, $decline['code']]);

            $notificationUrl = rtrim($_SERVER['NOTIFICATION_SERVICE_URL'] ?? 'http://notification-service:8080', '/');
            $this->httpClient->request('POST', $notificationUrl . '/notifications', [
                'json' => [
                    'type' => 'payment_failed',
                    'channel' => 'email',
                    'recipient' => $customerId,
                    'order_id' => $orderId,
                    'priority' => 'high',
                    'message' => "Payment declined for order {$orderId}: {$decline['message']}",
                ],
            ]);

            $this->recordRequest('POST', '/payments', 422, microtime(true) - $startTime);

            return $this->json([
                'payment_id' => $paymentId,
                'status' => 'declined',
                'reason' => $decline['code'],
                'psp' => $psp,
            ], 422);
        }

        // Settlement
        $settleSpan = $tracer->spanBuilder('payment.settle')->startSpan();
        $settleScope = $settleSpan->activate();
        try {
            $settleSpan->setAttribute('payment.id', $paymentId);
            $settleSpan->setAttribute('settlement.amount', $amount);
            $settleSpan->setAttribute('settlement.currency', $currency);
            $settleSpan->setAttribute('settlement.psp', $psp);
            usleep(random_int(5000, 20000));
            $settleSpan->addEvent('settlement.completed');
        } finally {
            $settleScope->detach();
            $settleSpan->end();
        }

        // Persist payment record
        $dbSpan = $tracer->spanBuilder('db.query INSERT payments')
            ->setSpanKind(SpanKind::KIND_CLIENT)
            ->startSpan();
        $dbScope = $dbSpan->activate();
        try {
            $dbSpan->setAttribute('db.system', 'postgresql');
            $dbSpan->setAttribute('db.name', 'payments');
            $dbSpan->setAttribute('db.operation', 'INSERT');
            $dbSpan->setAttribute('db.statement', 'INSERT INTO payments (id, order_id, amount, currency, method, psp, status) VALUES ($1, $2, $3, $4, $5, $6, $7)');
            usleep(random_int(2000, 8000));
        } finally {
            $dbScope->detach();
            $dbSpan->end();
        }

        $this->metrics->getOrRegisterCounter('payment', 'successful_total', 'Successful payments', ['method', 'psp'])
            ->incBy(1, [$method, $psp]);
        $this->recordRequest('POST', '/payments', 200, microtime(true) - $startTime);

        return $this->json([
            'payment_id' => $paymentId,
            'status' => 'settled',
            'method' => $method,
            'psp' => $psp,
            'amount' => $amount,
            'currency' => $currency,
        ]);
    }

    #[Route('/health', methods: ['GET'])]
    public function health(): JsonResponse
    {
        return $this->json(['status' => 'healthy', 'service' => 'payment-service']);
    }

    private function recordRequest(string $method, string $endpoint, int $status, float $duration): void
    {
        $this->metrics->getOrRegisterCounter(
            'payment', 'http_requests_total', 'Total HTTP requests', ['method', 'endpoint', 'status']
        )->incBy(1, [$method, $endpoint, (string) $status]);

        $this->metrics->getOrRegisterHistogram(
            'payment', 'http_request_duration_seconds', 'HTTP request duration', ['method', 'endpoint'],
            [0.01, 0.025, 0.05, 0.1, 0.25, 0.5, 1.0, 2.5, 5.0]
        )->observe($duration, [$method, $endpoint]);
    }
}
