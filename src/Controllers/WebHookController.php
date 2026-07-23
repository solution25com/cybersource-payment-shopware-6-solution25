<?php

declare(strict_types=1);

namespace CyberSource\Shopware6\Controllers;

use CyberSource\Shopware6\Service\OrderService;
use CyberSource\Shopware6\Service\WebhookSignatureValidator;
use Psr\Log\LoggerInterface;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionEntity;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionStateHandler;
use Shopware\Core\Framework\Context;
use Shopware\Core\System\StateMachine\StateMachineRegistry;
use Shopware\Core\System\StateMachine\Transition;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use CyberSource\Shopware6\Service\ConfigurationService;

#[Route(defaults: ['_routeScope' => ['storefront']])]
class WebHookController extends AbstractController
{
    public function __construct(
        private readonly OrderService $orderService,
        private readonly OrderTransactionStateHandler $orderTransactionStateHandler,
        private readonly StateMachineRegistry $stateMachineRegistry,
        private readonly LoggerInterface $logger,
        private readonly ConfigurationService $configurationService,
        private readonly WebhookSignatureValidator $webhookSignatureValidator
    ) {
    }

    #[Route(path: '/cybersource/webhook/health', name: 'api.cybersource.webhook.health', methods: ['GET'])]
    public function healthCheck(): JsonResponse
    {
        $this->logger->info('Received health check request from CyberSource');

        return new JsonResponse(['status' => 'healthy'], Response::HTTP_OK);
    }

    #[Route(path: '/cybersource/webhook', name: 'api.cybersource.webhook', methods: ['POST'])]
    public function handleWebhook(Request $request, Context $context): JsonResponse
    {
        $rawContent = $request->getContent();
        try {
            $payload = json_decode($rawContent, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            $this->logger->error('Invalid webhook payload received');
            return new JsonResponse(
                ['status' => 'error', 'message' => 'Invalid payload'],
                400
            );
        }

        if (!is_array($payload) || $payload === []) {
            $this->logger->error('Invalid webhook payload received');
            return new JsonResponse(
                ['status' => 'error', 'message' => 'Invalid payload'],
                400
            );
        }

        $signature = $request->headers->get('v-c-signature');

        $eventType = $payload['eventType'] ?? null;
        if (!$eventType) {
            $this->logger->error('Missing eventType in webhook payload');
            return new JsonResponse(['status' => 'error', 'message' => 'Missing eventType'], 400);
        }

        $transactionId = $this->extractTransactionId($payload);
        if (!$transactionId) {
            $this->logger->error('Missing transaction ID in webhook payload');
            return new JsonResponse(['status' => 'error', 'message' => 'Missing transaction ID'], 400);
        }

        $this->logger->info('Webhook received', [
            'eventType' => $eventType,
            'transactionId' => $transactionId,
        ]);

        $orderTransaction = $this->orderService->getTransactionFromCustomFieldsDetails($transactionId, $context);

        if (!$orderTransaction) {
            $this->logger->error(
                'Order transaction not found for transaction ID',
                ['transactionId' => $transactionId]
            );
            return new JsonResponse(
                ['status' => 'error', 'message' => 'Order transaction not found'],
                404
            );
        }

        $salesChannelId = $orderTransaction->getOrder()?->getSalesChannelId();
        if (!$this->verifySignature($rawContent, $signature, $salesChannelId)) {
            $this->logger->error('Invalid webhook signature');
            return new JsonResponse(
                ['status' => 'error', 'message' => 'Invalid signature'],
                401
            );
        }
        $orderTransactionId = $orderTransaction->getId();

        $transition = $this->resolveTransition($eventType, $orderTransaction, $salesChannelId);
        if ($transition === null) {
            $this->orderService->updateWebhookMetadata($orderTransactionId, [
                'lastEventType' => $eventType,
                'lastEventStatus' => $this->extractProviderStatus($payload),
                'lastTransactionId' => $transactionId,
                'lastReceivedAt' => (new \DateTimeImmutable())->format(DATE_ATOM),
            ], $context);
            $this->logger->info('Webhook event stored without state transition', ['eventType' => $eventType]);
            return new JsonResponse(['status' => 'success']);
        }

        if (!$this->updatePaymentStatus($orderTransactionId, $transition, $context)) {
            $this->orderService->updateWebhookMetadata($orderTransactionId, [
                'lastEventType' => $eventType,
                'lastEventStatus' => $this->extractProviderStatus($payload),
                'lastTransactionId' => $transactionId,
                'lastReceivedAt' => (new \DateTimeImmutable())->format(DATE_ATOM),
            ], $context);
        }

        return new JsonResponse(['status' => 'success']);
    }

    /**
     * @param array{type:string,action:string,targetState:string} $transition
     */
    private function updatePaymentStatus(string $orderTransactionId, array $transition, Context $context): bool
    {
        try {
            if ($transition['type'] === 'handler') {
                switch ($transition['action']) {
                    case 'paid':
                        $this->orderTransactionStateHandler->paid($orderTransactionId, $context);
                        break;
                    case 'authorize':
                        $this->orderTransactionStateHandler->authorize($orderTransactionId, $context);
                        break;
                    case 'fail':
                        $this->orderTransactionStateHandler->fail($orderTransactionId, $context);
                        break;
                    case 'cancel':
                        $this->orderTransactionStateHandler->cancel($orderTransactionId, $context);
                        break;
                    case 'refund':
                        $this->orderTransactionStateHandler->refund($orderTransactionId, $context);
                        break;
                    default:
                        throw new \RuntimeException('Unsupported handler transition: ' . $transition['action']);
                }
            } else {
                $stateTransition = new Transition(
                    'order_transaction',
                    $orderTransactionId,
                    $transition['action'],
                    'stateId'
                );

                $this->stateMachineRegistry->transition($stateTransition, $context);
            }

            $this->logger->info('Payment status updated', [
                'orderTransactionId' => $orderTransactionId,
                'newState' => $transition['targetState'],
                'action' => $transition['action'],
            ]);

            return true;
        } catch (\Exception $e) {
            $this->logger->error('Failed to update payment status', [
                'orderTransactionId' => $orderTransactionId,
                'newState' => $transition['targetState'],
                'action' => $transition['action'],
                'error' => $e->getMessage()
            ]);

            return false;
        }
    }

    private function verifySignature(string $payload, ?string $signature, ?string $salesChannelId): bool
    {
        $secretKey = $this->configurationService->getSharedSecretKey($salesChannelId);
        $keyId = $this->configurationService->getSharedSecretKeyId($salesChannelId);
        if (!$secretKey) {
            $this->logger->error('Shared secret key not configured');
            return false;
        }

        return $this->webhookSignatureValidator->verify($payload, $signature, $secretKey, $keyId);
    }

    private function extractTransactionId(array $payload): ?string
    {
        $candidates = [
            $payload['payload']['transactionId'] ?? null,
            $payload['payload']['id'] ?? null,
            $payload['payloads']['testPayload']['transactionId'] ?? null,
            $payload['payloads']['testPayload']['id'] ?? null,
        ];

        foreach ($candidates as $candidate) {
            if (is_string($candidate) && $candidate !== '') {
                return $candidate;
            }
        }

        return null;
    }

    private function extractProviderStatus(array $payload): ?string
    {
        $candidates = [
            $payload['payload']['status'] ?? null,
            $payload['payloads']['testPayload']['status'] ?? null,
            $payload['status'] ?? null,
        ];

        foreach ($candidates as $candidate) {
            if (is_string($candidate) && $candidate !== '') {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * @return array{type:string,action:string,targetState:string}|null
     */
    private function resolveTransition(
        string $eventType,
        OrderTransactionEntity $orderTransaction,
        ?string $salesChannelId
    ): ?array {
        $currentState = $this->orderService->getPaymentStatus($orderTransaction);
        $transactionType = $this->configurationService->getTransactionType($salesChannelId);

        $reviewEvents = [
            'payments.payments.review',
            'payments.captures.review',
            'payments.credits.review',
            'risk.profile.decision.review',
        ];
        if (in_array($eventType, $reviewEvents, true)) {
            if ($currentState === 'pending_review') {
                return null;
            }
            if (in_array($currentState, ['open', 'pre_review'], true)) {
                return ['type' => 'state_machine', 'action' => 'pending_review', 'targetState' => 'pending_review'];
            }
            return null;
        }

        $rejectEvents = [
            'payments.payments.reject',
            'payments.reversals.reject',
            'payments.captures.reject',
            'payments.refunds.reject',
            'payments.credits.reject',
            'payments.voids.reject',
            'risk.profile.decision.reject',
            'risk.casemanagement.decision.reject',
        ];
        if (in_array($eventType, $rejectEvents, true)) {
            if ($currentState === 'failed') {
                return null;
            }
            if (in_array($currentState, ['pending_review', 'pre_review'], true)) {
                return ['type' => 'state_machine', 'action' => 'decline', 'targetState' => 'failed'];
            }
            if (in_array($currentState, ['open', 'authorized', 'in_progress'], true)) {
                return ['type' => 'handler', 'action' => 'fail', 'targetState' => 'failed'];
            }
            return null;
        }

        $paymentAcceptEvents = [
            'payments.payments.accept',
            'risk.casemanagement.decision.accept',
        ];
        if (in_array($eventType, $paymentAcceptEvents, true)) {
            if (in_array($currentState, ['paid', 'authorized'], true)) {
                return null;
            }
            if ($currentState === 'pending_review') {
                return ['type' => 'state_machine', 'action' => 'paid_authorized', 'targetState' => 'paid'];
            }
            if ($transactionType === 'auth') {
                return ['type' => 'handler', 'action' => 'authorize', 'targetState' => 'authorized'];
            }
            return ['type' => 'handler', 'action' => 'paid', 'targetState' => 'paid'];
        }

        if ($eventType === 'payments.payments.partial.approval') {
            if ($currentState === 'paid_partially') {
                return null;
            }
            if (in_array($currentState, ['open', 'in_progress'], true)) {
                return ['type' => 'state_machine', 'action' => 'pay_partially', 'targetState' => 'paid_partially'];
            }
            return null;
        }

        if ($eventType === 'payments.captures.accept') {
            return $currentState === 'paid' ? null : ['type' => 'handler', 'action' => 'paid', 'targetState' => 'paid'];
        }

        $refundEvents = [
            'payments.refunds.accept',
            'payments.credits.accept',
        ];
        if (in_array($eventType, $refundEvents, true)) {
            return $currentState === 'refunded'
                ? null
                : ['type' => 'handler', 'action' => 'refund', 'targetState' => 'refunded'];
        }

        $partialRefundEvents = [
            'payments.refunds.partial.approval',
            'payments.credits.partial.approval',
        ];
        if (in_array($eventType, $partialRefundEvents, true)) {
            if (in_array($currentState, ['refunded', 'refunded_partially'], true)) {
                return null;
            }
            if (in_array($currentState, ['paid', 'paid_partially'], true)) {
                return [
                    'type' => 'state_machine',
                    'action' => 'refund_partially',
                    'targetState' => 'refunded_partially',
                ];
            }
            return null;
        }

        $cancelEvents = [
            'payments.reversals.accept',
            'payments.voids.accept',
        ];
        if (in_array($eventType, $cancelEvents, true)) {
            return $currentState === 'cancelled'
                ? null
                : ['type' => 'handler', 'action' => 'cancel', 'targetState' => 'cancelled'];
        }

        if ($eventType === 'payments.payments.updated') {
            if ($currentState === 'in_progress') {
                return null;
            }
            if ($currentState === 'open') {
                return ['type' => 'state_machine', 'action' => 'process', 'targetState' => 'in_progress'];
            }
        }

        return null;
    }
}
