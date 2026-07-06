<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('send_rules', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            // attachment_name | keyword | birthdate
            $table->string('type', 20);
            $table->text('terms')->nullable();     // Liste (komma-/zeilengetrennt); bei birthdate ungenutzt
            $table->unsignedInteger('threshold')->default(1); // keyword: min. verschiedene Treffer; birthdate: min. Alter in Jahren
            $table->integer('score')->default(50);  // Punktebeitrag bei Treffer
            $table->boolean('active')->default(true);
            $table->timestamps();
        });

        Schema::create('send_classify_log', function (Blueprint $table) {
            $table->id();
            // Standard-Log: KEINE Mailinhalte, nur Score/Regeln/Metadaten
            $table->unsignedInteger('score')->default(0);
            $table->boolean('asked')->default(false);
            $table->json('rule_hits')->nullable();  // [{id,name,score}]
            $table->unsignedSmallInteger('recipient_count')->default(0);
            $table->unsignedSmallInteger('external_count')->default(0);
            $table->boolean('smime_covered')->default(false);
            $table->string('user_choice', 10)->nullable(); // secure | normal | null
            $table->timestamps();
        });

        // Sinnvolle Startregeln (Jugendhilfe-Kontext) — vom Betreiber anpassbar
        $now = now();
        DB::table('send_rules')->insert([
            ['name' => 'Sensible Anhänge', 'type' => 'attachment_name',
                'terms' => "Hilfeplan\nLeistungsplan\nPEP\nStammblatt\nEntwicklungsbericht",
                'threshold' => 1, 'score' => 70, 'active' => true, 'created_at' => $now, 'updated_at' => $now],
            ['name' => 'Fachbegriffe (Häufung)', 'type' => 'keyword',
                'terms' => "Sorgerecht\npsychisch\nKrise\nDiagnose\nJugendamt\nKlient\nMutter\nVater\nemotional",
                'threshold' => 2, 'score' => 40, 'active' => true, 'created_at' => $now, 'updated_at' => $now],
            ['name' => 'Geburtsdatum', 'type' => 'birthdate',
                'terms' => null, 'threshold' => 2, 'score' => 20, 'active' => true, 'created_at' => $now, 'updated_at' => $now],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('send_classify_log');
        Schema::dropIfExists('send_rules');
    }
};
