<?php

namespace App\Services;

use App\Models\CatalogItem;
use App\Models\CatalogSubmission;
use App\Models\Vendor;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * Generates a VIT-compliant .xlsx file from catalog submission data.
 *
 * Column headers and order are driven by VitFieldDefinition::visibleInWebApp().
 * CatalogItem columns now match VIT field keys directly, so no
 * FIELD_TO_COLUMN translation map is needed.
 */
class CatalogExportService
{
    private const JSON_COLUMNS = [
        'search_terms',
        'classifications',
        'specifications',
        'selling_points',
    ];

    public function generate(Vendor $vendor, string $catalogName, Collection $items): Spreadsheet
    {
        $fields = VitFieldDefinition::visibleInWebApp();

        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Catalog');

        $this->writeHeaderRow($sheet, $fields);

        $rowNumber = 2;
        foreach ($items as $item) {
            $this->writeItemRow($sheet, $fields, $item, $vendor, $rowNumber);
            $rowNumber++;
        }

        foreach ($fields as $index => $field) {
            $column = $this->columnLetter($index);
            $sheet->getColumnDimension($column)->setAutoSize(true);
        }

        return $spreadsheet;
    }

    /**
     * Generate Excel from a query cursor for memory-efficient large-catalog support.
     *
     * @param  Vendor        $vendor
     * @param  string        $catalogName
     * @param  \Illuminate\Database\Query\Builder  $itemsQuery
     * @param  string        $disk
     * @return string
     */
    public function generateAndStoreFromQuery(Vendor $vendor, string $catalogName, $itemsQuery, string $disk = 'local'): string
    {

        $vendorName = is_string($vendor->name) ? $vendor->name : 'Unknown';
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Catalog');

        $fields = VitFieldDefinition::visibleInWebApp();
        $this->writeHeaderRow($sheet, $fields);

        $rowNumber = 2;
        foreach ($itemsQuery->cursor() as $item) {
            $this->writeItemRow($sheet, $fields, $item, $vendor, $rowNumber);
            $rowNumber++;
        }

        foreach ($fields as $index => $field) {
            $column = $this->columnLetter($index);
            $sheet->getColumnDimension($column)->setAutoSize(true);
        }

        $safeCatalogName = Str::slug($catalogName);
        $path = "exports/vendor-{$vendor->id}/catalog-{$safeCatalogName}-" . now()->format('YmdHis') . '.xlsx';

        Storage::disk($disk)->makeDirectory(dirname($path));

        if ($disk === 'local') {
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

    public function generateAndStore(Vendor $vendor, string $catalogName, Collection $items, string $disk = 'local'): string
    {
        $spreadsheet = $this->generate($vendor, $catalogName, $items);

        $safeCatalogName = Str::slug($catalogName);
        $path = "exports/vendor-{$vendor->id}/catalog-{$safeCatalogName}-" . now()->format('YmdHis') . '.xlsx';

        Storage::disk($disk)->makeDirectory(dirname($path));

        if ($disk === 'local') {
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
     * Generate Excel file from ALL current CatalogItems belonging to the submission's vendor.
     *
     * Reads directly from CatalogItem records by vendor_id. No pivot table
     * or snapshot needed — the generated Excel is the submission artifact.
     * Uses a cursor for memory-efficient iteration over large catalogs.
     *
     * @param  CatalogSubmission  $submission  The submission to process
     * @return string                         Relative storage path to the generated .xlsx
     */
    public function generateFromSubmission(CatalogSubmission $submission): string
    {

        $vendor = $submission->vendor;
        $vendorName = is_string($vendor->name) ? $vendor->name : 'Unknown';
        $catalogName = 'Export ' . $vendorName . ' ' . now()->format('Y-m-d');
        $disk = $submission->disk ?? 'local';

        // Stream items from DB using cursor for memory efficiency
        $itemsQuery = CatalogItem::where('vendor_id', $vendor->id)
            ->orderBy('id');

        $path = $this->generateAndStoreFromQuery($vendor, $catalogName, $itemsQuery, $disk);

        return $path;
    }

    private function writeHeaderRow($sheet, Collection $fields): void
    {
        foreach ($fields as $index => $field) {
            $column = $this->columnLetter($index);
            $header = $field->web_app_label;
            $sheet->setCellValue("{$column}1", $header);
            $sheet->getStyle("{$column}1")->getFont()->setBold(true);
        }
    }

    private function writeItemRow($sheet, Collection $fields, object $item, Vendor $vendor, int $rowNumber): void
    {
        foreach ($fields as $index => $field) {
            $column = $this->columnLetter($index);
            $cell = "{$column}{$rowNumber}";

            try {
                $value = $this->resolveValue($field->field_key, $item, $vendor);
            } catch (\Throwable $e) {
                Log::error('Failed to resolve field value while writing item row', [
                    'field_key' => $field->field_key,
                    'item_id' => $item->id ?? null,
                    'row_number' => $rowNumber,
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]);

                $value = '';
            }

            $sheet->setCellValueExplicit($cell, $value, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
        }
    }

    /**
     * Resolve a single VIT field value from the CatalogItem + Vendor.
     *
     * CatalogItem columns now match VIT field keys directly, so most
     * values can be read with $item->{$fieldKey}. Only special cases
     * (system-derived fields, fields not stored on CatalogItem) need
     * custom handling.
     */
    private function resolveValue(string $fieldKey, object $item, Vendor $vendor): string
    {
        // System-derived: seller name comes from the vendor record
        if ($fieldKey === 'seller') {
            return $vendor->name ?? '';
        }

        // Direct read — CatalogItem column matches VIT field key
        $rawValue = $item->{$fieldKey} ?? null;

        if (is_null($rawValue) || $rawValue === '' || $rawValue === []) {
            return '';
        }

        // JSON columns: decode and join as comma-separated strings
        if (in_array($fieldKey, self::JSON_COLUMNS, true)) {
            $decoded = is_string($rawValue) ? json_decode($rawValue, true) : $rawValue;

            if (! is_array($decoded) || empty($decoded)) {
                return '';
            }

            // Guard against nested arrays/objects that would break implode()
            $stringParts = array_map(function ($value) use ($fieldKey, $item) {
                if (is_array($value)) {

                    return $this->flattenValueForDisplay($value);
                }

                if (is_bool($value)) {
                    return $value ? 'TRUE' : 'FALSE';
                }

                return (string) $value;
            }, $decoded);

            return implode(', ', $stringParts);
        }

        if (is_bool($rawValue)) {
            return $rawValue ? 'TRUE' : 'FALSE';
        }

        return (string) $rawValue;
    }

    /**
     * Convert a nested array/object value into a readable string
     * instead of letting implode() choke on it.
     */
    private function flattenValueForDisplay(array $value): string
    {
        // Associative array like ['name' => 'Color', 'value' => 'Red'] -> "Color: Red"
        if (array_key_exists('name', $value) && array_key_exists('value', $value)) {
            return "{$value['name']}: {$value['value']}";
        }

        // Fallback: JSON-encode so at least something visible shows up in the export
        return json_encode($value);
    }

    private function columnLetter(int $zeroBasedIndex): string
    {
        return \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($zeroBasedIndex + 1);
    }
}
