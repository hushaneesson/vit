<?php

namespace App\Services\Catalog;

use App\Models\ClassificationType;
use App\Models\CountryCode;
use App\Models\ProductHierarchy;

/**
 * Classification processing pipeline for catalog item rows.
 *
 * Contributors must be processed in this order:
 * 1. Mapper classifications
 * 2. Country of Origin
 * 3. UNSPSC
 * 4. MSDS
 * 5. hierarchy handling
 * 6. final classification normalization
 *
 * Normalization itself is delegated to CatalogValueNormalizer so no
 * normalization logic is duplicated here.
 *
 * Note: hierarchy is handled separately from classifications. The exported
 * hierarchy_number is resolved back to the real ProductHierarchy id, not
 * stored as a classification entry.
 */
class CatalogClassificationProcessor
{
    public function __construct(
        protected CatalogValueNormalizer $valueNormalizer
    ) {}

    /**
     * Add Country of Origin to classifications.
     */
    public function addCountryOfOriginClassification(array &$attrs): void
    {
        if (!array_key_exists('country_of_origin', $attrs)) {
            return;
        }

        $countryOfOrigin = $attrs['country_of_origin'];

        unset($attrs['country_of_origin']);

        if ($countryOfOrigin === null || $countryOfOrigin === '') {
            return;
        }

        $country = CountryCode::query()
            ->where('name', trim((string) $countryOfOrigin))
            ->orWhere(
                'code',
                strtoupper(trim((string) $countryOfOrigin))
            )
            ->first();

        if ($country === null) {
            return;
        }

        $classifications = $this->getClassificationsArray($attrs);

        $classifications = array_values(array_filter(
            $classifications,
            fn($entry) => !(
                is_string($entry)
                && str_starts_with(
                    strtoupper(trim($entry)),
                    'COUNTRY_OF_ORIGIN='
                )
            )
        ));

        $classifications[] =
            'COUNTRY_OF_ORIGIN=' . strtoupper($country->code);

                $attrs['classifications'] = $classifications;
    }

    /**
     * Add UNSPSC to classifications.
     *
     * The classification key comes from ClassificationType rather than being
     * assumed by the processing layer. Replaces any existing entry with the
     * same key to prevent duplicates on repeated uploads.
     */
    public function addUnspscClassification(array &$attrs): void
    {
        if (!array_key_exists('unspsc_code', $attrs)) {
            return;
        }

        $unspsc = $attrs['unspsc_code'];

        // unspsc_code has no standalone database column; the persisted value
        // lives only inside classifications.
        unset($attrs['unspsc_code']);

        if ($unspsc === null || $unspsc === '') {
            return;
        }

        $unspscType = ClassificationType::query()
            ->where('key', 'UNSPSC')
            ->first();

        if ($unspscType === null) {
            return;
        }

        $unspscKey = trim((string) $unspscType->key);

        if ($unspscKey === '') {
            return;
        }

        $classifications = $this->getClassificationsArray($attrs);

        /*
         * Replace an existing entry with this key to prevent duplicates.
         */
        $classifications = array_values(array_filter(
            $classifications,
            fn($entry) => !(
                is_string($entry)
                && str_starts_with(
                    strtoupper(trim($entry)),
                    strtoupper($unspscKey) . '='
                )
            )
        ));

        $classifications[] =
            $unspscKey . '=' . trim((string) $unspsc);

        $attrs['classifications'] = $classifications;
    }

    /**
     * Add MSDS URL to classifications.
     *
     * msds_link has no standalone database column; the value is persisted
     * only in classifications as MSDS_URL=<value>. Removes any existing
     * MSDS_URL entry before adding the new value.
     */
    public function addMsdsClassification(array &$attrs): void
    {
        if (!array_key_exists('msds_link', $attrs)) {
            return;
        }

        $msdsLink = $attrs['msds_link'];

        // msds_link has no standalone database column; the persisted value
        // lives only inside classifications.
        unset($attrs['msds_link']);

        if ($msdsLink === null || $msdsLink === '') {
            return;
        }

        $classifications = $this->getClassificationsArray($attrs);

        /*
         * Remove any existing MSDS_URL entry before adding the new value.
         */
        $classifications = array_values(array_filter(
            $classifications,
            fn($entry) => !(
                is_string($entry)
                && str_starts_with(
                    strtoupper(trim($entry)),
                    'MSDS_URL='
                )
            )
        ));

        $classifications[] =
            'MSDS_URL=' . trim((string) $msdsLink);

                $attrs['classifications'] = $classifications;
    }

    /**
     * Resolve the exported hierarchy_number back to the real hierarchy FK.
     *
     * catalog_items.hierarchy stores a ProductHierarchy id, but the VIT export
     * writes that row's hierarchy_number. Reimport must reverse that lookup,
     * not write the raw hierarchy_number into the id column.
     */
    public function addHierarchyClassification(array &$attrs): void
    {
        if (!array_key_exists('hierarchy', $attrs)) {
            return;
        }

        $hierarchyNumber = $attrs['hierarchy'];

        unset($attrs['hierarchy']);

        if ($hierarchyNumber === null || $hierarchyNumber === '') {
            return;
        }

        $hierarchy = ProductHierarchy::query()
            ->where('hierarchy_number', trim((string) $hierarchyNumber))
            ->first();

        if ($hierarchy === null) {
            return;
        }

        $attrs['hierarchy'] = $hierarchy->id;
    }

    /**
     * Safely retrieve the classifications currently accumulated in attrs.
     */
    public function getClassificationsArray(array $attrs): array
    {
        $classifications = $attrs['classifications'] ?? [];

        return is_array($classifications)
            ? $classifications
            : [];
    }

    /**
     * Perform the final classifications normalization.
     *
     * This deliberately runs after every classification contributor has
     * finished (mapper -> Country of Origin -> UNSPSC -> MSDS).
     */
    public function normalizeClassificationsAttribute(array &$attrs): void
    {
        if (!array_key_exists('classifications', $attrs)) {
            return;
        }

        $attrs['classifications'] =
            $this->valueNormalizer->normalizeClassifications($attrs['classifications']);
    }
}


