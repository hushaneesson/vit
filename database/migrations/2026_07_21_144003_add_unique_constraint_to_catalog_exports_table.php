<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('catalog_exports', function (Blueprint $table) {
            $table->unique('catalog_submission_id', 'catalog_exports_submission_unique');
        });
    }

    public function down(): void
    {
        Schema::table('catalog_exports', function (Blueprint $table) {
            $table->dropUnique('catalog_exports_submission_unique');
        });
    }
};
