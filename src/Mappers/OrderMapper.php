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
        if (!$currency || !$currency->getIsoCode()) {
            throw new \RuntimeException('Currency not found for order.');
        }
        $totalAmount = $order->getAmountTotal();
        $currencyCode = $currency->getIsoCode();
        $billTo = CustomerMapper::mapToBillTo($customer);
        $orderLineItems = self::getOrderLineItemsData($order);

        return new Order($totalAmount, $currencyCode, $billTo, $orderLineItems);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public static function getOrderLineItemsData(OrderEntity $order): array
    {
        $lineItems = $order->getLineItems();
        if (!$lineItems instanceof OrderLineItemCollection) {
            return [];
        }

        return self::formatLineItemData($lineItems);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public static function formatLineItemData(OrderLineItemCollection $lineItems): array
    {
        $lineItemNumber = 1;
        $orderLineItemData = [];
        foreach ($lineItems as $lineItem) {
            if (!$lineItem instanceof OrderLineItemEntity) {
                continue;
            }
            $price = $lineItem->getPrice();
            if (!$price) {
                continue;
            }
            $taxAmount = 0;
            foreach ($price->getCalculatedTaxes() as $tax) {
                $taxAmount += $tax->getTax();
            }
            $payload = $lineItem->getPayload() ?? [];
            $orderLineItemData[] = [
                'number' => $lineItemNumber++,
                'productName' => $lineItem->getLabel(),
                'productCode' => $payload['productNumber'] ?? '',
                'unitPrice' => $price->getUnitPrice(),
                'totalAmount' => $price->getTotalPrice(),
                'quantity' => $lineItem->getQuantity(),
                'taxAmount' => $taxAmount,
                'productSku' => $payload['productNumber'] ?? '',
            ];
        }

        return $orderLineItemData;
    }
}