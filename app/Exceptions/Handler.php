<?php

namespace App\Exceptions;

use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Exceptions\Handler as ExceptionHandler;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\Auth;
use Throwable;
use Illuminate\Validation\ValidationException;
use Illuminate\Http\JsonResponse;

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

    public function register(): void
    {
        $this->reportable(function (Throwable $e) {
            //
        });
        $this->renderable(function (\Exception $e) {
            if ($e->getPrevious() instanceof \Illuminate\Session\TokenMismatchException) {
                Auth::logout();
                return redirect()->route('login')->with('error','Your session has expired');
            };
        });
    }
    public function render($request, Throwable $exception)
    {
        // Handle validation exception for API requests
        if ($exception instanceof ValidationException && $request->is('api/*') ) {
            return $this->handleApiValidationException($exception);
        }

        return parent::render($request, $exception);
    }
    /**
     * Handle validation exception for API requests.
     *
     * @param \Illuminate\Validation\ValidationException $exception
     * @return \Illuminate\Http\JsonResponse
     */
    protected function handleApiValidationException(ValidationException $exception)
    {
        return new JsonResponse([
            'message' => 'Process failed',
            'errors' => $exception->errors(),
        ], JsonResponse::HTTP_UNPROCESSABLE_ENTITY);
    }
    protected function unauthenticated($request, AuthenticationException $exception)
    {
        if ($request->expectsJson() || $request->is('api/*')) {
            if ($exception instanceof InvalidTokenException) {
                throw new HttpResponseException(response()->json(['message' => 'Unauthorized','status' => false],402));
            }
            throw new HttpResponseException(response()->json(['message' => 'Unauthenticated','status' => false], 401));
        }

        return parent::unauthenticated($request, $exception);
    }
}