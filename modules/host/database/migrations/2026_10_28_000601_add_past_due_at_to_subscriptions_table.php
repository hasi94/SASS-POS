<?php

/*
 * T-016 Ruling 1: AC-16's grace-period clock needs to know when a
 * subscription's status *entered* `past_due`; no existing column records it,
 * and `current_period_end` is a different fact that may predate or postdate
 * it.
 *
 * `ChangeSubscriptionStatus` sets `past_due_at` when the status enters
 * `past_due` and clears it when the status leaves. The scheduled sweep stamps
 * `now` on any `past_due` row where it is still null, so a row written before
 * this task's code existed starts its clock at the first sweep that sees it.
 *
 * @phase expand
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->dateTime('past_due_at', 3)->nullable()->after('cancellation_reason');
            $table->index(['status', 'past_due_at']);
        });
    }
};
