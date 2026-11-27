<?php

namespace App\Services;

use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Exception\AMQPTimeoutException;
use PhpAmqpLib\Message\AMQPMessage;
use Throwable;

class StockMinimumEventConsumer
{
    private const EXCHANGE = 'inventory.events';

    private const ROUTING_KEY = 'inventory.stock.minimum_reached';

    public function __construct(
        private PhpAmqpConnectionFactory $connections,
        private StockMinimumEventProcessor $processor,
    ) {}

    public function run(?int $maxMessages, callable $shouldStop): ConsumerRunResult
    {
        $connection = $this->connections->connect();
        $channel = null;
        $processed = 0;
        $duplicates = 0;
        $failed = 0;
        $failure = null;
        $consumerTag = null;

        try {
            $channel = $connection->channel();
            $channel->exchange_declare(self::EXCHANGE, 'topic', false, true, false);
            $channel->queue_declare(
                (string) config('supplier_gateway.queue'),
                false,
                true,
                false,
                false,
            );
            $channel->queue_bind(
                (string) config('supplier_gateway.queue'),
                self::EXCHANGE,
                self::ROUTING_KEY,
            );
            $channel->basic_qos(0, 1, false);
            $consumerTag = $channel->basic_consume(
                (string) config('supplier_gateway.queue'),
                '',
                false,
                false,
                false,
                false,
                function (AMQPMessage $message) use (&$processed, &$duplicates, &$failed, &$failure): void {
                    try {
                        if ($this->processor->process($message)) {
                            $processed++;
                        } else {
                            $duplicates++;
                        }
                    } catch (Throwable $exception) {
                        $failed++;
                        $failure = $exception;
                    }
                },
            );

            while (
                $failure === null &&
                ! $shouldStop() &&
                ($maxMessages === null || $processed + $duplicates + $failed < $maxMessages)
            ) {
                try {
                    $channel->wait(null, false, 1);
                } catch (AMQPTimeoutException) {
                    continue;
                }
            }

            $stopped = $shouldStop();

            if ($consumerTag !== null && $channel->is_open()) {
                $channel->basic_cancel($consumerTag);
            }

            return new ConsumerRunResult($processed, $duplicates, $failed, $stopped, $failure);
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
    }
}
