<?php

namespace App\Services;

use App\Models\CatalogExport;
use App\Models\CatalogItem;
use App\Models\CatalogSubmissionItem;
use App\Models\VitFieldDefinition;
use App\Models\Vendor;
use App\Enums\CatalogExportStatus;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * Generates a VIT-compliant .xlsx file from validated CatalogItem records.
 *
 * This service follows the same storage disk resolution pattern used by
 * ProcessCatalogUploadJob and CatalogFileInspectionService (resolve local
 * path for PhpSpreadsheet, write to configured disk).
 *
 * Column headers and order are driven by VitFieldDefinition::visibleInWebApp()
 * — the static, non-system-derived VIT field definitions — NOT the database
 * catalog_fields table. The field-to-CatalogItem-column mapping follows the
 * same map used by ProcessValidatedRowsJob.
 *
 * This is a standalone service intentionally decoupled from the
 * CatalogExcelGenerator (which references the dead CatalogFieldDefinition
 * model and follows a different pipeline).
 */
class CatalogExportService
{
    /**
     * Map VIT field_key values to CatalogItem database columns.
     * Must stay in sync with ProcessValidatedRowsJob::$fieldKeyToColumnMap.
     */
    private const FIELD_TO_COLUMN = [
        'seller_sku'              => 'vendor_sku',
        'manufacturer_sku'        => 'manufacturer_sku',
        'manufacturer'            => 'manufacturer_name',
        'brand_name'              => 'brand_name',
        'name'                    => 'name',
        'description'             => 'description',
        'product_type_or_family'  => 'product_type',
        'unit_of_measure'         => 'unit_of_measure',
        'quantity_per_unit'       => 'quantity_per_unit',
        'unspsc_code'             => 'unspsc_code',
        'list_price'              => 'list_price',
        'selling_price_per_unit'  => 'selling_price',
        'item_weight'             => 'weight',
        'min_qty_per_order'       => 'min_order_quantity',
        'max_qty_per_order'       => 'max_order_quantity',
        'multiples'               => 'multiples',
        'search_terms'            => 'search_terms',
        'classifications'         => 'classifications',
        'specifications'          => 'specifications',
        'selling_points'          => 'selling_points',
        'msds_link'               => 'msds_link',
    ];

    /**
     * CatalogItem columns that store JSON arrays and need special rendering.
     */
    private const JSON_COLUMNS = [
        'search_terms',
        'classifications',
        'specifications',
        'selling_points',
    ];

    /**
     * Numeric columns that could be null but should render as empty string.
     */
    private const NUMERIC_COLUMNS = [
        'quantity_per_unit',
        'weight',
        'min_order_quantity',
        'max_order_quantity',
        'multiples',
        'list_price',
        'selling_price',
    ];

    /**
     * Generate a VIT-compliant .xlsx workbook from validated CatalogItem records.
     *
     * @param  Vendor            $vendor       The vendor record (provides vendor name for seller field)
     * @param  string            $catalogName  Name for the catalog (used in filename)
     * @param  Collection<int, CatalogItem> $items   Validated catalog items
     * @return Spreadsheet
     */
    public function generate(Vendor $vendor, string $catalogName, Collection $items): Spreadsheet
    {
        $fields = VitFieldDefinition::visibleInWebApp();

        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Catalog');

        // Row 1: header row
        $this->writeHeaderRow($sheet, $fields);

        // Row 2+: data rows
        $rowNumber = 2;
        foreach ($items as $item) {
            $this->writeItemRow($sheet, $fields, $item, $vendor, $rowNumber);
            $rowNumber++;
        }

        // Auto-size columns for readability
        foreach ($fields as $index => $field) {
            $column = $this->columnLetter($index);
            $sheet->getColumnDimension($column)->setAutoSize(true);
        }

        return $spreadsheet;
    }

    /**
     * Generate the workbook and persist it to the configured storage disk.
     *
     * @param  Vendor            $vendor
     * @param  string            $catalogName
     * @param  Collection<int, CatalogItem> $items
     * @param  string            $disk  Filesystem disk to store on (default: 'spaces' matching existing pattern)
     * @return string                   Relative storage path to the generated .xlsx
     */
    public function generateAndStore(Vendor $vendor, string $catalogName, Collection $items, string $disk = 'local'): string
    {
        $spreadsheet = $this->generate($vendor, $catalogName, $items);

        $safeCatalogName = Str::slug($catalogName);
        $path = "exports/vendor-{$vendor->id}/catalog-{$safeCatalogName}-" . now()->format('YmdHis') . '.xlsx';

        // Ensure directory exists on the target disk
        Storage::disk($disk)->makeDirectory(dirname($path));

        // PhpSpreadsheet needs a local filesystem path. If remote, write to temp first.
        if ((Storage::disk($disk)->getConfig()['driver'] ?? null) === 'local') {
            $fullPath = Storage::disk($disk)->path($path);
            $writer = new Xlsx($spreadsheet);
            $writer->save($fullPath);
        } else {
            $tempPath = tempnam(sys_get_temp_dir(), 'catalog_export_');
            $writer = new Xlsx($spreadsheet);
            $writer->save($tempPath);
            Storage::disk($disk)->put($path, file_get_contents($tempPath));
            unlink($tempPath);
        }

        return $path;
    }

    /**
     * Query validated CatalogItem records for a given vendor.
     *
     * "Validated and relevant" means items whose status is 'acceptable' or
     * 'excellent' — i.e. all required VIT fields (name, description,
     * vendor_sku, unit_of_measure) are populated. Items marked 'incomplete'
     * are excluded since they lack core required data.
     *
     * @return Collection<int, CatalogItem>
     */
    public function getValidatedItems(int $vendorId): Collection
    {
        return CatalogItem::where('vendor_id', $vendorId)
            ->whereIn('status', ['acceptable', 'excellent'])
            ->orderBy('vendor_sku')
            ->get();
    }

    /**
     * Generate Excel file from an existing CatalogExport linked to a CatalogSubmission.
     * Uses the submission's snapshot records (catalog_submission_items table).
     *
     * @param  CatalogExport  $export  The export record to process
     * @return string                  Relative storage path to the generated .xlsx
     */
    public function generateFromSubmission(CatalogExport $export): string
    {
        // Load items from the submission snapshot (preserves data at submission time)
        $snapshotItems = $export->submission->submissionItems()->get();

        // Convert snapshot items to a format compatible with generateAndStore
        // We'll map them to a Collection that mimics CatalogItem models
        $items = $snapshotItems->map(function (CatalogSubmissionItem $snapshot) {
            // Create a minimal CatalogItem-like object from the snapshot
            return new CatalogItem((array) $snapshot->getAttributes());
        });

        $vendor = $export->vendor;
        $catalogName = 'Export ' . $vendor->name . ' ' . now()->format('Y-m-d');
        $disk = $export->disk ?? 'local';

        // Also need to store disk on export for later reference
        $path = $this->generateAndStore($vendor, $catalogName, $items, $disk);

        return $path;
    }

    /**
     * Full pipeline: query validated items, generate the .xlsx, store it,
     * and populate a CatalogExport record.
     *
     * @return CatalogExport
     */
    public function generateExport(Vendor $vendor, string $catalogName, string $disk = 'local'): CatalogExport
    {
        $items = $this->getValidatedItems($vendor->id);

        $export = CatalogExport::create([
            'vendor_id'             => $vendor->id,
            'status'                => CatalogExportStatus::Generating,
            'total_items'           => $items->count(),
            'generating_started_at' => now(),
        ]);

        try {
            $path = $this->generateAndStore($vendor, $catalogName, $items, $disk);

            $fileSize = Storage::disk($disk)->fileSize($path);

            $export->update([
                'file_path'    => $path,
                'disk'         => $disk,
                'file_size'    => $fileSize,
                'status'       => CatalogExportStatus::Completed,
                'generated_at' => now(),
            ]);
        } catch (\Throwable $e) {
            $export->update([
                'status'          => CatalogExportStatus::Failed,
                'failure_reason'  => $e->getMessage(),
                'generated_at'    => now(),
            ]);

            throw $e;
        }

        return $export->fresh();
    }

    // -----------------------------------------------------------------------
    //  Internal helpers
    // -----------------------------------------------------------------------

    /**
     * Write the header row using VitFieldDefinition labels.
     */
    private function writeHeaderRow($sheet, Collection $fields): void
    {
        foreach ($fields as $index => $field) {
            $column = $this->columnLetter($index);
            $header = $field->web_app_label;
            $sheet->setCellValue("{$column}1", $header);
            $sheet->getStyle("{$column}1")->getFont()->setBold(true);
        }
    }

    /**
     * Write a single CatalogItem's data into one row.
     */
    private function writeItemRow($sheet, Collection $fields, CatalogItem $item, Vendor $vendor, int $rowNumber): void
    {
        foreach ($fields as $index => $field) {
            $column = $this->columnLetter($index);
            $cell = "{$column}{$rowNumber}";

            $value = $this->resolveValue($field->field_key, $item, $vendor);
            $sheet->setCellValueExplicit($cell, $value, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
        }
    }

    /**
     * Resolve a single VIT field value from the CatalogItem + Vendor.
     *
     * Special handling:
     *   - 'seller' field comes from vendor.name (system-derived)
     *   - 'categorization_or_hierarchy' is not stored on CatalogItem (future)
     *   - JSON columns are joined into comma-separated strings
     *   - Numeric columns with null values render as empty string
     */
    private function resolveValue(string $fieldKey, CatalogItem $item, Vendor $vendor): string
    {
        // System-derived: seller name comes from the vendor record
        if ($fieldKey === 'seller') {
            return $vendor->name ?? '';
        }

        // Categorization/hierarchy is not yet stored on CatalogItem.
        // This is a required VIT field; we return an empty placeholder
        // so the column exists in the export. Populating it requires
        // resolving the hierarchy path from the UNSPSC code or other data.
        if ($fieldKey === 'categorization_or_hierarchy') {
            return '';
        }

        // image_file_name and brand_logo are not stored directly on CatalogItem
        if (in_array($fieldKey, ['image_file_name', 'brand_logo'], true)) {
            return '';
        }

        // Map field_key to CatalogItem column
        $column = self::FIELD_TO_COLUMN[$fieldKey] ?? null;

        if ($column === null) {
            return '';
        }

        $rawValue = $item->{$column};

        if (is_null($rawValue) || $rawValue === '' || $rawValue === []) {
            return '';
        }

        // JSON columns: decode and join as comma-separated strings
        if (in_array($column, self::JSON_COLUMNS, true)) {
            $decoded = is_string($rawValue) ? json_decode($rawValue, true) : $rawValue;

            if (! is_array($decoded) || empty($decoded)) {
                return '';
            }

            return implode(', ', $decoded);
        }

        // Boolean values
        if (is_bool($rawValue)) {
            return $rawValue ? 'TRUE' : 'FALSE';
        }

        return (string) $rawValue;
    }

    /**
     * Convert a zero-based column index to an Excel column letter (A, B, C...).
     */
    private function columnLetter(int $zeroBasedIndex): string
    {
        return \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($zeroBasedIndex + 1);
    }
}
