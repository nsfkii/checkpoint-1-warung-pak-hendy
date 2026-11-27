<?php

namespace Tests\Integration;

use App\Contracts\OutboxAmqpConnectionFactory;
use App\Models\OutboxEvent;
use App\Services\Outbox\RabbitMqOutboxPublisher;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

class RabbitMqOutboxPublisherIntegrationTest extends TestCase
{
    public function test_message_is_published_and_routed_through_the_live_rabbitmq_service(): void
    {
        $this->configureIsolatedTestBroker();

        $connection = app(OutboxAmqpConnectionFactory::class)->connect();
        $channel = $connection->channel();
        $queue = 'outbox-integration-'.Str::uuid();

        try {
            $channel->exchange_declare('inventory.events', 'topic', false, true, false);
            $channel->queue_declare($queue, false, false, true, true);
            $channel->queue_bind($queue, 'inventory.events', 'inventory.stock.minimum_reached');

            $event = $this->event();
            app(RabbitMqOutboxPublisher::class)->publish($event);
            $message = $channel->basic_get($queue);

            $this->assertNotNull($message);
            $this->assertSame($event->event_id, $message->get_properties()['message_id']);
            $this->assertSame(2, $message->get_properties()['delivery_mode']);
            $this->assertJsonStringEqualsJsonString(
                json_encode($event->payload, JSON_THROW_ON_ERROR),
                $message->getBody(),
            );

            $publishedPayload = json_decode($message->getBody(), true, flags: JSON_THROW_ON_ERROR);
            $this->assertSame(
                ['aggregateId', 'eventId', 'eventType', 'occurredAt', 'product', 'stock'],
                $this->sortedKeys($publishedPayload),
            );
            $this->assertSame(['id', 'name', 'unit'], $this->sortedKeys($publishedPayload['product']));
            $this->assertSame(
                ['minimumThreshold', 'stockAfter', 'stockBefore'],
                $this->sortedKeys($publishedPayload['stock']),
            );
            $message->ack();
        } finally {
            if ($channel->is_open()) {
                $channel->queue_delete($queue);
                $channel->close();
            }

            if ($connection->isConnected()) {
                $connection->close();
            }
        }
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
            'occurredAt' => now()->toISOString(),
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

    /**
     * @param  array<string, mixed>  $value
     * @return list<string>
     */
    private function sortedKeys(array $value): array
    {
        $keys = array_keys($value);
        sort($keys);

        return $keys;
    }

    private function configureIsolatedTestBroker(): void
    {
        if (getenv('RUN_RABBITMQ_INTEGRATION_TESTS') !== '1') {
            $this->markTestSkipped(
                'Set RUN_RABBITMQ_INTEGRATION_TESTS=1 and RABBITMQ_TEST_* to use an isolated RabbitMQ broker.',
            );
        }

        $host = getenv('RABBITMQ_TEST_HOST');
        $port = filter_var(getenv('RABBITMQ_TEST_PORT'), FILTER_VALIDATE_INT);
        $user = getenv('RABBITMQ_TEST_USER');
        $password = getenv('RABBITMQ_TEST_PASSWORD');
        $vhost = getenv('RABBITMQ_TEST_VHOST');

        if (
            ! is_string($host) || $host === '' ||
            ! is_int($port) || $port < 1 ||
            ! is_string($user) || $user === '' ||
            ! is_string($password) || $password === '' ||
            ! is_string($vhost) || $vhost === ''
        ) {
            throw new RuntimeException('All RABBITMQ_TEST_* variables are required for RabbitMQ integration tests.');
        }

        if (
            strtolower($host) === strtolower((string) config('outbox.rabbitmq.host')) &&
            $port === (int) config('outbox.rabbitmq.port')
        ) {
            throw new RuntimeException('RabbitMQ integration tests must use an isolated broker endpoint.');
        }

        Config::set('outbox.rabbitmq.host', $host);
        Config::set('outbox.rabbitmq.port', $port);
        Config::set('outbox.rabbitmq.user', $user);
        Config::set('outbox.rabbitmq.password', $password);
        Config::set('outbox.rabbitmq.vhost', $vhost);
    }
}
