<?php

namespace App\Models;

use App\Enums\CatalogSubmissionStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Represents a vendor's catalog review submission.
 *
 * Each submission is a snapshot of the vendor's catalog items at the
 * time the vendor requested admin review. The exact items are recorded
 * in the catalog_submission_items pivot table for auditability.
 *
 * This is a business approval entity — it tracks the lifecycle of a
 * vendor requesting review, an admin approving or rejecting, and the
 * resulting export generation.
 */
class CatalogSubmission extends Model
{
    use HasFactory;

    protected $fillable = [
        'vendor_id',
        'catalog_upload_id',
        'requested_by_client_id',
        'status',
        'total_items',
        'complete_items',
        'incomplete_items',
        'requested_at',
        'approved_by',
        'approved_at',
        'rejected_by',
        'rejected_at',
        'rejection_reason',
        'catalog_export_id',
    ];

    protected $casts = [
        'status' => CatalogSubmissionStatus::class,
        'total_items' => 'integer',
        'complete_items' => 'integer',
        'incomplete_items' => 'integer',
        'requested_at' => 'datetime',
        'approved_at' => 'datetime',
        'rejected_at' => 'datetime',
    ];

    /**
     * The vendor whose catalog is being submitted for review.
     */
    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    /**
     * The upload that triggered this submission (nullable — submissions
     * may be created independently of a specific upload).
     */
    public function catalogUpload(): BelongsTo
    {
        return $this->belongsTo(CatalogUpload::class);
    }

    /**
     * The client (vendor user) who requested the review.
     */
    public function requestedByClient(): BelongsTo
    {
        return $this->belongsTo(Client::class, 'requested_by_client_id');
    }

    /**
     * The admin who approved this submission.
     */
    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    /**
     * The admin who rejected this submission.
     */
    public function rejectedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'rejected_by');
    }

    /**
     * The export generated from this approved submission.
     */
    public function catalogExport(): HasOne
    {
        return $this->hasOne(CatalogExport::class, 'catalog_submission_id');
    }

    /**
     * The CatalogItem records that were included in this submission.
     * Snapshot recorded in the catalog_submission_items pivot table
     * at the time of review request.
     */
    public function catalogItems(): BelongsToMany
    {
        return $this->belongsToMany(CatalogItem::class, 'catalog_submission_items')
            ->withTimestamps();
    }
}
