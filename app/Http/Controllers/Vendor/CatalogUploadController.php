<?php

namespace App\Http\Controllers;

use App\Enums\CatalogUploadStatus;
use App\Exceptions\UnsupportedCsvEncodingException;
use App\Jobs\ProcessCatalogUploadJob;
use App\Models\CatalogUpload;
use App\Models\CatalogUploadColumnMapping;
use App\Models\VendorMappingTemplate;
use App\Services\Catalog\CatalogFileInspectionService;
use App\Services\VitFieldDefinition;
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

        $client = $this->currentClient();
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
            'status' => CatalogUploadStatus::Uploaded,
        ]);

        try {
            $inspection = $this->inspector->inspect(self::DISK, $storedPath, $fileType);
        } catch (UnsupportedCsvEncodingException $e) {
            // Encoding could not be safely normalized. Remove the row we just
            // created so no orphan upload remains, and return the vendor-facing
            // message as a validation error.
            $upload->delete();

            return response()->json([
                'message' => $e->getMessage(),
                'errors' => ['file' => [$e->getMessage()]],
            ], 422);
        }

        $upload->update(['status' => CatalogUploadStatus::Mapping]);

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
        ]);

        $catalogUpload->columnMappings()->delete();

        foreach ($data['mappings'] as $mapping) {
            CatalogUploadColumnMapping::create([
                'catalog_upload_id' => $catalogUpload->id,
                'field_key' => $mapping['field_key'],
                'column_index' => $mapping['column_index'],
                'source_column_name' => $mapping['source_column_name'],
            ]);
        }

        // Silently persist/update the mapping template in the background.
        // Completely transparent to the user — no notification, no prompt.
        $this->saveMappingTemplate($catalogUpload);

        $catalogUpload->update([
            'mapping_confirmed_at' => now(),
            'status' => CatalogUploadStatus::Mapping,
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

        $catalogUpload->update(['status' => CatalogUploadStatus::Queued]);

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
            'invalid_rows' => $catalogUpload->invalid_rows,
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

    private function currentClient()
    {
        return Auth::guard('client')->user();
    }

    private function authorizeUploadOwnership(CatalogUpload $catalogUpload): void
    {
        $client = $this->currentClient();

        abort_unless($catalogUpload->client_id === $client->id, 403);
    }

    private function activeCatalogFieldsForUi(): array
    {
        return VitFieldDefinition::frontendVisible()
            ->map(fn($field) => [
                'field_key' => $field->field_key,
                'web_app_label' => $field->web_app_label,
                'requirement_type' => $field->requirement_type,
                'field_type' => $field->field_type,
                'description' => $field->description,
            ])
            ->values()
            ->toArray();
    }

    /**
     * Base query for a vendor's active mapping templates. suggestedTemplate()
     * and saveMappingTemplate() both start here before adding their own
     * filters, instead of each retyping vendor_id + active conditions.
     */
    private function activeTemplatesForVendor(int $vendorId)
    {
        return VendorMappingTemplate::where('vendor_id', $vendorId)
            ->where('active', true);
    }

    private function normalizeColumnName(?string $value): string
    {
        return Str::lower(trim((string) $value));
    }

    private function suggestedTemplate(int $vendorId, array $columns): ?array
    {
        $template = $this->activeTemplatesForVendor($vendorId)
            ->with('fields')
            ->latest()
            ->first();

        if (! $template) {
            return null;
        }

        // Match template fields back to the newly-uploaded file's columns by
        // name (case-insensitive), since column order can shift between
        // exports even for the same vendor.
        $normalizedColumns = collect($columns)->map(fn($c) => $this->normalizeColumnName($c));

        $suggestions = $template->fields->mapWithKeys(function ($field) use ($normalizedColumns) {
            $index = $normalizedColumns->search($this->normalizeColumnName($field->source_column_name));

            return $index === false ? [] : [$index => $field->field_key];
        });

        return $suggestions->isEmpty() ? null : [
            'suggested_mapping' => $suggestions,
        ];
    }

    /**
     * Silently persist or update the mapping template for this file signature.
     *
     * If a template already exists for this vendor + file signature, its
     * fields are replaced with the current mapping (an update). Otherwise
     * a new template is created. This runs transparently in the background
     * — the user is never asked or notified.
     */
    private function saveMappingTemplate(CatalogUpload $catalogUpload): void
    {
        // Compute the file signature from the stored file
        $fileSignature = $this->inspector->computeFileSignature(
            $catalogUpload->disk,
            $catalogUpload->file_path,
            $catalogUpload->file_type
        );

        // Find an existing template for this vendor + file signature
        $template = $this->activeTemplatesForVendor($catalogUpload->vendor_id)
            ->whereNotNull('file_signature')
            ->where('file_signature', $fileSignature)
            ->latest()
            ->first();

        if (! $template) {
            // No existing template for this file layout — create one
            $template = VendorMappingTemplate::create([
                'vendor_id' => $catalogUpload->vendor_id,
                'created_by_client_id' => $catalogUpload->client_id,
                'name' => 'Auto-saved ' . now()->format('Y-m-d H:i'),
                'file_signature' => $fileSignature,
            ]);
        } else {
            // Update existing template — replace its fields
            $template->fields()->delete();
        }

        foreach ($catalogUpload->columnMappings as $mapping) {
            if (! $mapping->field_key) {
                continue;
            }

            $template->fields()->create([
                'field_key' => $mapping->field_key,
                'source_column_name' => $mapping->source_column_name,
            ]);
        }
    }
}
