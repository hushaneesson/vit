<?php

namespace App\Services\Catalog;

use App\Models\CatalogItem;
use App\Models\CatalogSubmission;
use App\Models\Vendor;
use App\Services\VitFieldDefinition;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Options as XlsxWriterOptions;
use OpenSpout\Writer\XLSX\Properties as XlsxWriterProperties;
use OpenSpout\Writer\XLSX\Writer as XlsxWriter;

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
     * Hoist static export metadata once per export so it is not recomputed
     * per field per row. Resolved export values are identical either way.
     *
     * @return array{
     *     headers: list<string>,
     *     multiValueFieldKeys: list<string>,
     *     appendedFields: Collection,
     *     unitWordField: object|null,
     *     unitOfMeasureField: object|null
     * }
     */
    private function buildHoistedExportMetadata(): array
    {
        $headers = [];

        foreach (VitFieldDefinition::exportableFields() as $field) {
            $headers[] = (string) ($field->vit_csv_column
                ?? $field->web_app_label);
        }

        return [
            'headers' => $headers,
            'multiValueFieldKeys' => VitFieldDefinition::all()
                ->where('is_multi_value', true)
                ->pluck('field_key')
                ->all(),
            'appendedFields' => VitFieldDefinition::appendedFields(),
            'unitWordField' => VitFieldDefinition::find('unit_word'),
            'unitOfMeasureField' => VitFieldDefinition::find('unit_of_measure'),
        ];
    }

    /**
     * Write the header row: vit_csv_column (falling back to web_app_label)
     * for every exportable field, bold via a single row style.
     *
     * @param list<string> $headers
     */
    private function writeHeaderRowToWriter(
        XlsxWriter $writer,
        array $headers
    ): void {
        $writer->addRow(
            Row::fromValues($headers, (new Style())->setFontBold())
        );
    }

    /**
     * Write item rows to an open OpenSpout writer. Values are resolved and
     * written one item at a time, matching the previous explicit string-cell
     * behavior (SKUs, leading zeros and numeric-looking values are never
     * reinterpreted by Excel).
     */
    private function writeItemsToWriter(
        XlsxWriter $writer,
        Collection $fields,
        iterable $items,
        Vendor $vendor,
        array $hoisted
    ): void {
        foreach ($items as $item) {
            $resolvedValues = $this->resolveAllValues(
                $fields,
                $item,
                $vendor,
                $hoisted['appendedFields'],
                $hoisted['unitWordField'],
                $hoisted['unitOfMeasureField'],
                $hoisted['multiValueFieldKeys']
            );

            $row = [];
            foreach ($fields as $field) {
                $row[] = (string) ($resolvedValues[$field->field_key] ?? '');
            }

            $writer->addRow(
                Row::fromValues($row)
            );
        }
    }

    /**
     * Generate and store the Excel export from a query using OpenSpout
     * streaming.
     *
     * The XLSX is written incrementally by OpenSpout, which streams rows to
     * disk rather than buffering the whole workbook in memory. The database
     * is iterated with chunkById(500) so only one chunk of hydrated models
     * and their eager-loaded relations is held in memory at a time, keeping
     * memory bounded regardless of catalog size.
     *
     * Exports with 50,000+ rows complete with approximately constant memory.
     *
     * @param Vendor $vendor
     * @param string $catalogName
     * @param mixed $itemsQuery Eloquent query with eager-loaded relationships
     * @param string $disk
     * @return string
     */
    public function generateAndStoreStreamingFromQuery(
        Vendor $vendor,
        string $catalogName,
        $itemsQuery,
        string $disk = 'local'
    ): string {
        $fields = VitFieldDefinition::exportableFields();
        $hoisted = $this->buildHoistedExportMetadata();

        $destinationPath = $this->buildExportPath(
            $vendor,
            $catalogName
        );

        $tempPath = $this->createTempFilePath(
            $destinationPath,
            $disk
        );

        $writer = new XlsxWriter(
            $this->buildXlsxWriterOptions()
        );

        try {
            $writer->openToFile($tempPath);

            try {
                $writer->getCurrentSheet()->setName('Catalog');
            } catch (\Throwable $e) {
                // Sheet title is cosmetic; never fail an export over it.
                unset($e);
            }

            /*
             * Header row: same headers and order as always, bold via a
             * single row style.
             */
            $this->writeHeaderRowToWriter($writer, $hoisted['headers']);

            /*
             * Stream every item into the workbook immediately.
             * chunkById(500) keeps eager loading intact and bounds the
             * number of hydrated models held in memory.
             */
            $itemsQuery->chunkById(
                500,
                function ($items) use ($writer, $fields, $vendor, $hoisted): void {
                    $this->writeItemsToWriter(
                        $writer,
                        $fields,
                        $items,
                        $vendor,
                        $hoisted
                    );
                }
            );

            $writer->close();

            $this->storeExportFile(
                $tempPath,
                $destinationPath,
                $disk
            );

            return $destinationPath;
        } catch (\Throwable $e) {
            /*
             * Remove any partially written destination so the job's
             * idempotency check cannot mistake it for a valid export.
             */
            try {
                Storage::disk($disk)->delete($destinationPath);
            } catch (\Throwable $ignored) {
                unset($ignored);
            }

            throw $e;
        } finally {
            if (is_file($tempPath)) {
                @unlink($tempPath);
            }
        }
    }

    /**
     * Generate the VIT Excel file from the current CatalogItems belonging to
     * the submission's catalog.
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

        /*
         * Export only items belonging to the submission's catalog. The
         * catalog-scoped architecture is the source of truth: submissions
         * are always created against a single catalog, and exporting
         * vendor-wide could leak a sibling catalog's items into this file.
         */
        $itemsQuery = CatalogItem::with([
            'commodityType',
            'hierarchyInfo',
            'unitOfMeasure',
            'catalog',
        ])
            ->where('vendor_id', $vendor->id)
            ->where('catalog_id', $submission->catalog_id)
            ->whereIn('status', ['acceptable', 'excellent'])
            ->whereNull('last_submitted_at')
            ->orderBy('id');

        $path = $this->generateAndStoreStreamingFromQuery(
            $vendor,
            $catalogName,
            $itemsQuery,
            $disk
        );

        /*
         * Stamp the same item set that was exported. This runs after the
         * file is stored; if it fails, remove the just-created export so a
         * later retry regenerates it cleanly and the items are not re-sent
         * to VIT.
         */
        try {
            DB::table('catalog_items')
                ->where('vendor_id', $vendor->id)
                ->where('catalog_id', $submission->catalog_id)
                ->whereIn('status', ['acceptable', 'excellent'])
                ->whereNull('last_submitted_at')
                ->update(['last_submitted_at' => now()]);
        } catch (\Throwable $e) {
            try {
                Storage::disk($disk)->delete($path);
            } catch (\Throwable $ignored) {
                unset($ignored);
            }

            throw $e;
        }

        return $path;
    }

    /**
     * Build the storage path for the generated catalog export.
     *
     * Both the vendor folder and the file stem are slugified so raw vendor/
     * catalog names cannot introduce path separators, traversal (..), or
     * other filesystem-sensitive characters. A short random suffix keeps
     * same-second exports for the same vendor/catalog from colliding.
     */
    private function buildExportPath(
        Vendor $vendor,
        string $catalogName
    ): string {
        $folderName = Str::slug($vendor->name) ?: 'vendor-' . $vendor->id;

        $fileStem = Str::slug($catalogName) ?: 'catalog';

        return "inbound/{$folderName}/"
            . "{$fileStem}-"
            . now()->format('Ymd-His')
            . '-' . Str::lower(Str::random(8))
            . '.xlsx';
    }

    /**
     * Create a temporary file for the export that ends up on the same
     * filesystem as its final destination when possible, so the final
     * rename() in storeExportFile() stays atomic even when the OS temp
     * directory lives on a different filesystem (e.g. Docker/Kubernetes
     * mounted volumes). Remote disks have no local destination path, so
     * the OS temp directory is used and the file is streamed instead.
     */
    private function createTempFilePath(
        string $destinationPath,
        string $disk
    ): string {
        $directory = sys_get_temp_dir();

        if ($disk === 'local') {
            $destinationDir = dirname($destinationPath);

            Storage::disk($disk)->makeDirectory($destinationDir);

            $directory = Storage::disk($disk)->path($destinationDir);
        }

        $tempPath = tempnam($directory, 'catalog_export_');

        if ($tempPath === false) {
            throw new \RuntimeException(
                'Unable to create temporary file for catalog export.'
            );
        }

        return $tempPath;
    }

    /**
     * Build the XLSX writer options, including the VIT export marker as
     * custom document properties.
     *
     * OpenSpout writes custom properties to docProps/custom.xml as
     * vt:lpwstr values. The re-upload detector (VitExportFileDetector)
     * parses vt:lpwstr and casts the version to int, so the marker stays
     * fully compatible with the previous PhpSpreadsheet output.
     */
    private function buildXlsxWriterOptions(): XlsxWriterOptions
    {
        $options = new XlsxWriterOptions();

        $options->setProperties(
            new XlsxWriterProperties(
                customProperties: [
                    VitFieldDefinition::VIT_EXPORT_MARKER_KEY
                    => VitFieldDefinition::VIT_EXPORT_MARKER_VALUE,

                    VitFieldDefinition::VIT_EXPORT_VERSION_KEY
                    => (string) VitFieldDefinition::VIT_EXPORT_VERSION,
                ]
            )
        );

        return $options;
    }

    /**
     * Move/stream the completed XLSX into its storage destination.
     *
     * The temp file is never loaded into PHP memory: local disks use an
     * atomic rename and remote disks stream the file to storage.
     */
    private function storeExportFile(
        string $tempPath,
        string $path,
        string $disk
    ): void {
        $storage = Storage::disk($disk);

        $storage->makeDirectory(
            dirname($path)
        );

        if ($disk === 'local') {
            /*
             * rename() on the same filesystem is atomic; no partial
             * destination file can appear.
             */
            if (!@rename($tempPath, $storage->path($path))) {
                throw new \RuntimeException(
                    'Unable to store catalog export on the configured disk.'
                );
            }

            return;
        }

        $stream = fopen($tempPath, 'rb');

        if ($stream === false) {
            throw new \RuntimeException(
                'Unable to open temporary catalog export for reading.'
            );
        }

        try {
            $stored = $storage->writeStream($path, $stream);

            if ($stored === false) {
                throw new \RuntimeException(
                    'Unable to store catalog export on the configured disk.'
                );
            }
        } finally {
            fclose($stream);
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
        Vendor $vendor,
        Collection $appendedFields,
        object $unitWordField,
        object $unitOfMeasureField,
        array $multiValueFieldKeys
    ): array {
        $values = [];

        /*
         * 1. Collect raw values for all exportable fields.
         */
        foreach ($fields as $field) {
            $values[$field->field_key] = $this->resolveValue(
                $field,
                $item,
                $vendor,
                $multiValueFieldKeys
            );
        }

        /*
         * 2. Apply appended fields such as quantity_per_unit.
         */
        foreach ($appendedFields as $appended) {
            $appendedValue = $this->resolveValue(
                $appended,
                $item,
                $vendor,
                $multiValueFieldKeys
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
                $unitWordField,
                $item,
                $vendor,
                $multiValueFieldKeys
            );

            $uom = $this->resolveValue(
                $unitOfMeasureField,
                $item,
                $vendor,
                $multiValueFieldKeys
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
        Vendor $vendor,
        array $multiValueFieldKeys
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
     * The processing pipeline always normalizes key/value fields into the
     * canonical ['key' => .., 'value' => ..] structure before storage, so
     * only that structure is handled here.
     */
    private function formatKeyValueValues(
        array $values,
        string $separator
    ): string {
        $stringParts = [];

        foreach ($values as $entry) {
            /*
             * Canonical key/value structure. The processing pipeline always
             * stores key/value fields (e.g. specifications, classifications)
             * in this normalized form, so no legacy formats need to be parsed
             * here.
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
}
