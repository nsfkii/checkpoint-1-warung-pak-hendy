<?php

namespace Tests\Integration;

use App\Services\PhpAmqpConnectionFactory;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Str;
use PhpAmqpLib\Message\AMQPMessage;
use RuntimeException;
use Tests\TestCase;

class StockMinimumEventConsumerIntegrationTest extends TestCase
{
    use DatabaseTransactions;

    public function test_valid_message_is_consumed_and_acked_by_the_manual_command(): void
    {
        $this->configureIsolatedTestBroker();

        $queue = 'supplier-gateway-integration-'.Str::uuid();
        $connection = app(PhpAmqpConnectionFactory::class)->connect();
        $channel = $connection->channel();

        try {
            $channel->exchange_declare('inventory.events', 'topic', false, true, false);
            $channel->queue_declare($queue, false, true, false, false);
            $channel->queue_bind($queue, 'inventory.events', 'inventory.stock.minimum_reached');
            Config::set('supplier_gateway.queue', $queue);

            $eventId = (string) Str::uuid();
            $channel->basic_publish(
                new AMQPMessage($this->payload($eventId), [
                    'content_type' => 'application/json',
                    'delivery_mode' => AMQPMessage::DELIVERY_MODE_PERSISTENT,
                    'message_id' => $eventId,
                    'type' => 'StockMinimumReached',
                ]),
                'inventory.events',
                'inventory.stock.minimum_reached',
            );

            $this->artisan('stock-minimum:consume', ['--max-messages' => '1'])
                ->expectsOutputToContain('Processed: 1; duplicates: 0; failed: 0.')
                ->assertSuccessful();

            $this->assertDatabaseHas('stock_minimum_notifications', ['event_id' => $eventId]);
            $this->assertNull($channel->basic_get($queue));
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

    public function test_repeated_delivery_is_acked_but_only_one_notification_is_stored(): void
    {
        $this->configureIsolatedTestBroker();

        $queue = 'supplier-gateway-integration-'.Str::uuid();
        $connection = app(PhpAmqpConnectionFactory::class)->connect();
        $channel = $connection->channel();

        try {
            $channel->exchange_declare('inventory.events', 'topic', false, true, false);
            $channel->queue_declare($queue, false, true, false, false);
            $channel->queue_bind($queue, 'inventory.events', 'inventory.stock.minimum_reached');
            Config::set('supplier_gateway.queue', $queue);

            $eventId = (string) Str::uuid();
            $message = new AMQPMessage($this->payload($eventId), [
                'content_type' => 'application/json',
                'delivery_mode' => AMQPMessage::DELIVERY_MODE_PERSISTENT,
                'message_id' => $eventId,
                'type' => 'StockMinimumReached',
            ]);
            $channel->basic_publish($message, 'inventory.events', 'inventory.stock.minimum_reached');
            $channel->basic_publish($message, 'inventory.events', 'inventory.stock.minimum_reached');

            $this->artisan('stock-minimum:consume', ['--max-messages' => '2'])
                ->expectsOutputToContain('Processed: 1; duplicates: 1; failed: 0.')
                ->assertSuccessful();

            $this->assertDatabaseCount('stock_minimum_notifications', 1);
            $this->assertDatabaseHas('stock_minimum_notifications', ['event_id' => $eventId]);
            $this->assertNull($channel->basic_get($queue));
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

    private function payload(string $eventId): string
    {
        return json_encode([
            'eventId' => $eventId,
            'eventType' => 'StockMinimumReached',
            'occurredAt' => now()->toISOString(),
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
            strtolower($host) === strtolower((string) config('rabbitmq.host')) &&
            $port === (int) config('rabbitmq.port')
        ) {
            throw new RuntimeException('RabbitMQ integration tests must use an isolated broker endpoint.');
        }

        Config::set('rabbitmq.host', $host);
        Config::set('rabbitmq.port', $port);
        Config::set('rabbitmq.user', $user);
        Config::set('rabbitmq.password', $password);
        Config::set('rabbitmq.vhost', $vhost);
    }
}
