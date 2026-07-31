<?php

namespace App\Enums;

/**
 * Business approval lifecycle for a catalog submission review.
 *
 * Each submission represents a vendor's catalog state at the time of
 * review request. Statuses track the approval workflow only — technical
 * file generation/upload state is tracked separately via the
 * processing_status column on the catalog_submissions table.
 *
 * Transitions:
 *   draft ──→ review_requested ──→ ready_for_review ──→ approved ──→ uploaded
 *                │                    │                      │
 *                │                    └──→ (admin rejects) │
 *                └──→ rejected ──→ review_requested (resubmit)
 */
enum CatalogSubmissionStatus: string
{
    case Draft = 'draft';
    case ReviewRequested = 'review_requested';
    case ReadyForReview = 'ready_for_review';
    case Approved = 'approved';
    case Uploaded = 'uploaded';
    case Rejected = 'rejected';
    case Withdrawn = 'withdrawn';
}
