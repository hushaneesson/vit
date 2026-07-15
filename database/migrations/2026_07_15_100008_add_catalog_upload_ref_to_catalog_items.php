<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('catalog_items', function (Blueprint $table) {
            $table->string('catalog_name')->nullable()->after('vendor_sku');
            $table->foreignId('catalog_upload_id')->nullable()->constrained('catalog_uploads')->nullOnDelete()->after('catalog_name');
        });
    }

    public function down(): void
    {
        Schema::table('catalog_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('catalog_upload_id');
            $table->dropColumn('catalog_name');
        });
    }
};
