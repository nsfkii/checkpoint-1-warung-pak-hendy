<?php

namespace Tests\Feature;

use App\Console\Commands\ConsumeStockMinimumEvents;
use App\Services\ConsumerRunResult;
use App\Services\StockMinimumEventConsumer;
use Illuminate\Support\Facades\Exceptions;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class ConsumeStockMinimumEventsTest extends TestCase
{
    public function test_command_reports_success_duplicate_and_failure_counts(): void
    {
        $consumer = $this->mock(StockMinimumEventConsumer::class);
        $consumer->shouldReceive('run')
            ->once()
            ->with(2, Mockery::type('callable'))
            ->andReturn(new ConsumerRunResult(1, 1, 0, false));

        $this->artisan('stock-minimum:consume', ['--max-messages' => '2'])
            ->expectsOutputToContain('Processed: 1; duplicates: 1; failed: 0.')
            ->assertSuccessful();
    }

    public function test_invalid_message_limit_is_rejected_without_starting_the_consumer(): void
    {
        $consumer = $this->mock(StockMinimumEventConsumer::class);
        $consumer->shouldNotReceive('run');

        $this->artisan('stock-minimum:consume', ['--max-messages' => '0'])
            ->expectsOutputToContain('The --max-messages option must be a positive integer.')
            ->assertFailed();
    }

    public function test_connection_failure_is_reported_as_a_command_failure(): void
    {
        Exceptions::fake();
        $consumer = $this->mock(StockMinimumEventConsumer::class);
        $consumer->shouldReceive('run')
            ->once()
            ->andThrow(new RuntimeException('broker unavailable'));

        $this->artisan('stock-minimum:consume')
            ->expectsOutputToContain('The consumer could not maintain its RabbitMQ connection.')
            ->expectsOutputToContain('Processed: 0; duplicates: 0; failed: 0.')
            ->assertFailed();

        Exceptions::assertReported(RuntimeException::class);
    }

    public function test_command_subscribes_to_termination_signals(): void
    {
        $command = new ConsumeStockMinimumEvents;
        $signals = $command->getSubscribedSignals();

        $this->assertContains(SIGINT, $signals);
        $this->assertContains(SIGTERM, $signals);
        $this->assertFalse($command->handleSignal(SIGTERM));
    }
}
