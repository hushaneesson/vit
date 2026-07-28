<?php

namespace App\Models;

use App\Enums\CatalogExportStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Tracks one catalog export generation run.
 *
 * Each record represents a single .xlsx file generated from the vendor's
 * validated CatalogItem records. The file is stored on the configured
 * filesystem disk and can later be uploaded to an FTP server (future).
 */
class CatalogExport extends Model
{
    use HasFactory;

    protected $fillable = [
        'vendor_id',
        'catalog_submission_id',
        'file_path',
        'disk',
        'file_size',
        'total_items',
        'status',
        'failure_reason',
        'generating_started_at',
        'generated_at',
    ];

    protected $casts = [
        'status' => CatalogExportStatus::class,
        'total_items' => 'integer',
        'file_size' => 'integer',
        'generating_started_at' => 'datetime',
        'generated_at' => 'datetime',
    ];

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    /**
     * The catalog submission that triggered this export (if any).
     */
    public function submission(): BelongsTo
    {
        return $this->belongsTo(CatalogSubmission::class, 'catalog_submission_id');
    }
}
