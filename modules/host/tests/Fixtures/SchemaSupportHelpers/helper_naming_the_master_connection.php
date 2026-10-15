<?php

/*
 * Check (c)'s AC-37 control: a shared helper reaching for another connection.
 *
 * A helper belongs to no tree, so both connection names are forbidden to it -
 * stricter than AC-37's wording, which picks the forbidden name from the tree
 * the file is in, and declared as such in SourceRules.
 */

use Illuminate\Support\Facades\DB;

return new class
{
    public function mirror(string $tenantId): void
    {
        DB::connection('master')->table('tenant_databases')->where('tenant_id', $tenantId)->get();
    }
};
