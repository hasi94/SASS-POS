<?php

/*
 * The asymmetric keys that sign and verify access tokens
 * (docs/database.md section 4.1).
 *
 * **`kid` is the primary key, and it is a VARCHAR, not an identifier column.**
 * A `kid` is a JOSE header value: it is the one id in this system that leaves
 * the process inside a token header, and it is matched by a verifier that may
 * not be ours. The identifier-column rule governs references to another row's
 * identity inside our own schema; this is a protocol field, so it keeps the
 * type the protocol gives it (D-28's companion reading).
 *
 * **The private key is never here, and not even as a ciphertext.**
 * `private_key_ref` is a reference a `SecretResolver` dereferences at signing
 * time (D-23); `public_key` is the only key material in the row, and it is
 * public by definition. A column called `private_key` would be a column
 * somebody eventually writes a key into.
 *
 * `status` is the three-state rotation: `active` signs, `retiring` verifies
 * only, `retired` verifies never. It is a VARCHAR and not a native enum -
 * AC-35(b) - and it is indexed, because "which key signs" is asked on every
 * token issue and is a selection on exactly this column.
 *
 * There is one `created_at` and no `updated_at`: a key's material does not
 * change. What changes is its status, and `retired_at` is the moment that
 * happened.
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
        Schema::create('signing_keys', function (Blueprint $table) {
            $table->string('kid', 40)->primary();
            $table->string('algorithm', 20);
            $table->text('public_key');
            $table->string('private_key_ref', 255);
            $table->string('status', 30);
            $table->dateTime('created_at', 3);
            $table->dateTime('retired_at', 3)->nullable();

            $table->index('status');
        });
    }
};
