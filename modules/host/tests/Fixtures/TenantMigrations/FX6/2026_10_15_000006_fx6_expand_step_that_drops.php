<?php

/*
 * FX-6: an expand step that calls a drop (T-008 step 8).
 *
 * The rule-violating fixture of the six, and the only one that is wrong on
 * purpose: AC-33 says an expand step adds and does nothing else to anything
 * that existed before it ran, so `SourceRules` must report this file. Its
 * sibling under tests/Fixtures/MigrationSourceRules/ proves the *static rule*
 * red as text; this one exists so the same violation can be handed to the
 * runner as a member of a set, which is a different question - a set is not
 * silently rule-clean because its files are in a test directory.
 *
 * It is never applied. The table it names is FX-3's, so applying it would be
 * destroying a fixture to observe a rule that is answered by reading the file.
 *
 * @phase expand
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('fx_offline_marker');
    }
};
