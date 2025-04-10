<?php

declare(strict_types=1);

namespace CyberSource\Shopware6\Mappers;

use CyberSource\Shopware6\Objects\Card;

class CardMapper
{
    public static function mapToCard(array $cardInformation): Card
    {
        return new Card(
            $cardInformation['transientToken'],
            (int) $cardInformation['cardExpirationMonth'],
            (int) $cardInformation['cardExpirationYear']
        );
    }
}
