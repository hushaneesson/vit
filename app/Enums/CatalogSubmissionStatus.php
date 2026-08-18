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

    /**
     * Human-readable label for display in vendor and admin views.
     */
    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::ReviewRequested, self::ReadyForReview => 'Pending',
            self::Approved, self::Uploaded => 'Delivered',
            self::Rejected => 'Rejected',
            self::Withdrawn => 'Withdrawn',
        };
    }

    /**
     * Tailwind text color class for the status label/badge.
     */
    public function color(): string
    {
        return match ($this) {
            self::Draft => 'text-slate-400',
            self::ReviewRequested, self::ReadyForReview => 'text-sky-600',
            self::Approved, self::Uploaded => 'text-emerald-600',
            self::Rejected => 'text-red-600',
            self::Withdrawn => 'text-gray-400',
        };
    }

    /**
     * Tailwind badge classes (background + text) for a pill-style status badge.
     */
    public function badgeClass(): string
    {
        return match ($this) {
            self::Draft => 'bg-slate-50 text-slate-500 border-slate-200',
            self::ReviewRequested, self::ReadyForReview => 'bg-sky-50 text-sky-700 border-sky-200',
            self::Approved, self::Uploaded => 'bg-emerald-50 text-emerald-700 border-emerald-200',
            self::Rejected => 'bg-rose-50 text-rose-700 border-rose-200',
            self::Withdrawn => 'bg-slate-50 text-slate-500 border-slate-200',
        };
    }

    /**
     * Filament badge color name for admin table/form display.
     */
    public function filamentColor(): string
    {
        return match ($this) {
            self::Draft => 'gray',
            self::ReviewRequested => 'warning',
            self::ReadyForReview => 'info',
            self::Approved, self::Uploaded => 'success',
            self::Rejected => 'danger',
            self::Withdrawn => 'gray',
        };
    }
}
