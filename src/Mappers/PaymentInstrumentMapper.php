<?php

declare(strict_types=1);

namespace CyberSource\Shopware6\Mappers;

use CyberSource\Shopware6\Objects\PaymentInstrument;

class PaymentInstrumentMapper
{
    public static function mapToPaymentInstrument(
        array $cardInformation
    ): PaymentInstrument {
        return new PaymentInstrument($cardInformation['paymentInstrument']);
    }
}
