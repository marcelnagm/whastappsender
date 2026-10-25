<?php

namespace App\Exceptions;

use Illuminate\Foundation\Exceptions\Handler as ExceptionHandler;
use App\Exceptions\PlanLimitException;
use Throwable;

class Handler extends ExceptionHandler
{
    /**
     * A list of the exception types that are not reported.
     *
     * @var array<int, class-string<Throwable>>
     */
    protected $dontReport = [
        //
    ];

    /**
     * A list of the inputs that are never flashed for validation exceptions.
     *
     * @var array<int, string>
     */
    protected $dontFlash = [
        'current_password',
        'password',
        'password_confirmation',
    ];

    /**
     * Register the exception handling callbacks for the application.
     *
     * @return void
     */
    public function register()
    {
        $this->reportable(function (Throwable $e) {
            //
        });

        // Plan limits / blocked account: a clear message instead of a 500
        $this->renderable(function (PlanLimitException $e, $request) {
            if ($request->expectsJson() || $request->is('api/*', 'chat/api/*')) {
                return response()->json([
                    'success' => false,
                    'message' => $e->getMessage(),
                    'code' => $e->reason,
                    'key' => $e->key,
                    'upgrade_url' => url('/billing'),
                ], 402);
            }

            return redirect()->route('billing.index')->with('plan_error', $e->getMessage());
        });
    }
}
