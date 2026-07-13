<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pro-Benutzer-Schalter, WO der Signaturblock angefügt wird: an = das
 * Compose-Add-in fügt ihn beim Schreiben ein (sichtbar im Entwurf), aus =
 * /api/signature antwortet none, das Add-in bleibt leer und das Gateway
 * hängt den Block serverseitig an (wie bei Clients ohne Add-in). Das
 * Add-in kann so für alle ausgerollt bleiben. Der Entra-Sync lässt die
 * Spalte unangetastet (updateOrCreate mit fester Attributliste).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('entra_users', function (Blueprint $table) {
            $table->boolean('signature_client_enabled')->default(true)->after('classify_enabled');
        });
    }

    public function down(): void
    {
        Schema::table('entra_users', function (Blueprint $table) {
            $table->dropColumn('signature_client_enabled');
        });
    }
};
