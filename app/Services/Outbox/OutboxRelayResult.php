<?php

namespace App\Services\Outbox;

final readonly class OutboxRelayResult
{
    public function __construct(
        public int $processed,
        public int $attempted,
        public int $published,
        public int $failed,
        public int $deadLettered,
        public int $leaseLost,
        public bool $stopped,
    ) {}
}
