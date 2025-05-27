<?php

declare(strict_types=1);

namespace CyberSource\Shopware6\Service;

use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\System\SalesChannel\Aggregate\SalesChannelDomain\SalesChannelDomainEntity;
use Shopware\Core\System\SalesChannel\SalesChannelEntity;
use Shopware\Core\System\SalesChannel\Aggregate\SalesChannelDomain\SalesChannelDomainCollection;
use Shopware\Core\System\SalesChannel\SalesChannelCollection;

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

    /**
     * @param EntityRepository<SalesChannelCollection> $salesChannelRepository
     * @param EntityRepository<SalesChannelDomainCollection> $domainRepository
     */
    public function __construct(
        EntityRepository $salesChannelRepository,
        EntityRepository $domainRepository
    ) {
        $this->salesChannelRepository = $salesChannelRepository;
        $this->domainRepository = $domainRepository;
    }

    public function getShopwareBaseUrl(?string $salesChannelId, Context $context): string
    {
        if ($salesChannelId === "default") {
            $criteria = (new Criteria())
                ->addFilter(new EqualsFilter('active', true))
                ->setLimit(1);
            $salesChannel = $this->salesChannelRepository->search($criteria, $context)->first();
            if (!$salesChannel instanceof SalesChannelEntity) {
                throw new \RuntimeException('No active sales channel found.');
            }
            $salesChannelId = $salesChannel->getId();
        }

        $criteria = (new Criteria())
            ->addFilter(new EqualsFilter('salesChannelId', $salesChannelId))
            ->setLimit(1);
        $domain = $this->domainRepository->search($criteria, $context)->first();

        if (!$domain instanceof SalesChannelDomainEntity) {
            throw new \RuntimeException('No domain found for the sales channel.');
        }

        $url = $domain->getUrl();
        return rtrim($url, '/');
    }
}