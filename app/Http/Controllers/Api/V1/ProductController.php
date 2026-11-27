<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreProductRequest;
use App\Http\Requests\UpdateProductRequest;
use App\Http\Resources\ProductResource;
use App\Models\Product;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

class ProductController extends Controller
{
    public function index()
    {
        $products = Product::query()
            ->orderBy('name')
            ->get();

        return ProductResource::collection($products);
    }

    public function show(string $productId): ProductResource
    {
        $product = Product::query()->findOrFail($productId);

        return new ProductResource($product);
    }

    public function store(StoreProductRequest $request): JsonResponse
    {
        $data = $request->validated();

        $product = Product::create([
            'name' => $data['name'],
            'unit' => $data['unit'],
            'selling_price' => $data['sellingPrice'],
            'stock_quantity' => $data['stockQuantity'],
            'minimum_stock' => $data['minimumStock'],
        ]);

        return (new ProductResource($product))
            ->response()
            ->setStatusCode(201)
            ->header(
                'Location',
                url("/api/v1/products/{$product->id}")
            );
    }

    public function update(
        UpdateProductRequest $request,
        string $productId
    ): ProductResource {
        $product = Product::query()->findOrFail($productId);

        $data = $request->validated();

        $product->update([
            'name' => $data['name'],
            'unit' => $data['unit'],
            'selling_price' => $data['sellingPrice'],
            'minimum_stock' => $data['minimumStock'],
        ]);

        return new ProductResource($product->refresh());
    }

    public function destroy(string $productId): Response
    {
        $product = Product::query()->findOrFail($productId);

        $product->delete();

        return response()->noContent();
    }
}
