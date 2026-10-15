<?php

use Flexipos\Kernel\Schema\Support\MigrationCatalogue;
use Flexipos\Kernel\Schema\Support\MigrationPhase;
use Flexipos\Kernel\Schema\Support\SourceRules;

/*
 * T-008 step 5: the four fields the catalogue derives from a file.
 *
 * No TestCase binding and no database: every case here is a pure function over
 * source text, which is also why they are the cheapest place to pin the
 * normalisation and the two fail-closed defaults.
 */

/**
 * One migration source, with the tags and the `up()` body the case needs.
 */
function catalogueSource(string $tags, string $statements): string
{
    return "<?php\n\n/*\n".$tags." */\n\nreturn new class\n{\n    public function up(): void\n    {\n        "
        .$statements."\n    }\n};\n";
}

/*
|--------------------------------------------------------------------------
| The checksum is of the content, not of the line endings
|--------------------------------------------------------------------------
*/

it('gives CRLF, CR and LF copies of one file the same checksum', function () {
    $lf = "<?php\n\nreturn 1;\n";

    // This repository is developed on Windows with core.autocrlf on, so a
    // checksum of the raw bytes would make the committed fixture disagree with
    // itself between a working tree and CI - and the disagreement would read as
    // a released migration having been rewritten, which is the one thing the
    // checksum exists to detect.
    expect(MigrationCatalogue::checksumOf(str_replace("\n", "\r\n", $lf)))
        ->toBe(MigrationCatalogue::checksumOf($lf))
        ->and(MigrationCatalogue::checksumOf(str_replace("\n", "\r", $lf)))
        ->toBe(MigrationCatalogue::checksumOf($lf));

    // The control: a real content change moves it. Without this, a checksum
    // function that returned a constant would pass the two assertions above.
    expect(MigrationCatalogue::checksumOf("<?php\n\nreturn 2;\n"))
        ->not->toBe(MigrationCatalogue::checksumOf($lf));
});

/*
|--------------------------------------------------------------------------
| Both declared fields default to the strictest reading
|--------------------------------------------------------------------------
*/

it('reads the phase from a comment and defaults to expand', function () {
    $create = "Schema::create('a', function (\$t) { \$t->string('id'); });";

    expect(MigrationPhase::fromSource(catalogueSource('', $create)))->toBe(MigrationPhase::Expand)
        ->and(MigrationPhase::fromSource(catalogueSource(" * @phase contract\n", $create)))->toBe(MigrationPhase::Contract)
        ->and(MigrationPhase::fromSource(catalogueSource(" * @phase backfill\n", $create)))->toBe(MigrationPhase::Backfill);
});

it('treats requires-offline as declared only when the whole tag is there', function () {
    $create = "Schema::create('a', function (\$t) { \$t->string('id'); });";

    $offline = static fn (string $tags): bool => MigrationCatalogue::declaresOffline(catalogueSource($tags, $create));

    expect($offline(' * '.MigrationCatalogue::OFFLINE_TAG."\n"))->toBeTrue()
        // No tag at all is "can be applied while the shop is serving", which is
        // the plan the runner may apply without stopping a till.
        ->and($offline(''))->toBeFalse()
        // A tag that is not the tag. Reading this as a declaration would be a
        // licence granted by a typo, in the direction that stops a shop.
        ->and($offline(' * '.MigrationCatalogue::OFFLINE_TAG."-never\n"))->toBeFalse();
});

/*
|--------------------------------------------------------------------------
| is_destructive is read from the statements, never declared
|--------------------------------------------------------------------------
*/

it('calls a migration destructive for what it does, whatever phase it declares', function () {
    // A contract step that drops a column of a table it did not create: this is
    // the answer D-10's refusal is keyed on, and the phase does not soften it.
    expect(SourceRules::isDestructive(catalogueSource(
        " * @phase contract\n",
        "Schema::table('settings', function (\$t) { \$t->dropColumn('legacy'); });"
    )))->toBeTrue();

    // TRUNCATE leaves the shape alone and destroys every row, so it trips none
    // of AC-33's drop patterns and is asked for separately.
    expect(SourceRules::isDestructive(catalogueSource(
        " * @phase backfill\n",
        "DB::statement('truncate table audit_logs');"
    )))->toBeTrue();

    // The two controls. A plain create is not destructive, or every migration
    // in the baseline would be refused while backups are unavailable...
    expect(SourceRules::isDestructive(catalogueSource(
        '',
        "Schema::create('a', function (\$t) { \$t->string('id'); });"
    )))->toBeFalse();

    // ...and neither is dropping something the same migration just created,
    // which nothing can lose data from. This is the narrowing AC-33 already
    // makes, and asserting it here is what stops it being widened into a rule
    // that bans the word.
    expect(SourceRules::isDestructive(catalogueSource(
        '',
        "Schema::create('a', function (\$t) { \$t->string('id'); });\n"
        ."        Schema::table('a', function (\$t) { \$t->dropColumn('id'); });"
    )))->toBeFalse();
});

/*
|--------------------------------------------------------------------------
| The row shape is one shape
|--------------------------------------------------------------------------
*/

it('encodes and decodes a catalogue through the row shape it inserts', function () {
    $entry = new MigrationCatalogue(
        version: 7,
        migration: '2026_10_15_000007_do_a_thing',
        phase: MigrationPhase::Contract,
        checksum: str_repeat('a', 64),
        isDestructive: true,
        requiresOffline: true,
    );

    // The fixture, the insert and the comparison are all this one shape, so a
    // difference between the branch and the database can never be a difference
    // between two spellings of the same row.
    expect(MigrationCatalogue::decodeFixture(MigrationCatalogue::encodeFixture([$entry])))
        ->toBe([$entry->toRow()]);

    // A malformed fixture decodes to nothing rather than to a guess, so the
    // caller reports a difference instead of repairing the file.
    expect(MigrationCatalogue::decodeFixture('not json at all'))->toBe([]);
});
