<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('entra_users', function (Blueprint $table) {
            $table->id();
            $table->uuid('entra_id')->unique();
            $table->string('upn')->index();
            $table->string('mail')->nullable()->index();
            $table->string('display_name')->nullable();
            $table->string('given_name')->nullable();
            $table->string('surname')->nullable();
            $table->string('job_title')->nullable();
            $table->string('department')->nullable();
            $table->string('company_name')->nullable();
            $table->string('office_location')->nullable();
            $table->string('business_phone')->nullable();
            $table->string('mobile_phone')->nullable();
            $table->string('fax_number')->nullable();
            $table->string('street_address')->nullable();
            $table->string('postal_code', 20)->nullable();
            $table->string('city')->nullable();
            $table->string('country')->nullable();
            $table->json('proxy_addresses')->nullable();
            $table->boolean('account_enabled')->default(true);
            $table->json('raw')->nullable();
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('entra_users');
    }
};
