<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('signature_templates', function (Blueprint $table) {
            $table->text('sender_exclude')->nullable()->after('sender_group_name');
            $table->text('recipient_include')->nullable()->after('direction');
            $table->text('recipient_exclude')->nullable()->after('recipient_include');
            // Weiterverarbeitung getrennt fuer angewandt / nicht angewandt
            $table->string('on_applied', 10)->default('stop')->after('valid_until');
            $table->string('on_not_applied', 10)->default('continue')->after('on_applied');
        });

        // Bestehendes continue_processing uebernehmen: true = weiter, false = stopp
        DB::table('signature_templates')->where('continue_processing', true)->update(['on_applied' => 'continue']);
        DB::table('signature_templates')->where('continue_processing', false)->update(['on_applied' => 'stop']);

        Schema::table('signature_templates', fn (Blueprint $t) => $t->dropColumn('continue_processing'));

        Schema::create('signature_qr_codes', function (Blueprint $table) {
            $table->id();
            $table->string('label');
            $table->text('text');
            $table->unsignedSmallInteger('size')->default(150);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::table('signature_templates', function (Blueprint $table) {
            $table->boolean('continue_processing')->default(false)->after('valid_until');
        });
        DB::table('signature_templates')->where('on_applied', 'continue')->update(['continue_processing' => true]);

        Schema::table('signature_templates', function (Blueprint $table) {
            $table->dropColumn(['sender_exclude', 'recipient_include', 'recipient_exclude', 'on_applied', 'on_not_applied']);
        });
        Schema::dropIfExists('signature_qr_codes');
    }
};
