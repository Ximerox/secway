<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Herkunft eines Klassifizierungs-Logs: 'addin' = Live-Prüfung über
 * /api/classify (Outlook-Add-in), 'review' = nachgelagerte Prüfung im
 * Gateway (MailIngest). Review-Einträge speichern immer Inhalt + Einzel-
 * wertung (Kalibrierung); die Inhalte werden nach 7 Tagen automatisch
 * entfernt, die Kennzahlen bleiben.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('send_classify_log', function (Blueprint $table) {
            $table->string('source', 10)->default('addin')->after('id');
            $table->index(['source', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::table('send_classify_log', function (Blueprint $table) {
            $table->dropIndex(['source', 'created_at']);
            $table->dropColumn('source');
        });
    }
};
