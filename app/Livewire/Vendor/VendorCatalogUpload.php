<?php

namespace App\Livewire\Vendor;

use App\Enums\CatalogUploadStatus;
use App\Jobs\ProcessCatalogUploadJob;
use App\Models\CatalogUpload;
use App\Models\CatalogUploadColumnMapping;
use App\Models\VendorMappingTemplate;
use App\Services\Catalog\CatalogFileInspectionService;
use App\Services\VitExportFileDetector;
use App\Services\VitFieldDefinition;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Component;
use Livewire\WithFileUploads;

class VendorCatalogUpload extends Component
{
    use WithFileUploads;

    private const DISK = 'spaces';

    public string $step = 'upload';

    public $file;

    public ?int $catalogUploadId = null;

    /**
     * Catalog being used during this catalog-specific batch upload.
     * Populated from the catalog upload route so the generated
     * CatalogUpload (and the CatalogItems derived from it) can be
     * associated with the correct catalog.
     */
    public ?int $catalogId = null;

    public array $columns = [];
    public array $sampleRows = [];
    public array $mapping = [];
    public array $suggestedIndexes = [];

    // field_key => separator for multi-value fields
    public array $separators = [];

    /**
     * Mapper-level unit selector for the item_weight_in_pounds field. Stored
     * on the item_weight mapping row's source_separator column as mapper
     * configuration (matches the existing per-field config pattern).
     */
    public string $weightUnit = 'lb';

    // field_key => start/end column index for multi-value attribute ranges
    public array $rangeStarts = [];

    public array $rangeEnds = [];

    public bool $suggestionsFinalized = false;

    public array $separatorValidationErrors = [];

    public array $progress = [
        'status' => null,
        'total_rows' => 0,
        'success_rows' => 0,
        'created_rows' => 0,
        'updated_rows' => 0,
        'unchanged_rows' => 0,
        'invalid_rows' => 0,
        'failure_reason' => null,
    ];

    public bool $validationReportEmailed = false;

    public bool $validationReportFailed = false;

    public ?string $currentFileSignature = null;

    protected $listeners = ['pollUploadStatus' => 'refreshStatus'];

    public function rules(): array
    {
        return [
            'file' => ['required', 'file', 'mimes:csv,xlsx,xls', 'max:51200'],
        ];
    }

    #[Computed]
    public function catalogFields()
    {
        return VitFieldDefinition::frontendVisible();
    }

    /**
     * Weight units available for the item_weight_in_pounds mapper setting.
     *
     * @return array<int, string>
     */
    public function weightUnits(): array
    {
        return \App\Services\WeightUnitConverter::supportedUnits();
    }

    public function mappedFieldKeys()
    {
        return collect($this->mapping)->filter(function ($columnIndex) {
            return $columnIndex !== null;
        })->keys();
    }

    public function availableColumnsFor(string $fieldKey)
    {
        $currentSelection = $this->mapping[$fieldKey] ?? null;
        $takenIndexes = $this->mappedColumnIndexes();

        $currentStr = $currentSelection !== null ? (string) $currentSelection : null;
        return collect($this->columns)->map(function ($name, $index) use ($currentStr, $takenIndexes) {
            $indexStr = (string) $index;
            return (object) [
                'index' => $indexStr,
                'name' => $name,
                'available' => $indexStr === $currentStr || !$takenIndexes->contains($indexStr),
            ];
        })->where('available', true)->values();
    }

    /**
     * Columns that may be used as range Start/End for a multi-value field.
     *
     * A column is available if it belongs to this field's own existing range
     * (so an active range stays editable), or if it is not occupied by
     * another field's range. Columns directly mapped to a normal field are
     * intentionally still selectable here: a range is allowed to touch a
     * directly-mapped column, and that column is simply excluded from the
     * range when the mapping is persisted. Only range-vs-range overlap is a
     * real conflict.
     *
     * @return \Illuminate\Support\Collection<int, object>
     */
    public function availableRangeColumnsFor(string $fieldKey)
    {
        $ownRange = $this->rangeColumnsFor($fieldKey);
        $ownRangeStr = array_map(fn($i) => (string) $i, $ownRange);

        // Columns occupied by OTHER fields' ranges must be excluded so a
        // range cannot overlap another field's range.
        $otherRangeTaken = collect($this->rangeStarts)
            ->reject(fn($start, $key) => $key === $fieldKey)
            ->flatMap(fn($start, $key) => $this->rangeColumnsFor($key))
            ->map(fn($i) => (string) $i)
            ->values();

        return collect($this->columns)->map(function ($name, $index) use ($ownRangeStr, $otherRangeTaken) {
            $indexStr = (string) $index;
            return (object) [
                'index' => $indexStr,
                'name' => $name,
                'available' => in_array($indexStr, $ownRangeStr, true) || !$otherRangeTaken->contains($indexStr),
            ];
        })->where('available', true)->values();
    }

    private function mappedColumnIndexes()
    {
        return collect($this->mapping)->filter(function ($columnIndex) {
            return $columnIndex !== null;
        })->map(fn($index) => (string) $index)->values();
    }

    public function unmappedRequiredFields()
    {
        return $this->catalogFields
            ->whereIn('requirement_type', ['required', 'recommended'])
            ->reject(fn($field) => $this->mappedFieldKeys()->contains($field->field_key))
            ->pluck('web_app_label');
    }

    public function uploadFile(CatalogFileInspectionService $inspector): void
    {
        $this->validate();

        $diagStart = microtime(true);
        Log::info('DIAG uploadFile start', ['filename' => optional($this->file)->getClientOriginalName(), 'size_bytes' => optional($this->file)->getSize(), 'queue_conn' => config('queue.default')]);

        $client = Auth::guard('client')->user();

        $extension = strtolower($this->file->getClientOriginalExtension());
        $fileType = $extension;

        $storedPath = $this->file->storeAs(
            "catalog-uploads/{$client->vendor_id}",
            Str::random(20) . '.' . $extension,
            self::DISK
        );

        $upload = CatalogUpload::create([
            'client_id' => $client->id,
            'vendor_id' => $client->vendor_id,
            'catalog_id' => $this->catalogId,
            'original_filename' => $this->file->getClientOriginalName(),
            'file_path' => $storedPath,
            'disk' => self::DISK,
            'file_type' => $fileType,
            'status' => CatalogUploadStatus::Uploaded,
        ]);

        Log::info('DIAG uploadFile created upload', ['elapsed_s' => round(microtime(true) - $diagStart, 3), 'mem_mb' => round(memory_get_usage(true) / 1048576, 2)]);

        $inspection = $inspector->inspect(self::DISK, $storedPath, $fileType);

        $this->catalogUploadId = $upload->id;
        $this->columns = $inspection['columns'];
        Log::info('DIAG uploadFile after inspect', ['cols' => count($inspection['columns']), 'elapsed_s' => round(microtime(true) - $diagStart, 3), 'mem_mb' => round(memory_get_usage(true) / 1048576, 2)]);

        $this->sampleRows = $inspection['sample_rows'];

        // VIT re-upload fast path: if the uploaded workbook is a valid
        // VIT-generated export (marker + version + headers all verified),
        // auto-map the vendor-importable fields and go straight to
        // processing, bypassing the manual mapper. System-derived /
        // export-only columns are intentionally left unmapped — the import
        // pipeline ignores them and the database stays the source of truth.
        try {
            $detection = app(VitExportFileDetector::class)
                ->detect(self::DISK, $storedPath, $fileType, $inspection['columns']);
        } catch (\Throwable $e) {
            $detection = null;
        }

        Log::info('DIAG uploadFile detect result', ['vit_file' => ($detection !== null), 'elapsed_s' => round(microtime(true) - $diagStart, 3)]);

        if ($detection !== null) {
            DB::transaction(function () use ($upload, $detection) {
                $upload->columnMappings()->delete();

                foreach ($detection['mappings'] as $mapping) {
                    CatalogUploadColumnMapping::create([
                        'catalog_upload_id' => $upload->id,
                        'field_key' => $mapping['field_key'],
                        'column_index' => $mapping['column_index'],
                        'source_column_name' => $mapping['source_column_name'],
                        'source_separator' => $mapping['source_separator'],
                    ]);
                }

                $upload->update([
                    'mapping_confirmed_at' => now(),
                    'status' => CatalogUploadStatus::Queued,
                ]);
            });

            // Detected VIT export: carry the single detection result into the
            // job so VIT values are not re-transformed (e.g. weight).
            Log::info('DIAG uploadFile PRE VIT dispatch (sync runs job inline)', ['elapsed_s' => round(microtime(true) - $diagStart, 3)]);

            ProcessCatalogUploadJob::dispatch($upload->id, true);

            Log::info('DIAG uploadFile POST VIT dispatch returned', ['elapsed_s' => round(microtime(true) - $diagStart, 3), 'mem_mb' => round(memory_get_usage(true) / 1048576, 2)]);

            $this->step = 'processing';

            $this->dispatch(
                'notify',
                type: 'success',
                message: 'VIT file detected!<br>Your columns were mapped automatically, and the catalog import has started.'
            );


            return;
        }

        Log::info('DIAG uploadFile entering computeFileSignature', ['upload' => $upload->id, 'elapsed_s' => round(microtime(true) - $diagStart, 3), 'mem_mb' => round(memory_get_usage(true) / 1048576, 2)]);
        $this->currentFileSignature = $inspector->computeFileSignature(self::DISK, $storedPath, $fileType, $inspection['columns']);
        Log::info('DIAG uploadFile computeFileSignature done', ['upload' => $upload->id, 'elapsed_s' => round(microtime(true) - $diagStart, 3), 'mem_mb' => round(memory_get_usage(true) / 1048576, 2)]);

        $this->mapping = $this->catalogFields
            ->pluck('field_key')
            ->mapWithKeys(fn($key) => [$key => null])
            ->toArray();

        $this->applySuggestedTemplate($client->vendor_id);
        $this->applyFuzzyMatchSuggestions();
        $this->suggestionsFinalized = true;

        $upload->update(['status' => CatalogUploadStatus::Mapping]);

        $this->step = 'mapping';
    }

    public function updatedMapping($value, $key): void
    {
        $this->mapping[$key] = $value !== null && $value !== '' ? (string) $value : null;
        unset($this->suggestedIndexes[$key]);
        // Choosing a single source column clears any range selection for the field.
        unset($this->rangeStarts[$key], $this->rangeEnds[$key]);
    }

    public function updatedSeparators($value, $key): void
    {
        $this->separators[$key] = $value;
    }

    public function updatedRangeStarts($value, $key): void
    {
        if (is_array($value)) {
            $this->rangeStarts = array_filter($value, fn($v) => $v !== null && $v !== '');

            // Switching to range mode must clear the single-column mapping
            // for every field that now has a range start.
            foreach (array_keys($this->rangeStarts) as $fieldKey) {
                $this->mapping[$fieldKey] = null;
            }

            return;
        }

        if (!is_string($key)) {
            return;
        }

        $previous = $this->rangeStarts[$key] ?? null;

        $this->rangeStarts[$key] = $value !== null && $value !== ''
            ? (string) $value
            : null;

        if ($this->rangeStarts[$key] !== null) {
            $this->mapping[$key] = null;
        }

        // A range is allowed to contain columns already mapped to another
        // field. Those columns will be ignored when the range is processed.
        //
        // We only need to prevent overlap with another range.
        if ($this->rangeOverlapsAnotherRange($key)) {
            $this->rangeStarts[$key] = $previous;
        }
    }

    public function updatedRangeEnds($value, $key): void
    {
        if (is_array($value)) {
            $this->rangeEnds = array_filter($value, fn($v) => $v !== null && $v !== '');

            // Switching to range mode must clear the single-column mapping
            // for every field that now has a range end.
            foreach (array_keys($this->rangeEnds) as $fieldKey) {
                $this->mapping[$fieldKey] = null;
            }

            return;
        }

        if (!is_string($key)) {
            return;
        }

        $previous = $this->rangeEnds[$key] ?? null;

        $this->rangeEnds[$key] = $value !== null && $value !== ''
            ? (string) $value
            : null;

        if ($this->rangeEnds[$key] !== null) {
            $this->mapping[$key] = null;
        }

        // A range is allowed to contain columns already mapped to another
        // field. Those columns will be ignored when the range is processed.
        //
        // We only need to prevent overlap with another range.
        if ($this->rangeOverlapsAnotherRange($key)) {
            $this->rangeEnds[$key] = $previous;
        }
    }

    /**
     * The ordered list of column indexes covered by a field's attribute range.
     * Handles reverse selection by normalizing start/end order.
     *
     * @return array<int, int>
     */
    public function rangeColumnsFor(string $fieldKey): array
    {
        $start = $this->rangeStarts[$fieldKey] ?? null;
        $end = $this->rangeEnds[$fieldKey] ?? null;

        if ($start === null || $end === null) {
            return [];
        }

        $start = (int) $start;
        $end = (int) $end;

        if ($start > $end) {
            [$start, $end] = [$end, $start];
        }

        return range($start, $end);
    }

    /**
     * Determines whether the current range overlaps another field's range.
     *
     * Directly mapped columns are intentionally NOT considered a conflict here.
     * If a directly mapped column falls inside this range, the range will simply
     * skip that column when it is persisted.
     */
    private function rangeOverlapsAnotherRange(string $fieldKey): bool
    {
        $range = $this->rangeColumnsFor($fieldKey);

        if (empty($range)) {
            return false;
        }

        $rangeIndexes = collect($range)
            ->map(fn($index) => (string) $index);

        foreach ($this->rangeStarts as $otherFieldKey => $start) {
            if ($otherFieldKey === $fieldKey) {
                continue;
            }

            $otherEnd = $this->rangeEnds[$otherFieldKey] ?? null;

            if ($start === null || $start === '' || $otherEnd === null || $otherEnd === '') {
                continue;
            }

            $otherRange = $this->rangeColumnsFor($otherFieldKey);

            foreach ($otherRange as $otherColumnIndex) {
                if ($rangeIndexes->contains((string) $otherColumnIndex)) {
                    return true;
                }
            }
        }

        return false;
    }

    public function confirmMapping(): void
    {
        $this->separatorValidationErrors = [];

        // Item Name (short_description) is required to proceed.
        $itemNameMapping = $this->mapping['short_description'] ?? null;

        if ($itemNameMapping === null || $itemNameMapping === '') {
            $this->separatorValidationErrors['short_description'] =
                'Please select a source column for Item Name before continuing.';

            return;
        }

        foreach ($this->catalogFields as $field) {
            if (! $field->is_multi_value) {
                continue;
            }

            $fieldKey = $field->field_key;

            // Range mappings do not use a source separator. Their values
            // come from multiple source columns, so skip separator
            // validation when this field has an active range.
            $hasActiveRange =
                isset($this->rangeStarts[$fieldKey]) &&
                $this->rangeStarts[$fieldKey] !== null &&
                $this->rangeStarts[$fieldKey] !== '' &&
                isset($this->rangeEnds[$fieldKey]) &&
                $this->rangeEnds[$fieldKey] !== null &&
                $this->rangeEnds[$fieldKey] !== '';

            if ($hasActiveRange) {
                continue;
            }

            $mapping = $this->mapping[$fieldKey] ?? null;

            if ($mapping === null || $mapping === '') {
                continue;
            }

            $separator = $this->separators[$fieldKey] ?? null;

            if ($separator === null || $separator === '') {
                $this->separatorValidationErrors[$fieldKey] =
                    "Please select a separator for {$field->web_app_label} before continuing.";
            }
        }

        if (!empty($this->separatorValidationErrors)) {
            return;
        }

        $upload = CatalogUpload::findOrFail($this->catalogUploadId);

        // ---------- Server-side mapping availability validation ----------
        // The UI prevents range-vs-range conflicts via availableRangeColumnsFor(),
        // but the server must be authoritative: reject overlapping range
        // assignments even if a crafted Livewire request tries to bypass the
        // frontend. A range touching a directly-mapped column is allowed; that
        // column is simply excluded from the range at persist time below.

        // 1. Active range mappings (field_key => list of column index strings).
        $rangeMappings = [];
        foreach ($this->rangeStarts as $fieldKey => $start) {
            $end = $this->rangeEnds[$fieldKey] ?? null;
            if ($start === null || $end === null || $start === '' || $end === '') {
                continue;
            }
            $rangeMappings[$fieldKey] = array_map(
                fn($i) => (string) $i,
                $this->rangeColumnsFor($fieldKey)
            );
        }

        $rangeFieldKeys = array_keys($rangeMappings);

        // 2. Normal single-column mappings (field_key => column index string).
        // Range fields' own single-column mapping entries are ignored at persist
        // time, so exclude them here to avoid false duplicate reports.
        $normalMappings = collect($this->mapping)
            ->filter(fn($index) => $index !== null && $index !== '')
            ->reject(fn($index, $key) => in_array($key, $rangeFieldKeys, true))
            ->map(fn($index) => (string) $index)
            ->all();

        // 3. Reject two normal fields assigned the same column.
        $duplicateIndexes = collect($normalMappings)
            ->groupBy(fn($index) => $index)
            ->filter(fn($group) => $group->count() > 1)
            ->keys()
            ->all();

        if (!empty($duplicateIndexes)) {
            $this->separatorValidationErrors = [];
            $badIndex = $duplicateIndexes[0];
            $badColumnName = $this->columns[$badIndex] ?? $badIndex;
            $this->separatorValidationErrors['__duplicate'] = "Column '{$badColumnName}' is mapped to more than one field. Each source column can only be used once.";
            return;
        }

        // 4. Reject a range that overlaps another range. Overlap with a normal
        // mapping is allowed (that column is excluded from the range on persist).
        foreach ($rangeMappings as $fieldKey => $rangeCols) {
            $label = VitFieldDefinition::find($fieldKey)?->web_app_label ?? $fieldKey;

            foreach ($rangeMappings as $otherKey => $otherRangeCols) {
                if ($otherKey === $fieldKey) {
                    continue;
                }

                $overlap = array_intersect($rangeCols, $otherRangeCols);

                if (!empty($overlap)) {
                    $badIndex = array_values($overlap)[0];
                    $badColumnName = $this->columns[$badIndex] ?? $badIndex;
                    $otherLabel = VitFieldDefinition::find($otherKey)?->web_app_label ?? $otherKey;

                    $this->separatorValidationErrors = [];
                    $this->separatorValidationErrors['__range_conflict'] =
                        "Column '{$badColumnName}' is inside the ranges for both '{$label}' and '{$otherLabel}'. Ranges cannot overlap.";

                    return;
                }
            }
        }
        // ----------------------------------------------------------------

        try {
            DB::transaction(function () use ($upload) {
                $upload->columnMappings()->delete();

                // Fields with an active range selection must not also persist
                // their single-column mapping, otherwise the first column of
                // the range would be inserted twice and violate the unique
                // constraint on (catalog_upload_id, column_index).
                $rangeFieldKeys = collect($this->rangeStarts)
                    ->filter(fn($start, $key) => $start !== null && $start !== '' && !empty($this->rangeEnds[$key]))
                    ->keys()
                    ->all();

                foreach ($this->mapping as $fieldKey => $columnIndex) {
                    if ($columnIndex === null) {
                        continue;
                    }

                    if (in_array($fieldKey, $rangeFieldKeys, true)) {
                        continue;
                    }

                    $columnName = $this->columns[$columnIndex] ?? null;

                    if ($columnName === null) {
                        continue;
                    }

                    $field = VitFieldDefinition::find($fieldKey);
                    $sourceSeparator = ($field && $field->is_multi_value) ? ($this->separators[$fieldKey] ?? null) : null;

                    // Item weight: the mapper's selected unit is stored as
                    // per-field mapping configuration on the mapping row. Its
                    // source_separator is otherwise always null (scalar field),
                    // so this reuses the existing config slot without a DB change.
                    if ($fieldKey === 'item_weight_in_pounds') {
                        $sourceSeparator = $this->weightUnit ?: \App\Services\WeightUnitConverter::DEFAULT_UNIT;
                    }

                    CatalogUploadColumnMapping::create([
                        'catalog_upload_id' => $upload->id,
                        'field_key' => $fieldKey,
                        'column_index' => $columnIndex,
                        'source_column_name' => $columnName,
                        'source_separator' => $sourceSeparator,
                    ]);
                }

                // Columns already claimed by a normal/direct mapping. Allowed
                // to fall inside a range, but the range must skip them when
                // creating its own mapping rows so we don't insert the same
                // column_index twice.
                $takenByNormalMappings = collect($this->mapping)
                    ->filter(
                        fn($index, $key) =>
                        $index !== null &&
                            $index !== '' &&
                            !in_array($key, $rangeFieldKeys, true)
                    )
                    ->map(fn($index) => (string) $index)
                    ->values();

                // Attribute-range mode: a multi-value field mapped to a contiguous
                // range of source columns. Each column in the range becomes its own
                // mapping row (same field_key) so the column header is preserved as
                // the attribute key, except columns already claimed by a direct
                // mapping, which are silently skipped.
                foreach ($this->rangeStarts as $fieldKey => $start) {
                    $end = $this->rangeEnds[$fieldKey] ?? null;
                    if ($start === null || $end === null) {
                        continue;
                    }

                    $field = VitFieldDefinition::find($fieldKey);
                    if (! $field || ! $field->is_multi_value) {
                        continue;
                    }

                    foreach ($this->rangeColumnsFor($fieldKey) as $columnIndex) {
                        if ($takenByNormalMappings->contains((string) $columnIndex)) {
                            continue;
                        }

                        $columnName = $this->columns[$columnIndex] ?? null;

                        if ($columnName === null) {
                            continue;
                        }

                        CatalogUploadColumnMapping::create([
                            'catalog_upload_id' => $upload->id,
                            'field_key' => $fieldKey,
                            'column_index' => $columnIndex,
                            'source_column_name' => $columnName,
                            'source_separator' => null,
                        ]);
                    }
                }

                $this->persistMappingTemplate($upload);

                $upload->update([
                    'mapping_confirmed_at' => now(),
                    'status' => CatalogUploadStatus::Queued,
                ]);
            });

            ProcessCatalogUploadJob::dispatch($upload->id);

            $this->step = 'processing';
        } catch (\Throwable $e) {
            throw $e;
        }
    }

    #[Computed]
    public function failedRows()
    {
        if (! $this->catalogUploadId) {
            return collect();
        }

        return \App\Models\CatalogUploadRow::where('catalog_upload_id', $this->catalogUploadId)
            ->where('status', 'invalid')
            ->whereNotNull('errors')
            ->orderBy('row_number')
            ->get(['row_number', 'errors']);
    }

    #[Computed]
    public function warningRows()
    {
        if (! $this->catalogUploadId) {
            return collect();
        }

        $rows = \App\Models\CatalogUploadRow::where('catalog_upload_id', $this->catalogUploadId)
            ->where('status', 'valid')
            ->whereNotNull('errors')
            ->orderBy('row_number')
            ->get(['row_number', 'errors']);

        return $rows->filter(function ($row) {
            $payload = is_string($row->errors) ? json_decode($row->errors, true) : $row->errors;
            return is_array($payload) && !empty($payload['warnings'] ?? []);
        });
    }

    public function refreshStatus(): void
    {
        $upload = CatalogUpload::find($this->catalogUploadId);

        if (! $upload) {
            return;
        }

        $this->progress = [
            'status' => $upload->status,
            'total_rows' => $upload->total_rows,
            'success_rows' => $upload->success_rows,
            'created_rows' => $upload->created_rows ?? 0,
            'updated_rows' => $upload->updated_rows ?? 0,
            'unchanged_rows' => $upload->unchanged_rows ?? 0,
            'invalid_rows' => $upload->invalid_rows ?? 0,
            'failure_reason' => $upload->failure_reason,
        ];

        if ($upload->status === CatalogUploadStatus::Completed) {
            $this->validationReportEmailed = !is_null($upload->validation_report_emailed_at);
            $this->validationReportFailed = false;
            $this->step = 'summary';
        } elseif ($upload->status === CatalogUploadStatus::Failed) {
            $this->step = 'error';
        }
    }

    public function startOver(): void
    {
        $this->reset(['file', 'catalogUploadId', 'columns', 'sampleRows', 'mapping', 'suggestedIndexes', 'suggestionsFinalized', 'currentFileSignature', 'rangeStarts', 'rangeEnds', 'separators', 'weightUnit']);
        $this->progress = ['status' => null, 'total_rows' => 0, 'success_rows' => 0, 'created_rows' => 0, 'updated_rows' => 0, 'unchanged_rows' => 0, 'invalid_rows' => 0, 'failure_reason' => null];
        $this->step = 'upload';
    }

    public function resetMapping(): void
    {
        $this->mapping = $this->catalogFields
            ->pluck('field_key')
            ->mapWithKeys(fn($key) => [$key => null])
            ->toArray();
        $this->rangeStarts = [];
        $this->rangeEnds = [];
        $this->separators = [];
        $this->suggestedIndexes = [];
    }

    private function applySuggestedTemplate(int $vendorId): void
    {
        $template = VendorMappingTemplate::where('vendor_id', $vendorId)
            ->where('active', true)
            ->whereNotNull('file_signature')
            ->where('file_signature', $this->currentFileSignature)
            ->with('fields')
            ->latest()
            ->first();

        if (! $template) {
            return;
        }

        $normalizedColumns = collect($this->columns)->map(fn($c) => Str::lower(trim((string) $c)));

        // Group template fields by field_key so a range mapping (multiple rows
        // with the same field_key, e.g. specifications -> "Barrier Style" and
        // "Barrier Type") is restored as a range instead of being collapsed to
        // a single column by the last row overwriting the previous ones.
        $template->fields
            ->groupBy('field_key')
            ->each(function ($fieldGroup) use ($normalizedColumns) {
                $fieldKey = $fieldGroup->first()->field_key;
                if (! $fieldKey) {
                    return;
                }

                $matchedIndexes = $fieldGroup
                    ->map(fn($field) => $normalizedColumns->search(Str::lower(trim($field->source_column_name))))
                    ->filter(fn($index) => $index !== false)
                    ->map(fn($index) => (int) $index)
                    ->values()
                    ->all();

                if (empty($matchedIndexes)) {
                    return;
                }

                if (count($matchedIndexes) === 1) {
                    // Single-column mapping
                    $this->mapping[$fieldKey] = (string) $matchedIndexes[0];

                    // Restore separator if the template stored one
                    $templateField = $fieldGroup->first();
                    if ($templateField && $templateField->source_separator) {
                        $this->separators[$fieldKey] = $templateField->source_separator;
                    }
                    return;
                }

                // Range mapping: restore start/end and clear the single-column
                // mapping so the UI shows the range controls.
                $this->rangeStarts[$fieldKey] = (string) min($matchedIndexes);
                $this->rangeEnds[$fieldKey] = (string) max($matchedIndexes);
                $this->mapping[$fieldKey] = null;
            });
    }

    private function applyFuzzyMatchSuggestions(): void
    {
        if ($this->suggestionsFinalized) {
            return;
        }

        $alreadyMapped = $this->mappedFieldKeys();
        $available = $this->catalogFields->reject(fn($field) => $alreadyMapped->contains($field->field_key));

        foreach ($available as $field) {
            $normalizedField = $this->normalizeForMatching($field->field_key);
            $normalizedLabel = $this->normalizeForMatching($field->web_app_label);

            if ($normalizedField === '' && $normalizedLabel === '') {
                continue;
            }

            $bestIndex = null;
            $bestScore = 0.0;

            foreach ($this->columns as $index => $columnName) {
                if ($this->mappedColumnIndexes()->contains((string) $index)) {
                    continue;
                }

                $normalizedColumn = $this->normalizeForMatching((string) $columnName);

                if ($normalizedColumn === '') {
                    continue;
                }

                $score = max(
                    $this->similarityPercent($normalizedField, $normalizedColumn),
                    $this->similarityPercent($normalizedLabel, $normalizedColumn),
                );

                $threshold = $field->requirement_type === 'required' ? 85.0 : 65.0;

                if ($score >= $threshold && $score > $bestScore) {
                    $bestScore = $score;
                    $bestIndex = $index;
                }
            }

            if ($bestIndex !== null) {
                $this->mapping[$field->field_key] = (string) $bestIndex;
                $this->suggestedIndexes[$field->field_key] = true;
            }
        }
    }

    private function normalizeForMatching(string $value): string
    {
        return strtolower(preg_replace('/[^a-z0-9]/i', '', $value) ?? '');
    }

    private function similarityPercent(string $a, string $b): float
    {
        if ($a === '' || $b === '') {
            return 0.0;
        }

        similar_text($a, $b, $percent);

        return $percent;
    }

    private function persistMappingTemplate(CatalogUpload $upload): void
    {
        $template = VendorMappingTemplate::where('vendor_id', $upload->vendor_id)
            ->where('active', true)
            ->whereNotNull('file_signature')
            ->where('file_signature', $this->currentFileSignature)
            ->latest()
            ->first();

        if (! $template) {
            $template = VendorMappingTemplate::create([
                'vendor_id' => $upload->vendor_id,
                'created_by_client_id' => $upload->client_id,
                'name' => 'Auto-saved ' . now()->format('Y-m-d H:i'),
                'file_signature' => $this->currentFileSignature,
            ]);
        } else {
            $template->fields()->delete();
        }

        foreach ($upload->columnMappings as $mapping) {
            if (! $mapping->field_key) {
                continue;
            }

            $template->fields()->create([
                'field_key' => $mapping->field_key,
                'source_column_name' => $mapping->source_column_name,
                'source_separator' => $mapping->source_separator,
            ]);
        }
    }

    public function render()
    {
        return view('livewire.vendor.vendor-catalog-upload');
    }
}
