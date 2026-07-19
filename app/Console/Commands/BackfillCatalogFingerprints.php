<?php

namespace App\Console\Commands;

use App\Models\CatalogItem;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class BackfillCatalogFingerprints extends Command
{
    protected $signature = 'catalog:backfill-fingerprints';

    protected $description = 'Backfill data_fingerprint for existing catalog items, removing exact duplicates';

    /**
     * These columns should be excluded from fingerprint computation
     * because they are metadata, not actual content data.
     * MUST match the exclusions used in ProcessValidatedRowsJob::computeFingerprint.
     */
    private array $nonComparableColumns = ['vendor_sku', 'vendor_id', 'catalog_name', 'catalog_upload_id', 'data_fingerprint'];

    public function handle(): int
    {
        $this->info('Starting fingerprint backfill and duplicate cleanup...');

        $bar = $this->output->createProgressBar(CatalogItem::count());
        $bar->start();

        $dedupTracker = []; // vendor_id => [fingerprint => id]
        $duplicatesRemoved = 0;
        $backfilled = 0;

        // Process ordered by created_at so the earliest record is kept
        CatalogItem::orderBy('created_at')
            ->chunk(100, function ($items) use (&$dedupTracker, &$duplicatesRemoved, &$backfilled, $bar) {
                foreach ($items as $item) {
                    $fingerprint = $this->computeFingerprint($item);

                    DB::table('catalog_items')
                        ->where('id', $item->id)
                        ->update(['data_fingerprint' => $fingerprint]);

                    $backfilled++;

                    // Check for duplicates within the same vendor
                    $vendorId = $item->vendor_id;
                    if (!isset($dedupTracker[$vendorId])) {
                        $dedupTracker[$vendorId] = [];
                    }

                    if (isset($dedupTracker[$vendorId][$fingerprint])) {
                        // Exact duplicate found — remove this one (keep the earliest)
                        $existing = $dedupTracker[$vendorId][$fingerprint];
                        $item->delete();
                        $duplicatesRemoved++;
                        $this->line("\nRemoved duplicate item [{$item->id}] — keeping [{$existing}]");
                    } else {
                        $dedupTracker[$vendorId][$fingerprint] = $item->id;
                    }

                    $bar->advance();
                }
            });

        $bar->finish();
        $this->newLine(2);
        $this->info("Backfilled fingerprints for {$backfilled} items.");
        $this->info("Removed {$duplicatesRemoved} exact duplicate items.");

        return Command::SUCCESS;
    }

    /**
     * Compute fingerprint using the SAME algorithm as ProcessValidatedRowsJob.
     * Only includes content fields (not vendor_id, catalog_name, etc.)
     * so the fingerprint matches what the job produces for new uploads.
     */
    private function computeFingerprint(CatalogItem $item): string
    {
        $content = [];

        foreach ($item->getAttributes() as $column => $value) {
            if (in_array($column, $this->nonComparableColumns, true)) {
                continue;
            }

            if (is_null($value) || $value === '' || $value === '[]' || $value === []) {
                $content[$column] = null;
            } elseif (is_numeric($value)) {
                $content[$column] = (string) (float) $value;
            } else {
                $content[$column] = trim((string) $value);
            }
        }

        ksort($content);

        return md5(json_encode($content));
    }
}
