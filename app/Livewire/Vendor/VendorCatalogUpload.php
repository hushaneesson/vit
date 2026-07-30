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

    public array $progress = [
        'status' => null,
        'total_rows' => 0,
        'success_rows' => 0,
        'created_rows' => 0,
        'updated_rows' => 0,
        'unchanged_rows' => 0,
        'duplicate_rows' => 0,
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

    /**
     * File signature of the currently-uploaded file, computed from its
     * column headers. Used to match against saved templates.
     */
    public ?string $currentFileSignature = null;

    protected $listeners = ['pollUploadStatus' => 'refreshStatus'];


    public function rules(): array
    {
        return [
            'file' => ['required', 'file', 'mimes:csv,txt,xlsx,xls', 'max:51200'], // 50MB
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
     * Column indexes already mapped to any field.
     */
    private function mappedColumnIndexes()
    {
        return collect($this->mapping)->filter(function ($columnIndex) {
            return $columnIndex !== null;
        })->map(fn($index) => (string) $index)->values();
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
            'file_path' => $storedPath,
            'disk' => self::DISK,
            'file_type' => $fileType,
            'status' => CatalogUploadStatus::Uploaded,
        ]);

        $inspection = $inspector->inspect(self::DISK, $storedPath, $fileType);

        $this->catalogUploadId = $upload->id;
        $this->columns = $inspection['columns'];
        $this->sampleRows = $inspection['sample_rows'];

        // Compute the file signature from the column headers and store it
        // so we can match against saved templates later.
        $this->currentFileSignature = $inspector->computeFileSignature(self::DISK, $storedPath, $fileType);

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
        // Livewire hydrates select values as strings; normalize to string for consistent comparison
        $this->mapping[$key] = $value !== null && $value !== '' ? (string) $value : null;
        unset($this->suggestedIndexes[$key]);
    }

    /**
     * STEP 2: vendor confirms the field -> column mapping, we save it,
     * silently persist/update the mapping template in the background,
     * then move to processing.
     *
     * Missing VIT-required fields are no longer blocking — the vendor's
     * existing catalog is imported as-is and missing fields become null
     * on the CatalogItem. They can be completed later from the Catalog
     * Item List before requesting review.
     *
     * The template save is completely transparent to the user — no
     * notification, no checkbox, no name prompt. The template is
     * matched/updated by file signature so the same file layout is
     * remembered for next time.
     */
    public function confirmMapping(): void
    {
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

                // Silently persist/update the mapping template in the
                // background. Completely transparent to the user.
                $this->persistMappingTemplate($upload);

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
            'created_rows' => $upload->created_rows ?? 0,
            'updated_rows' => $upload->updated_rows ?? 0,
            'unchanged_rows' => $upload->unchanged_rows ?? 0,
            'duplicate_rows' => $upload->duplicate_rows ?? 0,
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

    public function startOver(): void
    {
        $this->reset(['file', 'catalogUploadId', 'columns', 'sampleRows', 'mapping', 'suggestedIndexes', 'suggestionsFinalized', 'currentFileSignature']);
        $this->progress = ['status' => null, 'total_rows' => 0, 'success_rows' => 0, 'created_rows' => 0, 'updated_rows' => 0, 'unchanged_rows' => 0, 'duplicate_rows' => 0, 'skipped_rows' => 0, 'skipped_item_names' => null, 'error_rows' => 0, 'failure_reason' => null];
        $this->step = 'upload';
    }

    private function applySuggestedTemplate(int $vendorId): void
    {
        // Only match templates that have a file signature AND whose signature
        // matches the current file. This ensures a template created from
        // File A is never auto-applied to an incompatible File B.
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

        foreach ($template->fields as $field) {
            if (! $field->field_key) {
                continue;
            }

            $index = $normalizedColumns->search(Str::lower(trim($field->source_column_name)));

            if ($index !== false) {
                $this->mapping[$field->field_key] = $index;
            }
        }
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

    /**
     * Silently persist or update the mapping template for this file signature.
     *
     * If a template already exists for this vendor + file signature, its
     * fields are replaced with the current mapping (an update). Otherwise
     * a new template is created. This runs transparently in the background
     * — the user is never asked or notified.
     */
    private function persistMappingTemplate(CatalogUpload $upload): void
    {
        // Find an existing template for this vendor + file signature
        $template = VendorMappingTemplate::where('vendor_id', $upload->vendor_id)
            ->where('active', true)
            ->whereNotNull('file_signature')
            ->where('file_signature', $this->currentFileSignature)
            ->latest()
            ->first();

        if (! $template) {
            // No existing template for this file layout — create one
            $template = VendorMappingTemplate::create([
                'vendor_id' => $upload->vendor_id,
                'created_by_client_id' => $upload->client_id,
                'name' => 'Auto-saved ' . now()->format('Y-m-d H:i'),
                'file_signature' => $this->currentFileSignature,
            ]);
        } else {
            // Update existing template — replace its fields
            $template->fields()->delete();
        }

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
