<?php

namespace App\Services\Catalog;

use App\Jobs\ProcessValidatedRowsJob;
use App\Models\CatalogItem;
use App\Models\CatalogUpload;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Processes validated catalog upload rows into CatalogItem records.
 *
 * Given a validated row, this service decides whether an item should be
 * created or updated (matched by vendor_id + dealer_sku) and performs the
 * operation.
 *
 * Cross-catalog SKU protection: an existing item's catalog_id must never be
 * reassigned by an upload. If the matched item belongs to a different catalog
 * than the upload, the row is rejected and marked invalid instead of being
 * updated.
 */
class CatalogItemProcessor
{
    public function __construct(
        protected CatalogItemAttributeBuilder $attributeBuilder,
        protected CatalogClassificationProcessor $classificationProcessor,
        protected CatalogItemComparator $comparator,
        protected CatalogValueNormalizer $valueNormalizer
    ) {}

    /**
     * Process one validated upload row.
     *
     * @return array{created: int, updated: int, unchanged: int}
     */
    public function processRow(
        CatalogUpload $upload,
        $vendor,
        $row,
        array $nonComparableColumns
    ): array {
        $data = $this->attributeBuilder->prepareRowData($row);

        Log::info('ProcessValidatedRowsJob: processing row', [
            'upload_id' => $upload->id,
            'row_number' => $row->row_number,
            'row_status' => $row->status,
            'row_errors' => $row->errors,
            'dealer_sku' => $data['dealer_sku'] ?? null,
        ]);

        $attrs = $this->attributeBuilder->buildAttributes($data, $vendor);

        // Associate every item produced by this upload with the catalog the
        // upload belongs to (CatalogUpload.catalog_id -> CatalogItem.catalog_id).
        $attrs['catalog_id'] = $upload->catalog_id;

        /*
         * Classification contributors must be processed in this order:
         *
         * 1. Mapper classifications
         * 2. Country of Origin
         * 3. UNSPSC
         * 4. MSDS
         * 5. Final classifications normalization
         *
         * The final normalization must happen only after all contributors
         * have added their values.
         */
        $this->classificationProcessor->addCountryOfOriginClassification($attrs);
        $this->classificationProcessor->addUnspscClassification($attrs);
        $this->classificationProcessor->addMsdsClassification($attrs);
        $this->classificationProcessor->addHierarchyClassification($attrs);
        $this->classificationProcessor->normalizeClassificationsAttribute($attrs);

        /*
         * Only non-blank values are allowed to overwrite an existing item.
         */
        $updateAttrs = $this->buildUpdateAttributes($attrs);

        $sellerSku = $attrs['dealer_sku'] ?? null;

        if ($sellerSku) {
            $existing = $this->findExistingItem(
                $vendor->id,
                $sellerSku
            );

            if ($existing) {
                /*
                 * Cross-catalog SKU guard (safety net for the race window
                 * between row validation and item processing). An upload
                 * belonging to a different catalog must never update an
                 * existing SKU, and an item's catalog_id must never be
                 * reassigned by an upload.
                 */
                if ((int) $existing->catalog_id !== (int) $upload->catalog_id) {
                    $row->update([
                        'status' => 'invalid',
                        'errors' => [
                            'errors' => [
                                [
                                    'field_key' => 'dealer_sku',
                                    'message' =>
                                        ProcessValidatedRowsJob::CROSS_CATALOG_SKU_ERROR,
                                ],
                            ],
                        ],
                    ]);

                    return [
                        'created' => 0,
                        'updated' => 0,
                        'unchanged' => 0,
                    ];
                }

                return $this->updateExistingItem(
                    $existing,
                    $updateAttrs,
                    $nonComparableColumns,
                    $sellerSku
                );
            }
        }

        return $this->createItem(
            $attrs,
            $updateAttrs,
            $vendor->id,
            $sellerSku,
            (int) $upload->catalog_id,
            $row
        );
    }

    /**
     * Build the update payload.
     *
     * Blank CSV values must never overwrite existing database values.
     */
    protected function buildUpdateAttributes(array $attrs): array
    {
        $updateAttrs = array_filter(
            $attrs,
            fn($value) =>
            $value !== null
                && $value !== ''
                && $value !== [],
            ARRAY_FILTER_USE_BOTH
        );

        // vendor_id is metadata and must never be bulk-overwritten.
        unset($updateAttrs['vendor_id']);

        return $updateAttrs;
    }

    /**
     * Find an existing catalog item by vendor and dealer SKU.
     */
    protected function findExistingItem(
        $vendorId,
        string $sellerSku
    ): ?CatalogItem {
        return CatalogItem::where('vendor_id', $vendorId)
            ->where('dealer_sku', $sellerSku)
            ->first();
    }
/**
     * Update an existing CatalogItem if meaningful changes are detected.
     *
     * @return array{created: int, updated: int, unchanged: int}
     */
    protected function updateExistingItem(
        CatalogItem $existing,
        array $updateAttrs,
        array $nonComparableColumns,
        string $sellerSku
    ): array {
        $normalizedUpdateAttrs =
            $this->valueNormalizer->normalizeForComparison($updateAttrs);

        if (
            $this->comparator->hasMeaningfulChanges(
                $existing,
                $normalizedUpdateAttrs,
                $nonComparableColumns,
                $sellerSku
            )
        ) {
            $updateAttrs['updated_at'] = now();

            $existing->update($updateAttrs);

            return [
                'created' => 0,
                'updated' => 1,
                'unchanged' => 0,
            ];
        }

        return [
            'created' => 0,
            'updated' => 0,
            'unchanged' => 1,
        ];
    }

    /**
     * Create a new CatalogItem.
     *
     * If the unique dealer SKU constraint is hit, re-fetch the existing
     * item and update it instead — but only when the existing item belongs
     * to the upload's catalog. A cross-catalog duplicate (concurrent upload
     * race) is rejected and the row is marked invalid instead.
     *
     * @return array{created: int, updated: int, unchanged: int, cross_catalog_rejected?: bool}
     */
    protected function createItem(
        array $attrs,
        array $updateAttrs,
        $vendorId,
        ?string $sellerSku,
        ?int $expectedCatalogId = null,
        $row = null
    ): array {
        $attrs['id'] = (string) Str::uuid();
        $attrs['created_at'] = now();
        $attrs['updated_at'] = now();

        try {
            CatalogItem::create($attrs);

            return [
                'created' => 1,
                'updated' => 0,
                'unchanged' => 0,
            ];
        } catch (QueryException $e) {
            if (!$this->isDuplicateDealerSkuException($e)) {
                throw $e;
            }

            $existing = $this->findExistingItem(
                $vendorId,
                $sellerSku
            );

            if (!$existing) {
                throw $e;
            }

            /*
             * Cross-catalog SKU guard for the concurrent-upload race: the
             * item was created in another catalog between validation and
             * persistence. Never reassign its catalog_id or update it.
             */
            if (
                $expectedCatalogId !== null
                && (int) $existing->catalog_id !== $expectedCatalogId
            ) {
                if ($row) {
                    $row->update([
                        'status' => 'invalid',
                        'errors' => [
                            'errors' => [
                                [
                                    'field_key' => 'dealer_sku',
                                    'message' =>
                                        ProcessValidatedRowsJob::CROSS_CATALOG_SKU_ERROR,
                                ],
                            ],
                        ],
                    ]);
                }

                return [
                    'created' => 0,
                    'updated' => 0,
                    'unchanged' => 0,
                    'cross_catalog_rejected' => true,
                ];
            }

            $updateAttrs['updated_at'] = now();

            $existing->update($updateAttrs);

            return [
                'created' => 0,
                'updated' => 1,
                'unchanged' => 0,
            ];
        }
    }

    /**
     * Determine whether a database exception represents the expected
     * duplicate dealer SKU race/duplicate-upload case.
     */
    protected function isDuplicateDealerSkuException(
        QueryException $e
    ): bool {
        return $e->getCode() === '23000'
            && str_contains(
                $e->getMessage(),
                'dealer_sku'
            )
            && str_contains(
                $e->getMessage(),
                'Duplicate entry'
            );
    }
}