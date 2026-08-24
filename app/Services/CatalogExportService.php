<?php

namespace App\Services;

use App\Models\CatalogItem;
use App\Models\CatalogSubmission;
use App\Models\Vendor;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\Spreadsheet;

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
 * Classifications is read directly from CatalogItem.classifications (cast
 * to array) and joined with the field's join_separator — the exporter does
 * not know or care about specific classification keys. Quantity_per_unit is
 * appended onto the end of the name (shortdescription) value rather than
 * getting its own column, formatted as "10 Reams/CS" (quantity + unit_word
 * + "/" + UOM).
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

        $safeCatalogName = $catalogName;
        $path = "exports/vendor-{$vendor->id}/{$safeCatalogName}-" . now()->format('Ymd-His') . '.xlsx';

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
        $catalogName = $vendorName;
        $disk = $submission->disk ?? 'local';

        $itemsQuery = CatalogItem::with(['commodityType', 'hierarchyInfo', 'unitOfMeasure'])
            ->where('vendor_id', $vendor->id)
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

            $value = $resolvedValues[$field->field_key] ?? '';

            $sheet->setCellValueExplicit($cell, $value, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
        }
    }

    /**
     * Resolve all field values for a single row, applying:
     *  - model_attribute mapping (field_key -> Eloquent attribute name)
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
        foreach ($fields as $field) {
            $values[$field->field_key] = $this->resolveValue($field, $item, $vendor);
        }

        // 2. Apply appended fields (quantity_per_unit onto shortdescription).
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
        }

        return $values;
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

        if ($fieldKey === 'catalog_name') {
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
        // shortdescription -> name, image_urls -> images, hierarchy ->
        // hierarchyInfo.hierarchy_number, unit_of_measure ->
        // unitOfMeasure.code, product_commodity_type -> commodityType.name,
        // item_weight_in_pounds -> item_weight, country_of_origin ->
        // countryOfOrigin accessor).
        $attr = $field->model_attribute ?? $fieldKey;

        $rawValue = data_get($item, $attr);

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
