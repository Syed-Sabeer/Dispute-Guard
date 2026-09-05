<?php

namespace App\Exceptions;

use Illuminate\Foundation\Exceptions\Handler as ExceptionHandler;
use Throwable;

class Handler extends ExceptionHandler
{
    /**
     * The list of the inputs that are never flashed to the session on validation exceptions.
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
     */
    public function register(): void
    {
        $this->renderable(function (ShopifyApiException $e, $request) {
            return $request->expectsJson()
                ? response()->json(['message' => $e->getMessage()], 503)
                : response()->view('errors.shopify', ['message' => $e->getMessage()], 503);
        });
        $this->reportable(function (Throwable $e) {
            //
        });
    }
}
