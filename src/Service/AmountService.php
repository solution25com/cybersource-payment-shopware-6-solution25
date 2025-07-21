<?php
declare(strict_types=1);

namespace CyberSource\Shopware6\Service;

use Shopware\Core\Checkout\Cart\Cart;
use Shopware\Core\Checkout\Order\OrderEntity;

class AmountService
{
    /**
     * Calculates the total price from an Order or a Cart.
     * This method can be decorated by merchants to apply custom logic (e.g. excluding ROPIS).
     *
     * @param Cart|OrderEntity $source
     * @return float
     */
    public function getAmount(OrderEntity|Cart $source): float
    {
        return round($source->getPrice()->getTotalPrice(), 2);
    }
}