<?php

declare(strict_types=1);

namespace CyberSource\Shopware6\Mappers;

use Shopware\Core\Checkout\Order\OrderEntity;
use CyberSource\Shopware6\Objects\ClientReference;

class OrderClientReferenceMapper
{
    public static function mapToClientReference(
        OrderEntity $order
    ): ClientReference {
        $orderId = $order->getOrderNumber();

        $code = $orderId !== null ? $orderId : '0';
        return new ClientReference($code);
    }
}
