<?php

namespace App\Services;

use App\Data\StockMinimumReachedEvent;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class StockMinimumNotificationRecorder
{
    public function record(StockMinimumReachedEvent $event): bool
    {
        return DB::transaction(function () use ($event): bool {
            $inserted = DB::table('stock_minimum_notifications')->insertOrIgnore([
                'event_id' => $event->eventId,
                'event_type' => 'StockMinimumReached',
                'aggregate_id' => $event->aggregateId,
                'product_name' => $event->productName,
                'product_unit' => $event->productUnit,
                'stock_before' => $event->stockBefore,
                'stock_after' => $event->stockAfter,
                'minimum_threshold' => $event->minimumThreshold,
                'payload' => $event->payload,
                'occurred_at' => $event->occurredAtForDatabase(),
                'received_at' => now(),
            ]);

            if ($inserted === 1) {
                return true;
            }

            if (! DB::table('stock_minimum_notifications')->where('event_id', $event->eventId)->exists()) {
                throw new RuntimeException('The event insert was ignored without a matching deduplication record.');
            }

            return false;
        });
    }
}
