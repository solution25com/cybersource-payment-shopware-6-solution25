<?php

declare(strict_types=1);

namespace CyberSource\Shopware6\Service;

use Symfony\Contracts\Translation\TranslatorInterface;
use CyberSource\Shopware6\Library\RequestSignature\JWT;
use CyberSource\Shopware6\Library\RequestSignature\HTTP;
use CyberSource\Shopware6\Library\RequestSignature\Oauth;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use CyberSource\Shopware6\Library\Constants\EnvironmentUrl;

class ConfigurationService
{
    private const CONFIGURATION_KEY = 'CyberSourceShopware6';
    private SystemConfigService $systemConfig;
    private TranslatorInterface $translator;

    public function __construct(
        SystemConfigService $systemConfig,
        TranslatorInterface $translator
    ) {
        $this->systemConfig = $systemConfig;
        $this->translator = $translator;
    }

    public function get(string $key, ?string $salesChannelId = null): ?string
    {
        $value = $this->systemConfig->get(self::CONFIGURATION_KEY . '.config.' . $key, $salesChannelId);
        return is_string($value) ? $value : null;
    }

    public function isProductionActive(?string $salesChannelId = null): string
    {
        return (string) $this->get('isProductionActive', $salesChannelId);
    }

    /**
     * @param $salesChannelId
     *
     * @return null|array|mixed|string
     */
    public function getAccessKey(?string $salesChannelId = null)
    {
        $configKey = 'sandboxAccessKey';

        if ($this->isProductionActive($salesChannelId) == 1) {
            $configKey = 'liveAccessKey';
        }

        return (string) $this->get($configKey, $salesChannelId);
    }

    /**
     * @param $salesChannelId
     *
     * @return null|array|mixed|string
     */
    public function getSecretKey(?string $salesChannelId = null)
    {
        $configKey = 'sandboxSharedSecretKey';

        if ($this->isProductionActive($salesChannelId) == 1) {
            $configKey = 'liveSharedSecretKey';
        }

        return (string) $this->get($configKey, $salesChannelId);
    }

    /**
     * @param $salesChannelId
     *
     * @return null|array|mixed|string
     */
    public function getOrganizationID(?string $salesChannelId = null)
    {
        $configKey = 'sandboxOrganizationID';

        if ($this->isProductionActive($salesChannelId) == 1) {
            $configKey = 'liveOrganizationID';
        }

        return (string) $this->get($configKey, $salesChannelId);
    }

    /**
     * @param $salesChannelId
     *
     * @return null|array|mixed|string
     */
    public function getP12(?string $salesChannelId = null)
    {
        return '';
    }

    /**
     * @param $salesChannelId
     *
     * @return null|array|mixed|string
     */
    public function getAccessToken(?string $salesChannelId = null)
    {
        return '';
    }

    /**
     * @param $salesChannelId
     *
     * @return null|array|mixed|string
     */
    public function getBaseUrl(?string $salesChannelId = null)
    {
        $baseUrl = EnvironmentUrl::TEST;
        if ($this->isProductionActive($salesChannelId) == 1) {
            $baseUrl = EnvironmentUrl::PRODUCTION;
        }

        return $baseUrl;
    }

    /**
     * Retrieve HTTP request signature contract
     *
     * @return HTTP|JWT|Oauth
     */
    public function getSignatureContract() : HTTP|JWT|Oauth
    {
        // TODO: Currently, we are supporting the HTTP connection method only.
        // Later on, retrieve it from the configuration.
        $connectionMethod = 'HTTP';
        switch ($connectionMethod) {
            case 'HTTP':
                return $this->getHTTPRequestSignature();
            case 'JWT':
                return $this->getJWTRequestSignature();
            case 'Oauth':
                return $this->getOauthRequestSignature();
        }
    }

    /**
     * Retrieve HTTP request signature contract
     *
     * @return HTTP
     */
    public function getHTTPRequestSignature()
    {
        $accessKey = $this->getAccessKey();
        $secretKey = $this->getSecretKey();
        $orgId = $this->getOrganizationID();
        $environmentUrl = $this->getBaseUrl();

        return new HTTP($environmentUrl, $orgId, $accessKey, $secretKey);
    }

    /**
     * Retrieve JWT request signature contract
     *
     * @return JWT
     */
    private function getJWTRequestSignature()
    {
        throw new \RuntimeException(
            $this->translator->trans(
                'cybersource_shopware6.request-signature.JWTNotSupported'
            )
        );
// uncomment this when JWT is supported
//        $p12 = $this->getP12();
//        $orgId = $this->getOrganizationID();
//        $environmentUrl = $this->getBaseUrl();
//
//        return new JWT($environmentUrl, $orgId, $p12);
    }

    /**
     * Retrieve Oauth request signature contract
     *
     * @return Oauth
     */
    private function getOauthRequestSignature()
    {
        throw new \RuntimeException(
            $this->translator->trans(
                'cybersource_shopware6.request-signature.OauthNotSupported'
            )
        );
// uncomment this when Oauth is supported
//        $accessToken = $this->getAccessToken();
//        $orgId = $this->getOrganizationID();
//        $environmentUrl = $this->getBaseUrl();
//
//        return new Oauth($environmentUrl, $orgId, $accessToken);
    }

    public function getTransactionType(?string $salesChannelId = null): ?string
    {
        return $this->get('transactionType', $salesChannelId);
    }

    public function isThreeDSEnabled(): bool
    {
        return $this->systemConfig->getBool('CyberSourceShopware6.config.threeDS');
    }


    public function getSharedSecretKey(): ?string
    {
        return $this->systemConfig->getString('CyberSourceShopware6.config.sharedSecretKey');
    }

    public function getSharedSecretKeyId(): ?string
    {
        return $this->systemConfig->getString('CyberSourceShopware6.config.sharedSecretKeyId');
    }
}
