<?php

namespace App\Services\Catalog;

/**
 * Tracks which catalog upload completion notifications the user has already
 * seen, using the existing session mechanism (a flat array of
 * catalog_upload_id values under 'catalog_upload_notifications_shown').
 *
 * Shared by CatalogProcessingBanner (View Report / Dismiss) and
 * VendorCatalogUpload (report/summary display) so the acknowledgement
 * logic exists in exactly one place.
 */
class CatalogUploadNotificationAcknowledger
{
    public const SESSION_KEY = 'catalog_upload_notifications_shown';

    /**
     * Record the given upload as acknowledged. Idempotent: repeated calls
     * for the same upload do not duplicate the ID or rewrite the session.
     */
    public static function acknowledge(int $catalogUploadId): void
    {
        $notified = session(self::SESSION_KEY, []);

        if (! in_array($catalogUploadId, $notified)) {
            $notified[] = $catalogUploadId;
            session([self::SESSION_KEY => $notified]);
        }
    }

    public static function isAcknowledged(int $catalogUploadId): bool
    {
        return in_array($catalogUploadId, session(self::SESSION_KEY, []));
    }
}
