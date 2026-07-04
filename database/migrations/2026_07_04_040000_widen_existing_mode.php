<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('signature_templates', function (Blueprint $table) {
            // Platz für den dritten Modus 'replace_all'
            $table->string('existing_mode', 20)->default('replace')->change();
        });
    }

    public function down(): void
    {
        Schema::table('signature_templates', function (Blueprint $table) {
            $table->string('existing_mode', 10)->default('replace')->change();
        });
    }
};
