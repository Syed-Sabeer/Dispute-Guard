<?php

namespace App\Exceptions;

use Illuminate\Foundation\Exceptions\Handler as ExceptionHandler;
use Illuminate\Support\Facades\Log;
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
        'id_token',
        'session',
        'hmac',
        'token',
    ];

    /**
     * Register the exception handling callbacks for the application.
     */
    public function register(): void
    {
        $this->renderable(function (EmailProviderException $e, $request) {
            return $request->expectsJson()
                ? response()->json(['message' => $e->getMessage()], 503)
                : response()->view('errors.shopify', ['message' => $e->getMessage()], 503);
        });
        $this->renderable(function (ShopifyApiException $e, $request) {
            return $request->expectsJson()
                ? response()->json(['message' => $e->getMessage()], 503)
                : response()->view('errors.shopify', ['message' => $e->getMessage()], 503);
        });
        $this->reportable(function (Throwable $e) {
            // Exception messages/arguments can include SQL bindings, URLs with
            // session tokens, SMTP credentials or customer content.
            Log::error('Application operation failed', [
                'exception_class' => get_class($e),
                'file' => basename($e->getFile()),
                'line' => $e->getLine(),
            ]);

            return false;
        });
    }
}
