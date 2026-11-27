<?php

namespace App\Services\Outbox;

use App\Contracts\OutboxAmqpConnectionFactory;
use PhpAmqpLib\Connection\AMQPStreamConnection;

class PhpAmqpConnectionFactory implements OutboxAmqpConnectionFactory
{
    public function connect(): AMQPStreamConnection
    {
        return new AMQPStreamConnection(
            host: (string) config('outbox.rabbitmq.host'),
            port: (int) config('outbox.rabbitmq.port'),
            user: (string) config('outbox.rabbitmq.user'),
            password: (string) config('outbox.rabbitmq.password'),
            vhost: (string) config('outbox.rabbitmq.vhost'),
            connection_timeout: (float) config('outbox.rabbitmq.connection_timeout'),
            read_write_timeout: (float) config('outbox.rabbitmq.read_write_timeout'),
            heartbeat: (int) config('outbox.rabbitmq.heartbeat'),
            channel_rpc_timeout: (float) config('outbox.rabbitmq.publisher_confirm_timeout'),
        );
    }
}
