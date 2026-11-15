<?php

/*
 * The members of a tax group (docs/database.md section 6.4, T-029 AC-9).
 *
 * The documented primary key is `(tax_group_id, tax_id)`: a tax appears once
 * in a group. `sequence` orders the members, and **this task adds one key the
 * document does not state**, `unique (tax_group_id, sequence)`: compounding
 * is order-dependent (D-15), so without it the order a compound tax sees its
 * predecessors in would depend on row insertion order.
 *
 * The group's key cascades (removing a group removes its membership; the
 * delete guard on the group itself is the application's), the tax's does not.
 * No timestamps and no soft delete: a membership is data about the group,
 * and removing a row is the whole of editing it.
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
        Schema::create('tax_group_taxes', function (Blueprint $table) {
            IdentifierColumn::required($table, 'tax_group_id');
            IdentifierColumn::required($table, 'tax_id');
            $table->unsignedTinyInteger('sequence');

            $table->primary(['tax_group_id', 'tax_id']);
            $table->unique(['tax_group_id', 'sequence']);

            $table->foreign('tax_group_id')->references('id')->on('tax_groups')->cascadeOnDelete();
            $table->foreign('tax_id')->references('id')->on('taxes');
        });
    }
};
