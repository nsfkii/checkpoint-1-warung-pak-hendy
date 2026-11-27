<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_minimum_notifications', function (Blueprint $table) {
            $table->uuid('event_id')->primary();
            $table->string('event_type', 120);
            $table->uuid('aggregate_id');
            $table->string('product_name', 160);
            $table->string('product_unit', 32);
            $table->integer('stock_before');
            $table->integer('stock_after');
            $table->integer('minimum_threshold');
            $table->jsonb('payload');
            $table->timestampTz('occurred_at');
            $table->timestampTz('received_at')->useCurrent();
        });

        DB::statement(
            'ALTER TABLE stock_minimum_notifications ADD CONSTRAINT stock_minimum_notifications_stock_transition_check CHECK (stock_before > minimum_threshold AND stock_after <= minimum_threshold AND stock_after <= stock_before AND stock_after >= 0 AND minimum_threshold >= 0)',
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_minimum_notifications');
    }
};
