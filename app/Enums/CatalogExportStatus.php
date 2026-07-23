<?php

namespace App\Enums;

/**
 * Lifecycle status for a catalog export generation run.
 *
 * Matches the pattern used by CatalogUploadStatus for consistency:
 *   pending    → export is queued/waiting
 *   generating → file is being built
 *   completed  → file was successfully generated and stored
 *   failed     → an error occurred during generation
 */
enum CatalogExportStatus: string
{
    case Pending = 'pending';
    case Generating = 'generating';
    case Completed = 'completed';
    case Failed = 'failed';
}
