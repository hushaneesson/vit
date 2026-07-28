<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add the catalog_export_id foreign key to the catalog_submissions table.
     *
     * This migration runs after catalog_exports table has been created.
     * The FK was intentionally omitted from the create table migration
     * because catalog_exports didn't exist at that point.
     */
    public function up(): void
    {
        Schema::table('catalog_submissions', function (Blueprint $table) {
            // The column already exists from the create migration;
            // we only need to add the foreign key constraint now
            // that catalog_exports table exists.
            $table->foreign('catalog_export_id')
                ->references('id')
                ->on('catalog_exports')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('catalog_submissions', function (Blueprint $table) {
            $table->dropForeign(['catalog_export_id']);
        });
    }
};
