<?php

namespace App\Enums;

/**
 * Business approval lifecycle for a catalog submission review.
 *
 * Each submission represents a vendor's catalog state at the time of
 * review request. Statuses track the approval workflow only — export
 * generation states belong to CatalogExportStatus, not here.
 *
 * Transitions:
 *   draft ──→ review_requested ──→ approved ──→ ready_for_upload ──→ uploaded
 *                │                                                     (future)
 *                └──→ rejected ──→ review_requested (resubmit cycle)
 */
enum CatalogSubmissionStatus: string
{
    case Draft = 'draft';
    case ReviewRequested = 'review_requested';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case ReadyForUpload = 'ready_for_upload';
    case Uploaded = 'uploaded';
}
