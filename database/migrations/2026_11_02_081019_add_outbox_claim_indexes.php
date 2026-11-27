<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        DB::statement("CREATE INDEX outbox_events_pending_claim_idx
            ON outbox_events (next_attempt_at, created_at, event_id)
            WHERE status = 'pending'");
        DB::statement("CREATE INDEX outbox_events_expired_lease_idx
            ON outbox_events (lease_expires_at, created_at, event_id)
            WHERE status = 'publishing'");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS outbox_events_expired_lease_idx');
        DB::statement('DROP INDEX IF EXISTS outbox_events_pending_claim_idx');
    }
};
