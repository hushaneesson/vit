<?php

namespace App\Services\Excel;

use App\Models\CatalogFieldDefinition;
use App\Models\CatalogItem;
use App\Models\Vendor;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\Shared\Drawing as SharedDrawing;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Drawing;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * Builds a VIT-compliant .xlsx workbook column-by-column from
 * catalog_field_definitions (Phase 7). Column order, header text, and join
 * rules all come from that table — nothing here is hardcoded per-column,
 * so an admin changing a field definition changes the generated file with
 * no code deploy required.
 *
 * The one exception is the `vendor` column, which is not sourced from a
 * catalog field at all: it's pulled directly from the vendors row (the
 * admin-set, registered/activated marketplace name), per the VIT spec.
 *
 * Product images are embedded as real images (not URLs/filenames), stacked
 * vertically within the single "product_images" column cell for each
 * product row, per the confirmed layout decision.
 */
class CatalogExcelGenerator
{
    protected const VENDOR_FIELD_KEY = 'vendor_name';

    protected const IMAGE_FIELD_KEY = 'product_images';

    /**
     * @param  Collection<int, CatalogItem>  $items
     */
    public function generate(Vendor $vendor, string $catalogName, Collection $items): Spreadsheet
    {
        $fields = $this->resolveWorkbookColumns();

        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Catalog');

        $this->writeHeaderRow($sheet, $fields);

        $imageSize = (int) config('vit.excel.image_size', 400);
        $imagePadding = (int) config('vit.excel.image_padding', 8);

        $rowNumber = 2;

        foreach ($items as $item) {
            $this->writeItemRow($sheet, $fields, $item, $vendor, $rowNumber, $imageSize, $imagePadding);
            $rowNumber++;
        }

        $this->autoSizeNonImageColumns($sheet, $fields);

        return $spreadsheet;
    }

    /**
     * Generate the workbook and persist it to Laravel Storage, returning
     * the relative storage path (Phase 9 uses this to create the
     * submissions record).
     *
     * @param  Collection<int, CatalogItem>  $items
     */
    public function generateAndStore(Vendor $vendor, string $catalogName, Collection $items, string $disk = 'local'): string
    {
        $spreadsheet = $this->generate($vendor, $catalogName, $items);

        $safeCatalogName = \Illuminate\Support\Str::slug($catalogName);
        $path = "submissions/vendor-{$vendor->id}/catalog-{$safeCatalogName}-".now()->format('YmdHis').'.xlsx';

        $fullPath = Storage::disk($disk)->path($path);
        Storage::disk($disk)->makeDirectory(dirname($path));

        $writer = new Xlsx($spreadsheet);
        $writer->save($fullPath);

        return $path;
    }

    /**
     * Only fields that map to a real VIT column belong in the workbook.
     * Fields whose vit_column_header is a descriptive placeholder (wrapped
     * in parentheses, e.g. "(feeds hierarchy path)") only feed into another
     * real column's computed value and are skipped here to avoid duplicate
     * / phantom columns.
     *
     * @return Collection<int, CatalogFieldDefinition>
     */
    protected function resolveWorkbookColumns(): Collection
    {
        return CatalogFieldDefinition::query()
            ->active()
            ->ordered()
            ->get()
            ->reject(fn (CatalogFieldDefinition $field) => str_starts_with($field->vit_column_header, '('))
            ->unique('vit_column_header')
            ->values();
    }

    protected function writeHeaderRow($sheet, Collection $fields): void
    {
        foreach ($fields as $index => $field) {
            $column = $this->columnLetter($index);
            $sheet->setCellValue("{$column}1", $field->vit_column_header);
            $sheet->getStyle("{$column}1")->getFont()->setBold(true);
        }
    }

    protected function writeItemRow($sheet, Collection $fields, CatalogItem $item, Vendor $vendor, int $rowNumber, int $imageSize, int $imagePadding): void
    {
        $maxImageCount = 0;

        foreach ($fields as $index => $field) {
            $column = $this->columnLetter($index);
            $cell = "{$column}{$rowNumber}";

            if ($field->field_key === self::IMAGE_FIELD_KEY) {
                $imageCount = $this->embedImages($sheet, $item, $column, $rowNumber, $imageSize, $imagePadding);
                $maxImageCount = max($maxImageCount, $imageCount);

                continue;
            }

            $sheet->setCellValueExplicit($cell, $this->resolveValue($field, $item, $vendor), \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
        }

        if ($maxImageCount > 0) {
            $totalPixels = ($maxImageCount * $imageSize) + (($maxImageCount - 1) * $imagePadding);
            $sheet->getRowDimension($rowNumber)->setRowHeight(
                SharedDrawing::pixelsToPoints($totalPixels)
            );
        }
    }

    protected function resolveValue(CatalogFieldDefinition $field, CatalogItem $item, Vendor $vendor): string
    {
        if ($field->field_key === self::VENDOR_FIELD_KEY) {
            return (string) $vendor->name;
        }

        $value = $item->field_values[$field->field_key] ?? '';

        return is_bool($value) ? ($value ? 'TRUE' : 'FALSE') : (string) $value;
    }

    /**
     * Embed each of the product's uploaded images as an actual image object
     * anchored to this row's image column, stacked vertically within the
     * single cell (per the confirmed layout: all images on top of each
     * other in one "Product Images" column, with a taller row height).
     *
     * @return int number of images embedded (used to compute row height)
     */
    protected function embedImages($sheet, CatalogItem $item, string $column, int $rowNumber, int $imageSize, int $imagePadding): int
    {
        $images = $item->images;

        foreach ($images as $index => $image) {
            $absolutePath = Storage::disk($image->disk)->path($image->path);

            if (! is_file($absolutePath)) {
                continue;
            }

            $drawing = new Drawing;
            $drawing->setName('Product Image '.($index + 1));
            $drawing->setDescription($item->vendor_part_number.' - image '.($index + 1));
            $drawing->setPath($absolutePath);
            $drawing->setWidth($imageSize);
            $drawing->setHeight($imageSize);
            $drawing->setCoordinates("{$column}{$rowNumber}");
            $drawing->setOffsetY($index * ($imageSize + $imagePadding));
            $drawing->setWorksheet($sheet);
        }

        return $images->count();
    }

    protected function autoSizeNonImageColumns($sheet, Collection $fields): void
    {
        $imageSize = (int) config('vit.excel.image_size', 400);

        foreach ($fields as $index => $field) {
            $column = $this->columnLetter($index);

            if ($field->field_key === self::IMAGE_FIELD_KEY) {
                // Approximate character width from target pixel size so the
                // embedded images are fully visible within the column.
                $sheet->getColumnDimension($column)->setWidth(round($imageSize / 7));

                continue;
            }

            $sheet->getColumnDimension($column)->setAutoSize(true);
        }
    }

    protected function columnLetter(int $zeroBasedIndex): string
    {
        return \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($zeroBasedIndex + 1);
    }
}
