<?php

namespace App\Contracts;

use App\Exceptions\OutboxPublishException;
use App\Models\OutboxEvent;

interface OutboxPublisher
{
    /**
     * Return only after the broker confirms the message, or throw on failure.
     *
     * @throws OutboxPublishException
     */
    public function publish(OutboxEvent $event): void;
}
