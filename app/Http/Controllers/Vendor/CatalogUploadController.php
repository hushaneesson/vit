<?php

namespace App\Http\Controllers;

use App\Jobs\ProcessCatalogUploadJob;
use App\Models\CatalogField;
use App\Models\CatalogUpload;
use App\Models\CatalogUploadColumnMapping;
use App\Models\VendorMappingTemplate;
use App\Services\CatalogFileInspectionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

class CatalogUploadController extends Controller
{
    private const DISK = 'spaces'; // matches DigitalOcean Spaces disk defined in config/filesystems.php

    public function __construct(private CatalogFileInspectionService $inspector) {}

    /**
     * Step 1: client uploads a CSV/XLSX file. We store it and return the
     * detected columns + sample rows for the mapping screen. We do NOT
     * process the full file yet.
     */
    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'file' => ['required', 'file', 'mimes:csv,txt,xlsx,xls', 'max:51200'], // 50MB
        ]);

        $client = Auth::guard('client')->user();
        $file = $request->file('file');
        $extension = strtolower($file->getClientOriginalExtension());
        $fileType = $extension === 'txt' ? 'csv' : $extension;

        $storedPath = $file->store("catalog-uploads/{$client->vendor_id}", self::DISK);

        $upload = CatalogUpload::create([
            'client_id' => $client->id,
            'vendor_id' => $client->vendor_id,
            'original_filename' => $file->getClientOriginalName(),
            'file_path' => $storedPath,
            'disk' => self::DISK,
            'file_type' => $fileType,
            'status' => 'uploaded',
        ]);

        $inspection = $this->inspector->inspect(self::DISK, $storedPath, $fileType);

        $upload->update(['status' => 'mapping']);

        return response()->json([
            'catalog_upload_id' => $upload->id,
            'columns' => $inspection['columns'],
            'sample_rows' => $inspection['sample_rows'],
            'catalog_fields' => $this->activeCatalogFieldsForUi(),
            'suggested_template' => $this->suggestedTemplate($client->vendor_id, $inspection['columns']),
        ]);
    }

    /**
     * Step 2: client submits the column -> field mapping.
     * mappings = [{ column_index, source_column_name, field_key|null }]
     */
    public function saveMapping(Request $request, CatalogUpload $catalogUpload): JsonResponse
    {
        $this->authorizeUploadOwnership($catalogUpload);

        $data = $request->validate([
            'mappings' => ['required', 'array', 'min:1'],
            'mappings.*.column_index' => ['required', 'integer', 'min:0'],
            'mappings.*.source_column_name' => ['required', 'string'],
            'mappings.*.field_key' => ['nullable', 'string'],
            'save_as_template' => ['sometimes', 'boolean'],
            'template_name' => ['required_if:save_as_template,true', 'string', 'max:255'],
        ]);

        $catalogFieldsByKey = CatalogField::where('active', true)->get()->keyBy('field_key');

        $catalogUpload->columnMappings()->delete();

        foreach ($data['mappings'] as $mapping) {
            $field = $mapping['field_key'] ? $catalogFieldsByKey->get($mapping['field_key']) : null;

            CatalogUploadColumnMapping::create([
                'catalog_upload_id' => $catalogUpload->id,
                'catalog_field_id' => $field?->id,
                'column_index' => $mapping['column_index'],
                'source_column_name' => $mapping['source_column_name'],
            ]);
        }

        if (! $catalogUpload->fresh()->isReadyToProcess()) {
            return response()->json([
                'message' => 'All required fields must be mapped before continuing.',
                'ready_to_process' => false,
            ], 422);
        }

        if ($request->boolean('save_as_template')) {
            $this->saveMappingTemplate($catalogUpload, $data['template_name']);
        }

        $catalogUpload->update([
            'mapping_confirmed_at' => now(),
            'status' => 'mapping',
        ]);

        return response()->json(['ready_to_process' => true]);
    }

    /**
     * Step 3: client confirms and kicks off background processing.
     */
    public function process(CatalogUpload $catalogUpload): JsonResponse
    {
        $this->authorizeUploadOwnership($catalogUpload);

        if (! $catalogUpload->isReadyToProcess()) {
            return response()->json(['message' => 'Mapping is incomplete.'], 422);
        }

        $catalogUpload->update(['status' => 'queued']);

        ProcessCatalogUploadJob::dispatch($catalogUpload->id);

        return response()->json(['status' => 'queued']);
    }

    /**
     * Polled by the frontend progress screen.
     */
    public function status(CatalogUpload $catalogUpload): JsonResponse
    {
        $this->authorizeUploadOwnership($catalogUpload);

        return response()->json([
            'status' => $catalogUpload->status,
            'total_rows' => $catalogUpload->total_rows,
            'success_rows' => $catalogUpload->success_rows,
            'error_rows' => $catalogUpload->error_rows,
            'failure_reason' => $catalogUpload->failure_reason,
        ]);
    }

    /**
     * Error-row drill-down for the summary screen.
     */
    public function errorRows(CatalogUpload $catalogUpload): JsonResponse
    {
        $this->authorizeUploadOwnership($catalogUpload);

        $rows = $catalogUpload->rows()
            ->where('status', 'invalid')
            ->orderBy('row_number')
            ->paginate(50);

        return response()->json($rows);
    }

    private function authorizeUploadOwnership(CatalogUpload $catalogUpload): void
    {
        $client = Auth::guard('client')->user();

        abort_unless($catalogUpload->client_id === $client->id, 403);
    }

    private function activeCatalogFieldsForUi(): array
    {
        return CatalogField::query()
            ->where('active', true)
            ->where('visible_in_web_app', true)
            ->where('is_system_derived', false) // e.g. "Seller" comes from the vendor's account, never mapped from a file
            ->orderBy('sort_order')
            ->get(['field_key', 'web_app_label', 'requirement_type', 'field_type', 'description'])
            ->toArray();
    }

    private function suggestedTemplate(int $vendorId, array $columns): ?array
    {
        $template = VendorMappingTemplate::where('vendor_id', $vendorId)
            ->where('active', true)
            ->with('fields.catalogField')
            ->latest()
            ->first();

        if (! $template) {
            return null;
        }

        // Match template fields back to the newly-uploaded file's columns by
        // name (case-insensitive), since column order can shift between
        // exports even for the same vendor.
        $normalizedColumns = collect($columns)->map(fn ($c) => Str::lower(trim((string) $c)));

        $suggestions = $template->fields->mapWithKeys(function ($field) use ($normalizedColumns) {
            $index = $normalizedColumns->search(Str::lower(trim($field->source_column_name)));

            return $index === false ? [] : [$index => $field->catalogField->field_key];
        });

        return $suggestions->isEmpty() ? null : [
            'template_id' => $template->id,
            'template_name' => $template->name,
            'suggested_mapping' => $suggestions,
        ];
    }

    private function saveMappingTemplate(CatalogUpload $catalogUpload, string $name): void
    {
        $template = VendorMappingTemplate::create([
            'vendor_id' => $catalogUpload->vendor_id,
            'created_by_client_id' => $catalogUpload->client_id,
            'name' => $name,
        ]);

        foreach ($catalogUpload->columnMappings as $mapping) {
            if (! $mapping->catalog_field_id) {
                continue;
            }

            $template->fields()->create([
                'catalog_field_id' => $mapping->catalog_field_id,
                'source_column_name' => $mapping->source_column_name,
            ]);
        }
    }
}
