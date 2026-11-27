<?php

use App\Exceptions\TransactionAmountLimitExceeded;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        apiPrefix: 'api/v1',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withCommands()
    ->withMiddleware(function (Middleware $middleware): void {
        //
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        $exceptions->render(function (
            Throwable $exception,
            Request $request
        ) {
            if (! $request->is('api/*')) {
                return null;
            }

            $status = 500;
            $type = 'internal-error';
            $title = 'Internal Server Error';
            $detail = 'An unexpected error occurred.';
            $errors = null;

            if ($exception instanceof ValidationException) {
                $malformedJson = false;

                if ($request->isJson() && $request->getContent() !== '') {
                    try {
                        json_decode($request->getContent(), true, 512, JSON_THROW_ON_ERROR);
                    } catch (JsonException) {
                        $malformedJson = true;
                    }
                }

                if ($malformedJson) {
                    $status = 400;
                    $type = 'bad-request';
                    $title = 'Bad Request';
                    $detail = 'The request could not be understood.';
                } else {
                    $status = 422;
                    $type = 'validation-error';
                    $title = 'Validation failed';
                    $detail = 'One or more fields do not satisfy the request constraints.';

                    $errors = [];

                    foreach ($exception->errors() as $field => $messages) {
                        $failedRules = $exception->validator->failed()[$field] ?? [];
                        $code = 'invalid_value';

                        if (
                            $exception instanceof TransactionAmountLimitExceeded
                        ) {
                            $code = $exception->problemCode();
                        } elseif (
                            str_starts_with($field, 'items.') &&
                            str_ends_with($field, '.quantity')
                        ) {
                            $code = 'positive_integer_required';
                        } elseif (
                            str_starts_with($field, 'items.') &&
                            str_ends_with($field, '.productId') &&
                            array_key_exists('Distinct', $failedRules)
                        ) {
                            $code = 'duplicate_product_id';
                        }

                        foreach ($messages as $message) {
                            $errors[] = [
                                'pointer' => '/'.str_replace('.', '/', $field),
                                'code' => $code,
                                'detail' => $message,
                            ];
                        }
                    }
                }
            } elseif (
                $exception instanceof ModelNotFoundException ||
                $exception instanceof NotFoundHttpException
            ) {
                $status = 404;
                $type = 'not-found';
                $title = 'Resource not found';
                $detail = 'The requested resource could not be found.';
            } elseif ($exception instanceof HttpExceptionInterface) {
                $status = $exception->getStatusCode();

                if ($status === 400) {
                    $type = 'bad-request';
                    $title = 'Bad Request';
                    $detail = 'The request could not be understood.';
                } elseif ($status === 409) {
                    $type = 'conflict';
                    $title = 'Conflict';
                    $detail = 'The request conflicts with the current resource state.';
                } else {
                    return null;
                }
            }

            $body = [
                'type' => "https://warung-pak-hendy.example/problems/{$type}",
                'title' => $title,
                'status' => $status,
                'detail' => $detail,
                'instance' => '/'.ltrim($request->path(), '/'),
            ];

            if ($errors !== null) {
                $body['errors'] = $errors;
            }

            return response()->json(
                $body,
                $status,
                ['Content-Type' => 'application/problem+json']
            );
        });
    })->create();
