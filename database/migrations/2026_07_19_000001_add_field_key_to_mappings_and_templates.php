<?php

use App\Models\CatalogUploadColumnMapping;
use App\Models\VendorMappingTemplateField;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Migrate catalog_upload_column_mappings and vendor_mapping_template_fields
     * from catalog_field_id (FK to catalog_fields) to field_key (string).
     *
     * This is a one-way migration: after this runs, the old FK columns are
     * dropped and the catalog_fields table is no longer referenced.
     */
    public function up(): void
    {
        // --- catalog_upload_column_mappings ---
        Schema::table('catalog_upload_column_mappings', function (Blueprint $table) {
            $table->string('field_key', 100)->nullable()->after('catalog_field_id');
        });

        // Backfill field_key from the catalog_fields relationship
        CatalogUploadColumnMapping::query()
            ->whereNotNull('catalog_field_id')
            ->with('catalogField')
            ->each(function (CatalogUploadColumnMapping $mapping) {
                if ($mapping->catalogField) {
                    $mapping->field_key = $mapping->catalogField->field_key;
                    $mapping->save();
                }
            });

        // Make field_key NOT NULL after backfill
        Schema::table('catalog_upload_column_mappings', function (Blueprint $table) {
            $table->string('field_key', 100)->nullable(false)->change();
            $table->dropForeign(['catalog_field_id']);
            $table->dropColumn('catalog_field_id');
        });

        // --- vendor_mapping_template_fields ---
        Schema::table('vendor_mapping_template_fields', function (Blueprint $table) {
            $table->string('field_key', 100)->nullable()->after('catalog_field_id');
        });

        // Backfill field_key from the catalog_fields relationship
        VendorMappingTemplateField::query()
            ->whereNotNull('catalog_field_id')
            ->with('catalogField')
            ->each(function (VendorMappingTemplateField $field) {
                if ($field->catalogField) {
                    $field->field_key = $field->catalogField->field_key;
                    $field->save();
                }
            });

        // Make field_key NOT NULL after backfill
        Schema::table('vendor_mapping_template_fields', function (Blueprint $table) {
            $table->string('field_key', 100)->nullable(false)->change();
            $table->dropForeign(['catalog_field_id']);
            $table->dropColumn('catalog_field_id');
        });
    }

    public function down(): void
    {
        // Reverse catalog_upload_column_mappings
        Schema::table('catalog_upload_column_mappings', function (Blueprint $table) {
            $table->foreignId('catalog_field_id')->nullable()->after('field_key');
        });

        // Reverse vendor_mapping_template_fields
        Schema::table('vendor_mapping_template_fields', function (Blueprint $table) {
            $table->foreignId('catalog_field_id')->nullable()->after('field_key');
        });

        // We can't reliably backfill catalog_field_id from field_key since
        // the catalog_fields table may have changed. This is a destructive
        // rollback that leaves catalog_field_id as null.
        Schema::table('catalog_upload_column_mappings', function (Blueprint $table) {
            $table->dropColumn('field_key');
        });

        Schema::table('vendor_mapping_template_fields', function (Blueprint $table) {
            $table->dropColumn('field_key');
        });
    }
};
