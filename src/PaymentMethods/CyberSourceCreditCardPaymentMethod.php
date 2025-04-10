<?php

declare(strict_types=1);

namespace CyberSource\Shopware6\PaymentMethods;

use CyberSource\Shopware6\Contracts\Identity;
use CyberSource\Shopware6\Gateways\CreditCard;

class CyberSourceCreditCardPaymentMethod implements Identity
{
    /**
     * {@inheritDoc}
     *
     * @return string
     */
    public function getName(): string
    {
        return 'CyberSourceCreditCard';
    }

    /**
     * {@inheritDoc}
     *
     * @return string
     */
    public function getDescription(): string
    {
        return 'CyberSource Payment';
    }

    /**
     * {@inheritDoc}
     *
     * @return string
     */
    public function getPaymentHandler(): string
    {
        return CreditCard::class;
    }

    /**
     * {@inheritDoc}
     *
     * @return string
     */
    public function getGatewayCode(): string
    {
        return 'GATEWAY_CYBERSOURCE_CREDITCARD';
    }

    /**
     * {@inheritDoc}
     *
     * @return string|null
     */
    public function getTemplate(): ?string
    {
        return null;
    }

    /**
     * {@inheritDoc}
     *
     * @return string
     */
    public function getLogo(): string
    {
        return '';
    }

    /**
     * {@inheritDoc}
     *
     * @return string
     */
    public function getType(): string
    {
        return '';
    }
}
