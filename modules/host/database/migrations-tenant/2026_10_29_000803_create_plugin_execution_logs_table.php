<?php

/*
 * One row per hook invocation (docs/database.md section 6.20, T-019 D-4,
 * AC-18).
 *
 * `mode` ships the corrected **five**-value application enum
 * `(add, before, after, filter, override)`, not the four-value enum the
 * document's prose currently shows (D-4; step 16 corrects the prose). The
 * primary key is an auto-incrementing BIGINT, not a ULID: this is an
 * append-only log and the monotonic key is also the cheapest read order,
 * the same reasoning `platform_audit_logs` uses.
 *
 * `Flexipos\PluginSdk\Gateway\ExecutionLog` is the only writer.
 *
 * @phase expand
 */

use Flexipos\Kernel\Schema\Support\IdentifierColumn;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plugin_execution_logs', function (Blueprint $table) {
            $table->id();
            $table->string('plugin_name', 100);
            $table->string('point', 60);
            $table->string('mode', 30);
            $table->integer('duration_ms');
            $table->string('status', 30);
            $table->text('error')->nullable();
            IdentifierColumn::nullable($table, 'request_id');
            $table->dateTime('created_at', 3);

            $table->index(['plugin_name', 'created_at']);
        });
    }
};
