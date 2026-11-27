<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('transaction_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('transaction_id');
            $table->uuid('product_id');
            $table->string('product_name_snapshot', 160);
            $table->string('unit_snapshot', 32);
            $table->integer('quantity');
            $table->decimal('unit_price_snapshot', 15, 0);
            $table->decimal('subtotal', 15, 0);

            $table->foreign('transaction_id', 'transaction_items_transaction_id_foreign')
                ->references('id')->on('transactions')->restrictOnDelete();
            $table->foreign('product_id', 'transaction_items_product_id_foreign')
                ->references('id')->on('products')->restrictOnDelete();

            $table->unique(['transaction_id', 'product_id'], 'transaction_items_transaction_product_unique');
            $table->index('transaction_id', 'transaction_items_transaction_id_index');
            $table->index('product_id', 'transaction_items_product_id_index');
        });

        DB::statement("ALTER TABLE transaction_items ADD CONSTRAINT transaction_items_product_name_snapshot_non_whitespace_check CHECK (product_name_snapshot ~ '[^[:space:]]')");
        DB::statement("ALTER TABLE transaction_items ADD CONSTRAINT transaction_items_unit_snapshot_non_whitespace_check CHECK (unit_snapshot ~ '[^[:space:]]')");
        DB::statement('ALTER TABLE transaction_items ADD CONSTRAINT transaction_items_quantity_positive_check CHECK (quantity > 0)');
        DB::statement("ALTER TABLE transaction_items ADD CONSTRAINT transaction_items_unit_price_snapshot_nonnegative_not_nan_check CHECK (unit_price_snapshot <> 'NaN'::numeric AND unit_price_snapshot >= 0)");
        DB::statement("ALTER TABLE transaction_items ADD CONSTRAINT transaction_items_subtotal_nonnegative_not_nan_check CHECK (subtotal <> 'NaN'::numeric AND subtotal >= 0)");
        DB::statement('ALTER TABLE transaction_items ADD CONSTRAINT transaction_items_subtotal_matches_quantity_price_check CHECK (subtotal = quantity * unit_price_snapshot)');
    }

    public function down(): void
    {
        Schema::dropIfExists('transaction_items');
    }
};
