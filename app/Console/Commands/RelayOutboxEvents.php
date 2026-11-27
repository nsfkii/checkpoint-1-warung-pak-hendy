<?php

namespace App\Console\Commands;

use App\Services\Outbox\OutboxRelay;
use Illuminate\Console\Command;
use Symfony\Component\Console\Command\SignalableCommandInterface;

class RelayOutboxEvents extends Command implements SignalableCommandInterface
{
    protected $signature = 'outbox:relay {--limit= : Maximum number of outbox rows to process}';

    protected $description = 'Publish eligible transactional outbox events.';

    private bool $stopRequested = false;

    public function handle(OutboxRelay $relay): int
    {
        if (! extension_loaded('pcntl')) {
            $this->error('The pcntl extension is required for graceful worker shutdown.');

            return self::FAILURE;
        }

        $option = $this->option('limit');
        $limit = $option === null
            ? (int) config('outbox.batch_size')
            : filter_var($option, FILTER_VALIDATE_INT);

        if (! is_int($limit) || $limit < 1) {
            $this->error('The --limit option must be a positive integer.');

            return self::FAILURE;
        }

        $result = $relay->relay($limit, fn (): bool => $this->stopRequested);

        $this->line(sprintf(
            'Processed: %d; publish attempts: %d; published: %d; failed attempts: %d; dead-lettered: %d; lease lost: %d.',
            $result->processed,
            $result->attempted,
            $result->published,
            $result->failed,
            $result->deadLettered,
            $result->leaseLost,
        ));

        if ($result->stopped) {
            $this->comment('Stopped after the current event finished.');
        }

        return self::SUCCESS;
    }

    public function getSubscribedSignals(): array
    {
        return array_values(array_filter(
            [
                defined('SIGINT') ? SIGINT : null,
                defined('SIGTERM') ? SIGTERM : null,
            ],
            static fn (?int $signal): bool => $signal !== null,
        ));
    }

    public function handleSignal(int $signal, int|false $previousExitCode = 0): int|false
    {
        $this->stopRequested = true;

        return false;
    }
}
