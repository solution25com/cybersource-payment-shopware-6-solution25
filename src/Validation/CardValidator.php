<?php

declare(strict_types=1);

namespace CyberSource\Shopware6\Validation;

use Symfony\Component\Validator\Validation;
use Symfony\Contracts\Translation\TranslatorInterface;
use Symfony\Component\Validator\Constraints as Assert;
use CyberSource\Shopware6\Exceptions\ExceptionFactory;

class CardValidator
{
    private TranslatorInterface $translator;

    public function __construct(TranslatorInterface $translator)
    {
        $this->translator = $translator;
    }

    public function validate(string $orderTransactionId, array $cardInformation): void
    {
        $errorCodes = [
            '[cardNumber]' => 'INVALID_CARD_NUMBER',
            '[expirationDate]' => 'INVALID_EXPIRY_DATE',
            '[expirationDate].expirationDate' => 'INVALID_EXPIRY_DATE',
            '[securityCode]' => 'INVALID_SECURITY_CODE',
        ];

        $cardNumberRequiredMessage = $this->translator->trans(
            'cybersource_shopware6.credit_card.cardNumberRequiredMessage'
        );
        $cardNumberErrorMessage = $this->translator->trans(
            'cybersource_shopware6.credit_card.cardNumberErrorMessage'
        );
        $expiryDateRequiredMessage = $this->translator->trans(
            'cybersource_shopware6.credit_card.expiryDateRequiredMessage'
        );
        $expiryDateErrorMessage = $this->translator->trans(
            'cybersource_shopware6.credit_card.expiryDateErrorMessage'
        );
        $securityRequiredMessage = $this->translator->trans(
            'cybersource_shopware6.credit_card.securityRequiredMessage'
        );
        $securityErrorMessage = $this->translator->trans(
            'cybersource_shopware6.credit_card.securityErrorMessage'
        );

        $validator = Validation::createValidator();

        $constraints = new Assert\Collection([
            'cardNumber' => [
                new Assert\NotBlank(['message' => $cardNumberRequiredMessage]),
                new Assert\Length([
                    'min' => 14,
                    'max' => 19,
                    'minMessage' => $cardNumberErrorMessage,
                    'maxMessage' => $cardNumberErrorMessage
                ]),
                new Assert\Luhn(['message' => $cardNumberErrorMessage]),
            ],
            'expirationDate' => [
                new Assert\NotBlank(['message' => $expiryDateRequiredMessage]),
                new Assert\Length(['min' => 5, 'max' => 5, 'exactMessage' => $expiryDateErrorMessage]),
                new Assert\Regex([
                    'pattern' => '/^\d{2}\/\d{2}$/',
                    'message' => $expiryDateErrorMessage,
                ]),
                new Assert\Callback([
                    'callback' => [$this, 'validateExpirationDate'],
                ]),
            ],
            'securityCode' => [
                new Assert\NotBlank(['message' => $securityRequiredMessage]),
                new Assert\Length([
                    'min' => 3,
                    'max' => 4,
                    'minMessage' => $securityErrorMessage,
                    'maxMessage' => $securityErrorMessage
                ]),
                new Assert\Type(['type' => 'digit', 'message' => $securityErrorMessage]),
            ],
        ]);

        $violations = $validator->validate($cardInformation, $constraints);

        $errors = [];

        foreach ($violations as $violation) {
            $field = $violation->getPropertyPath();
            $errorCode = $errorCodes[$field];
            $message = $violation->getMessage();

            $errors[] = [
                'status' => 'BAD_REQUEST',
                'errorInformation' => [
                    'reason' => $errorCode,
                    'message' => $message,
                ],
            ];
        }

        if (!empty($errors)) {
            $exceptionFactory = new ExceptionFactory($orderTransactionId, $errors[0]);
            $exceptionFactory->raiseMatchingException();
        }
    }

    public function validateExpirationDate($value, $context)
    {
        $expiryDateErrorMessage = $this->translator->trans(
            'cybersource_shopware6.credit_card.expiryDateErrorMessage'
        );
        list($month, $year) = explode('/', $value);

        $now = new \DateTime();
        $currentMonth = (int) $now->format('m');
        $currentYear = (int) $now->format('y');

        if ($year < $currentYear || ($year == $currentYear && $month < $currentMonth)) {
            $context->buildViolation($expiryDateErrorMessage)
                ->atPath('expirationDate')
                ->addViolation();
        }
    }
}
