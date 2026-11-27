<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('idempotency_keys', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('key', 255)->unique('idempotency_keys_key_unique');
            $table->char('request_hash', 64);
            $table->uuid('transaction_id')->nullable()->unique('idempotency_keys_transaction_id_unique');
            $table->smallInteger('response_status');
            $table->jsonb('response_body');
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('expires_at')->nullable();

            $table->foreign('transaction_id', 'idempotency_keys_transaction_id_foreign')
                ->references('id')->on('transactions')->restrictOnDelete();
        });

        DB::statement("ALTER TABLE idempotency_keys ADD CONSTRAINT idempotency_keys_request_hash_hex_check CHECK (request_hash ~ '^[0-9A-Fa-f]{64}$')");
        DB::statement('ALTER TABLE idempotency_keys ADD CONSTRAINT idempotency_keys_response_transaction_pair_check CHECK ((response_status = 200 AND transaction_id IS NOT NULL) OR (response_status = 409 AND transaction_id IS NULL))');
    }

    public function down(): void
    {
        Schema::dropIfExists('idempotency_keys');
    }
};
