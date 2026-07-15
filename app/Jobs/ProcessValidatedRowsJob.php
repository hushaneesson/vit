<?php

namespace App\Jobs;

use App\Models\CatalogField;
use App\Models\CatalogItem;
use App\Models\CatalogUpload;
use App\Models\Vendor;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * Phase 8B: consumes the validated CatalogUploadRow records from
 * ProcessCatalogUploadJob and creates CatalogItem rows in the
 * vendor's catalog.
 *
 * System-derived fields (e.g. seller → vendor.name) are populated
 * here, not from the uploaded file.
 */
class ProcessValidatedRowsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public function __construct(public int $catalogUploadId) {}

    public function handle(): void
    {
        $upload = CatalogUpload::with('vendor')->findOrFail($this->catalogUploadId);

        $upload->update(['status' => 'processing_items']);

        try {
            $vendor = $upload->vendor;
            $catalogName = $upload->catalog_name ?? 'Imported Catalog '.$upload->created_at->format('Y-m-d');

            // Build field_key => CatalogItem column mapping
            $fieldToColumn = $this->fieldKeyToColumnMap();

            // NOT NULL columns that need a default when the uploaded data is missing them
            $notNullDefaults = [
                'product_type' => 'General',
                'unspsc_code' => '00000000',
                'unit_of_measure' => 'EA',
                'list_price' => 0.00,
                'selling_price' => 0.00,
            ];

            $validRows = $upload->rows()
                ->where('status', 'valid')
                ->cursor();

            $createdCount = 0;
            $batch = [];
            $batchSize = 200;

            DB::beginTransaction();

            foreach ($validRows as $row) {
                $data = $row->data;

                $attrs = [
                    'vendor_id' => $vendor->id,
                    'catalog_name' => $catalogName,
                    'catalog_upload_id' => $upload->id,
                ];

                // Map validated field data to CatalogItem columns.
                // Always set every key so that every row in a batch has the
                // same columns – PostgreSQL requires this.
                foreach ($fieldToColumn as $fieldKey => $columnName) {
                    $rawValue = $data[$fieldKey] ?? null;

                    if (is_null($rawValue) || $rawValue === '' || $rawValue === []) {
                        // Use a default for NOT NULL columns, null for nullable ones
                        $attrs[$columnName] = $notNullDefaults[$columnName] ?? null;
                        continue;
                    }

                    // Cast multi-value arrays to JSON for JSON columns
                    if (in_array($columnName, ['search_terms', 'classifications', 'specifications', 'selling_points'], true)) {
                        $attrs[$columnName] = is_array($rawValue) ? json_encode($rawValue) : json_encode([$rawValue]);
                    } elseif ($columnName === 'weight') {
                        $attrs[$columnName] = is_numeric($rawValue) ? (float) $rawValue : 0.01;
                    } elseif (in_array($columnName, ['quantity_per_unit', 'min_order_quantity', 'max_order_quantity', 'multiples', 'list_price', 'selling_price'], true)) {
                        $attrs[$columnName] = is_numeric($rawValue) ? (float) $rawValue : 0.00;
                    } else {
                        $attrs[$columnName] = (string) $rawValue;
                    }
                }

                // Generate UUID for batch insertion
                $attrs['id'] = (string) Str::uuid();
                $attrs['created_at'] = now();
                $attrs['updated_at'] = now();

                $batch[] = $attrs;
                $createdCount++;

                if (count($batch) >= $batchSize) {
                    CatalogItem::insert($batch);
                    $batch = [];
                }
            }

            if (! empty($batch)) {
                CatalogItem::insert($batch);
            }

            DB::commit();

            $upload->update([
                'status' => 'completed',
                'total_rows' => $upload->rows()->count(),
                'success_rows' => $createdCount,
                'error_rows' => $upload->rows()->where('status', 'invalid')->count(),
                'processing_completed_at' => now(),
            ]);
        } catch (Throwable $e) {
            DB::rollBack();

            $upload->update([
                'status' => 'failed',
                'failure_reason' => 'Row processing failed: '.$e->getMessage(),
                'processing_completed_at' => now(),
            ]);

            throw $e;
        }
    }

    /**
     * Columns in catalog_items that are NOT NULL and come from user uploads.
     * These must always have a value in every row.
     */
    private function getRequiredFieldKeys(): array
    {
        return [
            'seller_sku',
            'name',
            'description',
            'product_type_or_family',
            'unspsc_code',
            'unit_of_measure',
            'list_price',
            'selling_price_per_unit',
        ];
    }

    /**
     * Map CatalogField field_key values to CatalogItem database columns.
     */
    private function fieldKeyToColumnMap(): array
    {
        return [
            'seller_sku' => 'vendor_sku',
            'manufacturer_sku' => 'manufacturer_sku',
            'manufacturer' => 'manufacturer_name',
            'brand_name' => 'brand_name',
            'name' => 'name',
            'description' => 'description',
            'product_type_or_family' => 'product_type',
            'unit_of_measure' => 'unit_of_measure',
            'quantity_per_unit' => 'quantity_per_unit',
            'unspsc_code' => 'unspsc_code',
            'list_price' => 'list_price',
            'selling_price_per_unit' => 'selling_price',
            'item_weight' => 'weight',
            'min_qty_per_order' => 'min_order_quantity',
            'max_qty_per_order' => 'max_order_quantity',
            'multiples' => 'multiples',
            'search_terms' => 'search_terms',
            'classifications' => 'classifications',
            'specifications' => 'specifications',
            'selling_points' => 'selling_points',
            'msds_link' => 'msds_link',
        ];
    }
}
