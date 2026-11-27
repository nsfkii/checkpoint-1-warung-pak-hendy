<?php

namespace App\Contracts;

use PhpAmqpLib\Connection\AMQPStreamConnection;

interface OutboxAmqpConnectionFactory
{
    public function connect(): AMQPStreamConnection;
}
