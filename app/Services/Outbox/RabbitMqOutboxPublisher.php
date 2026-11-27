<?php

namespace App\Services\Outbox;

use App\Contracts\OutboxAmqpConnectionFactory;
use App\Contracts\OutboxPublisher;
use App\Exceptions\OutboxPublishException;
use App\Models\OutboxEvent;
use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Message\AMQPMessage;
use Throwable;

class RabbitMqOutboxPublisher implements OutboxPublisher
{
    private const EXCHANGE = 'inventory.events';

    private const ROUTING_KEY = 'inventory.stock.minimum_reached';

    public function __construct(private OutboxAmqpConnectionFactory $connections) {}

    public function publish(OutboxEvent $event): void
    {
        $eventId = $event->payload['eventId'] ?? null;

        if (
            $event->event_type !== 'StockMinimumReached' ||
            $event->exchange_name !== self::EXCHANGE ||
            $event->routing_key !== self::ROUTING_KEY ||
            ! is_string($eventId) ||
            $eventId === '' ||
            $eventId !== $event->event_id
        ) {
            throw new OutboxPublishException('The outbox event does not match the supported event contract.');
        }

        $connection = null;
        $channel = null;

        try {
            $connection = $this->connections->connect();
            try {
                $channel = $connection->channel();
                $channel->exchange_declare(
                    self::EXCHANGE,
                    'topic',
                    false,
                    true,
                    false,
                );
                $channel->confirm_select();

                $wasNacked = false;
                $wasReturned = false;

                $channel->set_nack_handler(static function (AMQPMessage $message) use (&$wasNacked): void {
                    $wasNacked = true;
                });
                $channel->set_return_listener(
                    static function (
                        int $replyCode,
                        string $replyText,
                        string $exchange,
                        string $routingKey,
                        AMQPMessage $message,
                    ) use (&$wasReturned): void {
                        $wasReturned = true;
                    },
                );

                $message = new AMQPMessage(
                    json_encode($event->payload, JSON_THROW_ON_ERROR),
                    [
                        'content_type' => 'application/json',
                        'delivery_mode' => AMQPMessage::DELIVERY_MODE_PERSISTENT,
                        'message_id' => $eventId,
                        'type' => $event->event_type,
                    ],
                );

                $channel->basic_publish($message, self::EXCHANGE, self::ROUTING_KEY, true);
                $channel->wait_for_pending_acks_returns(
                    (float) config('outbox.rabbitmq.publisher_confirm_timeout'),
                );

                if ($wasNacked) {
                    throw new OutboxPublishException('RabbitMQ negatively acknowledged the outbox message.');
                }

                if ($wasReturned) {
                    throw new OutboxPublishException('RabbitMQ could not route the outbox message.');
                }
            } finally {
                try {
                    if ($channel instanceof AMQPChannel && $channel->is_open()) {
                        $channel->close();
                    }
                } finally {
                    if ($connection->isConnected()) {
                        $connection->close();
                    }
                }
            }
        } catch (OutboxPublishException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw new OutboxPublishException('RabbitMQ could not confirm the outbox message.', previous: $exception);
        }
    }
}
