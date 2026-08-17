<?php

namespace App\Livewire\Vendor;

use App\Enums\CatalogUploadStatus;
use App\Jobs\ProcessCatalogUploadJob;
use App\Models\CatalogUpload;
use App\Models\CatalogUploadColumnMapping;
use App\Models\VendorMappingTemplate;
use App\Services\CatalogFileInspectionService;
use App\Services\VitFieldDefinition;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
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

    public array $columns = [];
    public array $sampleRows = [];
    public array $mapping = [];
    public array $suggestedIndexes = [];

    // field_key => separator for multi-value fields
    public array $separators = [];

    // field_key => start/end column index for multi-value attribute ranges
    public array $rangeStarts = [];

    public array $rangeEnds = [];

    public array $columnSearch = [];

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
     * A column is available if it is not mapped to another field, or if it
     * belongs to this field's own existing range (so an active range stays
     * editable). Columns mapped to other fields are excluded.
     *
     * @return \Illuminate\Support\Collection<int, object>
     */
    public function availableRangeColumnsFor(string $fieldKey)
    {
        $takenIndexes = $this->mappedColumnIndexes();
        $ownRange = $this->rangeColumnsFor($fieldKey);
        $ownRangeStr = array_map(fn($i) => (string) $i, $ownRange);

        // Columns occupied by OTHER fields' ranges must also be excluded so a
        // range cannot overlap another field's range.
        $otherRangeTaken = collect($this->rangeStarts)
            ->reject(fn($start, $key) => $key === $fieldKey)
            ->flatMap(fn($start, $key) => $this->rangeColumnsFor($key))
            ->map(fn($i) => (string) $i)
            ->values();

        return collect($this->columns)->map(function ($name, $index) use ($takenIndexes, $ownRangeStr, $otherRangeTaken) {
            $indexStr = (string) $index;
            return (object) [
                'index' => $indexStr,
                'name' => $name,
                'available' => in_array($indexStr, $ownRangeStr, true) || (!$takenIndexes->contains($indexStr) && !$otherRangeTaken->contains($indexStr)),
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
            ->where('requirement_type', 'required')
            ->reject(fn($field) => $this->mappedFieldKeys()->contains($field->field_key))
            ->pluck('web_app_label');
    }

    public function uploadFile(CatalogFileInspectionService $inspector): void
    {
        $this->validate();

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
            'original_filename' => $this->file->getClientOriginalName(),
            'file_path' => $storedPath,
            'disk' => self::DISK,
            'file_type' => $fileType,
            'status' => CatalogUploadStatus::Uploaded,
        ]);

        $inspection = $inspector->inspect(self::DISK, $storedPath, $fileType);

        $this->catalogUploadId = $upload->id;
        $this->columns = $inspection['columns'];
        $this->sampleRows = $inspection['sample_rows'];

        $this->currentFileSignature = $inspector->computeFileSignature(self::DISK, $storedPath, $fileType);

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
        $this->rangeStarts[$key] = $value !== null && $value !== '' ? (string) $value : null;

        if ($this->rangeStarts[$key] !== null) {
            $this->mapping[$key] = null;
        }

        // Prevent a range that would cross a column mapped to another field.
        if ($this->rangeCrossesMappedColumn($key)) {
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
        $this->rangeEnds[$key] = $value !== null && $value !== '' ? (string) $value : null;

        if ($this->rangeEnds[$key] !== null) {
            $this->mapping[$key] = null;
        }

        // Prevent a range that would cross a column mapped to another field.
        if ($this->rangeCrossesMappedColumn($key)) {
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
     * Whether the current range for a field crosses a column that is mapped
     * to another field. Used to prevent a range from spanning columns that
     * belong to other fields.
     */
    private function rangeCrossesMappedColumn(string $fieldKey): bool
    {
        $range = $this->rangeColumnsFor($fieldKey);
        if (empty($range)) {
            return false;
        }

        $taken = $this->mappedColumnIndexes();

        // Columns occupied by OTHER fields' ranges are also taken, preventing
        // overlapping ranges from one field's perspective.
        $otherRangeTaken = collect($this->rangeStarts)
            ->reject(fn($start, $key) => $key === $fieldKey)
            ->flatMap(fn($start, $key) => $this->rangeColumnsFor($key))
            ->map(fn($i) => (string) $i);

        foreach ($range as $colIndex) {
            if ($taken->contains((string) $colIndex) || $otherRangeTaken->contains((string) $colIndex)) {
                return true;
            }
        }

        return false;
    }

    public function confirmMapping(): void
    {
        $this->separatorValidationErrors = [];

        foreach ($this->catalogFields as $field) {
            if (! $field->is_multi_value) {
                continue;
            }

            $mapping = $this->mapping[$field->field_key] ?? null;

            if ($mapping === null || $mapping === '') {
                continue;
            }

            $separator = $this->separators[$field->field_key] ?? null;

            if ($separator === null || $separator === '') {
                $this->separatorValidationErrors[$field->field_key] = "Please select a separator for {$field->web_app_label} before continuing.";
            }
        }

        if (!empty($this->separatorValidationErrors)) {
            return;
        }

        $upload = CatalogUpload::findOrFail($this->catalogUploadId);

        // ---------- Server-side mapping availability validation ----------
        // The UI prevents conflicts via availableColumnsFor()/availableRangeColumnsFor(),
        // but the server must be authoritative: reject overlapping assignments even
        // if a crafted Livewire request tries to bypass the frontend.

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
        // time, so exclude them here to avoid false duplicate/overlap reports.
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

        // 4. Reject a range that overlaps a normal mapping or another range.
        foreach ($rangeMappings as $fieldKey => $rangeCols) {
            $label = VitFieldDefinition::find($fieldKey)?->web_app_label ?? $fieldKey;

            foreach ($rangeCols as $rangeColStr) {
                // Overlap with a normal mapping?
                if (in_array($rangeColStr, $normalMappings, true)) {
                    $this->separatorValidationErrors = [];
                    $badColumnName = $this->columns[$rangeColStr] ?? $rangeColStr;
                    $this->separatorValidationErrors['__range_conflict'] = "Column '{$badColumnName}' is inside the range for '{$label}' and is also mapped to another field. Each source column can only be used once.";
                    return;
                }

                // Overlap with another field's range?
                foreach ($rangeMappings as $otherKey => $otherRangeCols) {
                    if ($otherKey === $fieldKey) {
                        continue;
                    }
                    if (in_array($rangeColStr, $otherRangeCols, true)) {
                        $this->separatorValidationErrors = [];
                        $badColumnName = $this->columns[$rangeColStr] ?? $rangeColStr;
                        $otherLabel = VitFieldDefinition::find($otherKey)?->web_app_label ?? $otherKey;
                        $this->separatorValidationErrors['__range_conflict'] = "Column '{$badColumnName}' is inside the ranges for both '{$label}' and '{$otherLabel}'. Ranges cannot overlap.";
                        return;
                    }
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

                    CatalogUploadColumnMapping::create([
                        'catalog_upload_id' => $upload->id,
                        'field_key' => $fieldKey,
                        'column_index' => $columnIndex,
                        'source_column_name' => $columnName,
                        'source_separator' => $sourceSeparator,
                    ]);
                }

                // Attribute-range mode: a multi-value field mapped to a contiguous
                // range of source columns. Each column in the range becomes its own
                // mapping row (same field_key) so the column header is preserved as
                // the attribute key.
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
        $this->reset(['file', 'catalogUploadId', 'columns', 'sampleRows', 'mapping', 'suggestedIndexes', 'suggestionsFinalized', 'currentFileSignature', 'rangeStarts', 'rangeEnds', 'columnSearch', 'separators']);
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
        $this->columnSearch = [];
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
