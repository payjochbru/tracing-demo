<?php

namespace App\Controller;

use OpenTelemetry\API\Globals;
use OpenTelemetry\API\Trace\StatusCode;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class PaymentController extends AbstractController
{
    private const PAYMENT_METHODS = ['visa', 'mastercard', 'ideal', 'bancontact', 'sepa_direct_debit'];

    public function __construct(
        private readonly HttpClientInterface $httpClient,
    ) {
    }

    #[Route('/payments', methods: ['POST'])]
    public function processPayment(Request $request): JsonResponse
    {
        $tracer = Globals::tracerProvider()->getTracer('payment-service');

        $data = json_decode($request->getContent(), true) ?: [];
        $paymentId = 'pay_' . bin2hex(random_bytes(6));
        $orderId = $data['order_id'] ?? 'unknown';
        $customerId = $data['customer_id'] ?? 'unknown';
        $amount = $data['amount'] ?? 0;
        $currency = $data['currency'] ?? 'EUR';
        $method = self::PAYMENT_METHODS[array_rand(self::PAYMENT_METHODS)];
        $cardLast4 = (string) random_int(1000, 9999);

        // Fraud check
        $fraudSpan = $tracer->spanBuilder('payment.fraud_check')->startSpan();
        $fraudScope = $fraudSpan->activate();
        try {
            $riskScore = random_int(0, 100);
            $fraudSpan->setAttribute('fraud.risk_score', $riskScore);
            $fraudSpan->setAttribute('fraud.customer_id', $customerId);
            $fraudSpan->setAttribute('fraud.amount', $amount);
            $fraudSpan->setAttribute('fraud.method', $method);
            usleep(random_int(10000, 50000));
            $fraudSpan->addEvent('fraud_check.completed', [
                'risk_score' => $riskScore,
                'threshold' => 80,
            ]);
        } finally {
            $fraudScope->detach();
            $fraudSpan->end();
        }

        $shouldFail = random_int(1, 100) <= 5;

        // Authorization
        $authSpan = $tracer->spanBuilder('payment.authorize')->startSpan();
        $authScope = $authSpan->activate();
        try {
            $authSpan->setAttribute('payment.id', $paymentId);
            $authSpan->setAttribute('payment.order_id', $orderId);
            $authSpan->setAttribute('payment.method', $method);
            $authSpan->setAttribute('payment.amount', $amount);
            $authSpan->setAttribute('payment.currency', $currency);
            $authSpan->setAttribute('payment.card_last4', $cardLast4);

            usleep(random_int(20000, 80000));

            if ($shouldFail) {
                $authSpan->setStatus(StatusCode::STATUS_ERROR, 'Payment declined by issuer');
                $authSpan->addEvent('authorization.declined', [
                    'reason' => 'insufficient_funds',
                ]);
            } else {
                $authSpan->addEvent('authorization.approved');
            }
        } finally {
            $authScope->detach();
            $authSpan->end();
        }

        if ($shouldFail) {
            $notificationUrl = rtrim($_SERVER['NOTIFICATION_SERVICE_URL'] ?? 'http://notification-service:8080', '/');
            $this->httpClient->request('POST', $notificationUrl . '/notifications', [
                'json' => [
                    'type' => 'payment_failed',
                    'channel' => 'email',
                    'recipient' => $customerId,
                    'order_id' => $orderId,
                    'message' => "Payment {$paymentId} for order {$orderId} was declined.",
                ],
            ]);

            return $this->json([
                'payment_id' => $paymentId,
                'status' => 'declined',
                'reason' => 'insufficient_funds',
            ], 422);
        }

        // Settlement
        $settleSpan = $tracer->spanBuilder('payment.settle')->startSpan();
        $settleScope = $settleSpan->activate();
        try {
            $settleSpan->setAttribute('payment.id', $paymentId);
            $settleSpan->setAttribute('settlement.amount', $amount);
            $settleSpan->setAttribute('settlement.currency', $currency);
            usleep(random_int(5000, 20000));
            $settleSpan->addEvent('settlement.completed');
        } finally {
            $settleScope->detach();
            $settleSpan->end();
        }

        return $this->json([
            'payment_id' => $paymentId,
            'status' => 'settled',
            'method' => $method,
            'amount' => $amount,
            'currency' => $currency,
        ]);
    }

    #[Route('/health', methods: ['GET'])]
    public function health(): JsonResponse
    {
        return $this->json(['status' => 'healthy', 'service' => 'payment-service']);
    }
}
