<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Add unique constraint to prevent duplicate row numbers per upload.
     * This enables safe retries and concurrent execution protection.
     */
    public function up(): void
    {
        // First, detect existing duplicates
        $duplicates = DB::table('catalog_upload_rows')
            ->select(['catalog_upload_id', 'row_number', DB::raw('COUNT(*) as cnt')])
            ->groupBy(['catalog_upload_id', 'row_number'])
            ->havingRaw('COUNT(*) > 1')
            ->get();

        if ($duplicates->isNotEmpty()) {
            throw new Exception(
                'Duplicate catalog_upload_rows detected. Clean these records before applying constraint. ' .
                    'Affected uploads and rows: ' . $duplicates->toJson()
            );
        }

        Schema::table('catalog_upload_rows', function (Blueprint $table) {
            $table->unique(['catalog_upload_id', 'row_number'], 'catalog_upload_rows_row_unique');
        });
    }

    public function down(): void
    {
        Schema::table('catalog_upload_rows', function (Blueprint $table) {
            $table->dropUnique('catalog_upload_rows_row_unique');
        });
    }
};
