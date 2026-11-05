<?php

use Flexipos\Kernel\Api\DeclaresErrorCodes;
use Flexipos\Kernel\Api\ErrorCatalogue;
use Flexipos\Kernel\Api\ErrorCode;
use Flexipos\Kernel\Api\Features\ListErrorCodes\ListErrorCodes;
use Flexipos\Kernel\Api\Features\ListErrorCodes\ListErrorCodesHandler;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\ServiceProvider;
use Tests\TestCase;

/*
 * T-021 AC-14: `api:error-codes` lists every registered code, sorted, with
 * its module, status and message.
 */

uses(TestCase::class);

it("the handler's rows equal ErrorCatalogue::all(), sorted", function () {
    $provider = new class(app()) extends ServiceProvider implements DeclaresErrorCodes
    {
        public function errorCodes(): array
        {
            return ['qa_list' => [new ErrorCode('qa_list.x', 400, 'x message')]];
        }
    };

    app()->register($provider);

    $rows = app(ListErrorCodesHandler::class)->handle(new ListErrorCodes);

    $byCatalogue = array_map(
        fn (ErrorCode $e) => $e->code,
        app(ErrorCatalogue::class)->all()
    );

    expect(array_column($rows, 'code'))->toBe($byCatalogue);

    $qaRow = array_values(array_filter($rows, fn (array $r) => $r['code'] === 'qa_list.x'))[0] ?? null;

    expect($qaRow)->not->toBeNull()
        ->and($qaRow['module'])->toBe('qa_list')
        ->and($qaRow['status'])->toBe(400)
        ->and($qaRow['message'])->toBe('x message');
});

it('the command lists every code, sorted', function () {
    $exitCode = Artisan::call('api:error-codes');

    expect($exitCode)->toBe(0)
        ->and(Artisan::output())->toContain('kernel.not_found');
});
