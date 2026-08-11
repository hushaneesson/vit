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
 * Column headers and order are driven by VitFieldDefinition::exportableFields(),
 * which returns only fields that get their own column in the output Excel file.
 * The header text uses vit_csv_column (the VIT spec's exact column name), not
 * web_app_label. Values are resolved from CatalogItem using model_attribute
 * (falling back to field_key) so that name mismatches between field_key and
 * the model column are handled in one place (VitFieldDefinition).
 *
 * Fields that pack into the classifications column (unspsc_code, msds_link,
 * country_of_origin) are merged into classifications at write time, not
 * written as their own columns. Quantity_per_unit is appended onto the end
 * of the name (shortdescription) value rather than getting its own column,
 * formatted as "10 Reams/CS" (quantity + unit_word + "/" + UOM).
 */
class CatalogExportService
{
    public function generate(Vendor $vendor, string $catalogName, Collection $items): Spreadsheet
    {
        $fields = VitFieldDefinition::exportableFields();

        $spreadsheet = new Spreadsheet();
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
     * @param  Vendor                       $vendor
     * @param  string                       $catalogName
     * @param  \Illuminate\Database\Query\Builder  $itemsQuery
     * @param  string                       $disk
     * @return string
     */
    public function generateAndStoreFromQuery(Vendor $vendor, string $catalogName, $itemsQuery, string $disk = 'local'): string
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Catalog');

        $fields = VitFieldDefinition::exportableFields();
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
        $path = "exports/vendor-{$vendor->id}/catalog-{$safeCatalogName}-" . now()->format('Ymd-His') . '.xlsx';

        Storage::disk($disk)->makeDirectory(dirname($path));

        if ($disk === 'local') {
            $fullPath = Storage::disk($disk)->path($path);
            $writer = \PhpOffice\PhpSpreadsheet\IOFactory::createWriter($spreadsheet, 'Xlsx');
            $writer->save($fullPath);
        } else {
            $tempPath = tempnam(sys_get_temp_dir(), 'catalog_export_');
            $writer = \PhpOffice\PhpSpreadsheet\IOFactory::createWriter($spreadsheet, 'Xlsx');
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
        $path = "exports/vendor-{$vendor->id}/catalog-{$safeCatalogName}-" . now()->format('Ymd-His') . '.xlsx';

        Storage::disk($disk)->makeDirectory(dirname($path));

        if ($disk === 'local') {
            $fullPath = Storage::disk($disk)->path($path);
            $writer = \PhpOffice\PhpSpreadsheet\IOFactory::createWriter($spreadsheet, 'Xlsx');
            $writer->save($fullPath);
        } else {
            $tempPath = tempnam(sys_get_temp_dir(), 'catalog_export_');
            $writer = \PhpOffice\PhpSpreadsheet\IOFactory::createWriter($spreadsheet, 'Xlsx');
            $writer->save($tempPath);
            Storage::disk($disk)->put($path, file_get_contents($tempPath));
            unlink($tempPath);
        }

        return $path;
    }

    /**
     * Generate Excel file from ALL current CatalogItems belonging to the
     * submission's vendor.
     *
     * @param  CatalogSubmission  $submission
     * @return string  Relative storage path to the generated .xlsx
     */
    public function generateFromSubmission(CatalogSubmission $submission): string
    {
        $vendor = $submission->vendor;
        $vendorName = is_string($vendor->name) ? $vendor->name : 'Unknown';
        $catalogName = 'Export ' . $vendorName . ' ' . now()->format('M j, Y g:i A');
        $disk = $submission->disk ?? 'local';

        $itemsQuery = CatalogItem::where('vendor_id', $vendor->id)
            ->whereIn('status', ['acceptable', 'excellent'])
            ->orderBy('id');

        return $this->generateAndStoreFromQuery($vendor, $catalogName, $itemsQuery, $disk);
    }

    /**
     * Write the header row using vit_csv_column as the header text.
     * Only exportable fields are written (packed and appended fields are
     * excluded since they don't get their own column).
     */
    private function writeHeaderRow($sheet, Collection $fields): void
    {
        foreach ($fields as $index => $field) {
            $column = $this->columnLetter($index);
            $header = $field->vit_csv_column ?? $field->web_app_label;
            $sheet->setCellValue("{$column}1", $header);
            $sheet->getStyle("{$column}1")->getFont()->setBold(true);
        }
    }

    /**
     * Write a single item row, applying packing and appending logic before
     * the cell values are written.
     */
    private function writeItemRow($sheet, Collection $fields, object $item, Vendor $vendor, int $rowNumber): void
    {
        $resolvedValues = $this->resolveAllValues($fields, $item, $vendor);

        foreach ($fields as $index => $field) {
            $column = $this->columnLetter($index);
            $cell = "{$column}{$rowNumber}";

            try {
                $value = $resolvedValues[$field->field_key] ?? '';
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
     * Resolve all field values for a single row, applying:
     *  - model_attribute mapping (field_key -> Eloquent attribute name)
     *  - packed fields (UNSPSC, MSDS Link, Country of Origin -> classifications)
     *  - appended fields (quantity_per_unit -> appended to shortdescription's value)
     *
     * quantity_per_unit append format: "{quantity} {unit_word}/{unit_of_measure}"
     * e.g. "10 Reams/CS". The unit_word comes from the CatalogItem::unit_word
     * column (defined as a separate VIT field at sort_order 211). If unit_word
     * is not provided but the UOM is, falls back to "{quantity} {uom}".
     */
    private function resolveAllValues(Collection $fields, object $item, Vendor $vendor): array
    {
        $values = [];

        // 1. Collect raw values for all exportable fields.
        // Hierarchy is a selected leaf in the product_hierarchies table. The
        // CatalogItem stores the chosen hierarchy_number (the VIT value) in
        // the scalar hierarchy column, so resolveValue() reads that string
        // directly rather than inventing a display path from unrelated data.
        foreach ($fields as $field) {
            $values[$field->field_key] = $this->resolveValue($field, $item, $vendor);
        }

        // 2. Merge packed fields into their target (classifications).
        $packedFields = VitFieldDefinition::packedFields()
            ->where('packs_into_field', 'classifications');

        if ($packedFields->isNotEmpty()) {
            $classificationsRaw = $values['classifications'] ?? '';
            $classificationParts = $this->extractClassificationParts($classificationsRaw);

            foreach ($packedFields as $packed) {
                $packedValue = $this->resolveValue($packed, $item, $vendor);

                // MSDS link is conditional on Hazmat — only pack if the item
                // is flagged Hazmat. If not Hazmat, skip the MSDS field entirely.
                if ($packed->field_key === 'msds_link') {
                    $isHazmat = $this->hasHazmatClassification($classificationParts);
                    if (! $isHazmat) {
                        continue;
                    }
                }

                if ($packedValue !== '') {
                    $classificationParts[] = "{$packed->packs_into_key}={$packedValue}";
                }
            }

            $classificationsDefinition = VitFieldDefinition::find('classifications');
            $classificationSeparator = $classificationsDefinition?->join_separator ?? '|';

            $values['classifications'] = implode($classificationSeparator, $classificationParts);
        }

        // 3. Apply appended fields (quantity_per_unit onto shortdescription).
        $appendedFields = VitFieldDefinition::appendedFields();

        foreach ($appendedFields as $appended) {
            $appendedValue = $this->resolveValue($appended, $item, $vendor);

            if ($appendedValue !== '') {
                $targetKey = $appended->append_to_field;
                $targetValue = $values[$targetKey] ?? '';

                // Resolve unit_word and unit_of_measure for the full VIT format:
                // "{quantity} {unit_word}/{uom}" e.g. "10 Reams/CS"
                $unitWord = $this->resolveValue(VitFieldDefinition::find('unit_word'), $item, $vendor);
                $uom = $this->resolveValue(VitFieldDefinition::find('unit_of_measure'), $item, $vendor);

                if ($unitWord !== '' && $uom !== '') {
                    $formattedAppend = "{$appendedValue} {$unitWord}/{$uom}";
                } elseif ($uom !== '') {
                    // Fallback: no unit_word provided, just use quantity + UOM
                    $formattedAppend = "{$appendedValue} {$uom}";
                } else {
                    $formattedAppend = (string) $appendedValue;
                }

                $values[$targetKey] = $targetValue !== ''
                    ? "{$targetValue}, {$formattedAppend}"
                    : $formattedAppend;
            }

            // The appended field does NOT get its own column (excluded from
            // exportableFields() because vit_csv_column is null).
            $values[$appended->field_key] = '';
        }

        return $values;
    }

    /**
     * Split the raw classifications value into individual parts.
     * Handles JSON-encoded arrays, pipe-separated strings, and empty values.
     */
    private function extractClassificationParts(string $rawValue): array
    {
        if ($rawValue === '') {
            return [];
        }

        $maybeJson = json_decode($rawValue, true);
        if (is_array($maybeJson)) {
            return array_values(array_filter($maybeJson, fn($part) => trim((string) $part) !== ''));
        }

        if ($rawValue === '' || $rawValue === '[]') {
            return [];
        }

        $classificationSeparator = VitFieldDefinition::find('classifications')?->join_separator ?? '|';

        return array_values(array_filter(explode($classificationSeparator, $rawValue), fn($part) => trim((string) $part) !== ''));
    }

    /**
     * Check whether the classifications data includes "Hazmat".
     */
    private function hasHazmatClassification(array $classificationParts): bool
    {
        foreach ($classificationParts as $part) {
            if (trim((string) $part) === 'Hazmat') {
                return true;
            }
        }

        return false;
    }

    /**
     * Resolve a single VIT field value from the CatalogItem + Vendor.
     *
     * Reads from the model attribute specified by model_attribute
     * (falling back to field_key). System-derived fields like vendor_name
     * are handled specially.
     */
    private function resolveValue(object $field, object $item, Vendor $vendor): string
    {
        $fieldKey = $field->field_key;

        // System-derived fields: generate at export time, not from database
        if ($fieldKey === 'vendor_name') {
            return $vendor->name ?? '';
        }

        if ($fieldKey === 'type') {
            return 'ELINK';
        }

        if ($fieldKey === 'customer_sku' || $fieldKey === 'vendor_sku' || $fieldKey === 'search_sku') {
            return $item->dealer_sku ?? '';
        }

        // Determine the Eloquent attribute to read from CatalogItem.
        // model_attribute in the DEFINITIONS handles all overrides (e.g.
        // shortdescription -> name, image_urls -> images, etc.).
        $attr = $field->model_attribute ?? $fieldKey;

        // TODO: remove this special case for hierarchy once the field def is updated to use model_attribute
        if ($fieldKey === 'hierarchy') {
            $rawValue = $item->hierarchy ?? $item->{$fieldKey} ?? null;
        } else {
            $rawValue = $item->{$attr} ?? $item->{$fieldKey} ?? null;
        }

        if (is_null($rawValue) || $rawValue === '' || $rawValue === []) {
            return '';
        }

        // Multi-value columns are defined in VitFieldDefinition; decode and
        // join them with the field's own separator at export time.
        $multiValueFieldKeys = VitFieldDefinition::all()
            ->where('is_multi_value', true)
            ->pluck('field_key')
            ->all();

        if (in_array($fieldKey, $multiValueFieldKeys, true)) {
            $decoded = is_string($rawValue) ? json_decode($rawValue, true) : $rawValue;

            if (! is_array($decoded) || empty($decoded)) {
                return '';
            }

            $separator = $field->join_separator ?? ',';

            // Check if this is a key/value field (specifications) or normal array
            $isKeyValue = $field->is_key_value ?? false;

            if ($isKeyValue) {
                // Specifications: convert key/value objects to "key=value" strings
                $stringParts = [];
                foreach ($decoded as $entry) {
                    if (is_array($entry) && isset($entry['key']) && isset($entry['value'])) {
                        $key = (string) $entry['key'];
                        $value = $entry['value'];

                        if (is_array($value)) {
                            $stringParts[] = $key . '=' . $this->flattenValueForDisplay($value);
                        } elseif (is_bool($value)) {
                            $stringParts[] = $key . '=' . ($value ? 'TRUE' : 'FALSE');
                        } else {
                            $stringParts[] = $key . '=' . (string) $value;
                        }
                    } elseif (is_array($entry) && array_keys($entry) !== range(0, count($entry) - 1)) {
                        // Legacy associative format
                        foreach ($entry as $key => $value) {
                            if (is_array($value)) {
                                $stringParts[] = (string) $key . '=' . $this->flattenValueForDisplay($value);
                            } elseif (is_bool($value)) {
                                $stringParts[] = (string) $key . '=' . ($value ? 'TRUE' : 'FALSE');
                            } else {
                                $stringParts[] = (string) $key . '=' . (string) $value;
                            }
                        }
                    } elseif (is_string($entry) && str_contains($entry, '=')) {
                        // Legacy indexed "key=value" string
                        $stringParts[] = $entry;
                    }
                }

                return implode($separator, $stringParts);
            } else {
                // Normal array fields: just join the values
                $stringParts = [];
                foreach ($decoded as $entry) {
                    if (is_string($entry)) {
                        $stringParts[] = $entry;
                    } elseif (is_int($entry) || is_float($entry)) {
                        $stringParts[] = (string) $entry;
                    }
                }

                return implode($separator, $stringParts);
            }
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
        // Legacy CatalogItem form object format: ['key' => 'Size', 'value' => 'Large']
        // -> "Size=Large" (the canonical "Key=Value" representation).
        if (array_key_exists('key', $value) && array_key_exists('value', $value)) {
            return (string) $value['key'] . '=' . (string) $value['value'];
        }

        // Any other array shape is not a recognized specification format. Fall
        // back to JSON so it is visibly not a valid "Key=Value" cell value
        // rather than silently emitting a spec-incorrect string.
        return json_encode($value);
    }

    private function columnLetter(int $zeroBasedIndex): string
    {
        return \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($zeroBasedIndex + 1);
    }
}
