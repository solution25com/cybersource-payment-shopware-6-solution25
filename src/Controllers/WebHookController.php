<?php

declare(strict_types=1);

namespace CyberSource\Shopware6\Controllers;

use CyberSource\Shopware6\Service\OrderService;
use Psr\Log\LoggerInterface;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\Validation\DataBag\RequestDataBag;
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
        private readonly StateMachineRegistry $stateMachineRegistry,
        private readonly LoggerInterface $logger,
        private readonly ConfigurationService $configurationService
    ) {
    }

    #[Route(path: '/cybersource/webhook/health', name: 'api.cybersource.webhook.health', methods: ['GET'])]
    public function healthCheck(Request $request): JsonResponse
    {
        $this->logger->info('Received health check request from CyberSource', [
            'headers' => $request->headers->all(),
        ]);

        return new JsonResponse(['status' => 'healthy'], Response::HTTP_OK);
    }

    #[Route(path: '/cybersource/webhook', name: 'api.cybersource.webhook', methods: ['POST'])]
    public function handleWebhook(Request $request, RequestDataBag $dataBag, Context $context): JsonResponse
    {
        $payload = $dataBag->all();
        if (!$payload) {
            $this->logger->error('Invalid webhook payload received');
            return new JsonResponse(
                ['status' => 'error', 'message' => 'Invalid payload'],
                400
            );
        }

        $this->logger->info('Webhook received', ['payload' => $payload]);

        $signature = $request->headers->get('v-c-signature');
        $rawContent = $request->getContent();

        $eventType = $payload['eventType'] ?? null;
        if (!$eventType) {
            $this->logger->error('Missing eventType in webhook payload');
            return new JsonResponse(['status' => 'error', 'message' => 'Missing eventType'], 400);
        }

        $transactionId = $payload['payloads']['testPayload']['transactionId'] ?? null;
        if (!$transactionId) {
            $this->logger->error('Missing transaction ID in webhook payload');
            return new JsonResponse(['status' => 'error', 'message' => 'Missing transaction ID'], 400);
        }

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

        $salesChannelId = $orderTransaction->getOrder()->getSalesChannelId();
        if (!$this->verifySignature($rawContent, $signature, $salesChannelId)) {
            $this->logger->error('Invalid webhook signature');
            return new JsonResponse(
                ['status' => 'error', 'message' => 'Invalid signature'],
                401
            );
        }
        $orderTransactionId = $orderTransaction->getId();

        // Map CyberSource event types to Shopware payment states
        $stateMapping = [
            'payments.payments.accept' => ['state' => 'paid', 'transition' => 'pay'],
            'payments.payments.reject' => ['state' => 'failed', 'transition' => 'decline'],
            'payments.payments.review' => ['state' => 'pending_review', 'transition' => 'pending_review'],
            'payments.payments.partial.approval' => ['state' => 'partially_paid', 'transition' => 'partial_payment'],
            'payments.reversals.accept' => ['state' => 'cancelled', 'transition' => 'cancel'],
            'payments.reversals.reject' => ['state' => 'failed', 'transition' => 'decline'],
            'payments.captures.accept' => ['state' => 'paid', 'transition' => 'pay'],
            'payments.captures.review' => ['state' => 'pending_review', 'transition' => 'pending_review'],
            'payments.captures.reject' => ['state' => 'failed', 'transition' => 'decline'],
            'payments.refunds.accept' => ['state' => 'refunded', 'transition' => 'refund'],
            'payments.refunds.reject' => ['state' => 'failed', 'transition' => 'decline'],
            'payments.refunds.partial.approval' =>
                ['state' => 'partially_refunded', 'transition' => 'partial_refunded'],
            'payments.credits.accept' => ['state' => 'refunded', 'transition' => 'refund'],
            'payments.credits.review' => ['state' => 'pending_review', 'transition' => 'pending_review'],
            'payments.credits.reject' => ['state' => 'failed', 'transition' => 'decline'],
            'payments.credits.partial.approval' =>
                ['state' => 'partially_refunded', 'transition' => 'partial_refunded'],
            'payments.voids.accept' => ['state' => 'cancelled', 'transition' => 'cancel'],
            'payments.voids.reject' => ['state' => 'failed', 'transition' => 'decline'],
            'risk.profile.decision.review' => ['state' => 'pending_review', 'transition' => 'pending_review'],
            'risk.profile.decision.reject' => ['state' => 'failed', 'transition' => 'decline'],
            'risk.casemanagement.decision.accept' => ['state' => 'paid', 'transition' => 'pay'],
            'risk.casemanagement.decision.reject' => ['state' => 'failed', 'transition' => 'decline'],
            'payments.payments.updated' => ['state' => 'in_progress', 'transition' => 'update'],
        ];

        if (isset($stateMapping[$eventType])) {
            $this->updatePaymentStatus(
                $orderTransactionId,
                $stateMapping[$eventType]['state'],
                $stateMapping[$eventType]['transition'],
                $context
            );
        } else {
            $this->logger->info('Unhandled webhook event type', ['eventType' => $eventType]);
        }

        return new JsonResponse(['status' => 'success']);
    }

    private function updatePaymentStatus(
        string $orderTransactionId,
        string $newState,
        string $transitionName,
        Context $context
    ): void {
        try {
            $transition = new Transition(
                'order_transaction',
                $orderTransactionId,
                $transitionName,
                $newState
            );

            $this->stateMachineRegistry->transition($transition, $context);
            $this->logger->info('Payment status updated', [
                'orderTransactionId' => $orderTransactionId,
                'newState' => $newState
            ]);
        } catch (\Exception $e) {
            $this->logger->error('Failed to update payment status', [
                'orderTransactionId' => $orderTransactionId,
                'newState' => $newState,
                'error' => $e->getMessage()
            ]);
        }
    }

    private function verifySignature(string $payload, ?string $signature, ?string $salesChannelId): bool
    {
        $secretKey = $this->configurationService->getSharedSecretKey($salesChannelId);
        if (!$secretKey) {
            $this->logger->error('Shared secret key not configured');
            return false;
        }

        $expectedSignature = base64_encode(hash_hmac('sha256', $payload, $secretKey, true));
        return $signature && hash_equals($expectedSignature, $signature);
    }
}
