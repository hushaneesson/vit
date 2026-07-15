<?php

namespace App\Livewire\Vendor;

use App\Jobs\ProcessCatalogUploadJob;
use App\Models\CatalogField;
use App\Models\CatalogUpload;
use App\Models\CatalogUploadColumnMapping;
use App\Models\VendorMappingTemplate;
use App\Services\CatalogFileInspectionService;
use Illuminate\Support\Facades\Auth;
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
    public array $mapping = []; // column_index => field_key|null
    public array $suggestedIndexes = []; // column_index => true, if auto-suggested (not vendor-confirmed)

    public bool $saveAsTemplate = false;
    public string $templateName = '';

    public array $progress = [
        'status' => null,
        'total_rows' => 0,
        'success_rows' => 0,
        'error_rows' => 0,
        'failure_reason' => null,
    ];

    protected $listeners = ['pollUploadStatus' => 'refreshStatus'];

    public function rules(): array
    {
        $this->catalogName = trim($this->catalogName);

        $rules = [
            'file' => ['required', 'file', 'mimes:csv,txt,xlsx,xls', 'max:51200'], // 50MB
            'catalogName' => ['required', 'string', 'max:255'],
        ];

        if ($this->step === 'upload') {
            // Only validate catalogName on the upload step
        }

        return $rules;
    }

    /**
     * The static list of VIT eLink fields the vendor maps their columns
     * to. No category/hierarchy concept here - just the flat field list.
     */
    #[Computed]
    public function catalogFields()
    {
        return CatalogField::query()
            ->where('active', true)
            ->where('visible_in_web_app', true)
            ->where('is_system_derived', false) // e.g. "Seller" comes from the vendor's account, never mapped from a file
            ->orderBy('sort_order')
            ->get(['id', 'field_key', 'web_app_label', 'requirement_type', 'field_type', 'description']);
    }

    #[Computed]
    public function unmappedRequiredFields()
    {
        $mappedKeys = collect($this->mapping)->filter()->values();

        return $this->catalogFields
            ->where('requirement_type', 'required')
            ->reject(fn ($field) => $mappedKeys->contains($field->field_key))
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
            Str::random(20).'.'.$extension,
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
            'status' => 'uploaded',
        ]);

        $inspection = $inspector->inspect(self::DISK, $storedPath, $fileType);

        $this->catalogUploadId = $upload->id;
        $this->columns = $inspection['columns'];
        $this->sampleRows = $inspection['sample_rows'];
        $this->mapping = array_fill(0, count($this->columns), null);

        $this->applySuggestedTemplate($client->vendor_id);
        $this->applyFuzzyMatchSuggestions();

        $upload->update(['status' => 'mapping']);

        $this->step = 'mapping';
    }

    /**
     * Fires when the vendor edits any mapping.{index} select manually.
     * Once they've made a deliberate choice, it's no longer "just a
     * suggestion" - clear the badge for that column.
     */
    public function updatedMapping($value, $key): void
    {
        unset($this->suggestedIndexes[$key]);
    }

    /**
     * STEP 2: vendor confirms the column -> field mapping, we validate that
     * every required field is mapped, save it, optionally save a reusable
     * template, then move to processing.
     */
    public function confirmMapping(): void
    {
        if ($this->unmappedRequiredFields->isNotEmpty()) {
            $this->addError('mapping', 'Please map all required fields before continuing.');

            return;
        }

        $upload = CatalogUpload::findOrFail($this->catalogUploadId);
        $catalogFieldsByKey = $this->catalogFields->keyBy('field_key');

        $upload->columnMappings()->delete();

        foreach ($this->columns as $index => $columnName) {
            $fieldKey = $this->mapping[$index] ?? null;
            $field = $fieldKey ? $catalogFieldsByKey->get($fieldKey) : null;

            CatalogUploadColumnMapping::create([
                'catalog_upload_id' => $upload->id,
                'catalog_field_id' => $field?->id,
                'column_index' => $index,
                'source_column_name' => $columnName,
            ]);
        }

        if ($this->saveAsTemplate && $this->templateName !== '') {
            $this->persistMappingTemplate($upload);
        }

        $upload->update([
            'mapping_confirmed_at' => now(),
            'status' => 'queued',
        ]);

        ProcessCatalogUploadJob::dispatch($upload->id);

        $this->step = 'processing';
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
            'error_rows' => $upload->error_rows,
            'failure_reason' => $upload->failure_reason,
        ];

        if ($upload->status === 'completed') {
            $this->step = 'summary';
        } elseif ($upload->status === 'failed') {
            $this->step = 'error';
        }
    }

    public function startOver(): void
    {
        $this->reset(['file', 'catalogUploadId', 'columns', 'sampleRows', 'mapping', 'suggestedIndexes', 'saveAsTemplate', 'templateName']);
        $this->progress = ['status' => null, 'total_rows' => 0, 'success_rows' => 0, 'error_rows' => 0, 'failure_reason' => null];
        $this->step = 'upload';
    }

    private function applySuggestedTemplate(int $vendorId): void
    {
        $template = VendorMappingTemplate::where('vendor_id', $vendorId)
            ->where('active', true)
            ->with('fields.catalogField')
            ->latest()
            ->first();

        if (! $template) {
            return;
        }

        $normalizedColumns = collect($this->columns)->map(fn ($c) => Str::lower(trim((string) $c)));

        foreach ($template->fields as $field) {
            $index = $normalizedColumns->search(Str::lower(trim($field->source_column_name)));

            if ($index !== false) {
                $this->mapping[$index] = $field->catalogField->field_key;
            }
        }
    }

    /**
     * Second suggestion pass, runs after the saved-template check, for any
     * column still unmapped. Scores each unmapped column's header against
     * every unclaimed VIT field's key + label using similar_text(), and
     * auto-fills the best match if it clears that field's confidence
     * threshold. Required fields need a much closer match (85%) than
     * optional ones (65%) - a wrong guess on a required field silently
     * lets bad data through, a wrong guess on an optional field is a
     * cheap, obvious fix.
     */
    private function applyFuzzyMatchSuggestions(): void
    {
        $alreadyMapped = collect($this->mapping)->filter();
        $available = $this->catalogFields->reject(fn ($field) => $alreadyMapped->contains($field->field_key));

        foreach ($this->columns as $index => $columnName) {
            if (! empty($this->mapping[$index])) {
                continue; // already mapped (e.g. by the saved-template pass)
            }

            $normalizedColumn = $this->normalizeForMatching((string) $columnName);

            if ($normalizedColumn === '') {
                continue;
            }

            $bestField = null;
            $bestScore = 0.0;

            foreach ($available as $field) {
                $score = max(
                    $this->similarityPercent($normalizedColumn, $this->normalizeForMatching($field->field_key)),
                    $this->similarityPercent($normalizedColumn, $this->normalizeForMatching($field->web_app_label)),
                );

                $threshold = $field->requirement_type === 'required' ? 85.0 : 65.0;

                if ($score >= $threshold && $score > $bestScore) {
                    $bestScore = $score;
                    $bestField = $field;
                }
            }

            if ($bestField) {
                $this->mapping[$index] = $bestField->field_key;
                $this->suggestedIndexes[$index] = true;
                $available = $available->reject(fn ($field) => $field->id === $bestField->id);
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
            if (! $mapping->catalog_field_id) {
                continue;
            }

            $template->fields()->create([
                'catalog_field_id' => $mapping->catalog_field_id,
                'source_column_name' => $mapping->source_column_name,
            ]);
        }
    }

    public function render()
    {
        return view('livewire.vendor.vendor-catalog-upload');
    }
}
