<?php

namespace Tests\Feature;

use App\Models\OutboxEvent;
use App\Models\Product;
use App\Models\Transaction;
use App\Models\TransactionItem;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class OutboxEventTest extends TestCase
{
    use DatabaseTransactions;

    public function test_crossing_stock_threshold_creates_a_transactional_outbox_event(): void
    {
        $product = $this->createProduct(stockQuantity: 5, minimumStock: 3);
        $idempotencyKey = (string) Str::uuid();

        $response = $this->sell($product, quantity: 2, idempotencyKey: $idempotencyKey);

        $response->assertOk();
        $this->sell($product, quantity: 2, idempotencyKey: $idempotencyKey)->assertOk();
        $this->assertDatabaseHas('products', [
            'id' => $product->id,
            'stock_quantity' => 3,
        ]);
        $this->assertDatabaseHas('outbox_events', [
            'aggregate_id' => $product->id,
            'status' => 'pending',
        ]);

        $event = OutboxEvent::query()->where('aggregate_id', $product->id)->sole();

        $this->assertSame('StockMinimumReached', $event->event_type);
        $this->assertSame('inventory.events', $event->exchange_name);
        $this->assertSame('inventory.stock.minimum_reached', $event->routing_key);
        $this->assertSame($product->id, $event->aggregate_id);
        $this->assertSame('pending', $event->status);
        $this->assertSame(0, $event->attempt_count);
        $this->assertSame($event->event_id, $event->payload['eventId']);
        $this->assertNotEmpty($event->payload['occurredAt']);
        $this->assertSame($product->id, $event->payload['aggregateId']);
        $this->assertSame(5, $event->payload['stock']['stockBefore']);
        $this->assertSame(3, $event->payload['stock']['stockAfter']);
        $this->assertSame(3, $event->payload['stock']['minimumThreshold']);
    }

    public function test_stock_above_threshold_after_sale_does_not_create_an_event(): void
    {
        $product = $this->createProduct(stockQuantity: 5, minimumStock: 3);

        $this->sell($product, quantity: 1)->assertOk();

        $this->assertDatabaseMissing('outbox_events', ['aggregate_id' => $product->id]);
    }

    public function test_stock_already_at_or_below_threshold_does_not_create_an_event_again(): void
    {
        $product = $this->createProduct(stockQuantity: 2, minimumStock: 3);

        $this->sell($product, quantity: 1)->assertOk();

        $this->assertDatabaseMissing('outbox_events', ['aggregate_id' => $product->id]);
    }

    public function test_event_insert_failure_rolls_back_the_sale_and_stock_change(): void
    {
        $product = $this->createProduct(stockQuantity: 5, minimumStock: 3);
        $idempotencyKey = (string) Str::uuid();
        $transactionCount = Transaction::query()->count();
        $transactionItemCount = TransactionItem::query()->count();
        $outboxCount = OutboxEvent::query()->count();
        $dispatcher = OutboxEvent::getEventDispatcher();
        $creatingEvent = 'eloquent.creating: '.OutboxEvent::class;

        $dispatcher->listen($creatingEvent, static function (OutboxEvent $event): void {
            if ($event->event_type === 'StockMinimumReached') {
                throw new \RuntimeException('Simulated outbox insert failure.');
            }
        });

        try {
            $response = $this->sell($product, quantity: 2, idempotencyKey: $idempotencyKey);
        } finally {
            $dispatcher->forget($creatingEvent);
        }

        $response->assertInternalServerError()
            ->assertJsonPath('type', 'https://warung-pak-hendy.example/problems/internal-error');

        $this->assertSame(5, Product::findOrFail($product->id)->stock_quantity);
        $this->assertSame($transactionCount, Transaction::query()->count());
        $this->assertSame($transactionItemCount, TransactionItem::query()->count());
        $this->assertSame($outboxCount, OutboxEvent::query()->count());
        $this->assertDatabaseMissing('idempotency_keys', ['key' => $idempotencyKey]);
    }

    private function createProduct(int $stockQuantity, int $minimumStock): Product
    {
        return Product::create([
            'name' => 'Air Mineral',
            'unit' => 'botol',
            'selling_price' => 3500,
            'stock_quantity' => $stockQuantity,
            'minimum_stock' => $minimumStock,
        ]);
    }

    private function sell(Product $product, int $quantity, ?string $idempotencyKey = null): TestResponse
    {
        return $this->postJson('/api/v1/transactions', [
            'items' => [
                ['productId' => $product->id, 'quantity' => $quantity],
            ],
        ], [
            'Idempotency-Key' => $idempotencyKey ?? (string) Str::uuid(),
        ]);
    }
}
