<?php

namespace Tests\Feature;

use App\Data\StockMinimumReachedEvent;
use App\Services\StockMinimumEventProcessor;
use App\Services\StockMinimumNotificationRecorder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Mockery;
use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Message\AMQPMessage;
use RuntimeException;
use Tests\TestCase;

class StockMinimumEventConsumerTest extends TestCase
{
    use DatabaseTransactions;

    public function test_valid_event_is_recorded_and_acknowledged_after_the_database_transaction_finishes(): void
    {
        $eventId = '11111111-1111-4111-8111-111111111111';
        $channel = Mockery::mock(AMQPChannel::class);
        $channel->shouldReceive('basic_ack')
            ->once()
            ->with(17, false)
            ->andReturnUsing(function () use ($eventId): void {
                $this->assertSame(1, DB::transactionLevel());
                $this->assertDatabaseHas('stock_minimum_notifications', ['event_id' => $eventId]);
            });
        $message = $this->message($this->payload($eventId), $eventId, $channel, 17);

        $wasNew = (new StockMinimumEventProcessor(new StockMinimumNotificationRecorder))->process($message);

        $this->assertTrue($wasNew);
        $this->assertDatabaseCount('stock_minimum_notifications', 1);
        $this->assertDatabaseHas('stock_minimum_notifications', [
            'event_id' => $eventId,
            'event_type' => 'StockMinimumReached',
            'stock_before' => 8,
            'stock_after' => 3,
            'minimum_threshold' => 4,
        ]);
    }

    public function test_duplicate_event_id_is_acknowledged_without_recording_a_second_notification(): void
    {
        $eventId = '11111111-1111-4111-8111-111111111111';
        $channel = Mockery::mock(AMQPChannel::class);
        $channel->shouldReceive('basic_ack')->twice()->with(17, false);
        $processor = new StockMinimumEventProcessor(new StockMinimumNotificationRecorder);

        $firstWasNew = $processor->process($this->message($this->payload($eventId), $eventId, $channel, 17));
        $duplicateWasNew = $processor->process($this->message($this->payload($eventId), $eventId, $channel, 17));

        $this->assertTrue($firstWasNew);
        $this->assertFalse($duplicateWasNew);
        $this->assertDatabaseCount('stock_minimum_notifications', 1);
    }

    public function test_invalid_payload_is_left_unacknowledged(): void
    {
        $channel = Mockery::mock(AMQPChannel::class);
        $channel->shouldNotReceive('basic_ack');
        $message = $this->message('not-json', '11111111-1111-4111-8111-111111111111', $channel, 17);

        try {
            (new StockMinimumEventProcessor(new StockMinimumNotificationRecorder))->process($message);
            $this->fail('Invalid JSON should not be processed.');
        } catch (InvalidArgumentException) {
            $this->assertDatabaseCount('stock_minimum_notifications', 0);
        }
    }

    public function test_message_identifier_must_match_the_payload_event_id(): void
    {
        $channel = Mockery::mock(AMQPChannel::class);
        $channel->shouldNotReceive('basic_ack');
        $message = $this->message(
            $this->payload('11111111-1111-4111-8111-111111111111'),
            '22222222-2222-4222-8222-222222222222',
            $channel,
            17,
        );

        try {
            (new StockMinimumEventProcessor(new StockMinimumNotificationRecorder))->process($message);
            $this->fail('A mismatched message identifier should not be processed.');
        } catch (InvalidArgumentException) {
            $this->assertDatabaseCount('stock_minimum_notifications', 0);
        }
    }

    public function test_storage_failure_leaves_the_message_unacknowledged(): void
    {
        $eventId = '11111111-1111-4111-8111-111111111111';
        $channel = Mockery::mock(AMQPChannel::class);
        $channel->shouldNotReceive('basic_ack');
        $recorder = Mockery::mock(StockMinimumNotificationRecorder::class);
        $recorder->shouldReceive('record')->once()->andThrow(new RuntimeException('database unavailable'));

        try {
            (new StockMinimumEventProcessor($recorder))
                ->process($this->message($this->payload($eventId), $eventId, $channel, 17));
            $this->fail('Storage failure should propagate.');
        } catch (RuntimeException $exception) {
            $this->assertSame('database unavailable', $exception->getMessage());
        }
    }

    public function test_payload_must_represent_the_documented_stock_transition(): void
    {
        $payload = json_decode($this->payload('11111111-1111-4111-8111-111111111111'), true, flags: JSON_THROW_ON_ERROR);
        $payload['stock']['stockBefore'] = 4;
        $payload['stock']['stockAfter'] = 5;

        $this->expectException(InvalidArgumentException::class);

        StockMinimumReachedEvent::fromJson(json_encode($payload, JSON_THROW_ON_ERROR));
    }

    public function test_payload_without_product_object_is_rejected(): void
    {
        $payload = json_decode($this->payload('11111111-1111-4111-8111-111111111111'), true, flags: JSON_THROW_ON_ERROR);
        unset($payload['product']);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('The product field must be an object.');

        StockMinimumReachedEvent::fromJson(json_encode($payload, JSON_THROW_ON_ERROR));
    }

    public function test_stock_values_must_fit_the_postgresql_integer_column(): void
    {
        $payload = json_decode($this->payload('11111111-1111-4111-8111-111111111111'), true, flags: JSON_THROW_ON_ERROR);
        $payload['stock']['stockBefore'] = 2_147_483_648;

        $this->expectException(InvalidArgumentException::class);

        StockMinimumReachedEvent::fromJson(json_encode($payload, JSON_THROW_ON_ERROR));
    }

    private function message(string $body, string $messageId, AMQPChannel $channel, int $deliveryTag): AMQPMessage
    {
        $message = new AMQPMessage($body, ['message_id' => $messageId]);
        $message->setChannel($channel);
        $message->setDeliveryInfo($deliveryTag, false, 'inventory.events', 'inventory.stock.minimum_reached');

        return $message;
    }

    private function payload(string $eventId): string
    {
        return json_encode([
            'eventId' => $eventId,
            'eventType' => 'StockMinimumReached',
            'occurredAt' => '2026-11-02T10:00:00Z',
            'aggregateId' => '33333333-3333-4333-8333-333333333333',
            'product' => [
                'id' => '33333333-3333-4333-8333-333333333333',
                'name' => 'Air Mineral',
                'unit' => 'botol',
            ],
            'stock' => [
                'stockBefore' => 8,
                'stockAfter' => 3,
                'minimumThreshold' => 4,
            ],
        ], JSON_THROW_ON_ERROR);
    }
}
