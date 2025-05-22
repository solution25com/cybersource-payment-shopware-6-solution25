<?php

declare(strict_types=1);

namespace CyberSource\Shopware6\Service;

use Shopware\Core\Defaults;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\System\CustomField\Aggregate\CustomFieldSet\CustomFieldSetCollection;
use Shopware\Core\System\CustomField\CustomFieldTypes;
use Shopware\Core\System\CustomField\Aggregate\CustomFieldSetRelation\CustomFieldSetRelationCollection;

class CustomFieldService
{
    private const CUSTOM_FIELDSET_NAME = 'customer_cybersource';

    private const CUSTOM_FIELDSET = [
        'name' => self::CUSTOM_FIELDSET_NAME,
        'config' => [
            'label' => [
                'en-GB' => 'CyberSource Customer Fields',
                'de-DE' => 'CyberSource Kundenfelder',
                Defaults::LANGUAGE_SYSTEM => 'CyberSource Customer Fields',
            ],
        ],
        'customFields' => [
            [
                'name' => 'cybersource_payment_token',
                'type' => CustomFieldTypes::TEXT,
                'config' => [
                    'label' => [
                        'en-GB' => 'CyberSource Payment Token',
                        'de-DE' => 'CyberSource Zahlungs-Token',
                        Defaults::LANGUAGE_SYSTEM => 'CyberSource Payment Token',
                    ],
                    'componentName' => 'sw-field',
                    'type' => 'text',
                    'customFieldPosition' => 1,
                ],
            ],
        ],
    ];
    /**
     * @param EntityRepository<CustomFieldSetCollection> $customFieldSetRepository
     * @param EntityRepository<CustomFieldSetRelationCollection> $customFieldSetRelationRepository
     */
    public function __construct(
        private readonly EntityRepository $customFieldSetRepository,
        private readonly EntityRepository $customFieldSetRelationRepository
    ) {
    }

    public function createCustomFields(Context $context): void
    {
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('name', self::CUSTOM_FIELDSET_NAME));
        $customFieldSetIds = $this->customFieldSetRepository->searchIds($criteria, $context)->getIds();

        if (!empty($customFieldSetIds)) {
            $this->customFieldSetRepository->delete(
                array_map(fn ($id) => ['id' => $id], $customFieldSetIds),
                $context
            );
        }
        $this->customFieldSetRepository->upsert([
            self::CUSTOM_FIELDSET,
        ], $context);

        $this->addRelations($context);
    }

    private function addRelations(Context $context): void
    {
        try {
            $this->customFieldSetRelationRepository->upsert(array_map(fn (string $customFieldSetId) => [
                'customFieldSetId' => $customFieldSetId,
                'entityName' => 'customer',
            ], $this->getCustomFieldSetIds($context)), $context);
        } catch (\Exception $e) {
        }
    }

    public function remove(Context $context): void
    {
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('name', self::CUSTOM_FIELDSET_NAME));
        $customFieldSetIds = $this->customFieldSetRepository->searchIds($criteria, $context)->getIds();

        if (!empty($customFieldSetIds)) {
            $this->customFieldSetRelationRepository->delete(
                array_map(fn ($id) => ['customFieldSetId' => $id], $customFieldSetIds),
                $context
            );

            $this->customFieldSetRepository->delete(
                array_map(fn ($id) => ['id' => $id], $customFieldSetIds),
                $context
            );
        }
    }

    /**
     * @return array<string>
     */
    private function getCustomFieldSetIds(Context $context): array
    {
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('name', self::CUSTOM_FIELDSET_NAME));
        $ids = $this->customFieldSetRepository->searchIds($criteria, $context)->getIds();
        return array_values(array_filter($ids, 'is_string'));
    }
}
