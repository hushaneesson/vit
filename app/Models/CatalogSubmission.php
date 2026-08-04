<?php

namespace App\Models;

use App\Enums\CatalogSubmissionStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Represents a vendor's catalog review submission.
 *
 * Each submission tracks the lifecycle of a vendor requesting review,
 * an admin approving or rejecting, and the resulting Excel file
 * being uploaded to VIT.
 *
 * This is the single source of truth for:
 * - Business approval status (CatalogSubmissionStatus enum)
 * - Generated Excel file path, size, disk
 * - Technical processing state (processing_status column)
 * - Generation and upload timestamps
 *
 * No item selection is performed — all current CatalogItems for the
 * vendor are included in the generated Excel. The generated Excel
 * file is the artifact — no database snapshot of item data is maintained.
 */
class CatalogSubmission extends Model
{
    use HasFactory;

    protected $fillable = [
        'vendor_id',
        'requested_by_client_id',
        'status',
        'total_items',
        'requested_at',
        'approved_by',
        'approved_at',
        'rejected_by',
        'rejected_at',
        'rejection_reason',
        // Export file metadata
        'file_path',
        'disk',
        'file_size',
        'product_count',
        'submission_date',
        // VIT upload tracking
        'processing_status',
        'failure_reason',
        'upload_attempts',
        'last_upload_error',
        'vit_api_response',
        'generating_started_at',
        'generated_at',
        'uploaded_at',
    ];

    protected $casts = [
        'status' => CatalogSubmissionStatus::class,
        'total_items' => 'integer',
        'requested_at' => 'datetime',
        'approved_at' => 'datetime',
        'rejected_at' => 'datetime',
        'generating_started_at' => 'datetime',
        'generated_at' => 'datetime',
        'uploaded_at' => 'datetime',
    ];

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    public function catalogUpload(): BelongsTo
    {
        return $this->belongsTo(CatalogUpload::class);
    }

    public function requestedByClient(): BelongsTo
    {
        return $this->belongsTo(Client::class, 'requested_by_client_id');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function rejectedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'rejected_by');
    }
}
