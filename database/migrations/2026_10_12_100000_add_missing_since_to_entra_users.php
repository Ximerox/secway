<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Konten, die der Entra-Sync nicht mehr findet, werden zunächst nur markiert
 * statt sofort gelöscht. Sonst setzt ein unvollständiger Graph-Abruf oder eine
 * Filteränderung die Schalter pro Benutzer (Add-in-Prüfung, Signatur im Client)
 * still auf den Standard zurück. Gelöscht wird erst nach einer Frist.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('entra_users', function (Blueprint $t) {
            $t->timestamp('missing_since')->nullable()->after('synced_at')->index();
        });
    }

    public function down(): void
    {
        Schema::table('entra_users', function (Blueprint $t) {
            $t->dropColumn('missing_since');
        });
    }
};
