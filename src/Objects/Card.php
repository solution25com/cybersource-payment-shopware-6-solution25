<?php

declare(strict_types=1);

namespace CyberSource\Shopware6\Objects;

class Card
{
    private readonly string $transientToken;
    private readonly int $expirationMonth;
    private readonly int $expirationYear;

    public function __construct(
        string $transientToken,
        int $expirationMonth,
        int $expirationYear
    ) {
        $this->transientToken = $transientToken;
        $this->expirationMonth = $expirationMonth;
        $this->expirationYear = $expirationYear;
    }

    public function toArray(): array
    {
        return [
            'tokenInformation' => [
                'transientTokenJwt' => $this->transientToken
            ],
            'paymentInformation' => [
                'card' => [
                    'expirationMonth' => str_pad((string) $this->expirationMonth, 2, '0', STR_PAD_LEFT),
                    'expirationYear' => (string) $this->expirationYear
                ]
            ]
        ];
    }

    public function toInstrumentArray(): array
    {
        return [];
//            'card' => [
//                'number' => $this->number,
//            ],
//        ];
    }

    public function toPaymentInstrumentCardArray(): array
    {
        $cardType = $this->getCardTypeFromNumber($this->number);

        return [
            'card' => [
                'expirationYear' => $this->convertTwoDigitYearToFourDigit($this->expirationYear),
                'expirationMonth' => $this->expirationMonth,
                'type' => $cardType,
            ],
        ];
    }

    private function convertTwoDigitYearToFourDigit(int $twoDigitYear): int
    {
        if ($twoDigitYear > 99) {
            return (int) $twoDigitYear;
        }

        $now = new \DateTime();
        $currentYear = $now->format('Y');
        $currentCentury = (int) $currentYear - (int) $now->format('y');
        $fourDigitYear = (int) $currentCentury + (int) $twoDigitYear;

        if ($fourDigitYear < $currentYear) {
            $fourDigitYear += 100;
        }

        return $fourDigitYear;
    }

    private function getCardTypeFromNumber(string $cardNumber): string
    {
        $cardNumber = preg_replace('/\D/', '', $cardNumber);

        $patterns = [
            "001" => "/^4[0-9]{12}(?:[0-9]{3})?$/", // Visa
            "002" => "/^5[1-5][0-9]{14}|^(222[1-9]|22[3-9]\d|2[3-6]\d{2}|27[0-1]\d|2720)[0-9]{12}$/", //phpcs:ignore
            "003" => "/^3[47][0-9]{13}$/", // American Express
            "004" => "/^6(?:011|5[0-9]{2})[0-9]{12}$/", // Discover
            "005" => "/^3(?:0[0-5]|[68][0-9])[0-9]{11}$/", // Diners Club
            "006" => "/^30[0-5][0-9]{11}$/", // Carte Blanche
            "007" => "/^(?:2131|1800|35\d{3})\d{11}$/", // JCB
            "008" => "/^(?:34|37)\d{13}$/", // Optima
            "011" => "/^46[0-9]{14,22}$/", // Twinpay Credit
            "012" => "/^48[0-9]{14,22}$/", // Twinpay Debit
            "013" => "/^60[0-9]{14,17}$/", // Walmart
            "014" => "/^2(?:014|149)[0-9]{11}$/", // EnRoute
            "015" => "/^32[0-9]{14}$/", // Lowes Consumer
            "016" => "/^6011\d{12}$/", // Home Depot Consumer
            "017" => "/^(54|55)\d{14}$/", // MBNA
            "018" => "/^47[0-9]{13}$/", // Dicks Sportswear
            "019" => "/^65[0-9]{14,16}$/", // Casual Corner
            "020" => "/^66[0-9]{14,16}$/", // Sears
            "021" => "/^(35[0-9]{4}|2131|1800)[0-9]{11}$/", // JAL
            "023" => "/^(36[0-9]{4}|2131|1800)[0-9]{11}$/", // Disney
            "024" => "/^(6759|676770|676774)[0-9]{12,15}$/", // Maestro UK Domestic
            "025" => "/^(4[0-9]{15})|(5[0-9]{15})$/", // Sam's Club Consumer
            "026" => "/^41[0-9]{14}$/", // Sam's Club Business
            "028" => "/^98[0-9]{12}$/", // Bill Me Later
            "029" => "/^637([0-9]{13})$/", // Bebe
            "030" => "/^601657([0-9]{10})$/", // Restoration Hardware
            "031" => "/^957434[0-9]{10}$/", // Delta Online
            "032" => "/^6(?:022|2[689]|4[4-9]|5[0-8]|6[23]|7[0-3]|8[2-9])[0-9]{10}(?:[0-9]{2})?$/", // Solo
            "033" => "/^(4026|417500|4508|4844|491(3|7))\d+$/", // Visa Electron
            "034" => "/^5019\d+$/", // Dankort
            "035" => "/^(6304|6706|6771|6709)\d+$/", // Laser
            "036" => "/^389\d{11}$/", // Carte Bleue
            "037" => "/^3(?:0[0-5]|[68][0-9])[0-9]{11}$/", // Carta Si
            "038" => "/^54[0-9]{14}$/", // Pinless Debit
            "039" => "/^9[0-9]{15}$/", // Encoded Account
            "040" => "/^(1|3)[0-9]{14,15}$/", // UATP
            "041" => "/^(4021|5018|5020|5038|6037|6304|6759)\d+$/", // Household
            "042" => "/^(5[06-8]|6\d)\d{10,17}$/", // Maestro International
            "043" => "/^(417500|4917|4913)\d+$/", // GE Money UK
            "044" => "/^9[0-9]{15}$/", // Korean Cards
            "045" => "/^9[0-9]{15}$/", // Style
            "046" => "/^(3088|3096|3112|3158|3337|3526|3544|3578|3589|3590|3596|3597)\d+$/", // J.Crew
            "047" => "/^627056|627067|627568\d{10}$/", // PayEase China Processing Ewallet
            "048" => "/^955880|965380|968407\d{10}$/", // PayEase China Processing Bank Transfer
            "049" => "/^627067|627568\d{10}$/", // Meijer Private Label
            "050" => "/^(38[0-9]{16})|(60[0-9]{14,17})$/", // Hipercard
            "051" => "/^(50[0-9]{17})|(60[0-9]{14,17})$/", // Aura
            "052" => "/^(([0-9]{6})\d{10}(?:\d{2})?)$/", // Redecard
            "054" => "/^((509091)|(636297)|(636368)|(636369)|(438935)|(504175)|(451416)|(636297)|(504175)|(451416)|(636297)|(636369)|(5067)|(4576)|(40117)|(506699))\d{10}$/", //phpcs:ignore
            "055" => "/^62[0-9]{14}$/", // Capital One Private Label
            "056" => "/^65[0-9]{14}$/", // Synchrony Private Label
            "057" => "/^65[0-9]{14}$/", // Costco Private Label
            "060" => "/^((5038|6304|6759|6761)\d{12}(\d{2,3})?)$/", //mada
            "062" => "/^(62\d{14,17})$/", // china union pay
            "063" => "/^\d{16}$/", //falabella private label,
        ];

        foreach ($patterns as $type => $pattern) {
            if (preg_match($pattern, $cardNumber)) {
                return $type;
            }
        }

        // No need to throw an exception here as we have already caught exceptions
        // on the Cybersource save card REST API endpoint in the main file.
        return '';
    }
}
