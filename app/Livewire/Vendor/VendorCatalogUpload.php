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

    public bool $suggestionsFinalized = false;

    public array $progress = [
        'status' => null,
        'total_rows' => 0,
        'success_rows' => 0,
        'created_rows' => 0,
        'updated_rows' => 0,
        'unchanged_rows' => 0,
        'error_rows' => 0,
        'failure_reason' => null,
    ];

    public bool $validationReportEmailed = false;

    public bool $validationReportFailed = false;

    public ?string $currentFileSignature = null;

    protected $listeners = ['pollUploadStatus' => 'refreshStatus'];

    public function rules(): array
    {
        return [
            'file' => ['required', 'file', 'mimes:csv,txt,xlsx,xls', 'max:51200'],
        ];
    }

    #[Computed]
    public function catalogFields()
    {
        return VitFieldDefinition::visibleInWebApp();
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
    }

    public function confirmMapping(): void
    {
        $upload = CatalogUpload::findOrFail($this->catalogUploadId);

        try {
            DB::transaction(function () use ($upload) {
                $upload->columnMappings()->delete();

                foreach ($this->mapping as $fieldKey => $columnIndex) {
                    if ($columnIndex === null) {
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
                    ]);
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
            'error_rows' => $upload->invalid_rows ?? 0,
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
        $this->reset(['file', 'catalogUploadId', 'columns', 'sampleRows', 'mapping', 'suggestedIndexes', 'suggestionsFinalized', 'currentFileSignature']);
        $this->progress = ['status' => null, 'total_rows' => 0, 'success_rows' => 0, 'created_rows' => 0, 'updated_rows' => 0, 'unchanged_rows' => 0, 'error_rows' => 0, 'failure_reason' => null];
        $this->step = 'upload';
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
            ]);
        }
    }

    public function render()
    {
        return view('livewire.vendor.vendor-catalog-upload');
    }
}
