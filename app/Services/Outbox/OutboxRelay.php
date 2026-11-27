<?php

namespace App\Services\Outbox;

use App\Contracts\OutboxPublisher;
use App\Exceptions\OutboxPublishException;
use App\Models\OutboxEvent;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class OutboxRelay
{
    public function __construct(private OutboxPublisher $publisher) {}

    public function relay(int $limit = 100, ?\Closure $shouldStop = null): OutboxRelayResult
    {
        if ($limit < 1) {
            throw new \InvalidArgumentException('The outbox batch limit must be positive.');
        }

        $processed = 0;
        $attempted = 0;
        $published = 0;
        $failed = 0;
        $deadLettered = 0;
        $leaseLost = 0;
        $stopped = false;

        while ($processed < $limit) {
            if ($shouldStop !== null && $shouldStop()) {
                $stopped = true;
                break;
            }

            $claim = $this->claimNext();

            if ($claim['dead_lettered']) {
                $processed++;
                $deadLettered++;

                continue;
            }

            $event = $claim['event'];

            if ($event === null) {
                break;
            }

            $processed++;
            $attempted++;

            try {
                $this->publisher->publish($event);
                if ($this->markPublished($event)) {
                    $published++;
                } else {
                    $leaseLost++;
                    break;
                }
            } catch (OutboxPublishException $exception) {
                $failed++;
                $failureOutcome = $this->markFailed($event, $exception);

                if ($failureOutcome === 'dead_lettered') {
                    $deadLettered++;
                } elseif ($failureOutcome === 'lease_lost') {
                    $leaseLost++;
                    break;
                }
            }
        }

        return new OutboxRelayResult(
            processed: $processed,
            attempted: $attempted,
            published: $published,
            failed: $failed,
            deadLettered: $deadLettered,
            leaseLost: $leaseLost,
            stopped: $stopped,
        );
    }

    /**
     * @return array{event: OutboxEvent|null, dead_lettered: bool}
     */
    private function claimNext(): array
    {
        $now = now();
        $maximumAttempts = (int) config('outbox.max_attempts');

        return DB::transaction(function () use ($maximumAttempts, $now): array {
            $exhaustedLease = OutboxEvent::query()
                ->where('status', 'publishing')
                ->where('lease_expires_at', '<=', $now)
                ->where('attempt_count', '>=', $maximumAttempts)
                ->orderBy('lease_expires_at')
                ->lock('FOR UPDATE SKIP LOCKED')
                ->first();

            if ($exhaustedLease !== null) {
                $exhaustedLease->status = 'dead_lettered';
                $exhaustedLease->dead_lettered_at = $now;
                $exhaustedLease->lease_token = null;
                $exhaustedLease->lease_expires_at = null;
                $exhaustedLease->next_attempt_at = null;
                $exhaustedLease->last_error ??= 'Maximum attempts reached after lease expiry.';
                $exhaustedLease->save();

                return ['event' => null, 'dead_lettered' => true];
            }

            $event = OutboxEvent::query()
                ->where('attempt_count', '<', $maximumAttempts)
                ->where(function ($query) use ($now): void {
                    $query->where(function ($query) use ($now): void {
                        $query->where('status', 'pending')
                            ->where(function ($query) use ($now): void {
                                $query->whereNull('next_attempt_at')
                                    ->orWhere('next_attempt_at', '<=', $now);
                            });
                    })->orWhere(function ($query) use ($now): void {
                        $query->where('status', 'publishing')
                            ->whereNotNull('lease_expires_at')
                            ->where('lease_expires_at', '<=', $now);
                    });
                })
                ->orderBy('created_at')
                ->orderBy('event_id')
                ->limit(1)
                ->lock('FOR UPDATE SKIP LOCKED')
                ->first();

            if ($event === null) {
                return ['event' => null, 'dead_lettered' => false];
            }

            $event->status = 'publishing';
            $event->lease_token = (string) Str::uuid();
            $event->lease_expires_at = $now->copy()->addSeconds((int) config('outbox.lease_seconds'));
            $event->attempt_count++;
            $event->next_attempt_at = null;
            $event->save();

            return ['event' => $event, 'dead_lettered' => false];
        });
    }

    private function markPublished(OutboxEvent $event): bool
    {
        $updated = OutboxEvent::query()
            ->whereKey($event->event_id)
            ->where('status', 'publishing')
            ->where('lease_token', $event->lease_token)
            ->where('lease_expires_at', '>', now())
            ->update([
                'status' => 'published',
                'published_at' => now(),
                'lease_token' => null,
                'lease_expires_at' => null,
                'last_error' => null,
            ]);

        return $updated === 1;
    }

    private function markFailed(OutboxEvent $event, OutboxPublishException $exception): string
    {
        $maximumAttempts = (int) config('outbox.max_attempts');
        $isExhausted = $event->attempt_count >= $maximumAttempts;
        $now = now();
        $retryDelay = min(
            (int) config('outbox.retry_base_seconds') * (2 ** max(0, $event->attempt_count - 1)),
            (int) config('outbox.retry_max_seconds'),
        );

        $updated = OutboxEvent::query()
            ->whereKey($event->event_id)
            ->where('status', 'publishing')
            ->where('lease_token', $event->lease_token)
            ->where('lease_expires_at', '>', $now)
            ->update([
                'status' => $isExhausted ? 'dead_lettered' : 'pending',
                'dead_lettered_at' => $isExhausted ? $now : null,
                'next_attempt_at' => $isExhausted ? null : $now->copy()->addSeconds($retryDelay),
                'lease_token' => null,
                'lease_expires_at' => null,
                'last_error' => class_basename($exception),
            ]);

        Log::warning('Outbox event publish failed.', [
            'event_id' => $event->event_id,
            'attempt_count' => $event->attempt_count,
            'exception' => class_basename($exception),
        ]);

        if ($updated !== 1) {
            return 'lease_lost';
        }

        return $isExhausted ? 'dead_lettered' : 'retrying';
    }
}
