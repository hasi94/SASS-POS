<?php

declare(strict_types=1);

namespace Flexipos\Access\Features\SyncPermissionRegistry;

use Flexipos\Access\Registry\PermissionRegistry;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;

/**
 * Make the tenant's `permissions` table say what the application declares
 * (AC-15, AC-16).
 *
 * **It never deletes a key.** A key that has disappeared from the code is
 * reported `orphaned` and left where it is, with every grant of it intact
 * (AC-16), because deleting it would cascade through `role_permissions` and
 * `employee_permissions` and silently remove rights - and the commonest
 * cause of a key vanishing is a module not booted in this process, which is
 * a deployment fact rather than a decision to revoke anything.
 *
 * **It never flips `is_branch_scoped` on an existing key.** That flag decides
 * how every stored grant of the key is read (D-8 (c)): turning it on would
 * make every null-scope grant stop conferring it, and turning it off would
 * make every branch-scoped grant start conferring it everywhere. Either is a
 * silent change to rights nobody asked for, so the key is left alone and
 * reported, and the rest of the sync proceeds (AC-15).
 *
 * **One transaction.** Either the whole reconciliation lands or none of it
 * does; a half-synced table is a set of keys whose provenance nobody can
 * state. The declaration set is read **before** the transaction opens, so a
 * set that cannot be reconciled with itself refuses with nothing written at
 * all rather than with a rollback.
 */
final class SyncPermissionRegistryHandler
{
    public function __construct(
        private readonly PermissionRegistry $registry,
        private readonly DatabaseManager $database,
        private readonly ConfigRepository $config,
    ) {}

    public function handle(SyncPermissionRegistry $command): SyncPermissionRegistryResult
    {
        // Outside the transaction on purpose: this is the call that refuses a
        // declaration set declaring one key two different ways, and a refusal
        // that never opened a transaction cannot have written a thing.
        $declared = $this->registry->declarations();

        return $this->tenant()->transaction(function (Connection $connection) use ($declared): SyncPermissionRegistryResult {
            /** @var array<string, object{key: string, module: string, group: string, description: string, is_branch_scoped: int|bool}> $stored */
            $stored = [];

            foreach ($connection->table('permissions')->get() as $row) {
                /** @var object{key: string, module: string, group: string, description: string, is_branch_scoped: int|bool} $row */
                $stored[(string) $row->key] = $row;
            }

            $inserted = [];
            $updated = [];
            $unchanged = [];
            $refused = [];

            foreach ($declared as $declaration) {
                $key = $declaration->key;

                if (! isset($stored[$key])) {
                    $connection->table('permissions')->insert([
                        'key' => $key,
                        'module' => $declaration->module,
                        'group' => $declaration->group,
                        'description' => $declaration->description,
                        'is_branch_scoped' => $declaration->isBranchScoped,
                    ]);

                    $inserted[] = $key;

                    continue;
                }

                $row = $stored[$key];

                if ((bool) $row->is_branch_scoped !== $declaration->isBranchScoped) {
                    $refused[] = $key;

                    continue;
                }

                if (
                    (string) $row->module === $declaration->module
                    && (string) $row->group === $declaration->group
                    && (string) $row->description === $declaration->description
                ) {
                    $unchanged[] = $key;

                    continue;
                }

                $connection->table('permissions')->where('key', $key)->update([
                    'module' => $declaration->module,
                    'group' => $declaration->group,
                    'description' => $declaration->description,
                ]);

                $updated[] = $key;
            }

            $declaredKeys = array_map(static fn ($declaration): string => $declaration->key, $declared);

            $orphaned = array_values(array_diff(array_keys($stored), $declaredKeys));
            sort($orphaned);

            return new SyncPermissionRegistryResult($inserted, $updated, $unchanged, $orphaned, $refused);
        });
    }

    /**
     * The tenant connection, named from configuration rather than written as
     * `tenant`: the stanza's name has one authority and this is not it.
     */
    private function tenant(): Connection
    {
        /** @var Connection $connection */
        $connection = $this->database->connection((string) $this->config->get('tenancy.connection', 'tenant'));

        return $connection;
    }
}
