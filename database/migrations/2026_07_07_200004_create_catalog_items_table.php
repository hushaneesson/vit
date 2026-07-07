<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One row per product/catalog item. Traceable to both the client (who
     * did it) and the vendor (which company it belongs to). Data isolation
     * is enforced via vendor_id so multiple clients under the same vendor
     * see the same catalog data.
     *
     * Standard/simple field values are stored in `field_values` (JSON,
     * keyed by catalog_field_definitions.field_key). Complex/multi-value
     * fields (Phase 5) are stored pre-joined in field_values as well, using
     * each field's configured join_separator, so the Excel generator can
     * read them directly without re-deriving the join logic.
     */
    public function up(): void
    {
        Schema::create('catalog_items', function (Blueprint $table) {
            $table->id();

            $table->foreignId('vendor_id')->constrained('vendors')->cascadeOnDelete();
            $table->foreignId('client_id')->constrained('clients')->cascadeOnDelete();

            $table->string('catalog_name'); // Vendor Catalog Name, e.g. "XYZ Company-General Catalog"
            $table->string('vendor_part_number'); // dealer sku — unique per product (per vendor)

            $table->json('field_values'); // field_key => value (already formatted/joined per field rules)

            $table->enum('status', ['draft', 'ready', 'submitted'])->default('draft');

            $table->timestamps();

            $table->unique(['vendor_id', 'vendor_part_number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('catalog_items');
    }
};
