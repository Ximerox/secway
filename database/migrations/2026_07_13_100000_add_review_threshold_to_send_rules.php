<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Getrennter Schwell-/Faktorwert für die nachgelagerte Prüfung: bei der
 * KI-Regel ist `threshold` der Faktor (%) auf den KI-Wert — der soll fürs
 * kleine Plugin-Modell und das große nachgelagerte Modell unterschiedlich
 * einstellbar sein. Für keyword/birthdate bleibt das Kriterium in beiden
 * Modi gleich (der Editor setzt beide Spalten identisch). Backfill =
 * threshold, damit sich das Verhalten durch die Migration nicht ändert.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('send_rules', function (Blueprint $table) {
            $table->unsignedInteger('review_threshold')->default(0)->after('threshold');
        });

        DB::table('send_rules')->update(['review_threshold' => DB::raw('threshold')]);
    }

    public function down(): void
    {
        Schema::table('send_rules', function (Blueprint $table) {
            $table->dropColumn('review_threshold');
        });
    }
};
