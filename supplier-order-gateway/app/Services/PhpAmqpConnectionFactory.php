<?php

namespace App\Services;

use PhpAmqpLib\Connection\AMQPStreamConnection;

class PhpAmqpConnectionFactory
{
    public function connect(): AMQPStreamConnection
    {
        return new AMQPStreamConnection(
            host: (string) config('rabbitmq.host'),
            port: (int) config('rabbitmq.port'),
            user: (string) config('rabbitmq.user'),
            password: (string) config('rabbitmq.password'),
            vhost: (string) config('rabbitmq.vhost'),
            connection_timeout: (float) config('rabbitmq.connection_timeout'),
            read_write_timeout: (float) config('rabbitmq.read_write_timeout'),
            heartbeat: (int) config('rabbitmq.heartbeat'),
            channel_rpc_timeout: (float) config('rabbitmq.channel_rpc_timeout'),
        );
    }
}
