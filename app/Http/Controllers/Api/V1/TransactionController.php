<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\TransactionAmountLimitExceeded;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreTransactionRequest;
use App\Http\Resources\TransactionResource;
use App\Models\IdempotencyKey;
use App\Models\OutboxEvent;
use App\Models\Product;
use App\Models\Transaction;
use App\Models\TransactionItem;
use App\Support\JsonCanonicalizer;
use Illuminate\Database\QueryException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class TransactionController extends Controller
{
    private const MAX_RUPIAH_AMOUNT = 999999999999999;

    public function store(StoreTransactionRequest $request): JsonResponse
    {
        $idempotencyKey = $request->header('Idempotency-Key');

        if (! is_string($idempotencyKey)) {
            $this->abortWithProblem(
                'missing-header',
                'Bad Request',
                400,
                'Idempotency-Key header is required.',
            );
        }

        if ($idempotencyKey === '' || mb_strlen($idempotencyKey, 'UTF-8') > 255) {
            $this->abortWithProblem(
                'invalid-header',
                'Bad Request',
                400,
                'Idempotency-Key must contain between 1 and 255 characters.',
            );
        }

        $validated = $request->validated();
        $items = $validated['items'];
        $requestHash = JsonCanonicalizer::hash($validated);

        try {
            $outcome = DB::transaction(function () use ($idempotencyKey, $items, $requestHash): array {
                $existing = IdempotencyKey::query()
                    ->where('key', $idempotencyKey)
                    ->lockForUpdate()
                    ->first();

                if ($existing !== null) {
                    return $this->existingOutcome($existing, $requestHash);
                }

                $productIds = array_map(
                    static fn (array $item): string => strtolower($item['productId']),
                    $items,
                );

                $products = Product::withTrashed()
                    ->whereIn('id', $productIds)
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get()
                    ->keyBy(static fn (Product $product): string => strtolower($product->id));

                $missingProductIds = array_diff($productIds, $products->keys()->all());

                if ($missingProductIds !== []) {
                    $this->abortWithProblem(
                        'product-not-found',
                        'Product not found',
                        404,
                        'One or more productId values do not identify a product.',
                    );
                }

                if ($products->contains(static fn (Product $product): bool => $product->trashed())) {
                    return $this->storeBusinessConflict(
                        $idempotencyKey,
                        $requestHash,
                        'product-archived',
                        'Product unavailable',
                        'An archived product cannot be used in a new transaction.',
                    );
                }

                foreach ($items as $item) {
                    $product = $products->get(strtolower($item['productId']));

                    if ($item['quantity'] > $product->stock_quantity) {
                        return $this->storeBusinessConflict(
                            $idempotencyKey,
                            $requestHash,
                            'insufficient-stock',
                            'Insufficient stock',
                            'One or more products do not have enough stock for this transaction.',
                        );
                    }
                }

                $totalAmount = 0;
                /** @var list<array{product: Product, quantity: int, unit_price_snapshot: int, subtotal: int}> $transactionItems */
                $transactionItems = [];

                foreach ($items as $index => $item) {
                    $product = $products->get(strtolower($item['productId']));
                    $quantity = $item['quantity'];
                    $unitPrice = $product->selling_price;

                    if ($unitPrice > 0 && $quantity > intdiv(self::MAX_RUPIAH_AMOUNT, $unitPrice)) {
                        throw new TransactionAmountLimitExceeded(
                            "items.{$index}.quantity",
                            'The item subtotal exceeds the maximum supported Rupiah amount.',
                        );
                    }

                    $subtotal = $quantity * $unitPrice;

                    if ($subtotal > self::MAX_RUPIAH_AMOUNT - $totalAmount) {
                        throw new TransactionAmountLimitExceeded(
                            "items.{$index}.quantity",
                            'The transaction total exceeds the maximum supported Rupiah amount.',
                        );
                    }

                    $totalAmount += $subtotal;
                    $transactionItems[] = [
                        'product' => $product,
                        'quantity' => $quantity,
                        'unit_price_snapshot' => $unitPrice,
                        'subtotal' => $subtotal,
                    ];
                }

                $transaction = Transaction::create([
                    'total_amount' => $totalAmount,
                    'transaction_at' => now(),
                ]);

                foreach ($transactionItems as $transactionItem) {
                    $product = $transactionItem['product'];
                    $quantity = $transactionItem['quantity'];
                    $stockBefore = $product->stock_quantity;
                    $stockAfter = $stockBefore - $quantity;

                    TransactionItem::create([
                        'transaction_id' => $transaction->id,
                        'product_id' => $product->id,
                        'product_name_snapshot' => $product->name,
                        'unit_snapshot' => $product->unit,
                        'quantity' => $quantity,
                        'unit_price_snapshot' => $transactionItem['unit_price_snapshot'],
                        'subtotal' => $transactionItem['subtotal'],
                    ]);

                    $product->stock_quantity = $stockAfter;
                    $product->save();

                    if (
                        $stockBefore > $product->minimum_stock &&
                        $stockAfter <= $product->minimum_stock
                    ) {
                        $occurredAt = now();
                        $eventId = (string) Str::uuid();
                        $event = new OutboxEvent([
                            'event_type' => 'StockMinimumReached',
                            'exchange_name' => 'inventory.events',
                            'routing_key' => 'inventory.stock.minimum_reached',
                            'aggregate_id' => $product->id,
                            'payload' => [
                                'eventId' => $eventId,
                                'eventType' => 'StockMinimumReached',
                                'occurredAt' => $occurredAt->toISOString(),
                                'aggregateId' => $product->id,
                                'product' => [
                                    'id' => $product->id,
                                    'name' => $product->name,
                                    'unit' => $product->unit,
                                ],
                                'stock' => [
                                    'stockBefore' => $stockBefore,
                                    'stockAfter' => $stockAfter,
                                    'minimumThreshold' => $product->minimum_stock,
                                ],
                            ],
                            'occurred_at' => $occurredAt,
                            'status' => 'pending',
                            'attempt_count' => 0,
                        ]);
                        $event->event_id = $eventId;
                        $event->save();
                    }
                }

                $responseBody = (new TransactionResource($transaction->load('items')))
                    ->response()
                    ->getData(true);

                IdempotencyKey::create([
                    'key' => $idempotencyKey,
                    'request_hash' => $requestHash,
                    'transaction_id' => $transaction->id,
                    'response_status' => 200,
                    'response_body' => $responseBody,
                ]);

                return [
                    'status' => 200,
                    'body' => $responseBody,
                ];
            });
        } catch (QueryException $exception) {
            if (! $this->isIdempotencyKeyUniqueViolation($exception)) {
                throw $exception;
            }

            $existing = IdempotencyKey::query()
                ->where('key', $idempotencyKey)
                ->first();

            if ($existing === null) {
                throw $exception;
            }

            $outcome = $this->existingOutcome($existing, $requestHash);
        }

        return response()->json(
            $outcome['body'],
            $outcome['status'],
            $outcome['status'] >= 400
                ? ['Content-Type' => 'application/problem+json']
                : ['Content-Type' => 'application/json'],
        );
    }

    /**
     * @return array{status: int, body: array}
     */
    private function existingOutcome(IdempotencyKey $existing, string $requestHash): array
    {
        if (hash_equals($existing->request_hash, $requestHash)) {
            return [
                'status' => $existing->response_status,
                'body' => $existing->response_body,
            ];
        }

        return [
            'status' => 409,
            'body' => $this->problemDetails(
                'idempotency-key-conflict',
                'Idempotency key conflict',
                409,
                'This Idempotency-Key was already used with a different request body.',
            ),
        ];
    }

    /**
     * @return array{status: int, body: array}
     */
    private function storeBusinessConflict(
        string $idempotencyKey,
        string $requestHash,
        string $type,
        string $title,
        string $detail,
    ): array {
        $responseBody = $this->problemDetails($type, $title, 409, $detail);

        IdempotencyKey::create([
            'key' => $idempotencyKey,
            'request_hash' => $requestHash,
            'transaction_id' => null,
            'response_status' => 409,
            'response_body' => $responseBody,
        ]);

        return [
            'status' => 409,
            'body' => $responseBody,
        ];
    }

    private function isIdempotencyKeyUniqueViolation(QueryException $exception): bool
    {
        return ($exception->errorInfo[0] ?? null) === '23505'
            && str_contains($exception->getMessage(), 'idempotency_keys_key_unique');
    }

    private function abortWithProblem(
        string $type,
        string $title,
        int $status,
        string $detail,
    ): never {
        throw new HttpResponseException(response()->json(
            $this->problemDetails($type, $title, $status, $detail),
            $status,
            ['Content-Type' => 'application/problem+json'],
        ));
    }

    private function problemDetails(string $type, string $title, int $status, string $detail): array
    {
        return [
            'type' => "https://warung-pak-hendy.example/problems/{$type}",
            'title' => $title,
            'status' => $status,
            'detail' => $detail,
            'instance' => '/api/v1/transactions',
        ];
    }
}
