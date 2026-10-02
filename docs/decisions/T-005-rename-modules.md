# T-005 decisions — rename services to modules

Decisions made autonomously by `ba` while writing `docs/specs/T-005-rename-modules.md`, per the
TEAM PROTOCOL (no blocking questions to the human). D-1 to D-42 belong to T-001 to T-004 and are
unchanged; this file continues the numbering at D-43.

D-43 | Does the directory rename also hoist the host application to a top-level `app/`, as the
diagram in `docs/architecture.md` section 4 eventually shows? | No. Literal substitution:
`services/` becomes `modules/`, and `host/` and `demo/` stay siblings beneath it
(`modules/host`, `modules/demo`). `host` remains a reserved name that is not itself a generated
module, exactly as it was under `services/`. | The brief and the backlog describe a vocabulary
rename of a measured 57 files. Hoisting the host moves `vendor/`, the lock file, `artisan` and
every relative path in the toolchain, which is a different and much larger change nobody
asked for here, and it is not blocked by doing the rename first. Reversible: yes.

D-44 | What is the new namespace root, and does the Laravel `ServiceProvider` suffix change? |
`Flexipos\Service\<Studly>\` becomes `Flexipos\Module\<Studly>\`. The suffix does not change:
generated provider classes stay `<Studly>ServiceProvider` (for example
`Flexipos\Module\Demo\DemoServiceProvider`), and `Illuminate\Support\ServiceProvider` is untouched.
| `ServiceProvider` is the framework's own term, not this project's old vocabulary for a module.
Renaming it would fight the framework, and a precise boundary between "our old word" and "the
framework's word" is what makes the absence proof in the spec checkable. Reversible: yes.

D-45 | New names for the host classes. | `App\Support\ServiceHost\ServiceRegistry` becomes
`App\Support\ModuleHost\ModuleRegistry`. `UnknownServiceException` becomes `UnknownModuleException`
and its `service()` accessor becomes `module()`. `App\Providers\ServiceHostServiceProvider`
becomes `App\Providers\ModuleHostServiceProvider`. `SvcMakeCommand` becomes `ModuleMakeCommand`.
`ModuleRegistry::servicePath()` becomes `modulePath()`. | Direct counterparts that keep the existing
pattern (`<Concept>Registry`, `Unknown<Concept>Exception`, `<Concept>Host`). Reversible: yes.

D-46 | Environment variable and configuration names. | `SERVICES` becomes `MODULES`.
`config/servicehost.php` becomes `config/modulehost.php`; its key `'services'` becomes `'modules'`,
read as `config('modulehost.modules')`. The stock Laravel `config/services.php` (third-party
credentials) is NOT renamed and not edited. | The first is the backlog's explicit instruction.
The second is the one file whose name invites a mistaken rename, so it is named here. Reversible: yes.

D-47 | Generator command and stub names. | Signature `svc:make` becomes `module:make`. Stub
directory `stubs/service/` becomes `stubs/module/`. Stub file names are unchanged, because
`ServiceProvider.php.stub` still stubs a `<Studly>ServiceProvider` (D-44). Generated test
fixtures that embed the old names follow the same mapping. | Literal counterpart. Reversible: yes.

D-48 | Composer identities. | Module package name `flexipos/service-<name>` becomes
`flexipos/module-<name>` (`flexipos/service-demo` becomes `flexipos/module-demo`); the host's
keyword `service-host` becomes `module-host`; the manifest key `extra.flexipos.service` becomes
`extra.flexipos.module`. `flexipos/host` never carried the word and is unchanged. The lock file
is regenerated to match. | These are this project's own names; leaving any one is a silent
partial rename that nothing would fail on. They are local path-repository names, never
published. Reversible: yes.

D-49 | Boundary-layer naming. | `Service_<Studly>` becomes `Module_<Studly>` in the shipped
Deptrac configuration and in every fixture that mimics it (`ServiceA`/`ServiceB` become
`ModuleA`/`ModuleB`, `Service_A`/`Service_B` become `Module_A`/`Module_B`, `Service_Sales`/
`Service_Billing` become `Module_Sales`/`Module_Billing`). `Package_*` layers are unchanged. |
One naming convention, applied everywhere; a fixture on the old name beside a shipped
configuration on the new one is itself the inconsistency this task exists to remove.
Reversible: yes.

D-50 | Does the `GET /health/ready` JSON field `"services"` change? | Yes, to `"modules"`
(`{"status":"ok","modules":["demo"]}`). The corresponding assertions change their expected key and
keep their count. | Nothing outside this repository consumes it: the frontend and print agent
are still bootstrap rows. A lone `"services"` field in an otherwise renamed system is the
visible inconsistency a reviewer would flag on day one. Reversible: yes (JSON shape, no stored data).

D-51 | `phpunit.xml` labels and variables. | `<testsuite name="Services">` becomes
`"Modules"`; `<env name="SERVICES">` becomes `MODULES`. Relative paths such as `../*/tests` are
unchanged because they do not contain the word. | Same literal rename. Reversible: yes.

D-52 | The brief excludes `docs/specs`, `plans`, `qa` and `reviews` for T-001 to T-004. What about
`docs/decisions/T-001` to `T-004`? | Treated identically: a dated note at the top, substance
untouched. | A decision record quotes `SERVICES`, `Service_Demo`, `svc:make` as the reasoning at
the time; rewriting them falsifies the record exactly as editing a spec would. Only the four named
folders are stated by the brief, so this is an extension by the same reason, flagged for the
lead. (The brief says "eighteen" records; four folders by four tasks is sixteen, plus four
decisions files is twenty. The rule is by folder and task, not by that count.) Reversible: yes.

D-53 | Are `.claude/agent-memory/**` files historical or forward-looking? | Forward-looking:
updated to the new vocabulary, including renaming `project_servicehost_boot_contract.md` to
`project_modulehost_boot_contract.md` and fixing the `MEMORY.md` index lines that link to it. |
Their whole purpose is to be relied on by the next agent; a note describing a renamed-away class
and variable misleads, which is worse than the churn. They are not task records. Reversible: yes.

D-54 | Do test file and class names that encode the old word get renamed? | Yes, by literal
counterpart (`SvcMakeValidationTest` to `ModuleMakeValidationTest`, `ServiceRegistryManifestTrustTest`
to `ModuleRegistryManifestTrustTest`, `ServicesEnvParsingTest` to `ModulesEnvParsingTest`,
`QaServiceNameValidationTest` to `QaModuleNameValidationTest`, and so on), always with a
rename-preserving move, and with the literal path lists in `tests/Helpers.php` updated in the same
change. The exhaustive list is `architect`'s plan, not this spec: the absence proof, not a
hand-kept list, shows completeness. | A file name is old vocabulary like any other, and the
literal lists in `tests/Helpers.php` are the concrete place a half rename goes unnoticed.
Reversible: yes.

D-55 | How is "unchanged counts" reconciled with FP-169 adding tests? | Two stated guarantees.
The rename alone leaves the full-gate test and assertion counts equal to the baseline. After
FP-169 the counts equal baseline plus exactly the tests FP-169 adds, which the plan names. | A
single "unchanged" requirement would forbid the new coverage the task is required to add. Lighter
than T-004's by-name set difference because this task removes and replaces nothing. Reversible: yes.

D-56 | Does the spec prescribe the Deptrac layer name or collector for the host's test namespace
(FP-169)? | No. The spec requires the observable outcome with a positive and a negative control;
layer design is `architect`'s. | `ba` does not design technical solutions. Reversible: yes.
