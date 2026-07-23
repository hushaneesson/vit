<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add the catalog_submission_id FK to the catalog_exports table.
     *
     * This is a separate migration because catalog_exports was created
     * before catalog_submissions exists. The FK is nullable — an export
     * may exist independently before being linked to an approval.
     */
    public function up(): void
    {
        Schema::table('catalog_exports', function (Blueprint $table) {
            $table->foreignId('catalog_submission_id')->nullable()
                ->constrained('catalog_submissions')
                ->nullOnDelete()
                ->after('vendor_id');
        });
    }

    public function down(): void
    {
        Schema::table('catalog_exports', function (Blueprint $table) {
            $table->dropConstrainedForeignId('catalog_submission_id');
        });
    }
};
