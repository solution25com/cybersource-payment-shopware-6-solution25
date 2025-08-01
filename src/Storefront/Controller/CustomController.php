<?php

declare(strict_types=1);

namespace CyberSource\Shopware6\Storefront\Controller;

use Shopware\Storefront\Controller\StorefrontController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Storefront\Page\GenericPageLoader;
use CyberSource\Shopware6\Service\CyberSourceApiClient;

#[Route(defaults: ['_routeScope' => ['storefront']])]
class CustomController extends StorefrontController
{
    private GenericPageLoader $genericPageLoader;
    private CybersourceApiClient $apiClient;
    public function __construct(
        GenericPageLoader $genericPageLoader,
        CybersourceApiClient $cybersourceApiClient,
    ) {
        $this->genericPageLoader = $genericPageLoader;
        $this->apiClient = $cybersourceApiClient;
    }
    #[Route(
        path: '/cybersource/capture-context',
        name: 'custom.capture_context',
        methods: ['GET'],
        defaults: ['_routeScope' => ['storefront']]
    )]
    public function getCaptureContext(): JsonResponse
    {
        return $this->apiClient->getCaptureContext();
    }

    #[Route(
        path: '/cybersource/authorize-payment',
        name: 'custom.authorize_payment',
        methods: ['POST'],
        defaults: ['_routeScope' => ['storefront']]
    )]
    public function authorizePayment(Request $request, SalesChannelContext $context): JsonResponse
    {
        return $this->apiClient->authorizePayment($request, $context);
    }

    #[Route(
        path: '/cybersource/proceed-authentication',
        name: 'custom.proceed_authentication',
        methods: ['POST'],
        defaults: ['_routeScope' => ['storefront']]
    )]
    public function proceedAuthentication(Request $request, SalesChannelContext $context): JsonResponse
    {
        return $this->apiClient->proceedAuthentication($request, $context);
    }

    #[Route(
        path: '/cybersource/3ds-callback',
        name: 'custom.3ds_callback',
        methods: ['POST'],
        defaults: ['_routeScope' => ['storefront']]
    )]
    public function handle3dsCallback(Request $request, SalesChannelContext $context): Response
    {
        return $this->apiClient->handle3dsCallback($request, $context);
    }
    #[Route(
        path: '/cybersource/get-saved-cards',
        name: 'cybersource.get_saved_cards',
        methods: ['GET'],
        defaults: ['_routeScope' => ['storefront']]
    )]
    public function getSavedCards(SalesChannelContext $context): JsonResponse
    {
        return new JsonResponse($this->apiClient->getSavedCards($context));
    }

    #[Route(
        path: '/account/cybersource/saved-cards',
        name: 'frontend.cybersource.saved_cards',
        methods: ['GET']
    )]
    public function savedCards(Request $request, SalesChannelContext $context): Response
    {
        $customer = $context->getCustomer();
        if (!$customer) {
            return $this->redirectToRoute('frontend.account.login');
        }
        $page = $this->genericPageLoader->load($request, $context);
        $cards = $this->apiClient->getSavedCards($context)['cards'] ?? ['cards' => []];

        $response = $this->renderStorefront('@Storefront/storefront/page/account/saved_cards.html.twig', [
            'page' => $page,
            'cards' => $cards,
            'paymentMethodId' => $context->getPaymentMethod()->getId()
        ]);

        return $response;
    }

    #[Route(
        path: '/account/cybersource/add-card',
        name: 'frontend.cybersource.add_card',
        methods: ['POST']
    )]
    public function addCard(Request $request, SalesChannelContext $context): JsonResponse
    {
        return $this->apiClient->addCard($request, $context);
    }

    #[Route(
        path: '/account/cybersource/delete-card',
        name: 'frontend.cybersource.delete_card',
        methods: ['POST']
    )]
    public function deleteCard(Request $request, SalesChannelContext $context): Response
    {
        $this->apiClient->deleteCard($request, $context);
        return $this->redirectToRoute('frontend.cybersource.saved_cards');
    }
}
