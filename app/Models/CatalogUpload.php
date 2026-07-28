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
        'error_rows',
        'updated_rows',
        'skipped_rows',
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
        // Every required VIT field must be mapped to a column before we
        // let the client kick off processing.
        $mappedFieldKeys = $this->columnMappings()
            ->whereNotNull('field_key')
            ->pluck('field_key');

        $requiredFieldKeys = VitFieldDefinition::requiredFieldKeys();

        return $requiredFieldKeys->diff($mappedFieldKeys)->isEmpty();
    }
}
