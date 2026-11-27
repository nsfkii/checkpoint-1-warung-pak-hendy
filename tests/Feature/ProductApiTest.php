<?php

namespace Tests\Feature;

use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_can_get_product_list(): void
    {
        Product::create([
            'name' => 'Air Mineral',
            'unit' => 'botol',
            'selling_price' => 3500,
            'stock_quantity' => 24,
            'minimum_stock' => 6,
        ]);

        $response = $this->getJson('/api/v1/products');

        $response->assertOk()
            ->assertJsonPath('data.0.name', 'Air Mineral')
            ->assertJsonPath('data.0.sellingPrice', 3500);
    }

    public function test_can_create_product(): void
    {
        $response = $this->postJson('/api/v1/products', [
            'name' => 'Indomie Goreng',
            'unit' => 'pcs',
            'sellingPrice' => 3500,
            'stockQuantity' => 20,
            'minimumStock' => 5,
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.name', 'Indomie Goreng')
            ->assertJsonPath('data.sellingPrice', 3500);

        $this->assertDatabaseHas('products', [
            'name' => 'Indomie Goreng',
            'stock_quantity' => 20,
        ]);
    }

    public function test_can_get_product_detail(): void
    {
        $product = Product::create([
            'name' => 'Kopi',
            'unit' => 'sachet',
            'selling_price' => 2000,
            'stock_quantity' => 30,
            'minimum_stock' => 5,
        ]);

        $response = $this->getJson("/api/v1/products/{$product->id}");

        $response->assertOk()
            ->assertJsonPath('data.id', $product->id)
            ->assertJsonPath('data.name', 'Kopi');
    }

    public function test_can_update_product(): void
    {
        $product = Product::create([
            'name' => 'Teh',
            'unit' => 'botol',
            'selling_price' => 4000,
            'stock_quantity' => 20,
            'minimum_stock' => 5,
        ]);

        $response = $this->putJson("/api/v1/products/{$product->id}", [
            'name' => 'Teh Manis',
            'unit' => 'botol',
            'sellingPrice' => 5000,
            'minimumStock' => 8,
        ]);

        $response->assertOk()
            ->assertJsonPath('data.name', 'Teh Manis')
            ->assertJsonPath('data.sellingPrice', 5000)
            ->assertJsonPath('data.stockQuantity', 20);
    }

    public function test_can_archive_product(): void
    {
        $product = Product::create([
            'name' => 'Susu',
            'unit' => 'kotak',
            'selling_price' => 6000,
            'stock_quantity' => 15,
            'minimum_stock' => 5,
        ]);

        $response = $this->deleteJson("/api/v1/products/{$product->id}");

        $response->assertNoContent();

        $this->assertSoftDeleted('products', [
            'id' => $product->id,
        ]);
    }

    public function test_missing_product_returns_problem_details(): void
    {
        $response = $this->getJson(
            '/api/v1/products/00000000-0000-4000-8000-000000000000'
        );

        $response->assertNotFound()
            ->assertJsonStructure([
                'type',
                'title',
                'status',
                'detail',
                'instance',
            ]);
    }

    public function test_invalid_product_input_returns_problem_details(): void
    {
        $response = $this->postJson('/api/v1/products', [
            'name' => '',
            'unit' => 'botol',
            'sellingPrice' => 3500,
            'stockQuantity' => 24,
            'minimumStock' => 6,
        ]);

        $response->assertUnprocessable()
            ->assertJsonStructure([
                'type',
                'title',
                'status',
                'detail',
                'instance',
                'errors' => [
                    '*' => [
                        'pointer',
                        'code',
                        'detail',
                    ],
                ],
            ]);
    }
}
