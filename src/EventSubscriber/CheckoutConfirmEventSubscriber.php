<?php

declare(strict_types=1);

namespace CyberSource\Shopware6\EventSubscriber;

use Shopware\Storefront\Page\PageLoadedEvent;
use CyberSource\Shopware6\Gateways\CreditCard;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use CyberSource\Shopware6\Storefront\Struct\CheckoutTemplateCustomData;
use Shopware\Storefront\Page\Account\Order\AccountEditOrderPageLoadedEvent;
use Shopware\Core\Framework\DataAbstractionLayer\Search\EntitySearchResult;
use Shopware\Storefront\Page\Checkout\Confirm\CheckoutConfirmPageLoadedEvent;

class CheckoutConfirmEventSubscriber implements EventSubscriberInterface
{
    private EntityRepository $customerRepository;

    public function __construct(
        EntityRepository $customerRepository
    ) {
        $this->customerRepository = $customerRepository;
    }

    public static function getSubscribedEvents(): array
    {
        return [
            CheckoutConfirmPageLoadedEvent::class => 'addPaymentMethodSpecificFormFields',
            AccountEditOrderPageLoadedEvent::class => 'addPaymentMethodSpecificFormFields'
        ];
    }

    public function addPaymentMethodSpecificFormFields(PageLoadedEvent $event): void
    {
        $pageObject = $event->getPage();
        $salesChannelContext = $event->getSalesChannelContext();
        $selectedPaymentGateway = $salesChannelContext->getPaymentMethod();

        if ($selectedPaymentGateway->getHandlerIdentifier() != CreditCard::class) {
            return;
        }

        $isGuestLogin = $salesChannelContext->getCustomer()->getGuest();

        $savedCards = [];
        if (!$isGuestLogin) {
            $savedCards = $this->getCustomerSavedCardTokens($event);
        }
        $templateVariables = new CheckoutTemplateCustomData();
        $templateVariables->assign([
            'template' => '@Storefront/cybersource_shopware6/credit-card-iframe.html.twig',
            'savedCards' => $savedCards,
            'isGuestLogin' => $isGuestLogin,
        ]);

        $pageObject->addExtension(
            CheckoutTemplateCustomData::EXTENSION_NAME,
            $templateVariables
        );
    }

    private function getCustomerSavedCardTokens(PageLoadedEvent $event): array
    {
        $customerId = $event->getSalesChannelContext()->getCustomer()->getId();

        $criteria = new Criteria([$customerId]);
        $criteria->addAssociation('customFields');

        $customer = $this->customerRepository->search($criteria, $event->getContext())->first();

        $customFields = $customer->getCustomFields()['cybersource_card_details'] ?? [];

        return $customFields;
    }
}
