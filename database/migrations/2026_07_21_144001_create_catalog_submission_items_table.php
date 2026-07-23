<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Pivot table between catalog_submissions and catalog_items.
     *
     * Snapshot of the exact CatalogItem IDs that were included in a
     * submission at the time of review request. This ensures auditability:
     * even if catalog items are later updated or deleted, the submission
     * record always reflects what the admin reviewed.
     */
    public function up(): void
    {
        Schema::create('catalog_submission_items', function (Blueprint $table) {
            $table->id();

            $table->foreignId('catalog_submission_id')
                ->constrained('catalog_submissions')
                ->cascadeOnDelete();

            $table->foreignUuid('catalog_item_id')
                ->constrained('catalog_items')
                ->cascadeOnDelete();

            $table->timestamps();

            $table->unique(['catalog_submission_id', 'catalog_item_id'], 'submission_item_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('catalog_submission_items');
    }
};
