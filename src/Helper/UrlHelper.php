<?php

declare(strict_types=1);

namespace CyberSource\Shopware6\Helper;

use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\Context;

class UrlHelper
{
    private EntityRepository $salesChannelRepository;
    private EntityRepository $domainRepository;

    public function __construct(
        EntityRepository $salesChannelRepository,
        EntityRepository $domainRepository
    )
    {
        $this->salesChannelRepository = $salesChannelRepository;
        $this->domainRepository = $domainRepository;
    }

    public function getShopwareBaseUrl(?string $salesChannelId = null): string
    {
        $context = Context::createDefaultContext();

        if (!$salesChannelId) {
            $criteria = (new Criteria())
                ->addFilter(new EqualsFilter('active', true))
                ->setLimit(1);
            $salesChannel = $this->salesChannelRepository->search($criteria, $context)->first();
            if (!$salesChannel) {
                throw new \RuntimeException('No active sales channel found.');
            }
            $salesChannelId = $salesChannel->getId();
        }


        $criteria = (new Criteria())
            ->addFilter(new EqualsFilter('salesChannelId', $salesChannelId))
            ->setLimit(1);
        $domain = $this->domainRepository->search($criteria, $context)->first();

        if (!$domain) {
            throw new \RuntimeException('No domain found for the sales channel.');
        }

        $url = $domain->getUrl();
        return rtrim($url, '/');
    }
}