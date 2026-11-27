<?php

namespace Tests\Feature;

use App\Contracts\OutboxAmqpConnectionFactory;
use App\Exceptions\OutboxPublishException;
use App\Models\OutboxEvent;
use App\Services\Outbox\RabbitMqOutboxPublisher;
use Illuminate\Support\Str;
use Mockery;
use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;
use RuntimeException;
use Tests\TestCase;

class RabbitMqOutboxPublisherTest extends TestCase
{
    public function test_it_publishes_persistent_json_to_the_configured_event_route(): void
    {
        $event = $this->event();
        $publishedMessage = null;
        [$factory, $connection, $channel] = $this->mockConnection();

        $channel->shouldReceive('exchange_declare')
            ->once()
            ->with('inventory.events', 'topic', false, true, false);
        $channel->shouldReceive('confirm_select')->once();
        $channel->shouldReceive('set_nack_handler')->once()->with(Mockery::type('callable'));
        $channel->shouldReceive('set_return_listener')->once()->with(Mockery::type('callable'));
        $channel->shouldReceive('basic_publish')
            ->once()
            ->withArgs(function (
                AMQPMessage $message,
                string $exchange,
                string $routingKey,
                bool $mandatory,
            ) use (&$publishedMessage): bool {
                $publishedMessage = $message;

                return $exchange === 'inventory.events'
                    && $routingKey === 'inventory.stock.minimum_reached'
                    && $mandatory;
            });
        $channel->shouldReceive('wait_for_pending_acks_returns')->once()->with(5.0);
        $this->expectConnectionClosed($connection, $channel);

        $this->publisher($factory)->publish($event);

        $this->assertSame(
            [
                'content_type' => 'application/json',
                'delivery_mode' => AMQPMessage::DELIVERY_MODE_PERSISTENT,
                'message_id' => $event->event_id,
                'type' => 'StockMinimumReached',
            ],
            $publishedMessage->get_properties(),
        );
        $this->assertJsonStringEqualsJsonString(
            json_encode($event->payload, JSON_THROW_ON_ERROR),
            $publishedMessage->getBody(),
        );
    }

    public function test_connection_failure_is_wrapped_for_outbox_retry(): void
    {
        $factory = Mockery::mock(OutboxAmqpConnectionFactory::class);
        $factory->shouldReceive('connect')
            ->once()
            ->andThrow(new RuntimeException('Connection refused.'));

        try {
            $this->publisher($factory)->publish($this->event());
            $this->fail('Expected the connection error to be wrapped.');
        } catch (OutboxPublishException $exception) {
            $this->assertInstanceOf(RuntimeException::class, $exception->getPrevious());
            $this->assertSame('RabbitMQ could not confirm the outbox message.', $exception->getMessage());
        }
    }

    public function test_channel_failure_is_wrapped_for_outbox_retry(): void
    {
        $event = $this->event();
        [$factory, $connection, $channel] = $this->mockConnection();
        $channel->shouldReceive('exchange_declare')
            ->once()
            ->andThrow(new RuntimeException('Channel closed.'));
        $channel->shouldReceive('is_open')->andReturn(false);
        $connection->shouldReceive('isConnected')->andReturn(true);
        $connection->shouldReceive('close')->once();

        try {
            $this->publisher($factory)->publish($event);
            $this->fail('Expected the channel error to be wrapped.');
        } catch (OutboxPublishException $exception) {
            $this->assertInstanceOf(RuntimeException::class, $exception->getPrevious());
        }
    }

    public function test_negative_publisher_confirmation_is_retried(): void
    {
        $event = $this->event();
        [$factory, $connection, $channel] = $this->mockConnection();
        $nackHandler = null;
        $channel->shouldReceive('exchange_declare')->once();
        $channel->shouldReceive('confirm_select')->once();
        $channel->shouldReceive('set_nack_handler')
            ->once()
            ->withArgs(function (callable $handler) use (&$nackHandler): bool {
                $nackHandler = $handler;

                return true;
            });
        $channel->shouldReceive('set_return_listener')->once();
        $channel->shouldReceive('basic_publish')->once();
        $channel->shouldReceive('wait_for_pending_acks_returns')
            ->once()
            ->andReturnUsing(function () use (&$nackHandler, $event): void {
                $nackHandler(new AMQPMessage(json_encode($event->payload, JSON_THROW_ON_ERROR)));
            });
        $this->expectConnectionClosed($connection, $channel);

        $this->expectException(OutboxPublishException::class);
        $this->expectExceptionMessage('RabbitMQ negatively acknowledged the outbox message.');

        $this->publisher($factory)->publish($event);
    }

    public function test_unroutable_message_is_rejected_for_retry(): void
    {
        $event = $this->event();
        [$factory, $connection, $channel] = $this->mockConnection();
        $returnListener = null;
        $channel->shouldReceive('exchange_declare')->once();
        $channel->shouldReceive('confirm_select')->once();
        $channel->shouldReceive('set_nack_handler')->once();
        $channel->shouldReceive('set_return_listener')
            ->once()
            ->withArgs(function (callable $listener) use (&$returnListener): bool {
                $returnListener = $listener;

                return true;
            });
        $channel->shouldReceive('basic_publish')->once();
        $channel->shouldReceive('wait_for_pending_acks_returns')
            ->once()
            ->andReturnUsing(function () use (&$returnListener, $event): void {
                $returnListener(
                    312,
                    'NO_ROUTE',
                    'inventory.events',
                    'inventory.stock.minimum_reached',
                    new AMQPMessage(json_encode($event->payload, JSON_THROW_ON_ERROR)),
                );
            });
        $this->expectConnectionClosed($connection, $channel);

        $this->expectException(OutboxPublishException::class);
        $this->expectExceptionMessage('RabbitMQ could not route the outbox message.');

        $this->publisher($factory)->publish($event);
    }

    private function event(): OutboxEvent
    {
        $eventId = (string) Str::uuid();
        $aggregateId = (string) Str::uuid();
        $event = new OutboxEvent;
        $event->event_id = $eventId;
        $event->event_type = 'StockMinimumReached';
        $event->exchange_name = 'inventory.events';
        $event->routing_key = 'inventory.stock.minimum_reached';
        $event->payload = [
            'eventId' => $eventId,
            'eventType' => 'StockMinimumReached',
            'occurredAt' => '2026-11-02T15:00:00Z',
            'aggregateId' => $aggregateId,
            'product' => [
                'id' => $aggregateId,
                'name' => 'Air Mineral',
                'unit' => 'botol',
            ],
            'stock' => [
                'stockBefore' => 5,
                'stockAfter' => 3,
                'minimumThreshold' => 3,
            ],
        ];

        return $event;
    }

    private function publisher(OutboxAmqpConnectionFactory $factory): RabbitMqOutboxPublisher
    {
        config(['outbox.rabbitmq.publisher_confirm_timeout' => 5.0]);

        return new RabbitMqOutboxPublisher($factory);
    }

    /**
     * @return array{OutboxAmqpConnectionFactory, AMQPStreamConnection, AMQPChannel}
     */
    private function mockConnection(): array
    {
        $factory = Mockery::mock(OutboxAmqpConnectionFactory::class);
        $connection = Mockery::mock(AMQPStreamConnection::class);
        $channel = Mockery::mock(AMQPChannel::class);

        $factory->shouldReceive('connect')->once()->andReturn($connection);
        $connection->shouldReceive('channel')->once()->andReturn($channel);

        return [$factory, $connection, $channel];
    }

    private function expectConnectionClosed(
        AMQPStreamConnection $connection,
        AMQPChannel $channel,
    ): void {
        $channel->shouldReceive('is_open')->andReturn(true);
        $channel->shouldReceive('close')->once();
        $connection->shouldReceive('isConnected')->andReturn(true);
        $connection->shouldReceive('close')->once();
    }
}
