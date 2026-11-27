<?php

namespace Tests\Feature;

use App\Contracts\OutboxPublisher;
use App\Exceptions\OutboxPublishException;
use App\Models\OutboxEvent;
use App\Models\Product;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Tests\TestCase;

class OutboxRelayCommandTest extends TestCase
{
    use DatabaseTransactions;

    public function test_command_processes_only_the_configured_batch_and_reports_counts(): void
    {
        config(['outbox.batch_size' => 2]);
        $this->createEvent();
        $this->createEvent();
        $this->createEvent();
        $publisher = new class implements OutboxPublisher
        {
            public array $publishedIds = [];

            public function publish(OutboxEvent $event): void
            {
                $this->publishedIds[] = $event->event_id;
            }
        };
        $this->app->instance(OutboxPublisher::class, $publisher);

        $this->artisan('outbox:relay')
            ->expectsOutput('Processed: 2; publish attempts: 2; published: 2; failed attempts: 0; dead-lettered: 0; lease lost: 0.')
            ->assertExitCode(0);

        $this->assertCount(2, $publisher->publishedIds);
        $this->assertSame(2, OutboxEvent::query()->where('status', 'published')->count());
        $this->assertSame(1, OutboxEvent::query()->where('status', 'pending')->count());
        $pendingEventId = OutboxEvent::query()->where('status', 'pending')->value('event_id');
        $this->assertNotContains($pendingEventId, $publisher->publishedIds);
    }

    public function test_command_does_not_claim_an_event_before_its_retry_time(): void
    {
        $event = $this->createEvent([
            'next_attempt_at' => now()->addMinute(),
        ]);
        $publisher = new class implements OutboxPublisher
        {
            public int $calls = 0;

            public function publish(OutboxEvent $event): void
            {
                $this->calls++;
            }
        };
        $this->app->instance(OutboxPublisher::class, $publisher);

        $this->artisan('outbox:relay', ['--limit' => 1])
            ->expectsOutput('Processed: 0; publish attempts: 0; published: 0; failed attempts: 0; dead-lettered: 0; lease lost: 0.')
            ->assertExitCode(0);

        $event->refresh();
        $this->assertSame(0, $publisher->calls);
        $this->assertSame('pending', $event->status);
        $this->assertSame(0, $event->attempt_count);
    }

    public function test_command_rejects_non_positive_batch_limits(): void
    {
        $this->artisan('outbox:relay', ['--limit' => 0])
            ->expectsOutput('The --limit option must be a positive integer.')
            ->assertExitCode(1);
    }

    public function test_command_reports_publish_failures_and_database_dead_letters(): void
    {
        $this->createEvent(['attempt_count' => 4]);
        $this->createEvent([
            'status' => 'publishing',
            'lease_token' => (string) Str::uuid(),
            'lease_expires_at' => now()->subSecond(),
            'attempt_count' => 5,
        ]);
        $publisher = new class implements OutboxPublisher
        {
            public int $calls = 0;

            public function publish(OutboxEvent $event): void
            {
                $this->calls++;

                throw new OutboxPublishException('Broker unavailable.');
            }
        };
        $this->app->instance(OutboxPublisher::class, $publisher);

        $this->artisan('outbox:relay', ['--limit' => 2])
            ->expectsOutput('Processed: 2; publish attempts: 1; published: 0; failed attempts: 1; dead-lettered: 2; lease lost: 0.')
            ->assertExitCode(0);

        $this->assertSame(1, $publisher->calls);
        $this->assertSame(2, OutboxEvent::query()->where('status', 'dead_lettered')->count());
    }

    private function createEvent(array $overrides = []): OutboxEvent
    {
        $product = Product::create([
            'name' => 'Air Mineral',
            'unit' => 'botol',
            'selling_price' => 3500,
            'stock_quantity' => 5,
            'minimum_stock' => 3,
        ]);
        $eventId = (string) Str::uuid();
        $event = new OutboxEvent(array_merge([
            'event_type' => 'StockMinimumReached',
            'exchange_name' => 'inventory.events',
            'routing_key' => 'inventory.stock.minimum_reached',
            'aggregate_id' => $product->id,
            'payload' => [
                'eventId' => $eventId,
                'eventType' => 'StockMinimumReached',
                'occurredAt' => now()->toISOString(),
                'aggregateId' => $product->id,
                'stock' => [
                    'stockBefore' => 5,
                    'stockAfter' => 3,
                    'minimumThreshold' => 3,
                ],
            ],
            'occurred_at' => now(),
            'status' => 'pending',
            'attempt_count' => 0,
        ], $overrides));
        $event->event_id = $eventId;
        $event->save();

        return $event;
    }
}
