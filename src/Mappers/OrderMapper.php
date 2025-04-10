<?php

declare(strict_types=1);

namespace CyberSource\Shopware6\Mappers;

use CyberSource\Shopware6\Objects\Order;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Checkout\Customer\CustomerEntity;

class OrderMapper
{
    public static function mapToOrder(OrderEntity $order, CustomerEntity $customer): Order
    {
        $totalAmount = $order->getAmountTotal();
        $currency = $order->getCurrency()->getIsoCode();
        $billTo = CustomerMapper::mapToBillTo($customer);
        $orderLineItems = self::getOrderLineItemsData($order);

        return new Order($totalAmount, $currency, $billTo, $orderLineItems);
    }

    public static function getOrderLineItemsData(OrderEntity $order): array
    {
        $lineItems = $order->getLineItems()->getElements();
        $orderLineItemData = self::formatLineItemData($lineItems);
        return $orderLineItemData;
    }

    public static function formatLineItemData($lineItems): array
    {
        $lineItemNumber = 1;
        $orderLineItemData = [];
        foreach ($lineItems as $lineItem) {
            $taxAmount = 0;
            foreach ($lineItem->getPrice()->getCalculatedTaxes() as $tax) {
                $taxAmount = $tax->getTax();
            }

            $productCode = '';
            if (isset($lineItem->payload['productNumber'])) {
                $productCode = $lineItem->payload['productNumber'];
            }

            $orderLineItemData[] = array(
                'number' => $lineItemNumber++,
                'productName' => $lineItem->getLabel(),
                'productCode' => $productCode,
                'unitPrice' => $lineItem->getPrice()->getUnitPrice(),
                'totalAmount' => $lineItem->getPrice()->getTotalPrice(),
                'quantity' => $lineItem->getQuantity(),
                'taxAmount' => $taxAmount,
                'productSku' => $productCode,
            );
        }

        return $orderLineItemData;
    }
}
