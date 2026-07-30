<?php

namespace App\Models;

use App\Enums\CatalogUploadStatus;
use App\Services\VitFieldDefinition;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CatalogUpload extends Model
{
    use HasFactory;

    protected $fillable = [
        'client_id',
        'vendor_id',
        'original_filename',
        'file_path',
        'disk',
        'file_type',
        'status',
        'total_rows',
        'success_rows',
        'created_rows',
        'error_rows',
        'updated_rows',
        'skipped_rows',
        'unchanged_rows',
        'duplicate_rows',
        'skipped_item_names',
        'failure_reason',
        'mapping_confirmed_at',
        'processing_started_at',
        'processing_completed_at',
        'validation_report_emailed_at',
    ];

    protected $casts = [
        'status' => CatalogUploadStatus::class,
        'updated_rows' => 'integer',
        'skipped_rows' => 'integer',
        'created_rows' => 'integer',
        'unchanged_rows' => 'integer',
        'duplicate_rows' => 'integer',
        'skipped_item_names' => 'array',
        'mapping_confirmed_at' => 'datetime',
        'processing_started_at' => 'datetime',
        'processing_completed_at' => 'datetime',
        'validation_report_emailed_at' => 'datetime',
    ];

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    public function columnMappings(): HasMany
    {
        return $this->hasMany(CatalogUploadColumnMapping::class);
    }

    public function rows(): HasMany
    {
        return $this->hasMany(CatalogUploadRow::class);
    }

    public function isReadyToProcess(): bool
    {
        // The application now imports a vendor's existing catalog so they
        // can complete and edit it inside the application before submission.
        // Missing VIT-required fields should NOT prevent importing — they
        // simply become null on the CatalogItem and can be completed later.
        //
        // As long as at least one column is mapped to a field, the upload
        // is ready to process. Structural CSV validation (unreadable file,
        // corrupt CSV, etc.) is handled separately in the processing job.
        return $this->columnMappings()
            ->whereNotNull('field_key')
            ->exists();
    }
}
