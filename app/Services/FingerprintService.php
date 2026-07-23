<?php

namespace App\Services;

class FingerprintService
{
    /**
     * Columns that should be excluded from fingerprint computation
     * because they are metadata, not actual content data.
     */
    public const EXCLUDE_COLUMNS = [
        'vendor_sku',
        'vendor_id',
        'catalog_name',
        'catalog_upload_id',
        'data_fingerprint'
    ];

    /**
     * Compute a deterministic fingerprint for duplicate detection.
     * Uses MD5 for compatibility with existing fingerprints.
     *
     * @param array $data The data to fingerprint
     * @param array|null $excludeColumns Columns to exclude (defaults to EXCLUDE_COLUMNS)
     * @return string The 32-character MD5 hash
     */
    public static function compute(array $data, array $excludeColumns = null): string
    {
        $excluded = $excludeColumns ?? self::EXCLUDE_COLUMNS;
        $content = [];

        foreach ($data as $column => $value) {
            if (in_array($column, $excluded, true)) {
                continue;
            }

            // Handle null, empty, and empty-array-string ('[]')
            if (is_null($value) || $value === '' || $value === '[]' || $value === []) {
                $content[$column] = null;
            } elseif (is_array($value)) {
                // Sort arrays so order doesn't affect fingerprint
                sort($value);
                $content[$column] = $value;
            } elseif (is_numeric($value)) {
                // Normalize numeric values to avoid "10" vs 10.0 mismatches
                $content[$column] = (string) (float) $value;
            } else {
                $content[$column] = trim((string) $value);
            }
        }

        // Sort by key for deterministic ordering
        ksort($content);

        return md5(json_encode($content));
    }
}
