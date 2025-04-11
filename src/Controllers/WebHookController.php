<?php

declare(strict_types=1);

namespace CyberSource\Shopware6\Controllers;

use Psr\Log\LoggerInterface;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
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
        private readonly EntityRepository $orderTransactionRepo,
        private readonly StateMachineRegistry $stateMachineRegistry,
        private readonly LoggerInterface $logger,
        private readonly ConfigurationService $configurationService
    ) {}
    #[Route(path: '/cybersource/webhook/health', name: 'api.cybersource.webhook.health', methods: ['GET'])]
    public function healthCheck(Request $request, Context $context): JsonResponse
    {
        $this->logger->info('Received health check request from CyberSource', [
            'headers' => $request->headers->all(),
        ]);

        return new JsonResponse(['status' => 'healthy'], Response::HTTP_OK);
    }
    #[Route(path: '/cybersource/webhook', name: 'api.cybersource.webhook', methods: ['POST'])]
    public function handleWebhook(Request $request, Context $context): JsonResponse
    {
        $payload = json_decode($request->getContent(), true);
        if (!$payload) {
            $this->logger->error('Invalid webhook payload received');
            return new JsonResponse(['status' => 'error', 'message' => 'Invalid payload'], 400);
        }

        $this->logger->info('Webhook received', ['payload' => $payload]);

//        $signature = $request->headers->get('x-cybersource-signature');
        $signature = $request->headers->get('v-c-signature');
        if (!$this->verifySignature($request->getContent(), $signature)) {
            $this->logger->error('Invalid webhook signature');
            return new JsonResponse(['status' => 'error', 'message' => 'Invalid signature'], 401);
        }

        $transactionId = $payload['data']['id'] ?? null;
        if (!$transactionId) {
            $this->logger->error('Missing transaction ID in webhook payload');
            return new JsonResponse(['status' => 'error', 'message' => 'Missing transaction ID'], 400);
        }

        $criteria = new \Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria();
        $criteria->addFilter(new \Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter(
            'customFields.cybersource_payment_details.transaction_id',
            $transactionId
        ));
        $orderTransaction = $this->orderTransactionRepo->search($criteria, $context)->first();

        if (!$orderTransaction) {
            $this->logger->error('Order transaction not found for transaction ID', ['transactionId' => $transactionId]);
            return new JsonResponse(['status' => 'error', 'message' => 'Order transaction not found'], 404);
        }

        $orderTransactionId = $orderTransaction->getId();
        $status = $payload['data']['status'] ?? null;

        if (!$status) {
            $this->logger->error('Missing status in webhook payload');
            return new JsonResponse(['status' => 'error', 'message' => 'Missing status'], 400);
        }

        switch ($status) {
            case 'AUTHORIZED':
                $this->updatePaymentStatus($orderTransactionId, 'paid', 'pay', $context);
                break;

            case 'DECLINED':
                $this->updatePaymentStatus($orderTransactionId, 'failed', 'decline', $context);
                break;

            case 'AUTHORIZED_PENDING_REVIEW':
                $this->updatePaymentStatus($orderTransactionId, 'pending_review', 'pending_review', $context);
                break;

            case 'PENDING_REVIEW':
                $this->updatePaymentStatus($orderTransactionId, 'pre_review', 'pre_review', $context);
                break;

            default:
                $this->logger->info('Unhandled webhook status', ['status' => $status]);
                break;
        }

        return new JsonResponse(['status' => 'success']);
    }

    private function updatePaymentStatus(string $orderTransactionId, string $newState, string $transitionName, Context $context): void
    {
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

    private function verifySignature(string $payload, ?string $signature): bool
    {
        $secretKey = $this->configurationService->getSecretKey();
        $expectedSignature = base64_encode(hash_hmac('sha256', $payload, $secretKey, true));
        return $signature && hash_equals($expectedSignature, $signature);
    }
}