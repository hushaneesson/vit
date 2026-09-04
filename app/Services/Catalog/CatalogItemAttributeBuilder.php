<?php

namespace App\Services\Catalog;

use App\Services\VitFieldDefinition;

/**
 * Converts validated catalog upload row data into the attribute array
 * used to persist a CatalogItem.
 *
 * This service does not decide whether an item is created or updated and
 * does not perform any database persistence.
 */
class CatalogItemAttributeBuilder
{
    public function __construct(
        protected CatalogValueNormalizer $valueNormalizer
    ) {}

    public function prepareRowData($row): array
    {
        $data = $row->data;

        if (!is_array($data)) {
            $data = json_decode(
                (string) ($data ?? ''),
                true
            ) ?: [];
        }

        return $data;
    }

    /**
     * Build the CatalogItem attribute map using VitFieldDefinition
     * as the source of truth.
     */
    public function buildAttributes(array $data, $vendor): array
    {
        $attrs = [
            'vendor_id' => $vendor->id,
        ];

        $definitions = VitFieldDefinition::all()
            ->whereIn('field_key', array_keys($data))
            ->keyBy('field_key');

        foreach ($data as $fieldKey => $rawValue) {
            $definition = $definitions->get($fieldKey);

            $modelAttribute = $this->resolveModelAttribute(
                $fieldKey,
                $definition
            );

            if ($this->handleBlankValue(
                $attrs,
                $fieldKey,
                $modelAttribute,
                $rawValue
            )) {
                continue;
            }

            if ($definition && $definition->is_multi_value) {
                $this->processMultiValueField(
                    $attrs,
                    $fieldKey,
                    $modelAttribute,
                    $rawValue,
                    $definition
                );

                continue;
            }

            $attrs[$modelAttribute] = $this->castFieldValue(
                $fieldKey,
                $rawValue,
                $definition
            );
        }

        return $attrs;
    }

    /**
     * Resolve the actual database attribute used by the processing pipeline.
     *
     * Relationship paths and accessors used by the export layer cannot be
     * written directly to CatalogItem, so those fields fall back to their
     * field_key.
     */
    private function resolveModelAttribute(
        string $fieldKey,
        $definition
    ): string {
        $modelAttribute = $definition
            ? ($definition->model_attribute ?? $fieldKey)
            : $fieldKey;

        if (
            str_contains($modelAttribute, '.')
            || $modelAttribute === 'countryOfOrigin'
        ) {
            return $fieldKey;
        }

        return $modelAttribute;
    }

    /**
     * Handle blank input values.
     *
     * @return bool True when processing for this field is complete.
     */
    private function handleBlankValue(
        array &$attrs,
        string $fieldKey,
        string $modelAttribute,
        mixed $rawValue
    ): bool {
        if (
            !is_null($rawValue)
            && $rawValue !== ''
            && $rawValue !== []
        ) {
            return false;
        }

        if ($fieldKey === 'item_weight_in_pounds') {
            $attrs[$modelAttribute] = 0.01;

            return true;
        }

        // is_discontinued must never be NULL.
        if ($fieldKey === 'discontinued') {
            $attrs[$modelAttribute] = false;

            return true;
        }

        // CatalogItem.availability is INTEGER NOT NULL DEFAULT 0
        if ($fieldKey === 'availability') {
            $attrs[$modelAttribute] = 0;

            return true;
        }

        $attrs[$modelAttribute] = null;

        return true;
    }

    /**
     * Process a multi-value field into its model attribute.
     */
    private function processMultiValueField(
        array &$attrs,
        string $fieldKey,
        string $modelAttribute,
        mixed $rawValue,
        $definition
    ): void {
        $parts = is_array($rawValue)
            ? $rawValue
            : json_decode((string) $rawValue, true);

        if (!is_array($parts)) {
            $parts = [];
        }

        if ($definition->is_key_value) {
            /*
             * Classifications collect contributions from:
             *
             * - mapper classifications
             * - Country of Origin
             * - UNSPSC
             * - MSDS
             *
             * Therefore they must not be normalized until all sources
             * have contributed.
             */
            if ($modelAttribute === 'classifications') {
                $attrs[$modelAttribute] = $parts;

                return;
            }

            // Specifications use the canonical key/value structure.
            $attrs[$modelAttribute] =
                $this->valueNormalizer->normalizeKeyValueMultiValue($parts);

            return;
        }

        // Normal array fields are cleaned and preserved as simple arrays.
        $cleaned = [];

        foreach ($parts as $value) {
            if (is_string($value)) {
                $value = trim($value);
            }

            if ($value !== '' && $value !== null) {
                $cleaned[] = $value;
            }
        }

        $attrs[$modelAttribute] = $cleaned;
    }

    /**
     * Cast a normal field according to its VitFieldDefinition field_type.
     */
    private function castFieldValue(
        string $fieldKey,
        mixed $rawValue,
        $definition
    ): mixed {
        if (!$definition) {
            return (string) $rawValue;
        }

        $cast = match ($definition->field_type) {
            'number', 'decimal' => is_numeric($rawValue)
                ? (float) $rawValue
                : null,

            'boolean' => is_bool($rawValue)
                ? $rawValue
                : (
                    in_array(
                        strtolower((string) $rawValue),
                        ['true', 'false', '1', '0', 'yes', 'no'],
                        true
                    )
                    ? (bool) $rawValue
                    : false
                ),

            default => (string) $rawValue,
        };

        // Preserve the existing item_weight fallback behavior.
        if ($fieldKey === 'item_weight_in_pounds') {
            $cast = is_numeric($rawValue)
                ? (float) $rawValue
                : 0.01;
        }

        // availability is stored in an INTEGER NOT NULL column; coerce numeric
        // input to an integer and fall back to the column default .
        if ($fieldKey === 'availability') {
            $cast = is_numeric($rawValue)
                ? (int) $rawValue
                : 0;
        }

        return $cast;
    }
}
