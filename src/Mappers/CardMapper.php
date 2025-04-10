<?php

declare(strict_types=1);

namespace CyberSource\Shopware6\Mappers;

use CyberSource\Shopware6\Objects\Card;

class CardMapper
{
    public static function mapToCard(array $cardInformation): Card
    {
        $cardExpiryData = explode("/", $cardInformation['expirationDate']);
        $number = (string) $cardInformation['cardNumber'];
        $securityCode = (int) $cardInformation['securityCode'];

        $expirationMonth = (int) $cardExpiryData[0];
        $expirationYear = (int) $cardExpiryData[1];

        return new Card($number, $expirationMonth, $expirationYear, $securityCode);
    }
}
