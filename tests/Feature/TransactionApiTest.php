<?php

namespace Tests\Feature;

use App\Models\IdempotencyKey;
use App\Models\Product;
use App\Models\TransactionItem;
use App\Support\JsonCanonicalizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Symfony\Component\Process\Exception\ProcessSignaledException;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class TransactionApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_records_a_single_product_transaction_with_database_snapshots(): void
    {
        $product = $this->createProduct('Air Mineral', 'botol', 3500, 12);

        $response = $this->postTransaction([
            ['productId' => $product->id, 'quantity' => 3],
        ]);

        $response->assertOk()
            ->assertJsonPath('data.totalAmount', 10500)
            ->assertJsonPath('data.items.0.productId', $product->id)
            ->assertJsonPath('data.items.0.productName', 'Air Mineral')
            ->assertJsonPath('data.items.0.unit', 'botol')
            ->assertJsonPath('data.items.0.quantity', 3)
            ->assertJsonPath('data.items.0.unitPrice', 3500)
            ->assertJsonPath('data.items.0.subtotal', 10500);

        $this->assertDatabaseHas('products', [
            'id' => $product->id,
            'stock_quantity' => 9,
        ]);
        $this->assertDatabaseHas('transactions', [
            'total_amount' => 10500,
        ]);
        $this->assertDatabaseHas('transaction_items', [
            'product_id' => $product->id,
            'product_name_snapshot' => 'Air Mineral',
            'unit_snapshot' => 'botol',
            'quantity' => 3,
            'unit_price_snapshot' => 3500,
            'subtotal' => 10500,
        ]);
        $this->assertDatabaseCount('outbox_events', 0);
        $this->assertDatabaseCount('idempotency_keys', 1);
    }

    public function test_it_records_multiple_products_and_reduces_each_stock(): void
    {
        $water = $this->createProduct('Air Mineral', 'botol', 3500, 12);
        $snack = $this->createProduct('Biskuit', 'bungkus', 2500, 8);

        $response = $this->postTransaction([
            ['productId' => $water->id, 'quantity' => 2],
            ['productId' => $snack->id, 'quantity' => 3],
        ]);

        $response->assertOk()
            ->assertJsonPath('data.totalAmount', 14500)
            ->assertJsonCount(2, 'data.items');

        $this->assertDatabaseHas('products', [
            'id' => $water->id,
            'stock_quantity' => 10,
        ]);
        $this->assertDatabaseHas('products', [
            'id' => $snack->id,
            'stock_quantity' => 5,
        ]);
        $this->assertDatabaseCount('transactions', 1);
        $this->assertDatabaseCount('transaction_items', 2);
    }

    public function test_insufficient_stock_returns_409_without_partial_transaction_or_stock_changes(): void
    {
        $available = $this->createProduct('Air Mineral', 'botol', 3500, 12);
        $limited = $this->createProduct('Biskuit', 'bungkus', 2500, 1);

        $response = $this->postTransaction([
            ['productId' => $available->id, 'quantity' => 2],
            ['productId' => $limited->id, 'quantity' => 2],
        ]);

        $response->assertConflict()
            ->assertHeader('Content-Type', 'application/problem+json')
            ->assertJsonPath('type', 'https://warung-pak-hendy.example/problems/insufficient-stock')
            ->assertJsonPath('status', 409);

        $this->assertDatabaseHas('products', [
            'id' => $available->id,
            'stock_quantity' => 12,
        ]);
        $this->assertDatabaseHas('products', [
            'id' => $limited->id,
            'stock_quantity' => 1,
        ]);
        $this->assertDatabaseCount('transactions', 0);
        $this->assertDatabaseCount('transaction_items', 0);
    }

    public function test_failure_saving_a_later_item_rolls_back_the_transaction_and_prior_stock_changes(): void
    {
        $first = $this->createProduct('Air Mineral', 'botol', 3500, 12);
        $second = $this->createProduct('Biskuit', 'bungkus', 2500, 8);
        $idempotencyKey = (string) Str::uuid();
        $dispatcher = TransactionItem::getEventDispatcher();
        $creatingEvent = 'eloquent.creating: '.TransactionItem::class;

        $dispatcher->listen(
            $creatingEvent,
            static function (TransactionItem $item) use ($second): void {
                if ($item->product_id === $second->id) {
                    throw new \RuntimeException('Simulated transaction item failure.');
                }
            },
        );

        try {
            $response = $this->postTransaction([
                ['productId' => $first->id, 'quantity' => 2],
                ['productId' => $second->id, 'quantity' => 1],
            ], $idempotencyKey);
        } finally {
            $dispatcher->forget($creatingEvent);
        }

        $response->assertInternalServerError()
            ->assertHeader('Content-Type', 'application/problem+json')
            ->assertJsonPath('type', 'https://warung-pak-hendy.example/problems/internal-error');

        $this->assertDatabaseHas('products', [
            'id' => $first->id,
            'stock_quantity' => 12,
        ]);
        $this->assertDatabaseHas('products', [
            'id' => $second->id,
            'stock_quantity' => 8,
        ]);
        $this->assertDatabaseCount('transactions', 0);
        $this->assertDatabaseCount('transaction_items', 0);
        $this->assertDatabaseMissing('idempotency_keys', ['key' => $idempotencyKey]);
    }

    public function test_database_failure_while_storing_idempotency_rolls_back_the_sale_and_leaves_no_key(): void
    {
        $product = $this->createProduct('Air Mineral', 'botol', 3500, 12);
        $idempotencyKey = (string) Str::uuid();
        $dispatcher = IdempotencyKey::getEventDispatcher();
        $creatingEvent = 'eloquent.creating: '.IdempotencyKey::class;

        $dispatcher->listen(
            $creatingEvent,
            static function (IdempotencyKey $idempotencyRecord) use ($idempotencyKey): void {
                if ($idempotencyRecord->key === $idempotencyKey) {
                    $idempotencyRecord->request_hash = 'invalid-hash';
                }
            },
        );

        try {
            $response = $this->postTransaction([
                ['productId' => $product->id, 'quantity' => 2],
            ], $idempotencyKey);
        } finally {
            $dispatcher->forget($creatingEvent);
        }

        $response->assertInternalServerError()
            ->assertHeader('Content-Type', 'application/problem+json')
            ->assertJsonPath('detail', 'An unexpected error occurred.')
            ->assertDontSee('SQLSTATE');

        $this->assertSame(12, Product::findOrFail($product->id)->stock_quantity);
        $this->assertDatabaseCount('transactions', 0);
        $this->assertDatabaseCount('transaction_items', 0);
        $this->assertDatabaseMissing('idempotency_keys', ['key' => $idempotencyKey]);
    }

    public function test_archived_product_returns_409_without_creating_a_transaction(): void
    {
        $product = $this->createProduct('Air Mineral', 'botol', 3500, 12);
        $product->delete();

        $response = $this->postTransaction([
            ['productId' => $product->id, 'quantity' => 1],
        ]);

        $response->assertConflict()
            ->assertHeader('Content-Type', 'application/problem+json')
            ->assertJsonPath('type', 'https://warung-pak-hendy.example/problems/product-archived');

        $this->assertDatabaseCount('transactions', 0);
        $this->assertDatabaseCount('transaction_items', 0);
    }

    public function test_unknown_product_returns_404_without_creating_a_transaction(): void
    {
        $response = $this->postTransaction([
            ['productId' => '00000000-0000-4000-8000-000000000000', 'quantity' => 1],
        ]);

        $response->assertNotFound()
            ->assertHeader('Content-Type', 'application/problem+json')
            ->assertJsonPath('type', 'https://warung-pak-hendy.example/problems/product-not-found');

        $this->assertDatabaseCount('transactions', 0);
    }

    public function test_invalid_quantities_return_422_without_creating_a_transaction(): void
    {
        $product = $this->createProduct('Air Mineral', 'botol', 3500, 12);

        foreach ([0, '2', 1.5] as $quantity) {
            $response = $this->postTransaction([
                ['productId' => $product->id, 'quantity' => $quantity],
            ]);

            $response->assertUnprocessable()
                ->assertHeader('Content-Type', 'application/problem+json')
                ->assertJsonPath('errors.0.pointer', '/items/0/quantity')
                ->assertJsonPath('errors.0.code', 'positive_integer_required')
                ->assertJsonPath('errors.0.detail', 'quantity must be an integer between 1 and 2147483647.');
        }

        $this->assertDatabaseHas('products', [
            'id' => $product->id,
            'stock_quantity' => 12,
        ]);
        $this->assertDatabaseCount('transactions', 0);
    }

    public function test_non_uuid_product_id_returns_422(): void
    {
        $this->postTransaction([
            ['productId' => 'not-a-uuid', 'quantity' => 1],
        ])
            ->assertUnprocessable()
            ->assertHeader('Content-Type', 'application/problem+json')
            ->assertJsonPath('errors.0.pointer', '/items/0/productId');

        $this->assertDatabaseCount('transactions', 0);
    }

    public function test_duplicate_product_ids_are_rejected_even_with_different_uuid_case(): void
    {
        $product = $this->createProduct('Air Mineral', 'botol', 3500, 12);

        $this->postTransaction([
            ['productId' => strtolower($product->id), 'quantity' => 1],
            ['productId' => strtoupper($product->id), 'quantity' => 1],
        ])
            ->assertUnprocessable()
            ->assertJsonPath('errors.0.pointer', '/items/0/productId')
            ->assertJsonPath('errors.0.code', 'duplicate_product_id')
            ->assertJsonPath('errors.0.detail', 'Each productId may appear only once in a transaction.');

        $this->assertDatabaseCount('transactions', 0);
    }

    public function test_client_supplied_price_is_rejected(): void
    {
        $product = $this->createProduct('Air Mineral', 'botol', 3500, 12);

        $this->postJson('/api/v1/transactions', [
            'items' => [[
                'productId' => $product->id,
                'quantity' => 1,
                'unitPrice' => 1,
            ]],
        ], [
            'Idempotency-Key' => (string) Str::uuid(),
        ])
            ->assertUnprocessable()
            ->assertHeader('Content-Type', 'application/problem+json');

        $this->assertDatabaseHas('products', [
            'id' => $product->id,
            'stock_quantity' => 12,
        ]);
        $this->assertDatabaseCount('transactions', 0);
    }

    public function test_subtotal_above_numeric_column_capacity_returns_specific_422_problem_without_changes(): void
    {
        $product = $this->createProduct('Barang Mahal', 'pcs', 999999999999999, 2);

        $this->postTransaction([
            ['productId' => $product->id, 'quantity' => 2],
        ])
            ->assertUnprocessable()
            ->assertHeader('Content-Type', 'application/problem+json')
            ->assertJsonPath('type', 'https://warung-pak-hendy.example/problems/validation-error')
            ->assertJsonPath('status', 422)
            ->assertJsonPath('errors.0.pointer', '/items/0/quantity')
            ->assertJsonPath('errors.0.code', 'amount_limit_exceeded')
            ->assertJsonPath('errors.0.detail', 'The item subtotal exceeds the maximum supported Rupiah amount.');

        $this->assertDatabaseHas('products', [
            'id' => $product->id,
            'stock_quantity' => 2,
        ]);
        $this->assertDatabaseCount('transactions', 0);
        $this->assertDatabaseCount('transaction_items', 0);
    }

    public function test_transaction_total_above_numeric_column_capacity_returns_specific_422_problem(): void
    {
        $first = $this->createProduct('Barang Pertama', 'pcs', 600000000000000, 1);
        $second = $this->createProduct('Barang Kedua', 'pcs', 500000000000000, 1);

        $this->postTransaction([
            ['productId' => $first->id, 'quantity' => 1],
            ['productId' => $second->id, 'quantity' => 1],
        ])
            ->assertUnprocessable()
            ->assertHeader('Content-Type', 'application/problem+json')
            ->assertJsonPath('type', 'https://warung-pak-hendy.example/problems/validation-error')
            ->assertJsonPath('status', 422)
            ->assertJsonPath('errors.0.pointer', '/items/1/quantity')
            ->assertJsonPath('errors.0.code', 'amount_limit_exceeded')
            ->assertJsonPath('errors.0.detail', 'The transaction total exceeds the maximum supported Rupiah amount.');

        $this->assertDatabaseHas('products', [
            'id' => $first->id,
            'stock_quantity' => 1,
        ]);
        $this->assertDatabaseHas('products', [
            'id' => $second->id,
            'stock_quantity' => 1,
        ]);
        $this->assertDatabaseCount('transactions', 0);
        $this->assertDatabaseCount('transaction_items', 0);
    }

    public function test_idempotency_key_header_is_required_and_limited_to_255_characters(): void
    {
        $product = $this->createProduct('Air Mineral', 'botol', 3500, 12);
        $payload = ['items' => [['productId' => $product->id, 'quantity' => 1]]];

        $this->postJson('/api/v1/transactions', $payload)
            ->assertBadRequest()
            ->assertJsonPath('type', 'https://warung-pak-hendy.example/problems/missing-header');

        $this->postJson('/api/v1/transactions', $payload, ['Idempotency-Key' => ''])
            ->assertBadRequest()
            ->assertJsonPath('type', 'https://warung-pak-hendy.example/problems/invalid-header');

        $this->postJson('/api/v1/transactions', $payload, ['Idempotency-Key' => str_repeat('k', 256)])
            ->assertBadRequest()
            ->assertJsonPath('type', 'https://warung-pak-hendy.example/problems/invalid-header');

        $this->assertDatabaseCount('transactions', 0);
    }

    public function test_idempotency_key_accepts_255_ascii_and_utf8_characters(): void
    {
        $product = $this->createProduct('Air Mineral', 'botol', 3500, 3);
        $items = [['productId' => $product->id, 'quantity' => 1]];
        $asciiKey = str_repeat('k', 255);
        $utf8Key = str_repeat('é', 255);

        $asciiResponse = $this->postTransaction($items, $asciiKey);
        $utf8Response = $this->postTransaction($items, $utf8Key);

        $asciiResponse->assertOk();
        $utf8Response->assertOk();
        $this->assertDatabaseHas('idempotency_keys', ['key' => $asciiKey]);
        $this->assertDatabaseHas('idempotency_keys', ['key' => $utf8Key]);
        $this->assertSame(1, Product::findOrFail($product->id)->stock_quantity);

        $this->postTransaction($items, str_repeat('é', 256))->assertBadRequest();
        $this->assertDatabaseCount('transactions', 2);
        $this->assertDatabaseCount('idempotency_keys', 2);
    }

    public function test_quantity_above_signed_postgresql_integer_maximum_returns_422(): void
    {
        $product = $this->createProduct('Air Mineral', 'botol', 3500, 12);
        $idempotencyKey = (string) Str::uuid();

        $this->postTransaction([
            ['productId' => $product->id, 'quantity' => 2147483648],
        ], $idempotencyKey)
            ->assertUnprocessable()
            ->assertJsonPath('errors.0.pointer', '/items/0/quantity')
            ->assertJsonPath('errors.0.detail', 'quantity must be an integer between 1 and 2147483647.');

        $this->assertDatabaseMissing('idempotency_keys', ['key' => $idempotencyKey]);
        $this->assertSame(12, Product::findOrFail($product->id)->stock_quantity);
        $this->assertDatabaseCount('transactions', 0);
    }

    public function test_quantity_at_signed_postgresql_integer_maximum_is_accepted(): void
    {
        $product = $this->createProduct('Air Mineral', 'botol', 0, 2147483647);

        $this->postTransaction([
            ['productId' => $product->id, 'quantity' => 2147483647],
        ])
            ->assertOk()
            ->assertJsonPath('data.items.0.quantity', 2147483647)
            ->assertJsonPath('data.totalAmount', 0);

        $this->assertDatabaseHas('products', [
            'id' => $product->id,
            'stock_quantity' => 0,
        ]);
        $this->assertDatabaseHas('transaction_items', [
            'product_id' => $product->id,
            'quantity' => 2147483647,
        ]);
    }

    public function test_same_key_and_jcs_equivalent_payload_replay_the_stored_success_response(): void
    {
        $product = $this->createProduct('Air Mineral', 'botol', 3500, 12);
        $idempotencyKey = (string) Str::uuid();

        $firstResponse = $this->postTransaction([
            ['productId' => $product->id, 'quantity' => 2],
        ], $idempotencyKey);
        $replayResponse = $this->postTransaction([
            ['quantity' => 2, 'productId' => $product->id],
        ], $idempotencyKey);

        $firstResponse->assertOk();
        $replayResponse->assertOk()->assertExactJson($firstResponse->json());

        $storedKey = IdempotencyKey::query()->where('key', $idempotencyKey)->firstOrFail();
        $this->assertSame(200, $storedKey->response_status);
        $this->assertSame(
            JsonCanonicalizer::encode($firstResponse->json()),
            JsonCanonicalizer::encode($storedKey->response_body),
        );
        $this->assertSame($firstResponse->json('data.id'), $storedKey->transaction_id);
        $this->assertSame(64, strlen($storedKey->request_hash));
        $this->assertSame(10, Product::findOrFail($product->id)->stock_quantity);
        $this->assertDatabaseCount('transactions', 1);
        $this->assertDatabaseCount('transaction_items', 1);
        $this->assertDatabaseCount('idempotency_keys', 1);
    }

    public function test_same_key_with_a_different_payload_returns_problem_details_conflict(): void
    {
        $product = $this->createProduct('Air Mineral', 'botol', 3500, 12);
        $idempotencyKey = (string) Str::uuid();

        $this->postTransaction([
            ['productId' => $product->id, 'quantity' => 1],
        ], $idempotencyKey)->assertOk();

        $this->postTransaction([
            ['productId' => $product->id, 'quantity' => 2],
        ], $idempotencyKey)
            ->assertConflict()
            ->assertHeader('Content-Type', 'application/problem+json')
            ->assertJsonPath('type', 'https://warung-pak-hendy.example/problems/idempotency-key-conflict')
            ->assertJsonPath('status', 409);

        $this->assertSame(11, Product::findOrFail($product->id)->stock_quantity);
        $this->assertDatabaseCount('transactions', 1);
        $this->assertDatabaseCount('idempotency_keys', 1);
    }

    public function test_different_keys_create_distinct_transactions(): void
    {
        $product = $this->createProduct('Air Mineral', 'botol', 3500, 12);

        $firstResponse = $this->postTransaction([
            ['productId' => $product->id, 'quantity' => 1],
        ]);
        $secondResponse = $this->postTransaction([
            ['productId' => $product->id, 'quantity' => 1],
        ]);

        $firstResponse->assertOk();
        $secondResponse->assertOk();

        $this->assertNotSame($firstResponse->json('data.id'), $secondResponse->json('data.id'));
        $this->assertSame(10, Product::findOrFail($product->id)->stock_quantity);
        $this->assertDatabaseCount('transactions', 2);
        $this->assertDatabaseCount('idempotency_keys', 2);
    }

    public function test_key_can_be_reused_after_a_product_not_found_404(): void
    {
        $productId = (string) Str::uuid();
        $idempotencyKey = (string) Str::uuid();
        $items = [['productId' => $productId, 'quantity' => 1]];

        $this->postTransaction($items, $idempotencyKey)->assertNotFound();
        $this->assertDatabaseMissing('idempotency_keys', ['key' => $idempotencyKey]);

        $product = new Product([
            'name' => 'Air Mineral',
            'unit' => 'botol',
            'selling_price' => 3500,
            'stock_quantity' => 12,
            'minimum_stock' => 3,
        ]);
        $product->id = $productId;
        $product->save();

        $this->postTransaction($items, $idempotencyKey)->assertOk();

        $this->assertDatabaseHas('idempotency_keys', [
            'key' => $idempotencyKey,
            'response_status' => 200,
        ]);
        $this->assertSame(11, Product::findOrFail($productId)->stock_quantity);
    }

    public function test_key_can_be_reused_after_a_validation_422(): void
    {
        $product = $this->createProduct('Air Mineral', 'botol', 3500, 12);
        $idempotencyKey = (string) Str::uuid();

        $this->postTransaction([
            ['productId' => $product->id, 'quantity' => 0],
        ], $idempotencyKey)->assertUnprocessable();
        $this->assertDatabaseMissing('idempotency_keys', ['key' => $idempotencyKey]);

        $this->postTransaction([
            ['productId' => $product->id, 'quantity' => 1],
        ], $idempotencyKey)->assertOk();

        $this->assertDatabaseHas('idempotency_keys', [
            'key' => $idempotencyKey,
            'response_status' => 200,
        ]);
        $this->assertSame(11, Product::findOrFail($product->id)->stock_quantity);
    }

    public function test_insufficient_stock_conflict_is_stored_and_replayed_after_stock_changes(): void
    {
        $product = $this->createProduct('Air Mineral', 'botol', 3500, 1);
        $idempotencyKey = (string) Str::uuid();
        $items = [['productId' => $product->id, 'quantity' => 2]];

        $firstResponse = $this->postTransaction($items, $idempotencyKey);
        $firstResponse->assertConflict();

        $storedKey = IdempotencyKey::query()->where('key', $idempotencyKey)->firstOrFail();
        $this->assertSame(409, $storedKey->response_status);
        $this->assertNull($storedKey->transaction_id);

        $product->stock_quantity = 5;
        $product->save();

        $replayResponse = $this->postTransaction($items, $idempotencyKey);

        $replayResponse->assertConflict()->assertExactJson($firstResponse->json());
        $this->assertSame(5, Product::findOrFail($product->id)->stock_quantity);
        $this->assertDatabaseCount('transactions', 0);
        $this->assertDatabaseCount('idempotency_keys', 1);
    }

    public function test_archived_product_conflict_is_stored_and_replayed_after_product_restore(): void
    {
        $product = $this->createProduct('Air Mineral', 'botol', 3500, 12);
        $product->delete();
        $idempotencyKey = (string) Str::uuid();
        $items = [['productId' => $product->id, 'quantity' => 1]];

        $firstResponse = $this->postTransaction($items, $idempotencyKey);
        $firstResponse->assertConflict();

        $storedKey = IdempotencyKey::query()->where('key', $idempotencyKey)->firstOrFail();
        $this->assertSame(409, $storedKey->response_status);
        $this->assertNull($storedKey->transaction_id);

        $product->restore();

        $replayResponse = $this->postTransaction($items, $idempotencyKey);

        $replayResponse->assertConflict()->assertExactJson($firstResponse->json());
        $this->assertSame(12, Product::findOrFail($product->id)->stock_quantity);
        $this->assertDatabaseCount('transactions', 0);
        $this->assertDatabaseCount('idempotency_keys', 1);
    }

    public function test_simultaneous_http_requests_with_the_same_key_create_only_one_transaction(): void
    {
        $productId = (string) Str::uuid();
        $idempotencyKey = (string) Str::uuid();
        $items = [['productId' => $productId, 'quantity' => 1]];

        $result = $this->runConcurrentTransactionRequests(
            $productId,
            $idempotencyKey,
            [$items, $items],
        );

        $responses = $result['responses'];
        $this->assertSame(200, $responses[0]['status']);
        $this->assertSame(200, $responses[1]['status']);
        $this->assertSame(
            JsonCanonicalizer::encode($responses[0]['body']),
            JsonCanonicalizer::encode($responses[1]['body']),
        );
        $this->assertSame(11, $result['stock_quantity']);
        $this->assertSame(1, $result['transaction_count']);
        $this->assertSame(1, $result['transaction_item_count']);
        $this->assertSame(1, $result['idempotency_key_count']);
    }

    public function test_simultaneous_http_requests_with_same_key_and_different_payload_create_one_transaction(): void
    {
        $productId = (string) Str::uuid();
        $idempotencyKey = (string) Str::uuid();

        $result = $this->runConcurrentTransactionRequests(
            $productId,
            $idempotencyKey,
            [
                [['productId' => $productId, 'quantity' => 1]],
                [['productId' => $productId, 'quantity' => 2]],
            ],
        );

        $successfulResponses = array_values(array_filter(
            $result['responses'],
            static fn (array $response): bool => $response['status'] === 200,
        ));
        $conflictResponses = array_values(array_filter(
            $result['responses'],
            static fn (array $response): bool => $response['status'] === 409,
        ));

        $this->assertCount(1, $successfulResponses);
        $this->assertCount(1, $conflictResponses);
        $this->assertSame(
            'https://warung-pak-hendy.example/problems/idempotency-key-conflict',
            $conflictResponses[0]['body']['type'],
        );
        $winningQuantity = $successfulResponses[0]['body']['data']['items'][0]['quantity'];
        $this->assertSame(12 - $winningQuantity, $result['stock_quantity']);
        $this->assertSame(1, $result['transaction_count']);
        $this->assertSame(1, $result['transaction_item_count']);
        $this->assertSame(1, $result['idempotency_key_count']);
    }

    /**
     * @param  list<list<array{productId: string, quantity: int}>>  $requestItems
     * @return array{
     *     responses: list<array{status: int, body: array}>,
     *     stock_quantity: int,
     *     transaction_count: int,
     *     transaction_item_count: int,
     *     idempotency_key_count: int
     * }
     */
    private function runConcurrentTransactionRequests(
        string $productId,
        string $idempotencyKey,
        array $requestItems,
    ): array {
        $raceConnection = 'idempotency_concurrency';
        $connectionConfig = config('database.connections.'.config('database.default'));
        config(["database.connections.{$raceConnection}" => $connectionConfig]);
        DB::purge($raceConnection);

        $raceDatabase = DB::connection($raceConnection);
        $port = $this->availablePort();
        $server = new Process(
            ['setsid', PHP_BINARY, 'artisan', 'serve', '--host=127.0.0.1', "--port={$port}", '--no-reload'],
            base_path(),
            [
                'APP_ENV' => 'testing',
                'DB_CONNECTION' => 'pgsql',
                'DB_DATABASE' => 'testing',
                'DB_URL' => '',
                'PHP_CLI_SERVER_WORKERS' => '4',
            ],
        );
        $server->setTimeout(30);
        $requestProcesses = [];
        $lockHeld = false;

        try {
            $raceDatabase->table('products')->insert([
                'id' => $productId,
                'name' => 'Air Mineral',
                'unit' => 'botol',
                'selling_price' => 3500,
                'stock_quantity' => 12,
                'minimum_stock' => 3,
                'created_at' => now(),
                'updated_at' => now(),
                'deleted_at' => null,
            ]);

            $server->start();
            $this->waitForServer($server, $port);

            $raceDatabase->beginTransaction();
            $raceDatabase->table('products')
                ->where('id', $productId)
                ->lockForUpdate()
                ->first();
            $lockHeld = true;

            $url = "http://127.0.0.1:{$port}/api/v1/transactions";
            foreach ($requestItems as $items) {
                $requestProcesses[] = $this->concurrentRequestProcess(
                    $url,
                    $idempotencyKey,
                    json_encode(['items' => $items], JSON_THROW_ON_ERROR),
                );
                $requestProcesses[array_key_last($requestProcesses)]->start();
            }

            $waitDeadline = microtime(true) + 10;
            $waitingRequests = 0;

            do {
                $raceDatabase->select('SELECT pg_stat_clear_snapshot()');
                $waitingRequests = (int) $raceDatabase->selectOne(
                    "SELECT COUNT(*) AS aggregate
                    FROM pg_stat_activity
                    WHERE datname = current_database()
                        AND pid <> pg_backend_pid()
                        AND wait_event_type = 'Lock'
                        AND query ILIKE '%products%'"
                )->aggregate;

                if ($waitingRequests >= count($requestItems)) {
                    break;
                }

                usleep(25000);
            } while (microtime(true) < $waitDeadline);

            $this->assertSame(
                count($requestItems),
                $waitingRequests,
                'All requests should pass the key lookup and wait on the locked product.',
            );

            $raceDatabase->commit();
            $lockHeld = false;

            $responses = [];
            foreach ($requestProcesses as $requestProcess) {
                $requestProcess->wait();
                $responses[] = json_decode($requestProcess->getOutput(), true, 512, JSON_THROW_ON_ERROR);
            }

            return [
                'responses' => $responses,
                'stock_quantity' => (int) $raceDatabase->table('products')->where('id', $productId)->value('stock_quantity'),
                'transaction_count' => $raceDatabase->table('transactions')->count(),
                'transaction_item_count' => $raceDatabase->table('transaction_items')->where('product_id', $productId)->count(),
                'idempotency_key_count' => $raceDatabase->table('idempotency_keys')->where('key', $idempotencyKey)->count(),
            ];
        } finally {
            if ($lockHeld) {
                $raceDatabase->rollBack();
            }

            foreach ($requestProcesses as $requestProcess) {
                if ($requestProcess->isStarted() && ! $requestProcess->isTerminated()) {
                    $requestProcess->wait();
                }
            }

            $this->stopTestServer($server);

            $transactionId = $raceDatabase->table('idempotency_keys')
                ->where('key', $idempotencyKey)
                ->value('transaction_id');
            $raceDatabase->table('idempotency_keys')->where('key', $idempotencyKey)->delete();
            $raceDatabase->table('transaction_items')->where('product_id', $productId)->delete();

            if ($transactionId !== null) {
                $raceDatabase->table('transactions')->where('id', $transactionId)->delete();
            }

            $raceDatabase->table('products')->where('id', $productId)->delete();
            DB::purge($raceConnection);
        }
    }

    private function availablePort(): int
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0');
        $address = stream_socket_get_name($socket, false);
        fclose($socket);

        return (int) substr(strrchr($address, ':'), 1);
    }

    private function waitForServer(Process $server, int $port): void
    {
        $deadline = microtime(true) + 10;

        do {
            if (! $server->isRunning()) {
                throw new \RuntimeException($server->getErrorOutput().$server->getOutput());
            }

            $socket = @stream_socket_client("tcp://127.0.0.1:{$port}", timeout: 0.1);

            if (is_resource($socket)) {
                fclose($socket);

                return;
            }

            usleep(25000);
        } while (microtime(true) < $deadline);

        throw new \RuntimeException('The local Laravel test server did not become ready.');
    }

    private function stopTestServer(Process $server): void
    {
        if (! $server->isRunning()) {
            return;
        }

        $processId = $server->getPid();
        $processGroupId = $processId === null ? false : posix_getpgid($processId);

        if ($processId !== null && $processGroupId === $processId) {
            posix_kill(-$processGroupId, SIGTERM);

            try {
                $server->wait();
            } catch (ProcessSignaledException $exception) {
                if ($exception->getSignal() !== SIGTERM) {
                    throw $exception;
                }
            }

            return;
        }

        $server->stop(3);
    }

    private function concurrentRequestProcess(string $url, string $idempotencyKey, string $payload): Process
    {
        $script = sprintf(
            '$handle = curl_init(%s); curl_setopt_array($handle, [CURLOPT_POST => true, CURLOPT_HTTPHEADER => %s, CURLOPT_POSTFIELDS => %s, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20]); $body = curl_exec($handle); echo json_encode(["status" => curl_getinfo($handle, CURLINFO_RESPONSE_CODE), "body" => json_decode($body, true)]);',
            var_export($url, true),
            var_export([
                'Content-Type: application/json',
                'Accept: application/json',
                'Idempotency-Key: '.$idempotencyKey,
            ], true),
            var_export($payload, true),
        );

        $process = new Process([PHP_BINARY, '-r', $script], base_path());
        $process->setTimeout(25);

        return $process;
    }

    /**
     * @param  list<array{productId: string, quantity: int|string|float}>  $items
     */
    private function postTransaction(array $items, ?string $idempotencyKey = null): TestResponse
    {
        return $this->postJson('/api/v1/transactions', [
            'items' => $items,
        ], [
            'Idempotency-Key' => $idempotencyKey ?? (string) Str::uuid(),
        ]);
    }

    private function createProduct(
        string $name,
        string $unit,
        int $sellingPrice,
        int $stockQuantity,
    ): Product {
        return Product::create([
            'name' => $name,
            'unit' => $unit,
            'selling_price' => $sellingPrice,
            'stock_quantity' => $stockQuantity,
            'minimum_stock' => 3,
        ]);
    }
}
