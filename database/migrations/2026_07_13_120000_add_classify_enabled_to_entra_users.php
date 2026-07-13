<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pro-Benutzer-Schalter für die „Sicher versenden?"-Rückfrage: Ist er aus,
 * antwortet /api/classify für Mails dieses Absenders sofort mit ask=false —
 * das Add-in kann in Entra für alle ausgerollt bleiben und wird in SecWay
 * individuell (de)aktiviert. Der Entra-Sync lässt die Spalte unangetastet
 * (updateOrCreate mit fester Attributliste); nur wenn ein Benutzer aus dem
 * Sync fällt und später neu angelegt wird, gilt wieder der Default (an).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('entra_users', function (Blueprint $table) {
            $table->boolean('classify_enabled')->default(true)->after('account_enabled');
        });
    }

    public function down(): void
    {
        Schema::table('entra_users', function (Blueprint $table) {
            $table->dropColumn('classify_enabled');
        });
    }
};
