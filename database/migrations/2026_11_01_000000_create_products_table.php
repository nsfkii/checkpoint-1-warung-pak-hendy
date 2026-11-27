<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name', 160);
            $table->string('unit', 32);
            $table->decimal('selling_price', 15, 0);
            $table->integer('stock_quantity');
            $table->integer('minimum_stock');
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('updated_at')->useCurrent();
            $table->timestampTz('deleted_at')->nullable();
        });

        DB::statement("ALTER TABLE products ADD CONSTRAINT products_name_non_whitespace_check CHECK (name ~ '[^[:space:]]')");
        DB::statement("ALTER TABLE products ADD CONSTRAINT products_unit_non_whitespace_check CHECK (unit ~ '[^[:space:]]')");
        DB::statement("ALTER TABLE products ADD CONSTRAINT products_selling_price_nonnegative_not_nan_check CHECK (selling_price <> 'NaN'::numeric AND selling_price >= 0)");
        DB::statement('ALTER TABLE products ADD CONSTRAINT products_stock_quantity_nonnegative_check CHECK (stock_quantity >= 0)');
        DB::statement('ALTER TABLE products ADD CONSTRAINT products_minimum_stock_nonnegative_check CHECK (minimum_stock >= 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};
