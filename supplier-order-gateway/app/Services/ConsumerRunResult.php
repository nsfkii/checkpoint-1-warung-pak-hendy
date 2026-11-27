<?php

namespace App\Services;

use Throwable;

final readonly class ConsumerRunResult
{
    public function __construct(
        public int $processed,
        public int $duplicates,
        public int $failed,
        public bool $stopped,
        public ?Throwable $failure = null,
    ) {}
}
