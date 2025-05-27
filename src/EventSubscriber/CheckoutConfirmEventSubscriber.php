<?php

declare(strict_types=1);

namespace CyberSource\Shopware6\EventSubscriber;

use CyberSource\Shopware6\Storefront\Struct\CheckoutTemplateCustomData;
use Shopware\Core\Checkout\Customer\CustomerEntity;
use Shopware\Storefront\Page\PageLoadedEvent;
use CyberSource\Shopware6\Gateways\CreditCard;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Checkout\Customer\CustomerCollection;
use Shopware\Storefront\Page\Account\Order\AccountEditOrderPageLoadedEvent;
use Shopware\Storefront\Page\Checkout\Confirm\CheckoutConfirmPageLoadedEvent;
use Shopware\Core\Framework\Struct\ArrayStruct;

class CheckoutConfirmEventSubscriber implements EventSubscriberInterface
{
    /** @var EntityRepository<CustomerCollection> */
    private EntityRepository $customerRepository;

    /**
     * @param EntityRepository<CustomerCollection> $customerRepository
     */
    public function __construct(EntityRepository $customerRepository)
    {
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

        if ($selectedPaymentGateway->getHandlerIdentifier() !== CreditCard::class) {
            return;
        }

        $customer = $event->getSalesChannelContext()->getCustomer();
        if (!$customer instanceof CustomerEntity) {
            return;
        }

        $isGuestLogin = $customer->getGuest();
        $savedCards = $isGuestLogin ? [] : $this->getCustomerSavedCardTokens($event);

        $templateVariables = new ArrayStruct([
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
        $customer = $event->getSalesChannelContext()->getCustomer();
        if (!$customer instanceof CustomerEntity) {
            return [];
        }

        $customerId = $customer->getId();
        if (!$customerId) {
            return [];
        }

        $criteria = new Criteria([$customerId]);
        $criteria->addAssociation('customFields');
        $customer = $this->customerRepository->search($criteria, $event->getContext())->first();

        if (!$customer instanceof CustomerEntity) {
            return [];
        }

        $customFields = $customer->getCustomFields() ?? [];
        return $customFields['cybersource_card_details'] ?? [];
    }
}
