<?php

/*
 * Customer notes (docs/database.md section 6.9, T-028 AC-34).
 *
 * Ordinary leaf CRUD with no append-only constraint: `AddCustomerNote` and
 * `UpdateCustomerNote` both write this table. There is no delete slice
 * (no AC asks for one).
 *
 * `customer_id`'s foreign key is this task's second `CASCADE`
 * (docs/database.md section 6.9): a note is meaningless once its customer
 * is gone for good.
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
        Schema::create('customer_notes', function (Blueprint $table) {
            IdentifierColumn::primary($table);
            $table->dateTime('created_at', 3);
            $table->dateTime('updated_at', 3);
            IdentifierColumn::nullable($table, 'created_by');
            IdentifierColumn::nullable($table, 'updated_by');
            IdentifierColumn::required($table, 'customer_id');
            $table->text('note');
            $table->boolean('pinned')->default(false);
            $table->date('follow_up_date')->nullable();

            $table->foreign('customer_id')->references('id')->on('customers')->cascadeOnDelete();
            $table->foreign('created_by')->references('id')->on('employees');
            $table->foreign('updated_by')->references('id')->on('employees');
        });
    }
};
