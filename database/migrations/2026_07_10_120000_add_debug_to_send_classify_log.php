<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('send_classify_log', function (Blueprint $table) {
            // Nur befüllt, wenn der Diagnose-/Lernmodus (Setting classify_debug)
            // aktiv ist — enthält dann echten Mailinhalt. Standard: leer.
            $table->text('debug_subject')->nullable();
            $table->mediumText('debug_body')->nullable();
            $table->json('debug_attachments')->nullable();
            $table->json('debug_rules')->nullable(); // Einzelwertung ALLER Regeln
        });
    }

    public function down(): void
    {
        Schema::table('send_classify_log', function (Blueprint $table) {
            $table->dropColumn(['debug_subject', 'debug_body', 'debug_attachments', 'debug_rules']);
        });
    }
};
