<?php

namespace App\Services;

use App\Models\CatalogItem;
use App\Models\CatalogSubmission;
use App\Models\Vendor;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;

/**
 * Generates a VIT-compliant .xlsx file from catalog submission data.
 *
 * Column headers and order are driven by VitFieldDefinition::exportableFields(),
 * which returns only fields that get their own column in the output Excel file.
 *
 * Header text uses vit_csv_column, falling back to web_app_label.
 *
 * Values are resolved from CatalogItem using model_attribute, falling back
 * to field_key when no model_attribute is defined.
 *
 * Special handling includes:
 * - System-derived fields such as vendor_name, catalog_name and type.
 * - SKU fields that resolve from dealer_sku.
 * - Multi-value fields.
 * - Key/value fields such as specifications.
 * - Appended fields such as quantity_per_unit.
 */
class CatalogExportService
{
    /**
     * Generate an Excel spreadsheet from a collection of catalog items.
     */
    public function generate(
        Vendor $vendor,
        string $catalogName,
        Collection $items
    ): Spreadsheet {
        $fields = VitFieldDefinition::exportableFields();

        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Catalog');

        $this->writeHeaderRow($sheet, $fields);

        $rowNumber = 2;

        foreach ($items as $item) {
            $this->writeItemRow(
                $sheet,
                $fields,
                $item,
                $vendor,
                $rowNumber
            );

            $rowNumber++;
        }

        $this->autoSizeColumns($sheet, $fields);

        return $spreadsheet;
    }

    /**
     * Generate Excel from a query cursor for memory-efficient large-catalog support.
     *
     * @param Vendor $vendor
     * @param string $catalogName
     * @param mixed $itemsQuery
     * @param string $disk
     * @return string
     */
    public function generateAndStoreFromQuery(
        Vendor $vendor,
        string $catalogName,
        $itemsQuery,
        string $disk = 'local'
    ): string {
        $fields = VitFieldDefinition::exportableFields();

        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Catalog');

        $this->writeHeaderRow($sheet, $fields);

        $rowNumber = 2;

        foreach ($itemsQuery->cursor() as $item) {
            $this->writeItemRow(
                $sheet,
                $fields,
                $item,
                $vendor,
                $rowNumber
            );

            $rowNumber++;
        }

        $this->autoSizeColumns($sheet, $fields);

        $path = $this->buildExportPath(
            $vendor,
            $catalogName
        );

        $this->storeSpreadsheet(
            $spreadsheet,
            $path,
            $disk
        );

        return $path;
    }

    /**
     * Generate Excel file from all current CatalogItems belonging to the
     * submission's vendor.
     *
     * @return string Relative storage path to the generated .xlsx
     */
    public function generateFromSubmission(
        CatalogSubmission $submission
    ): string {
        $vendor = $submission->vendor;
        $catalog = $submission->catalog;

        $vendorName = is_string($vendor->name)
            ? $vendor->name
            : 'Unknown';

        $catalogName = $vendorName . '-' . $catalog->name;
        $disk = $submission->disk ?? config('filesystems.default');

        $itemsQuery = CatalogItem::with([
            'commodityType',
            'hierarchyInfo',
            'unitOfMeasure',
            'catalog',
        ])
            ->where('vendor_id', $vendor->id)
            ->whereIn('status', ['acceptable', 'excellent'])
            ->whereNull('last_submitted_at')
            ->orderBy('id');

        $path = $this->generateAndStoreFromQuery(
            $vendor,
            $catalogName,
            $itemsQuery,
            $disk
        );

        // Stamp the submitted items directly (bypassing the updated_at
        // timestamp) so their submission state can be tracked without
        // being flagged as "modified" by the stamp itself.
        DB::table('catalog_items')
            ->where('vendor_id', $vendor->id)
            ->whereIn('status', ['acceptable', 'excellent'])
            ->whereNull('last_submitted_at')
            ->update(['last_submitted_at' => now()]);

        return $path;
    }

    /**
     * Write the header row using vit_csv_column as the header text.
     *
     * Only exportable fields are written. Packed and appended fields that
     * do not have their own output column are excluded by exportableFields().
     */
    private function writeHeaderRow(
        object $sheet,
        Collection $fields
    ): void {
        foreach ($fields as $index => $field) {
            $column = $this->columnLetter($index);

            $header = $field->vit_csv_column
                ?? $field->web_app_label;

            $sheet->setCellValue(
                "{$column}1",
                $header
            );

            $sheet
                ->getStyle("{$column}1")
                ->getFont()
                ->setBold(true);
        }
    }

    /**
     * Write a single item row, applying packing and appended-field logic
     * before the cell values are written.
     */
    private function writeItemRow(
        object $sheet,
        Collection $fields,
        object $item,
        Vendor $vendor,
        int $rowNumber
    ): void {
        $resolvedValues = $this->resolveAllValues(
            $fields,
            $item,
            $vendor
        );

        foreach ($fields as $index => $field) {
            $column = $this->columnLetter($index);
            $cell = "{$column}{$rowNumber}";

            $value = $resolvedValues[$field->field_key] ?? '';

            $sheet->setCellValueExplicit(
                $cell,
                $value,
                DataType::TYPE_STRING
            );
        }
    }

    /**
     * Automatically size all exported columns.
     */
    private function autoSizeColumns(
        object $sheet,
        Collection $fields
    ): void {
        foreach ($fields as $index => $field) {
            $column = $this->columnLetter($index);

            $sheet
                ->getColumnDimension($column)
                ->setAutoSize(true);
        }
    }

    /**
     * Build the storage path for the generated catalog export.
     */
    private function buildExportPath(
        Vendor $vendor,
        string $catalogName
    ): string {
        return "inbound/{$vendor->name}/"
            . "{$catalogName}-"
            . now()->format('Ymd-His')
            . '.xlsx';
    }

    /**
     * Store the generated spreadsheet.
     *
     * Local disks are written directly to their filesystem path.
     *
     * Remote disks use a temporary local file and stream that file to
     * storage so the entire XLSX does not need to be loaded into PHP memory.
     */
    private function storeSpreadsheet(
        Spreadsheet $spreadsheet,
        string $path,
        string $disk
    ): void {
        Storage::disk($disk)->makeDirectory(
            dirname($path)
        );

        $writer = IOFactory::createWriter(
            $spreadsheet,
            'Xlsx'
        );

        if ($disk === 'local') {
            $writer->save(
                Storage::disk($disk)->path($path)
            );

            return;
        }

        $this->storeRemoteSpreadsheet(
            $writer,
            $path,
            $disk
        );
    }

    /**
     * Store a generated spreadsheet on a remote filesystem.
     *
     * The XLSX is first written to a temporary local file.
     * That file is then streamed to the configured filesystem instead
     * of being loaded entirely into PHP memory.
     */
    private function storeRemoteSpreadsheet(
        object $writer,
        string $path,
        string $disk
    ): void {
        $tempPath = tempnam(
            sys_get_temp_dir(),
            'catalog_export_'
        );

        if ($tempPath === false) {
            throw new \RuntimeException(
                'Unable to create temporary file for catalog export.'
            );
        }

        try {
            $writer->save($tempPath);

            $stream = fopen(
                $tempPath,
                'rb'
            );

            if ($stream === false) {
                throw new \RuntimeException(
                    'Unable to open temporary catalog export for reading.'
                );
            }

            try {
                $stored = Storage::disk($disk)->writeStream(
                    $path,
                    $stream
                );

                if ($stored === false) {
                    throw new \RuntimeException(
                        'Unable to store catalog export on the configured disk.'
                    );
                }
            } finally {
                fclose($stream);
            }
        } finally {
            if (is_file($tempPath)) {
                @unlink($tempPath);
            }
        }
    }

    /**
     * Resolve all field values for a single catalog item.
     *
     * Appended fields are applied after the normal field values have been
     * resolved so they can modify their target field.
     */
    private function resolveAllValues(
        Collection $fields,
        object $item,
        Vendor $vendor
    ): array {
        $values = [];

        /*
         * 1. Collect raw values for all exportable fields.
         */
        foreach ($fields as $field) {
            $values[$field->field_key] = $this->resolveValue(
                $field,
                $item,
                $vendor
            );
        }

        /*
         * 2. Apply appended fields such as quantity_per_unit.
         */
        $appendedFields = VitFieldDefinition::appendedFields();

        foreach ($appendedFields as $appended) {
            $appendedValue = $this->resolveValue(
                $appended,
                $item,
                $vendor
            );

            if ($appendedValue === '') {
                continue;
            }

            $targetKey = $appended->append_to_field;
            $targetValue = $values[$targetKey] ?? '';

            /*
             * Resolve unit_word and unit_of_measure for the full VIT format:
             *
             * {quantity} {unit_word}/{uom}
             *
             * Example:
             * 10 Reams/CS
             */
            $unitWord = $this->resolveValue(
                VitFieldDefinition::find('unit_word'),
                $item,
                $vendor
            );

            $uom = $this->resolveValue(
                VitFieldDefinition::find('unit_of_measure'),
                $item,
                $vendor
            );

            if ($unitWord !== '' && $uom !== '') {
                $formattedAppend =
                    "{$appendedValue} {$unitWord}/{$uom}";
            } elseif ($uom !== '') {
                /*
                 * Fallback when no unit_word is available.
                 */
                $formattedAppend =
                    "{$appendedValue} {$uom}";
            } else {
                $formattedAppend =
                    (string) $appendedValue;
            }

            $values[$targetKey] = $targetValue !== ''
                ? "{$targetValue}, {$formattedAppend}"
                : $formattedAppend;
        }

        return $values;
    }

    /**
     * Resolve a single VIT field value from CatalogItem and Vendor.
     *
     * System-derived fields such as vendor_name are handled specially.
     */
    private function resolveValue(
        object $field,
        object $item,
        Vendor $vendor
    ): string {
        $fieldKey = $field->field_key;

        /*
         * System-derived fields are generated during export rather than
         * read directly from the database.
         */
        if ($fieldKey === 'vendor_name') {
            return $vendor->name ?? '';
        }

        if ($fieldKey === 'catalog_name') {
            return $item->catalog?->name ?? '';
        }

        if ($fieldKey === 'type') {
            return 'ELINK';
        }

        /*
         * These three VIT fields intentionally all use dealer_sku.
         */
        if (
            $fieldKey === 'customer_sku'
            || $fieldKey === 'vendor_sku'
            || $fieldKey === 'search_sku'
        ) {
            return $item->dealer_sku ?? '';
        }

        /*
         * model_attribute in VitFieldDefinition handles field-specific
         * model relationships and attribute overrides.
         *
         * Examples:
         * - shortdescription -> name
         * - image_urls -> images
         * - hierarchy -> hierarchyInfo.hierarchy_number
         * - unit_of_measure -> unitOfMeasure.code
         * - product_commodity_type -> commodityType.name
         * - item_weight_in_pounds -> item_weight
         * - country_of_origin -> countryOfOrigin accessor
         */
        $attribute = $field->model_attribute
            ?? $fieldKey;

        $rawValue = data_get(
            $item,
            $attribute
        );

        if (
            is_null($rawValue)
            || $rawValue === ''
            || $rawValue === []
        ) {
            return '';
        }

        /*
         * Multi-value columns are defined by VitFieldDefinition.
         * Values are decoded and joined using the field's configured
         * export separator.
         */
        $multiValueFieldKeys = VitFieldDefinition::all()
            ->where('is_multi_value', true)
            ->pluck('field_key')
            ->all();

        if (
            in_array(
                $fieldKey,
                $multiValueFieldKeys,
                true
            )
        ) {
            return $this->formatMultiValueValue(
                $field,
                $rawValue
            );
        }

        if (is_bool($rawValue)) {
            return $rawValue
                ? 'TRUE'
                : 'FALSE';
        }

        return (string) $rawValue;
    }

    /**
     * Format a multi-value field according to its definition.
     *
     * Key/value fields such as specifications are converted to:
     *
     * key=value
     *
     * Normal array fields are simply joined using the field's configured
     * separator.
     */
    private function formatMultiValueValue(
        object $field,
        mixed $rawValue
    ): string {
        $decoded = is_string($rawValue)
            ? json_decode($rawValue, true)
            : $rawValue;

        if (
            !is_array($decoded)
            || empty($decoded)
        ) {
            return '';
        }

        $separator = $field->join_separator ?? ',';

        if ($field->is_key_value ?? false) {
            return $this->formatKeyValueValues(
                $decoded,
                $separator
            );
        }

        return $this->formatArrayValues(
            $decoded,
            $separator
        );
    }

    /**
     * Format a normal array-based multi-value field.
     */
    private function formatArrayValues(
        array $values,
        string $separator
    ): string {
        $stringParts = [];

        foreach ($values as $entry) {
            if (is_string($entry)) {
                $stringParts[] = $entry;

                continue;
            }

            if (
                is_int($entry)
                || is_float($entry)
            ) {
                $stringParts[] = (string) $entry;
            }
        }

        return implode(
            $separator,
            $stringParts
        );
    }

    /**
     * Format a key/value field such as specifications.
     *
     * Supports:
     *
     * 1. Canonical:
     *    ['key' => 'Size', 'value' => 'Large']
     *
     * 2. Legacy associative arrays.
     *
     * 3. Legacy "key=value" strings.
     */
    private function formatKeyValueValues(
        array $values,
        string $separator
    ): string {
        $stringParts = [];

        foreach ($values as $entry) {
            /*
             * Canonical key/value object.
             */
            if (
                is_array($entry)
                && isset(
                    $entry['key'],
                    $entry['value']
                )
            ) {
                $key = (string) $entry['key'];
                $value = $entry['value'];

                if (is_array($value)) {
                    $value = $this->flattenValueForDisplay(
                        $value
                    );
                } elseif (is_bool($value)) {
                    $value = $value
                        ? 'TRUE'
                        : 'FALSE';
                } else {
                    $value = (string) $value;
                }

                $stringParts[] =
                    $key . '=' . $value;

                continue;
            }

            /*
             * Legacy associative format.
             */
            if (
                is_array($entry)
                && array_keys($entry) !== range(
                    0,
                    count($entry) - 1
                )
            ) {
                foreach ($entry as $key => $value) {
                    if (is_array($value)) {
                        $value =
                            $this->flattenValueForDisplay(
                                $value
                            );
                    } elseif (is_bool($value)) {
                        $value = $value
                            ? 'TRUE'
                            : 'FALSE';
                    } else {
                        $value = (string) $value;
                    }

                    $stringParts[] =
                        (string) $key . '=' . $value;
                }

                continue;
            }

            /*
             * Legacy indexed "key=value" string.
             */
            if (
                is_string($entry)
                && str_contains($entry, '=')
            ) {
                $stringParts[] = $entry;
            }
        }

        return implode(
            $separator,
            $stringParts
        );
    }

    /**
     * Convert a nested array/object value into a readable string.
     *
     * Canonical format:
     *
     * ['key' => 'Size', 'value' => 'Large']
     *
     * becomes:
     *
     * Size=Large
     *
     * Unknown array structures are preserved as JSON.
     */
    private function flattenValueForDisplay(
        array $value
    ): string {
        if (
            array_key_exists('key', $value)
            && array_key_exists('value', $value)
        ) {
            return (string) $value['key']
                . '='
                . (string) $value['value'];
        }

        return json_encode($value);
    }

    /**
     * Convert a zero-based field index into an Excel column letter.
     */
    private function columnLetter(
        int $zeroBasedIndex
    ): string {
        return Coordinate::stringFromColumnIndex(
            $zeroBasedIndex + 1
        );
    }
}
