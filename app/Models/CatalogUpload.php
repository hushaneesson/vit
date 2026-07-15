<?php

namespace App\Models;

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
        'catalog_name',
        'file_path',
        'disk',
        'file_type',
        'status',
        'total_rows',
        'success_rows',
        'error_rows',
        'failure_reason',
        'mapping_confirmed_at',
        'processing_started_at',
        'processing_completed_at',
    ];

    protected $casts = [
        'mapping_confirmed_at' => 'datetime',
        'processing_started_at' => 'datetime',
        'processing_completed_at' => 'datetime',
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
        $mappedFieldIds = $this->columnMappings()
            ->whereNotNull('catalog_field_id')
            ->pluck('catalog_field_id');

        $requiredFieldIds = CatalogField::query()
            ->where('requirement_type', 'required')
            ->where('active', true)
            ->where('is_system_derived', false) // these never get a column mapping - value comes from elsewhere
            ->pluck('id');

        return $requiredFieldIds->diff($mappedFieldIds)->isEmpty();
    }
}
