<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('secure_messages', function (Blueprint $table) {
            $table->id();
            $table->string('queue_id', 32)->unique()->nullable();
            $table->string('message_id_header', 512)->nullable();
            $table->string('sender_email');
            $table->string('sender_name')->nullable();
            $table->string('subject', 512)->nullable();
            $table->text('enc_key');
            $table->longText('body_text')->nullable();
            $table->longText('body_html')->nullable();
            $table->unsignedBigInteger('size_bytes')->default(0);
            $table->timestamp('expires_at')->index();
            $table->timestamps();
        });

        Schema::create('message_recipients', function (Blueprint $table) {
            $table->id();
            $table->foreignId('secure_message_id')->constrained()->cascadeOnDelete();
            $table->string('email');
            $table->char('token', 64)->unique();
            $table->string('password_hash');
            $table->unsignedTinyInteger('failed_attempts')->default(0);
            $table->timestamp('locked_until')->nullable();
            $table->timestamp('notified_at')->nullable();
            $table->timestamp('password_sent_at')->nullable();
            $table->timestamp('first_viewed_at')->nullable();
            $table->timestamp('last_viewed_at')->nullable();
            $table->unsignedInteger('download_count')->default(0);
            $table->timestamps();
            $table->unique(['secure_message_id', 'email']);
        });

        Schema::create('attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('secure_message_id')->constrained()->cascadeOnDelete();
            $table->string('filename', 512);
            $table->string('mime')->nullable();
            $table->unsignedBigInteger('size_bytes')->default(0);
            $table->string('disk_path', 512);
            $table->timestamps();
        });

        Schema::create('audit_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('secure_message_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('message_recipient_id')->nullable()->constrained()->nullOnDelete();
            $table->string('event', 64)->index();
            $table->string('ip', 45)->nullable();
            $table->json('details')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_events');
        Schema::dropIfExists('attachments');
        Schema::dropIfExists('message_recipients');
        Schema::dropIfExists('secure_messages');
    }
};
