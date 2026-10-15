<?php

use Flexipos\Kernel\Schema\Support\MigrationTree;
use Flexipos\Kernel\Schema\Support\SourceRules;

/*
|--------------------------------------------------------------------------
| QA, T-008: shapes the shipped fixtures do not try
|--------------------------------------------------------------------------
|
| The static rules are the only evidence for AC-24 (source half), AC-33, AC-34
| and AC-35 while the engine is on hold, and each is proven against the
| fixtures dev wrote. A rule is only as good as the shapes somebody tried to
| get past it, so these are the shapes dev did not try. Every case builds the
| migration text in memory and hands it to SourceRules::inspect(), exactly as
| the tree scan does; nothing touches a database or the tree.
|
| Each violating snippet is paired with a control from the same family that the
| rule is known to report, so that "reported nothing" cannot be a fact about an
| empty or unparseable snippet.
*/

/**
 * A migration source with the given phase and body.
 */
function qaMigration(string $phase, string $body): string
{
    return "<?php\n/*\n * @phase {$phase}\n */\n"
        ."use Illuminate\\Database\\Migrations\\Migration;\n"
        ."use Illuminate\\Database\\Schema\\Blueprint;\n"
        ."use Illuminate\\Support\\Facades\\DB;\n"
        ."use Illuminate\\Support\\Facades\\Schema;\n"
        ."return new class extends Migration {\n    public function up(): void\n    {\n{$body}\n    }\n};\n";
}

/**
 * The rules reported, as a sorted unique list.
 *
 * @return list<string>
 */
function qaRulesReported(string $source, MigrationTree $tree): array
{
    $rules = array_map(
        static fn (array $violation): string => $violation['rule'],
        SourceRules::inspect($source, $tree)
    );

    $rules = array_values(array_unique($rules));
    sort($rules);

    return $rules;
}

it('reports a foreign key added to an existing table through the shapes the rule already knows', function () {
    // The control: the shape the shipped fixture uses, in the phase that may not have it.
    $viaForeign = qaMigration('expand', "Schema::table('settings', function (Blueprint \$table) {\n"
        ."    \$table->foreign('updated_by')->references('id')->on('employees');\n});");
    $viaConstrained = qaMigration('expand', "Schema::table('settings', function (Blueprint \$table) {\n"
        ."    \$table->foreignUlid('updated_by')->constrained('employees');\n});");

    expect(qaRulesReported($viaForeign, MigrationTree::Tenant))->toContain('AC-34')
        ->and(qaRulesReported($viaConstrained, MigrationTree::Tenant))->toContain('AC-34');
});

it('reports a foreign key added to an existing table through foreignId and references alone', function () {
    // ForeignIdColumnDefinition::references() calls Blueprint::foreign() itself,
    // so this creates the constraint without the text "->foreign(" or
    // "->constrained(" appearing anywhere. It is the way D-5's four deferred
    // keys will most naturally be written by the next task.
    $source = qaMigration('expand', "Schema::table('settings', function (Blueprint \$table) {\n"
        ."    \$table->foreignUlid('updated_by')->references('id')->on('employees');\n});");

    expect(qaRulesReported($source, MigrationTree::Tenant))->toContain('AC-34');
});

it('reports a tenant_id column added by a raw statement', function () {
    // The control: the quoted-literal shape the rule is written for.
    $quoted = qaMigration('expand', "Schema::table('settings', function (Blueprint \$table) {\n"
        ."    \$table->string('tenant_id', 26);\n});");

    $raw = qaMigration('expand', "DB::statement('alter table settings add column tenant_id char(26) null');");

    expect(qaRulesReported($quoted, MigrationTree::Tenant))->toContain('AC-24')
        ->and(qaRulesReported($raw, MigrationTree::Tenant))->toContain('AC-24');
});

it('reports a timestamp column declared in raw SQL', function () {
    // The control: the builder shape the rule already reports.
    $builder = qaMigration('expand', "Schema::create('qa_rows', function (Blueprint \$table) {\n"
        ."    \$table->timestamp('seen_at');\n});");

    $raw = qaMigration('expand', "DB::statement('alter table qa_rows add column seen_at timestamp null');");

    expect(qaRulesReported($builder, MigrationTree::Master))->toContain('AC-35(c)')
        ->and(qaRulesReported($raw, MigrationTree::Master))->toContain('AC-35(c)');
});
