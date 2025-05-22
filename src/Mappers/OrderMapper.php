<?php

declare(strict_types=1);

namespace CyberSource\Shopware6\Mappers;

use CyberSource\Shopware6\Objects\Order;
use Shopware\Core\Checkout\Order\Aggregate\OrderLineItem\OrderLineItemCollection;
use Shopware\Core\Checkout\Order\Aggregate\OrderLineItem\OrderLineItemEntity;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Checkout\Customer\CustomerEntity;

class OrderMapper
{
    public static function mapToOrder(OrderEntity $order, CustomerEntity $customer): Order
    {
        $currency = $order->getCurrency();
        if (!$currency) {
            throw new \RuntimeException('Currency not found for order.');
        }
        $totalAmount = $order->getAmountTotal();
        $currency = $currency->getIsoCode();
        $billTo = CustomerMapper::mapToBillTo($customer);
        $orderLineItems = self::getOrderLineItemsData($order);

        return new Order($totalAmount, $currency, $billTo, $orderLineItems);
    }

    public static function getOrderLineItemsData(OrderEntity $order): array
    {
        $lineItems = $order->getLineItems();
        if ($lineItems === null) {
            return [];
        }
        $orderLineItemData = self::formatLineItemData($lineItems);
        return $orderLineItemData;
    }

    public static function formatLineItemData(OrderLineItemCollection $lineItems): array
    {
        $lineItemNumber = 1;
        $orderLineItemData = [];
        foreach ($lineItems as $lineItem) {
            $price = $lineItem->getPrice();
            if ($price === null) {
                continue;
            }
            $taxAmount = 0;
            foreach ($price->getCalculatedTaxes() as $tax) {
                $taxAmount += $tax->getTax();
            }
            $orderLineItemData[] = [
                'number' => $lineItemNumber++,
                'productName' => $lineItem->getLabel(),
                'productCode' => $lineItem->getPayload()['productNumber'] ?? '',
                'unitPrice' => $price->getUnitPrice(),
                'totalAmount' => $price->getTotalPrice(),
                'quantity' => $lineItem->getQuantity(),
                'taxAmount' => $taxAmount,
                'productSku' => $lineItem->getPayload()['productNumber'] ?? '',
            ];
        }
        return $orderLineItemData;
    }
}
