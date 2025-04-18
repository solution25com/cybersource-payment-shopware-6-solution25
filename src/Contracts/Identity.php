<?php

declare(strict_types=1);

namespace CyberSource\Shopware6\Contracts;

interface Identity
{
    /**
     * Return name of the payment method.
     *
     * @return string
     */
    public function getName(): string;

    /**
     * Return the description of the payment method.
     *
     * @return string
     */
    public function getDescription(): string;

    /**
     * Return the payment handler of a plugin.
     *
     * @return string
     */
    public function getPaymentHandler(): string;

    /**
     * Return the gateway code used for the API.
     *
     * @return string
     */
    public function getGatewayCode(): string;

    /**
     * Give the location of a twig file to load with the payment method.
     *
     * @return string|null
     */
    public function getTemplate(): ?string;

    /**
     * Give the location of the payment logo.
     *
     * @return string
     */
    public function getLogo(): string;

    /**
     * Get the type that is currently being used.
     *
     * @return string
     */
    public function getType(): string;
}
