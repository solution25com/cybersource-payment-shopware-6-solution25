<?php

declare(strict_types=1);

namespace CyberSource\Shopware6\Service;

use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\System\SalesChannel\Aggregate\SalesChannelDomain\SalesChannelDomainEntity;
use Shopware\Core\System\SalesChannel\Aggregate\SalesChannelDomain\SalesChannelDomainCollection;
use Shopware\Core\System\SalesChannel\SalesChannelEntity;
use Shopware\Core\System\SalesChannel\SalesChannelCollection;
use Symfony\Component\HttpFoundation\RequestStack;

class UrlService
{
    /**
     * @var EntityRepository<SalesChannelCollection>
     */
    private $salesChannelRepository;

    /**
     * @var EntityRepository<SalesChannelDomainCollection>
     */
    private $domainRepository;
    private RequestStack $requestStack;

    /**
     * @param EntityRepository<SalesChannelCollection> $salesChannelRepository
     * @param EntityRepository<SalesChannelDomainCollection> $domainRepository
     */
    public function __construct(
        EntityRepository $salesChannelRepository,
        EntityRepository $domainRepository,
        RequestStack $requestStack
    ) {
        $this->salesChannelRepository = $salesChannelRepository;
        $this->domainRepository = $domainRepository;
        $this->requestStack = $requestStack;
    }

    public function getShopwareBaseUrl(?string $salesChannelId, Context $context): string
    {
        $domains = $this->loadSalesChannelDomains($salesChannelId, $context);
        $domain = $domains->first();
        if (!$domain instanceof SalesChannelDomainEntity) {
            throw new \RuntimeException('No domain found for the sales channel.');
        }

        return rtrim($domain->getUrl(), '/');
    }

    public function getTrustedOrigin(?string $salesChannelId, Context $context): string
    {
        $domains = $this->loadSalesChannelDomains($salesChannelId, $context);
        $allowedOrigins = [];

        foreach ($domains as $domain) {
            $allowedOrigins[] = $this->extractOrigin($domain->getUrl());
        }

        $request = $this->requestStack->getCurrentRequest();
        if ($request !== null) {
            $currentOrigin = rtrim($request->getSchemeAndHttpHost(), '/');
            if (in_array($currentOrigin, $allowedOrigins, true)) {
                return $currentOrigin;
            }
        }

        if ($allowedOrigins === []) {
            throw new \RuntimeException('No domain found for the sales channel.');
        }

        return $allowedOrigins[0];
    }

    private function loadSalesChannelDomains(?string $salesChannelId, Context $context): SalesChannelDomainCollection
    {
        $resolvedSalesChannelId = $this->resolveSalesChannelId($salesChannelId, $context);

        $criteria = (new Criteria())
            ->addFilter(new EqualsFilter('salesChannelId', $resolvedSalesChannelId));

        return $this->domainRepository->search($criteria, $context)->getEntities();
    }

    private function resolveSalesChannelId(?string $salesChannelId, Context $context): string
    {
        if ($salesChannelId !== 'default' && is_string($salesChannelId) && $salesChannelId !== '') {
            return $salesChannelId;
        }

        $criteria = (new Criteria())
            ->addFilter(new EqualsFilter('active', true))
            ->setLimit(1);
        $salesChannel = $this->salesChannelRepository->search($criteria, $context)->first();
        if (!$salesChannel instanceof SalesChannelEntity) {
            throw new \RuntimeException('No active sales channel found.');
        }

        return $salesChannel->getId();
    }

    private function extractOrigin(string $url): string
    {
        $scheme = parse_url($url, PHP_URL_SCHEME);
        $host = parse_url($url, PHP_URL_HOST);
        $port = parse_url($url, PHP_URL_PORT);

        if (!is_string($scheme) || !is_string($host)) {
            throw new \RuntimeException('Invalid sales channel domain URL configured.');
        }

        $origin = $scheme . '://' . $host;
        if (is_int($port)) {
            $origin .= ':' . $port;
        }

        return $origin;
    }
}
