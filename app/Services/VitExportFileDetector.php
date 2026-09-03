<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Reader\IReadFilter;
use PhpOffice\PhpSpreadsheet\Reader\IReader;
use PhpOffice\PhpSpreadsheet\Reader\Xlsx as XlsxReader;
use PhpOffice\PhpSpreadsheet\Reader\Xls as XlsReader;

/**
 * Detects whether an uploaded workbook is a VIT-generated catalog export
 * (stamped by CatalogExportService) and, if so, builds the automatic column
 * mappings that let the upload bypass the manual mapper UI.
 *
 * Detection is intentional and conservative:
 *
 *   Valid VIT marker  +  Expected VIT headers  =  eligible for auto-mapping
 *
 * A workbook is only auto-mapped when BOTH the marker (custom document
 * property) and every expected export header are present. Anything else
 * (missing marker, version mismatch, missing/renamed/duplicated headers) falls
 * back to the normal mapper flow.
 *
 * Only fields that are both frontend-importable (VitFieldDefinition::
 * frontendVisible) AND part of the VIT export structure (exportableFields)
 * are auto-mapped. System-derived / export-only columns are intentionally
 * left unmapped, so the existing import pipeline ignores them and the
 * database remains the source of truth for those values.
 */
class VitExportFileDetector
{
    /**
     * Detect a valid VIT-generated export and build its automatic mappings.
     *
     * @param  string  $disk  storage disk holding the uploaded file
     * @param  string  $path  path on disk
     * @param  string  $fileType  'csv' | 'xlsx' | 'xls'
     * @param  array<int, mixed>  $headerRow  header cell values (from CatalogFileInspectionService::inspect)
     * @return array{ mappings: array<int, array{column_index:int, field_key:string, source_column_name:string, source_separator:?string}> }|null
     *         null when the file is not an eligible VIT export
     */
    public function detect(string $disk, string $path, string $fileType, array $headerRow): ?array
    {
        if (! in_array($fileType, ['xlsx', 'xls'], true)) {
            return null;
        }

        if (! $this->hasValidVitMarker($disk, $path, $fileType)) {
            return null;
        }

        return $this->buildAutoMappings($headerRow);
    }

    /**
     * Confirm the workbook carries a valid, current-version VIT export marker.
     */
    private function hasValidVitMarker(string $disk, string $path, string $fileType): bool
    {
        if ($fileType === 'xlsx') {
            return $this->hasValidVitMarkerFromZip($disk, $path);
        }

        return $this->hasValidVitMarkerFromPhpSpreadsheet($disk, $path, $fileType);
    }

    /**
     * For .xlsx files, read docProps/custom.xml directly from the zip archive.
     * This avoids loading the whole workbook through PhpSpreadsheet just to read
     * a couple of custom properties — constant memory regardless of sheet size.
     */
    private function hasValidVitMarkerFromZip(string $disk, string $path): bool
    {
        try {
            $localPath = $this->resolveLocalPath($disk, $path);

            $zip = new \ZipArchive();
            if ($zip->open($localPath) !== true) {
                return false;
            }

            $xml = $zip->getFromName('docProps/custom.xml');
            $zip->close();
            $this->cleanupLocalPath($localPath);

            if ($xml === false) {
                return false;
            }

            $marker = $this->extractCustomPropertyValue($xml, VitFieldDefinition::VIT_EXPORT_MARKER_KEY);
            $version = $this->extractCustomPropertyValue($xml, VitFieldDefinition::VIT_EXPORT_VERSION_KEY);

            return $marker === VitFieldDefinition::VIT_EXPORT_MARKER_VALUE
                && (int) $version === VitFieldDefinition::VIT_EXPORT_VERSION;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Parse a single vt:lpwstr / vt:lpstr / vt:bstr value for the given property
     * name out of docProps/custom.xml. Returns null when absent/unparseable.
     */
    private function extractCustomPropertyValue(string $xml, string $propertyName): ?string
    {
        // Match: <property name="X" fmtId="..." pid="..." vt:lpwstr>VALUE</property>
        $pattern = '#<property\b[^>]*name="' . preg_quote($propertyName, '#') . '"[^>]*>(.*?)</property>#is';

        if (! preg_match($pattern, $xml, $m)) {
            return null;
        }

        $raw = trim($m[1]);

        // The value is typically wrapped in a vt:lpwstr (or vt:lpstr) element.
        if (preg_match('#<vt:lpwstr>(.*?)</vt:lpwstr>#is', $raw, $vm)
            || preg_match('#<vt:lpstr>(.*?)</vt:lpstr>#is', $raw, $vm)
            || preg_match('#<vt:bstr>(.*?)</vt:bstr>#is', $raw, $vm)) {
            return trim(html_entity_decode($vm[1], ENT_QUOTES | ENT_XML1));
        }

        // Fallback: bare text between the property tags.
        return trim(html_entity_decode(strip_tags($raw), ENT_QUOTES | ENT_XML1)) ?: null;
    }

    /**
     * Original PhpSpreadsheet-based marker detection, retained as the .xls
     * fallback (XLS is a binary OLE2 format, not a zip, so direct XML parsing
     * is not applicable).
     */
    private function hasValidVitMarkerFromPhpSpreadsheet(string $disk, string $path, string $fileType): bool
    {
        set_time_limit(300);
        ini_set('memory_limit', '512M');

        $localPath = $this->resolveLocalPath($disk, $path);

        try {
            $reader = $this->makeReader($fileType, $localPath);
            $reader->setReadDataOnly(true);

            $reader->setReadFilter(new class implements IReadFilter {
                public function readCell(string $columnAddress, int $row, string $worksheetName = ''): bool
                {
                    return $row <= 2;
                }
            });

            $spreadsheet = $reader->load($localPath);
            $this->cleanupLocalPath($localPath);
            $localPath = null;

            $properties = $spreadsheet->getProperties();

            $marker = $properties->getCustomPropertyValue(VitFieldDefinition::VIT_EXPORT_MARKER_KEY);
            $version = $properties->getCustomPropertyValue(VitFieldDefinition::VIT_EXPORT_VERSION_KEY);

            return $marker === VitFieldDefinition::VIT_EXPORT_MARKER_VALUE
                && (int) $version === VitFieldDefinition::VIT_EXPORT_VERSION;
        } catch (\Throwable $e) {
            return false;
        } finally {
            $this->cleanupLocalPath($localPath);
        }
    }

    /**
     * Build automatic column mappings from the uploaded header row.
     *
     * A verified VIT export is authoritative for the fields it contains, so
     * ALL active (non-system-derived) exportable VIT fields are auto-mapped,
     * including fields that may be hidden from the manual mapping UI yet carry
     * legitimate data in a VIT export (lead_time, country_of_origin,
     * unspsc_code, msds_link, classifications, image_urls, ...).
     *
     * System-derived / packed / appended fields (vendor, catalog, type, SKU
     * aliases, quantity_per_unit, etc.) are intentionally left unmapped. The
     * existing import pipeline ignores them and the database remains the
     * source of truth.
     *
     * Every expected export header must be present exactly once
     * (case-insensitive, order-independent). Extra columns are tolerated and
     * ignored. On any mismatch this returns null so the upload falls back to
     * the normal manual mapper.
     *
     * @param  array<int, mixed>  $headerRow
     * @return array{ mappings: array<int, array{column_index:int, field_key:string, source_column_name:string, source_separator:?string}> }|null
     */
    private function buildAutoMappings(array $headerRow): ?array
    {
        $normalizedHeaders = [];
        foreach ($headerRow as $index => $name) {
            if ($name === null || trim((string) $name) === '') {
                continue;
            }

            $normalized = mb_strtolower(trim((string) $name));

            // A duplicated header makes column identity ambiguous -> not eligible.
            if (isset($normalizedHeaders[$normalized])) {
                return null;
            }

            $normalizedHeaders[$normalized] = ['index' => (int) $index, 'name' => trim((string) $name)];
        }

        $exportableKeys = VitFieldDefinition::exportableFields()
            ->pluck('field_key')
            ->all();

        $autoMappable = VitFieldDefinition::active()
            ->filter(fn($field) => in_array($field->field_key, $exportableKeys, true));

        $expectedColumns = VitFieldDefinition::exportableFields()
            ->pluck('vit_csv_column')
            ->map(fn($column) => mb_strtolower(trim((string) $column)))
            ->unique()
            ->values();

        // Every expected VIT export header must be present.
        foreach ($expectedColumns as $expected) {
            if (! isset($normalizedHeaders[$expected])) {
                return null;
            }
        }

        $mappings = [];

        foreach ($autoMappable as $field) {
            $header = mb_strtolower(trim((string) $field->vit_csv_column));

            // A frontend-visible field that is exported under a vit_csv_column
            // must exist in the file (already guaranteed by the check above).
            if (! isset($normalizedHeaders[$header])) {
                return null;
            }

            $column = $normalizedHeaders[$header];

            $mappings[] = [
                'column_index' => $column['index'],
                'field_key' => $field->field_key,
                'source_column_name' => $column['name'],
                'source_separator' => $field->is_multi_value
                    ? ($field->join_separator ?? ',')
                    : null,
            ];
        }

        if (empty($mappings)) {
            return null;
        }

        return ['mappings' => $mappings];
    }

    /**
     * Resolve a (possibly remote) storage path to a local file path, streaming
     * to a temp file when the disk is not a local driver.
     */
    private function resolveLocalPath(string $disk, string $path): string
    {
        $storage = Storage::disk($disk);

        if (method_exists($storage->getAdapter(), 'getPathPrefix')) {
            $fullPath = $storage->path($path);

            if (is_file($fullPath)) {
                return $fullPath;
            }
        }

        // Remote adapter (e.g. Spaces/S3): stream to a temporary local copy.
        $tempPath = tempnam(sys_get_temp_dir(), 'vit_');
        file_put_contents($tempPath, $storage->get($path));

        return $tempPath;
    }

    /**
     * Build a reader instance for the given file type.
     */
    private function makeReader(string $fileType, string $localPath): IReader
    {
        return match ($fileType) {
            'xlsx' => new XlsxReader(),
            'xls' => new XlsReader(),
            default => IOFactory::createReaderForFile($localPath),
        };
    }

    /**
     * Remove the temporary local copy when one was created for a remote disk.
     */
    private function cleanupLocalPath(?string $localPath): void
    {
        if ($localPath === null) {
            return;
        }

        // Only delete when the path is a temp file we generated.
        if (str_starts_with($localPath, sys_get_temp_dir())) {
            @unlink($localPath);
        }
    }
}
