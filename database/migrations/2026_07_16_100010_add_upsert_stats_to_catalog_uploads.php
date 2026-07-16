<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('catalog_uploads', function (Blueprint $table) {
            $table->unsignedInteger('updated_rows')->default(0)->after('error_rows');
            $table->unsignedInteger('skipped_rows')->default(0)->after('updated_rows');
            $table->json('skipped_item_names')->nullable()->after('skipped_rows');
        });
    }

    public function down(): void
    {
        Schema::table('catalog_uploads', function (Blueprint $table) {
            $table->dropColumn(['updated_rows', 'skipped_rows', 'skipped_item_names']);
        });
    }
};
