<?php

namespace App\Services\Catalog;

/**
 * Generic value transformation and comparison-preparation logic shared by
 * the attribute builder, classification processing, and item comparison.
 */
class CatalogValueNormalizer
{
    /**
     * Normalize multi-value data into the canonical key/value object format.
     *
     * Used by specifications.
     *
     * @param  array<int, mixed>  $parts
     * @return array<int, array{key: string, value: string}>
     */
    public function normalizeKeyValueMultiValue(array $parts): array
    {
        $normalized = [];

        foreach ($parts as $entry) {
            // Already canonical.
            if (
                is_array($entry)
                && isset($entry['key'])
                && isset($entry['value'])
            ) {
                $key = trim((string) $entry['key']);
                $value = trim((string) $entry['value']);

                if ($key !== '' && $value !== '') {
                    $normalized[] = [
                        'key' => $key,
                        'value' => $value,
                    ];
                }

                continue;
            }

            // Associative array: ['Color' => 'Silver'].
            if (
                is_array($entry)
                && array_keys($entry) !== range(
                    0,
                    count($entry) - 1
                )
            ) {
                foreach ($entry as $key => $value) {
                    $key = trim((string) $key);
                    $value = trim((string) $value);

                    if ($key !== '' && $value !== '') {
                        $normalized[] = [
                            'key' => $key,
                            'value' => $value,
                        ];
                    }
                }

                continue;
            }

            // Indexed string: "Color=Silver".
            if (
                is_string($entry)
                && str_contains($entry, '=')
            ) {
                $equalsPos = strpos($entry, '=');

                $key = trim(
                    substr($entry, 0, $equalsPos)
                );

                $value = trim(
                    substr($entry, $equalsPos + 1)
                );

                if ($key !== '' && $value !== '') {
                    $normalized[] = [
                        'key' => $key,
                        'value' => $value,
                    ];
                }
            }
        }

        return $normalized;
    }

    /**
     * Normalize the final classifications value into:
     *
     * [
     *     ['key' => 'Color', 'value' => 'gray'],
     *     ['key' => 'Size', 'value' => 'Small'],
     * ]
     *
     * This must run only after all classification sources have contributed.
     *
     * Supported input formats include:
     *
     * - null / empty
     * - KEY=value strings
     * - key/value objects
     * - associative arrays
     * - JSON representations
     * - mixtures of the above
     *
     * No fixed classification key list is assumed.
     *
     * Duplicate keys are case-insensitive. The first position is preserved,
     * while the last processed value wins.
     *
     * @return array<int, array{key: string, value: string}>|null
     */
    public function normalizeClassifications(
        mixed $value
    ): ?array {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_string($value)) {
            $decoded = json_decode($value, true);

            $parts =
                json_last_error() === JSON_ERROR_NONE
                && is_array($decoded)
                ? $decoded
                : [$value];
        } else {
            $parts = is_array($value)
                ? $value
                : [];
        }

        $normalized = [];
        $seenKeys = [];

        foreach ($parts as $entry) {
            $key = null;
            $entryValue = null;

            // Already canonical.
            if (
                is_array($entry)
                && isset($entry['key'])
                && isset($entry['value'])
            ) {
                $key = trim((string) $entry['key']);
                $entryValue = trim((string) $entry['value']);
            }

            // Associative array: ['Color' => 'Silver'].
            elseif (
                is_array($entry)
                && array_keys($entry) !== range(
                    0,
                    count($entry) - 1
                )
            ) {
                foreach ($entry as $assocKey => $assocValue) {
                    $key = trim((string) $assocKey);
                    $entryValue = trim((string) $assocValue);

                    break;
                }
            }

            // Indexed string: "Color=Silver".
            elseif (
                is_string($entry)
                && str_contains($entry, '=')
            ) {
                $equalsPos = strpos($entry, '=');

                $key = trim(
                    substr($entry, 0, $equalsPos)
                );

                $entryValue = trim(
                    substr($entry, $equalsPos + 1)
                );
            }

            if ($key === null || $entryValue === null) {
                continue;
            }

            if ($key === '' || $entryValue === '') {
                continue;
            }

            $lookupKey = strtoupper($key);

            if (array_key_exists($lookupKey, $seenKeys)) {
                /*
                 * Preserve the original position but allow the latest
                 * value to replace the previous value.
                 */
                $normalized[$seenKeys[$lookupKey]]['value'] = $entryValue;

                continue;
            }

            $seenKeys[$lookupKey] = count($normalized);

            $normalized[] = [
                'key' => $key,
                'value' => $entryValue,
            ];
        }

        return $normalized === []
            ? null
            : $normalized;
    }

    /**
     * Normalize incoming CSV values for comparison against database values.
     *
     * This ensures consistent comparison regardless of how CSV values
     * were typed.
     */
    public function normalizeForComparison(
        array $attrs
    ): array {
        $normalized = [];

        foreach ($attrs as $key => $value) {
            /*
             * Treat the default item_weight fallback as blank.
             */
            if (
                $key === 'item_weight'
                && $value === 0.01
            ) {
                continue;
            }

            /*
             * Normalize numeric strings to floats.
             */
            if (is_numeric($value)) {
                $normalized[$key] = (float) $value;

                continue;
            }

            /*
             * Empty strings and arrays are treated as blank.
             */
            if ($value === '' || $value === []) {
                continue;
            }

            $normalized[$key] = $value;
        }

        return $normalized;
    }

    /**
     * Decode a JSON value for structural comparison.
     */
    public function decodeComparisonValue(
        mixed $value
    ): mixed {
        if (is_array($value)) {
            return $value;
        }

        $decoded = json_decode(
            (string) $value,
            true
        );

        return is_array($decoded)
            ? $decoded
            : $value;
    }

    /**
     * Normalize a single value for comparison.
     *
     * Trims whitespace and converts empty strings to null.
     */
    public function normalizeValueForComparison(
        mixed $value
    ): mixed {
        if ($value === null) {
            return null;
        }

        if (is_string($value)) {
            $trimmed = trim($value);

            return $trimmed === ''
                ? null
                : $trimmed;
        }

        return $value;
    }
}
