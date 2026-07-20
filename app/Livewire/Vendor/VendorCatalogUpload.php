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

    private const DISK = 'spaces'; // matches DigitalOcean Spaces disk in config/filesystems.php

    // upload | mapping | processing | summary | error
    public string $step = 'upload';

    public string $catalogName = '';

    public $file; // Livewire temporary upload

    public ?int $catalogUploadId = null;

    public array $columns = [];
    public array $sampleRows = [];
    /**
     * Mapping from VIT field_key => column_index (or null if unmapped).
     * This is the reverse of the old structure: each VIT field maps to
     * a column in the uploaded file, not the other way around.
     */
    public array $mapping = []; // field_key => column_index|null
    public array $suggestedIndexes = []; // field_key => true, if auto-suggested (not vendor-confirmed)

    /**
     * Once the fuzzy matching pass has run, this is set to true.
     * Suggestions are NEVER recalculated after this point, even if
     * the user changes a dropdown — that only clears the badge on
     * the field they touched.
     */
    public bool $suggestionsFinalized = false;

    public bool $saveAsTemplate = false;
    public string $templateName = '';

    /**
     * Snapshot of the mapping as it was when a saved template was applied.
     * Null if no template was loaded. Used to determine whether the user
     * has deviated from the template, and thus whether to show the
     * "Remember this mapping" checkbox.
     */
    public ?array $originalTemplateMapping = null;

    /**
     * The name of the loaded template, if any. Used to display in the
     * visual indicator and to auto-fill the template name input when
     * the user modifies the mapping.
     */
    public ?string $loadedTemplateName = null;

    public array $progress = [
        'status' => null,
        'total_rows' => 0,
        'success_rows' => 0,
        'updated_rows' => 0,
        'skipped_rows' => 0,
        'skipped_item_names' => null,
        'error_rows' => 0,
        'failure_reason' => null,
    ];

    /**
     * Whether the validation report was successfully emailed to the user.
     * Read from the upload record when transitioning to the summary step.
     */
    public bool $validationReportEmailed = false;

    /**
     * Whether the validation report email failed to send.
     * When true, the UI falls back to showing the first 10 rows.
     */
    public bool $validationReportFailed = false;

    protected $listeners = ['pollUploadStatus' => 'refreshStatus'];

    /**
     * Trim the catalog name whenever it's updated via the input field.
     */
    public function updatedCatalogName($value): void
    {
        $this->catalogName = trim($value);
    }

    public function rules(): array
    {
        return [
            'file' => ['required', 'file', 'mimes:csv,txt,xlsx,xls', 'max:51200'], // 50MB
            'catalogName' => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * The static list of VIT eLink fields the vendor maps their columns to.
     * No category/hierarchy concept here - just the flat field list.
     * Source of truth is VitFieldDefinition, NOT the database.
     */
    #[Computed]
    public function catalogFields()
    {
        return VitFieldDefinition::visibleInWebApp();
    }

    /**
     * Fields already mapped to any column (excluding "Do not import").
     * Used to filter dropdowns so each field can only be chosen once.
     * NOT cached with #[Computed] — reads fresh from $this->mapping
     * on every render so dropdowns stay in sync.
     */
    public function mappedFieldKeys()
    {
        return collect($this->mapping)->filter(function ($columnIndex) {
            return $columnIndex !== null;
        })->keys();
    }

    /**
     * Available columns for a given VIT field — excludes columns already
     * mapped to OTHER fields, but keeps the current field's selection
     * visible so the user can change it.
     */
    public function availableColumnsFor(string $fieldKey)
    {
        $currentSelection = $this->mapping[$fieldKey] ?? null;
        $takenIndexes = $this->mappedColumnIndexes();

        return collect($this->columns)->map(function ($name, $index) use ($currentSelection, $takenIndexes) {
            return (object) [
                'index' => $index,
                'name' => $name,
                'available' => $index === $currentSelection || !$takenIndexes->contains($index),
            ];
        })->where('available', true)->values();
    }

    /**
     * Column indexes already mapped to any field.
     */
    private function mappedColumnIndexes()
    {
        return collect($this->mapping)->filter(function ($columnIndex) {
            return $columnIndex !== null;
        })->values();
    }

    /**
     * Required fields not yet mapped to any column.
     * NOT cached with #[Computed] — reads fresh from $this->mapping
     * on every render so the progress bar stays in sync.
     */
    public function unmappedRequiredFields()
    {
        return $this->catalogFields
            ->where('requirement_type', 'required')
            ->reject(fn($field) => $this->mappedFieldKeys()->contains($field->field_key))
            ->pluck('web_app_label');
    }

    /**
     * STEP 1: vendor picks a file. We store it and read just the header +
     * a few sample rows so the mapping screen loads fast even on a large
     * file - we don't touch the full file until processing is confirmed.
     */
    public function uploadFile(CatalogFileInspectionService $inspector): void
    {
        $this->validate();

        $client = Auth::guard('client')->user();

        $extension = strtolower($this->file->getClientOriginalExtension());
        $fileType = $extension === 'txt' ? 'csv' : $extension;

        $storedPath = $this->file->storeAs(
            "catalog-uploads/{$client->vendor_id}",
            Str::random(20) . '.' . $extension,
            self::DISK
        );

        $upload = CatalogUpload::create([
            'client_id' => $client->id,
            'vendor_id' => $client->vendor_id,
            'original_filename' => $this->file->getClientOriginalName(),
            'catalog_name' => $this->catalogName ?: $this->file->getClientOriginalName(),
            'file_path' => $storedPath,
            'disk' => self::DISK,
            'file_type' => $fileType,
            'status' => CatalogUploadStatus::Uploaded,
        ]);

        $inspection = $inspector->inspect(self::DISK, $storedPath, $fileType);

        $this->catalogUploadId = $upload->id;
        $this->columns = $inspection['columns'];
        $this->sampleRows = $inspection['sample_rows'];

        // Initialize mapping: every VIT field starts unmapped (null)
        $this->mapping = $this->catalogFields
            ->pluck('field_key')
            ->mapWithKeys(fn($key) => [$key => null])
            ->toArray();

        $this->applySuggestedTemplate($client->vendor_id);
        $this->applyFuzzyMatchSuggestions();
        $this->suggestionsFinalized = true; // Lock suggestions — NEVER recalculate

        $upload->update(['status' => CatalogUploadStatus::Mapping]);

        $this->step = 'mapping';
    }

    /**
     * Fires when the vendor edits any mapping.{field_key} select manually.
     *
     * Normalizes the incoming value to an integer (or null for "Do not import")
     * so the rest of the codebase can use strict === comparisons throughout.
     *
     * The ONLY automatic effect is clearing the suggestion badge on the
     * field the user touched. No other field's mapping or suggestion
     * is ever changed.
     */
    public function updatedMapping($value, $key): void
    {
        $this->mapping[$key] = $value !== null && $value !== '' ? (int) $value : null;
        unset($this->suggestedIndexes[$key]);
    }

    /**
     * STEP 2: vendor confirms the field -> column mapping, we validate that
     * every required field is mapped, save it, optionally save a reusable
     * template, then move to processing.
     */
    public function confirmMapping(): void
    {
        if ($this->unmappedRequiredFields()->isNotEmpty()) {
            $this->addError('mapping', 'Please map all required fields before continuing.');

            return;
        }

        $upload = CatalogUpload::findOrFail($this->catalogUploadId);

        try {
            DB::transaction(function () use ($upload) {
                $upload->columnMappings()->delete();

                foreach ($this->mapping as $fieldKey => $columnIndex) {
                    if ($columnIndex === null) {
                        continue; // field was left unmapped — skip
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
                    ]);
                }

                if ($this->saveAsTemplate && $this->templateName !== '') {
                    $this->persistMappingTemplate($upload);
                }

                $upload->update([
                    'mapping_confirmed_at' => now(),
                    'status' => CatalogUploadStatus::Queued,
                ]);
            });

            ProcessCatalogUploadJob::dispatch($upload->id);

            $this->step = 'processing';
        } catch (\Throwable $e) {
            // Transaction was rolled back - mapping changes not persisted
            // The upload remains in its previous state
            throw $e;
        }
    }

    /**
     * Rows that failed validation during processing, with their error details.
     */
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

    /**
     * Called by wire:poll while on the processing screen.
     */
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
            'updated_rows' => $upload->updated_rows ?? 0,
            'skipped_rows' => $upload->skipped_rows ?? 0,
            'skipped_item_names' => $upload->skipped_item_names ?? null,
            'error_rows' => $upload->error_rows,
            'failure_reason' => $upload->failure_reason,
        ];

        if ($upload->status === CatalogUploadStatus::Completed) {
            $this->validationReportEmailed = !is_null($upload->validation_report_emailed_at);
            $this->validationReportFailed = false; // reset on each poll; email failure is logged server-side
            $this->step = 'summary';
        } elseif ($upload->status === CatalogUploadStatus::Failed) {
            $this->step = 'error';
        }
    }

    /**
     * Whether a saved mapping template was loaded for this file.
     * True when the user has an existing template that was applied.
     */
    public function templateIsLoaded(): bool
    {
        return $this->originalTemplateMapping !== null;
    }

    /**
     * Whether the current mapping differs from the template that was loaded,
     * or if no template was loaded at all. Returns true when the checkbox
     * should be shown:
     * - No template exists → user may want to save one
     * - Template loaded but user changed mappings → may want to save updated version
     *
     * Returns false (hide checkbox) when a template was loaded and the
     * mapping hasn't changed at all.
     */
    public function hasMappingChangedFromTemplate(): bool
    {
        // No template was loaded — always show the checkbox
        if ($this->originalTemplateMapping === null) {
            return true;
        }

        // Compare current mapping against the template snapshot
        return $this->mapping !== $this->originalTemplateMapping;
    }

    public function startOver(): void
    {
        $this->reset(['file', 'catalogUploadId', 'columns', 'sampleRows', 'mapping', 'suggestedIndexes', 'saveAsTemplate', 'templateName', 'suggestionsFinalized', 'originalTemplateMapping', 'loadedTemplateName']);
        $this->progress = ['status' => null, 'total_rows' => 0, 'success_rows' => 0, 'updated_rows' => 0, 'skipped_rows' => 0, 'skipped_item_names' => null, 'error_rows' => 0, 'failure_reason' => null];
        $this->step = 'upload';
    }

    private function applySuggestedTemplate(int $vendorId): void
    {
        $template = VendorMappingTemplate::where('vendor_id', $vendorId)
            ->where('active', true)
            ->with('fields')
            ->latest()
            ->first();

        if (! $template) {
            $this->originalTemplateMapping = null;
            $this->loadedTemplateName = null;
            return;
        }

        $normalizedColumns = collect($this->columns)->map(fn($c) => Str::lower(trim((string) $c)));

        foreach ($template->fields as $field) {
            if (! $field->field_key) {
                continue;
            }

            $index = $normalizedColumns->search(Str::lower(trim($field->source_column_name)));

            if ($index !== false) {
                $this->mapping[$field->field_key] = $index;
            }
        }

        // Snapshot the mapping as it was applied from the template
        $this->originalTemplateMapping = $this->mapping;

        // Store the template name for display and auto-fill
        $this->loadedTemplateName = $template->name;
        $this->templateName = $template->name;
    }

    /**
     * Second suggestion pass, runs after the saved-template check, for any
     * VIT field still unmapped. Scores each unmapped field's key + label
     * against every unclaimed column header using similar_text(), and
     * auto-fills the best match if it clears that field's confidence
     * threshold. Required fields need a much closer match (85%) than
     * optional ones (65%) - a wrong guess on a required field silently
     * lets bad data through, a wrong guess on an optional field is a
     * cheap, obvious fix.
     *
     * This method runs EXACTLY ONCE during uploadFile(). After that,
     * $this->suggestionsFinalized is set to true and this will never
     * run again — no matter what the user does with the dropdowns.
     */
    private function applyFuzzyMatchSuggestions(): void
    {
        // Safety guard — suggestions are locked after the initial pass
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
                // Skip columns already mapped to another field
                if ($this->mappedColumnIndexes()->contains($index)) {
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
                $this->mapping[$field->field_key] = $bestIndex;
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
        $template = VendorMappingTemplate::create([
            'vendor_id' => $upload->vendor_id,
            'created_by_client_id' => $upload->client_id,
            'name' => $this->templateName,
        ]);

        foreach ($upload->columnMappings as $mapping) {
            if (! $mapping->field_key) {
                continue;
            }

            $template->fields()->create([
                'field_key' => $mapping->field_key,
                'source_column_name' => $mapping->source_column_name,
            ]);
        }
    }

    public function render()
    {
        return view('livewire.vendor.vendor-catalog-upload');
    }
}
