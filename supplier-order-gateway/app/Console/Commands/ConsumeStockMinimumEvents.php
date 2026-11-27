<?php

namespace App\Console\Commands;

use App\Services\StockMinimumEventConsumer;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Symfony\Component\Console\Command\SignalableCommandInterface;
use Throwable;

#[Signature('stock-minimum:consume {--max-messages= : Exit after receiving this many messages}')]
#[Description('Consume and record minimum-stock notifications from RabbitMQ.')]
class ConsumeStockMinimumEvents extends Command implements SignalableCommandInterface
{
    protected $description = 'Consume and record minimum-stock notifications from RabbitMQ.';

    private bool $stopRequested = false;

    public function handle(StockMinimumEventConsumer $consumer): int
    {
        if (! extension_loaded('pcntl')) {
            $this->error('The pcntl extension is required for graceful worker shutdown.');

            return self::FAILURE;
        }

        $option = $this->option('max-messages');
        $maxMessages = $option === null ? null : filter_var($option, FILTER_VALIDATE_INT);

        if ($option !== null && (! is_int($maxMessages) || $maxMessages < 1)) {
            $this->error('The --max-messages option must be a positive integer.');

            return self::FAILURE;
        }

        try {
            $result = $consumer->run($maxMessages, fn (): bool => $this->stopRequested);
        } catch (Throwable $exception) {
            report($exception);
            $this->line('Processed: 0; duplicates: 0; failed: 0.');
            $this->error('The consumer could not maintain its RabbitMQ connection.');

            return self::FAILURE;
        }

        $this->line(sprintf(
            'Processed: %d; duplicates: %d; failed: %d.',
            $result->processed,
            $result->duplicates,
            $result->failed,
        ));

        if ($result->failure !== null) {
            report($result->failure);
            $this->error('Message processing failed; it was left unacknowledged and will be requeued when the connection closes.');

            return self::FAILURE;
        }

        if ($result->stopped) {
            $this->comment('Stopped after the current message finished.');
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
