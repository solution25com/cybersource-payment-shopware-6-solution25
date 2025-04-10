<?php

declare(strict_types=1);

namespace CyberSource\Shopware6\Gateways;

use Shopware\Core\Framework\Context;
use Symfony\Component\String\UnicodeString;
use Symfony\Component\HttpFoundation\Request;
use CyberSource\Shopware6\Library\CyberSource;
use CyberSource\Shopware6\Exceptions\APIException;
use Symfony\Component\HttpFoundation\RequestStack;
use CyberSource\Shopware6\Validation\CardValidator;
use CyberSource\Shopware6\Library\CyberSourceFactory;
use CyberSource\Shopware6\Exceptions\ExceptionFactory;
use Symfony\Contracts\Translation\TranslatorInterface;
use CyberSource\Shopware6\Service\ConfigurationService;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use CyberSource\Shopware6\Exceptions\CyberSourceException;
use CyberSource\Shopware6\Library\RequestObject\PaymentAuth;
use Shopware\Core\Framework\Validation\DataBag\RequestDataBag;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use CyberSource\Shopware6\Library\RequestObject\PaymentAuthFactory;
use Shopware\Core\Checkout\Payment\Cart\SyncPaymentTransactionStruct;
use Symfony\Component\HttpFoundation\Exception\SessionNotFoundException;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionStateHandler;
use Shopware\Core\Checkout\Payment\Cart\PaymentHandler\SynchronousPaymentHandlerInterface;

final class CreditCard implements SynchronousPaymentHandlerInterface
{
    private readonly ConfigurationService $configurationService;

    private readonly CardValidator $cardValidator;

    private readonly PaymentAuthFactory $paymentAuthFactory;

    private readonly CyberSourceFactory $cyberSourceFactory;

    private OrderTransactionStateHandler $orderTransactionStateHandler;

    private EntityRepository $orderTransactionRepo;

    private EntityRepository $customerRepository;

    private RequestStack $requestStack;

    private TranslatorInterface $translator;

    /**
     * __construct
     *
     * @param  ConfigurationService $configurationService
     * @param  CardValidator $cardValidator
     * @param  PaymentAuthFactory $paymentAuthFactory
     * @param  CyberSourceFactory $cyberSourceFactory
     * @param  OrderTransactionStateHandler $orderTransactionStateHandler
     * @param  EntityRepository $orderTransactionRepo
     * @return void
     */
    public function __construct(
        ConfigurationService $configurationService,
        CardValidator $cardValidator,
        PaymentAuthFactory $paymentAuthFactory,
        CyberSourceFactory $cyberSourceFactory,
        OrderTransactionStateHandler $orderTransactionStateHandler,
        EntityRepository $orderTransactionRepo,
        EntityRepository $customerRepository,
        RequestStack $requestStack,
        TranslatorInterface $translator
    ) {
        $this->configurationService = $configurationService;
        $this->cardValidator = $cardValidator;
        $this->paymentAuthFactory = $paymentAuthFactory;
        $this->cyberSourceFactory = $cyberSourceFactory;
        $this->orderTransactionStateHandler = $orderTransactionStateHandler;
        $this->orderTransactionRepo = $orderTransactionRepo;
        $this->customerRepository = $customerRepository;
        $this->requestStack = $requestStack;
        $this->translator = $translator;
    }

    /**
     * pay
     *
     * @param  SyncPaymentTransactionStruct $transaction
     * @param  RequestDataBag $dataBag
     * @param  SalesChannelContext $salesChannelContext
     * @return void
     */
    public function pay(
        SyncPaymentTransactionStruct $transaction,
        RequestDataBag $dataBag,
        SalesChannelContext $salesChannelContext
    ): void {
        try {
            $orderTransactionId = $transaction->getOrderTransaction()->getId();
            $customerId = $salesChannelContext->getCustomer()->getId();
            $email = $salesChannelContext->getCustomer()->getEmail();
            $isGuestLogin = $salesChannelContext->getCustomer()->getGuest();

            $salesContext = $salesChannelContext->getContext();

            // For guest users, saving cards is not allowed
            $isSaveCardChecked = !$isGuestLogin ? (bool) $dataBag->get("cybersource_shopware6_save_card") : false;

            $selectedSavedCard = $dataBag->get("cybersource_shopware6_saved_card");
            $requestCardNo = $dataBag->get("cybersource_shopware6_card_no");
            $strCreditCardNo = (new UnicodeString($requestCardNo))->replace(' ', '')->toString();
            $lastFourDigits = (string) substr($strCreditCardNo, -4);
            $cardInformation = [
                'expirationDate' => $dataBag->get("cybersource_shopware6_expiry_date"),
                'cardNumber' => $strCreditCardNo,
                'securityCode' => $dataBag->get('cybersource_shopware6_security_code'),
            ];

            $ifSavedCardBeingUsedIsValid = false;

            $savedCardBeingUsed = strlen($requestCardNo) === 0;
            if ($savedCardBeingUsed) {
                $customerCustomFields = $this->getCustomerCustomFields($customerId, $salesContext);
                foreach ($customerCustomFields['cybersource_card_details'] ?? [] as $cardDetail) {
                    if (array_key_exists($selectedSavedCard, $cardDetail)) {
                        $cardInformation['paymentInstrument'] = $selectedSavedCard;
                        $ifSavedCardBeingUsedIsValid = true;
                        break;
                    }
                }
            } else {
                $this->cardValidator->validate($orderTransactionId, $cardInformation);
                $cardInformation['paymentInstrument'] = '';
            }

            $paymentAuth = $this->paymentAuthFactory->createPaymentAuth(
                $transaction->getOrder(),
                $salesChannelContext->getCustomer(),
                $cardInformation,
                $ifSavedCardBeingUsedIsValid
            );

            $environmentUrl = $this->configurationService->getBaseUrl();
            $requestSignature = $this->configurationService->getSignatureContract();
            $transactionType = $this->configurationService->getTransactionType();
            $cyberSource = $this->cyberSourceFactory->createCyberSource(
                $environmentUrl,
                $requestSignature
            );

            $response = [];
            switch ($transactionType) {
                case 'auth':
                    $response = $cyberSource->authorizePaymentWithCreditCard($paymentAuth);
                    break;
                case 'auth_capture':
                    $response = $cyberSource->authAndCapturePaymentWithCreditCard($paymentAuth);
                    break;
            }

            if (
                isset($response['status']) &&
                ($response['status'] === 'PENDING' ||
                $response['status'] === 'TRANSMITTED')
            ) {
                $this->orderTransactionStateHandler->paid($orderTransactionId, $salesContext);
                $this->updateOrderTransactionCustomFields($response, $orderTransactionId, $salesContext);

                if ($isSaveCardChecked) {
                    $this->savePaymentInstrumentIfNotExists(
                        $salesContext,
                        $customerId,
                        $cyberSource,
                        $paymentAuth,
                        $lastFourDigits,
                        $email
                    );
                }
                return;
            }

            if (isset($response['status']) && $response['status'] === 'AUTHORIZED') {
                $this->orderTransactionStateHandler->authorize($orderTransactionId, $salesChannelContext->getContext());
                $this->updateOrderTransactionCustomFields($response, $orderTransactionId, $salesContext);

                if ($isSaveCardChecked) {
                    $this->savePaymentInstrumentIfNotExists(
                        $salesContext,
                        $customerId,
                        $cyberSource,
                        $paymentAuth,
                        $lastFourDigits,
                        $email
                    );
                }
                return;
            }

            $exceptionFactory = new ExceptionFactory($orderTransactionId, $response);
            $exceptionFactory->raiseMatchingException();
        } catch (CyberSourceException $exception) {
            throw $exception;
        } catch (\Exception $exception) {
            throw new APIException($orderTransactionId, 'API_ERROR', $exception->getMessage());
        }
    }

    private function updateOrderTransactionCustomFields(
        array $response,
        string $orderTransactionId,
        $salesContext
    ) {
        $transactionId = ($response['id']) ?? '';
        $this->orderTransactionRepo->update([
            [
                'id'           => $orderTransactionId,
                'customFields' => [
                    'cybersource_payment_details' => [
                        'transaction_id' => $transactionId,
                    ],
                ],
            ],
        ], $salesContext);
    }

    private function savePaymentInstrumentIfNotExists(
        Context $salesContext,
        string $customerId,
        CyberSource $cyberSource,
        PaymentAuth $paymentAuth,
        string $lastFourDigits,
        string $email
    ): void {
        try {
            $instrumentResponse = $cyberSource->generateInstrumentIdentifier($paymentAuth);

            $instrumentIdentifierId = $instrumentResponse["id"];
            $customerCustomFields = $this->getCustomerCustomFields($customerId, $salesContext);

            if ($this->isInstrumentIdentifierExisting($instrumentIdentifierId, $customerCustomFields)) {
                return;
            }

            if (isset($customerCustomFields['cybersource_customer_id'])) {
                $cybersourceCustomerId = $customerCustomFields['cybersource_customer_id'];
            } else {
                $cybersourceCustomerId = $this->createCybersourceCustomer(
                    $cyberSource,
                    $paymentAuth,
                    $customerId,
                    $email
                );
            }

            $paymentInstrumentId = $this->createPaymentInstrument(
                $cyberSource,
                $paymentAuth,
                $cybersourceCustomerId,
                $instrumentIdentifierId
            );

            $updatedCustomerCustomFields = $this->appendCardToCustomFields(
                $customerCustomFields,
                $instrumentIdentifierId,
                $paymentInstrumentId,
                $lastFourDigits
            );

            $updatedCustomerCustomFields['cybersource_customer_id'] = $cybersourceCustomerId;

            $this->updateCustomerCustomFields($customerId, $updatedCustomerCustomFields, $salesContext);
            $this->addFlashMessage('success', 'SAVE_CARD_SUCCESS');
        } catch (\Exception $exception) {
            $this->addFlashMessage('warning', 'SAVE_CARD_ERROR');
        }
    }

    private function isInstrumentIdentifierExisting(string $instrumentIdentifierId, array $customerCustomFields): bool
    {
        foreach ($customerCustomFields['cybersource_card_details'] ?? [] as $cardDetail) {
            foreach ($cardDetail as $paymentInstruments) {
                if (
                    isset($paymentInstruments['instrumentIdentifierId'])
                    && $paymentInstruments['instrumentIdentifierId'] === $instrumentIdentifierId
                ) {
                    return true;
                }
            }
        }
        return false;
    }

    private function createCybersourceCustomer(
        CyberSource $cyberSource,
        PaymentAuth $paymentAuth,
        string $customerId,
        string $email
    ): ?string {
        $cybersourceCustomerResponse = $cyberSource->createCybersourceCustomer($paymentAuth, $customerId, $email);
        return $cybersourceCustomerResponse["id"];
    }

    private function createPaymentInstrument(
        CyberSource $cyberSource,
        PaymentAuth $paymentAuth,
        string $cybersourceCustomerId,
        string $instrumentIdentifierId
    ): ?string {
        $paymentinstrumentResponse = $cyberSource->createPaymentInstrument(
            $paymentAuth,
            $cybersourceCustomerId,
            $instrumentIdentifierId
        );
        return $paymentinstrumentResponse["id"];
    }

    private function appendCardToCustomFields(
        array $customerCustomFields,
        string $instrumentIdentifierId,
        string $paymentInstrumentId,
        string $lastFourDigits
    ): array {
        $updatedCardDetails = $customerCustomFields['cybersource_card_details'] ?? [];
        $updatedCardDetails[][$paymentInstrumentId] = [
            'last4Digits' => $lastFourDigits,
            'instrumentIdentifierId' => $instrumentIdentifierId,
        ];

        $customerCustomFields['cybersource_card_details'] = $updatedCardDetails;
        return $customerCustomFields;
    }

    private function updateCustomerCustomFields(
        string $customerId,
        array $customFieldsData,
        Context $salesContext
    ): void {
        $this->customerRepository->update([
            [
                'id' => $customerId,
                'customFields' => $customFieldsData,
            ],
        ], $salesContext);
    }

    private function getCustomerCustomFields(string $customerId, Context $salesContext): array
    {
        $criteria = new Criteria([$customerId]);
        $criteria->addAssociation('customFields');

        $customer = $this->customerRepository->search($criteria, $salesContext)->first();
        $customFields = $customer->getCustomFields() ?? [];
        return $customFields;
    }

    private function addFlashMessage(string $type, string $flashMessageCode): void
    {
        try {
            $session = $this->requestStack->getSession();
            if (!\method_exists($session, 'getFlashBag')) {
                throw new SessionNotFoundException();
            }

            $session->getFlashBag()->add(
                $type,
                $this->translator->trans(\sprintf('cybersource_shopware6.flashMessageType.%s', $flashMessageCode))
            );
        } catch (SessionNotFoundException $e) {
        }
    }
}
