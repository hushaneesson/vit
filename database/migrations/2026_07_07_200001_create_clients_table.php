<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * `clients` = the actual login accounts for people at a vendor company.
     * Each client belongs to a vendor (vendor_id). Clients log in via
     * email-OTP only — there is intentionally NO password column on this
     * table. A vendor can have more than one client.
     */
    public function up(): void
    {
        Schema::create('clients', function (Blueprint $table) {
            $table->id();

            $table->foreignId('vendor_id')->constrained('vendors')->cascadeOnDelete();

            $table->string('name');
            $table->string('email')->unique();
            $table->string('phone')->nullable();
            $table->string('title')->nullable(); // job title / role at the vendor company

            // Invitation / activation lifecycle — admin invites, client activates
            // before they can request an OTP and log in.
            $table->enum('status', ['invited', 'active', 'disabled'])->default('invited');
            $table->string('invitation_token')->nullable()->unique();
            $table->timestamp('invited_at')->nullable();
            $table->timestamp('activated_at')->nullable();

            // OTP login fields (no password ever stored for clients)
            $table->string('otp_code')->nullable();
            $table->timestamp('otp_expires_at')->nullable();
            $table->timestamp('last_login_at')->nullable();

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('clients');
    }
};
