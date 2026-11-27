<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('transactions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->decimal('total_amount', 15, 0);
            $table->timestampTz('transaction_at')->useCurrent();
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('updated_at')->useCurrent();
        });

        DB::statement("ALTER TABLE transactions ADD CONSTRAINT transactions_total_amount_nonnegative_not_nan_check CHECK (total_amount <> 'NaN'::numeric AND total_amount >= 0)");
    }

    public function down(): void
    {
        Schema::dropIfExists('transactions');
    }
};
