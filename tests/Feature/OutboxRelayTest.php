<?php

namespace Tests\Feature;

use App\Contracts\OutboxPublisher;
use App\Exceptions\OutboxPublishException;
use App\Models\OutboxEvent;
use App\Models\Product;
use App\Services\Outbox\OutboxRelay;
use Closure;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class OutboxRelayTest extends TestCase
{
    use DatabaseTransactions;

    public function test_successful_publish_marks_event_published_after_publisher_returns(): void
    {
        $event = $this->createEvent();
        $relay = $this->relayUsing(function (OutboxEvent $claimedEvent): void {
            $this->assertSame(1, DB::transactionLevel());
            $this->assertSame('publishing', $claimedEvent->status);
            $this->assertNotNull($claimedEvent->lease_token);
            $this->assertSame(
                (int) config('outbox.lease_seconds'),
                abs($claimedEvent->lease_expires_at->timestamp - now()->timestamp),
            );
            $this->assertSame(1, $claimedEvent->attempt_count);
            $this->assertSame('publishing', DB::table('outbox_events')
                ->where('event_id', $claimedEvent->event_id)
                ->value('status'));
        });

        $result = $relay->relay();
        $this->assertSame(1, $result->published);

        $event->refresh();
        $this->assertSame('published', $event->status);
        $this->assertNotNull($event->published_at);
        $this->assertNull($event->lease_token);
        $this->assertNull($event->lease_expires_at);

        $result = $relay->relay();
        $this->assertSame(0, $result->attempted);
        $this->assertSame(1, $event->refresh()->attempt_count);
    }

    public function test_expired_lease_is_reclaimed_with_a_new_token(): void
    {
        $oldToken = (string) Str::uuid();
        $event = $this->createEvent([
            'status' => 'publishing',
            'lease_token' => $oldToken,
            'lease_expires_at' => now()->subSecond(),
            'attempt_count' => 1,
        ]);
        $newToken = null;
        $relay = $this->relayUsing(function (OutboxEvent $claimedEvent) use (&$newToken): void {
            $newToken = $claimedEvent->lease_token;
        });

        $result = $relay->relay();
        $this->assertSame(1, $result->published);

        $event->refresh();
        $this->assertNotSame($oldToken, $newToken);
        $this->assertSame(2, $event->attempt_count);
        $this->assertSame('published', $event->status);
    }

    public function test_worker_with_stale_lease_token_cannot_mark_event_published(): void
    {
        $event = $this->createEvent();
        $replacementToken = (string) Str::uuid();
        $relay = $this->relayUsing(function (OutboxEvent $claimedEvent) use ($replacementToken): void {
            DB::table('outbox_events')
                ->where('event_id', $claimedEvent->event_id)
                ->update(['lease_token' => $replacementToken]);
        });

        $result = $relay->relay();
        $this->assertSame(0, $result->published);
        $this->assertSame(1, $result->leaseLost);

        $event->refresh();
        $this->assertSame('publishing', $event->status);
        $this->assertSame($replacementToken, $event->lease_token);
        $this->assertNull($event->published_at);
    }

    public function test_worker_cannot_mark_event_published_after_its_lease_expires(): void
    {
        $event = $this->createEvent();
        $relay = $this->relayUsing(function (OutboxEvent $claimedEvent): void {
            DB::table('outbox_events')
                ->where('event_id', $claimedEvent->event_id)
                ->update(['lease_expires_at' => now()->subSecond()]);
        });

        $result = $relay->relay();
        $this->assertSame(0, $result->published);
        $this->assertSame(1, $result->leaseLost);

        $event->refresh();
        $this->assertSame('publishing', $event->status);
        $this->assertLessThan(now()->timestamp, $event->lease_expires_at->timestamp);
        $this->assertNull($event->published_at);
    }

    public function test_worker_cannot_schedule_retry_after_its_lease_expires(): void
    {
        $event = $this->createEvent();
        $relay = $this->relayUsing(function (OutboxEvent $claimedEvent): void {
            DB::table('outbox_events')
                ->where('event_id', $claimedEvent->event_id)
                ->update(['lease_expires_at' => now()->subSecond()]);

            throw new OutboxPublishException('Simulated publish failure.');
        });

        $result = $relay->relay();

        $event->refresh();
        $this->assertSame(1, $result->failed);
        $this->assertSame(1, $result->leaseLost);
        $this->assertSame('publishing', $event->status);
        $this->assertNull($event->next_attempt_at);
    }

    public function test_publish_failure_retries_with_exponential_backoff_then_dead_letters(): void
    {
        config([
            'outbox.max_attempts' => 5,
            'outbox.retry_base_seconds' => 5,
            'outbox.retry_max_seconds' => 15,
        ]);
        $event = $this->createEvent();
        $relay = $this->relayUsing(static function (): void {
            throw new OutboxPublishException('Broker unavailable.');
        });

        $result = $relay->relay();
        $this->assertSame(1, $result->failed);
        $this->assertSame(0, $result->deadLettered);
        $event->refresh();
        $this->assertSame('pending', $event->status);
        $this->assertSame(1, $event->attempt_count);
        $this->assertSame(5, abs($event->next_attempt_at->timestamp - now()->timestamp));
        $this->assertSame(class_basename(OutboxPublishException::class), $event->last_error);

        for ($attempt = 2; $attempt <= 5; $attempt++) {
            DB::table('outbox_events')
                ->where('event_id', $event->event_id)
                ->update(['next_attempt_at' => now()->subSecond()]);

            $result = $relay->relay();
            $this->assertSame(1, $result->failed);
            $event->refresh();
            $this->assertSame($attempt, $event->attempt_count);

            if ($attempt < 5) {
                $this->assertSame(
                    min(5 * (2 ** ($attempt - 1)), 15),
                    abs($event->next_attempt_at->timestamp - now()->timestamp),
                );
            } else {
                $this->assertSame(1, $result->deadLettered);
            }
        }

        $event->refresh();
        $this->assertSame('dead_lettered', $event->status);
        $this->assertSame(5, $event->attempt_count);
        $this->assertNotNull($event->dead_lettered_at);
        $this->assertNull($event->next_attempt_at);
        $this->assertNull($event->lease_token);
        $this->assertNull($event->lease_expires_at);
    }

    public function test_expired_lease_at_attempt_limit_is_dead_lettered_without_another_publish(): void
    {
        config(['outbox.max_attempts' => 5]);
        $event = $this->createEvent([
            'status' => 'publishing',
            'lease_token' => (string) Str::uuid(),
            'lease_expires_at' => now()->subSecond(),
            'attempt_count' => 5,
        ]);
        $publishCount = 0;
        $relay = $this->relayUsing(function () use (&$publishCount): void {
            $publishCount++;
        });

        $result = $relay->relay();

        $event->refresh();
        $this->assertSame(0, $publishCount);
        $this->assertSame(1, $result->deadLettered);
        $this->assertSame('dead_lettered', $event->status);
        $this->assertNotNull($event->dead_lettered_at);
    }

    public function test_relay_stops_before_claiming_an_event_when_stop_is_requested(): void
    {
        $event = $this->createEvent();
        $relay = $this->relayUsing(static function (): void {});

        $result = $relay->relay(10, static fn (): bool => true);

        $event->refresh();
        $this->assertTrue($result->stopped);
        $this->assertSame(0, $result->attempted);
        $this->assertSame('pending', $event->status);
    }

    public function test_relay_finishes_the_current_publish_before_honoring_a_stop_request(): void
    {
        $this->createEvent();
        $this->createEvent();
        $publishCount = 0;
        $relay = $this->relayUsing(function () use (&$publishCount): void {
            $publishCount++;
        });

        $stopWhenPublished = static function () use (&$publishCount): bool {
            return $publishCount > 0;
        };
        $result = $relay->relay(10, $stopWhenPublished);

        $this->assertTrue($result->stopped);
        $this->assertSame(1, $result->attempted);
        $this->assertSame(1, $result->published);
        $this->assertSame(1, OutboxEvent::query()->where('status', 'published')->count());
        $this->assertSame(1, OutboxEvent::query()->where('status', 'pending')->count());
    }

    public function test_published_event_is_not_claimed_again(): void
    {
        $event = $this->createEvent([
            'status' => 'published',
            'published_at' => now(),
            'attempt_count' => 1,
        ]);
        $publishCount = 0;
        $relay = $this->relayUsing(function () use (&$publishCount): void {
            $publishCount++;
        });

        $result = $relay->relay();

        $event->refresh();
        $this->assertSame(0, $result->processed);
        $this->assertSame(0, $publishCount);
        $this->assertSame('published', $event->status);
        $this->assertSame(1, $event->attempt_count);
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

        return OutboxEvent::create(array_merge([
            'event_type' => 'StockMinimumReached',
            'exchange_name' => 'inventory.events',
            'routing_key' => 'inventory.stock.minimum_reached',
            'aggregate_id' => $product->id,
            'payload' => [
                'eventId' => (string) Str::uuid(),
                'eventType' => 'StockMinimumReached',
                'occurredAt' => now()->toISOString(),
                'aggregateId' => $product->id,
                'stock' => [
                    'stockBefore' => 4,
                    'stockAfter' => 3,
                    'minimumThreshold' => 3,
                ],
            ],
            'occurred_at' => now(),
            'status' => 'pending',
            'attempt_count' => 0,
        ], $overrides));
    }

    private function relayUsing(Closure $callback): OutboxRelay
    {
        return new OutboxRelay(new class($callback) implements OutboxPublisher
        {
            public function __construct(private Closure $callback) {}

            public function publish(OutboxEvent $event): void
            {
                ($this->callback)($event);
            }
        });
    }
}
