<?php

declare(strict_types=1);

namespace App\Http\Controllers\Access;

use App\Http\Controllers\Controller;
use App\Http\Middleware\RefusePinInTheUrl;
use App\Http\Middleware\RequireTenantPermission;
use App\Http\Requests\AccessInput;
use App\Http\Responses\AccessRefusals;
use Flexipos\Access\Exceptions\AccessRefused;
use Flexipos\Access\Features\SetPin\SetPin;
use Flexipos\Access\Features\SetPin\SetPinHandler;
use Flexipos\Access\Features\SetPin\SetPinResult;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * `PUT /api/v1/access/employees/{employee}/pin` - an employee's PIN, from the body and from nowhere else (AC-47, AC-48).
 *
 * **The PIN is read with {@see AccessInput::raw()}, which is a body read.**
 * `$request->input()` merges the query string, and a credential read through
 * it would be written to the web server's access log before the application
 * ever saw the request. The route also carries
 * {@see RefusePinInTheUrl}, so a PIN in the URL is
 * refused before any state is read or written rather than merely ignored.
 *
 * **The value is passed on untouched and untyped.** AC-47 refuses a
 * non-string and an empty body as `validation`, which means the judging
 * belongs to `PinPolicy` - the one place the rules live - and a type check
 * here would move one of those rules away from the other four.
 *
 * **The response states success and holds neither the PIN nor the hash.**
 * It cannot: {@see SetPinResult} has
 * no field for either.
 */
final class SetPinController extends Controller
{
    public function __construct(private readonly SetPinHandler $handler) {}

    public function __invoke(Request $request, string $employee): JsonResponse
    {
        $actorId = RequireTenantPermission::employeeId($request);

        try {
            $result = $this->handler->handle(new SetPin(
                employeeId: $employee,
                pin: AccessInput::raw($request, 'pin'),
                actorId: $actorId,
            ));
        } catch (AccessRefused $refused) {
            return AccessRefusals::refused($refused);
        }

        return new JsonResponse([
            'employee_id' => $result->employeeId,
            'pin_set' => true,
        ], 200);
    }
}
