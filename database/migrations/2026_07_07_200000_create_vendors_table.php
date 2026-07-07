<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * `vendors` = general vendor (company) data. Created & managed ONLY by the
     * admin (Onboarding Manager). This is where the registered/activated
     * marketplace vendor name lives (the value the Excel generator pulls for
     * the vendor column). No auth/password fields live here — vendors do not
     * log in directly. Individual login accounts live in `clients`.
     */
    public function up(): void
    {
        Schema::create('vendors', function (Blueprint $table) {
            $table->id();

            // Registered / activated marketplace name — must match what VIT
            // has on file. Only the admin may set/edit this.
            $table->string('name');

            $table->string('legal_company_name')->nullable();

            // Company / address details
            $table->string('address_line_1')->nullable();
            $table->string('address_line_2')->nullable();
            $table->string('city')->nullable();
            $table->string('state')->nullable();
            $table->string('postal_code')->nullable();
            $table->string('country')->nullable();

            // Primary contact on file for the company (informational — not a login)
            $table->string('primary_contact_name')->nullable();
            $table->string('primary_contact_email')->nullable();
            $table->string('primary_contact_phone')->nullable();

            $table->enum('status', ['active', 'inactive', 'pending'])->default('pending');

            $table->text('notes')->nullable();

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('vendors');
    }
};
