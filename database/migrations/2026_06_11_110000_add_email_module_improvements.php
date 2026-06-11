<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Miglioramenti modulo email rispetto allo schema legacy:
 * - email_log.body_html + email_log.queue_id: il corpo viene salvato anche nel log
 *   (il legacy lo ricostruiva con un JOIN euristico batch+email sulla coda);
 * - email_queue.next_attempt_at: backoff esponenziale tra i retry
 *   (il legacy ritentava ad ogni run senza attesa).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('email_log', function (Blueprint $table) {
            $table->text('body_html')->nullable()->after('template_codice');
            $table->uuid('queue_id')->nullable()->after('batch_id');
        });

        Schema::table('email_queue', function (Blueprint $table) {
            $table->dateTime('next_attempt_at')->nullable()->after('last_attempt_at');
        });
    }

    public function down(): void
    {
        Schema::table('email_log', function (Blueprint $table) {
            $table->dropColumn(['body_html', 'queue_id']);
        });

        Schema::table('email_queue', function (Blueprint $table) {
            $table->dropColumn('next_attempt_at');
        });
    }
};
