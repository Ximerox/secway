<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Zweiter Score-Wert je Send-Regel: `review_score` gewichtet die Regel in der
 * nachgelagerten KI-Prüfung (Gateway), während `score` weiterhin die Live-
 * Rückfrage im Plugin steuert. Bestehende Regeln starten mit review_score =
 * score (identisches Verhalten, bis der Admin es differenziert).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('send_rules', function (Blueprint $table) {
            $table->integer('review_score')->default(0)->after('score');
        });

        DB::table('send_rules')->update(['review_score' => DB::raw('score')]);
    }

    public function down(): void
    {
        Schema::table('send_rules', function (Blueprint $table) {
            $table->dropColumn('review_score');
        });
    }
};
