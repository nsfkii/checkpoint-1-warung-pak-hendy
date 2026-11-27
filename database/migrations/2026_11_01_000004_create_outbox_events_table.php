<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('outbox_events', function (Blueprint $table) {
            $table->uuid('event_id')->primary();
            $table->string('event_type', 120);
            $table->string('exchange_name', 160);
            $table->string('routing_key', 200);
            $table->uuid('aggregate_id');
            $table->jsonb('payload');
            $table->timestampTz('occurred_at')->useCurrent();
            $table->timestampTz('created_at')->useCurrent();
            $table->string('status', 20);
            $table->uuid('lease_token')->nullable();
            $table->timestampTz('lease_expires_at')->nullable();
            $table->timestampTz('published_at')->nullable();
            $table->timestampTz('dead_lettered_at')->nullable();
            $table->integer('attempt_count')->default(0);
            $table->timestampTz('next_attempt_at')->nullable();
            $table->text('last_error')->nullable();

            $table->foreign('aggregate_id', 'outbox_events_aggregate_id_foreign')
                ->references('id')->on('products')->restrictOnDelete();
        });

        DB::statement("ALTER TABLE outbox_events ADD CONSTRAINT outbox_events_status_check CHECK (status IN ('pending', 'publishing', 'published', 'dead_lettered'))");
        DB::statement('ALTER TABLE outbox_events ADD CONSTRAINT outbox_events_attempt_count_nonnegative_check CHECK (attempt_count >= 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('outbox_events');
    }
};
