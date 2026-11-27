<?php

namespace Tests\Feature;

use App\Http\Requests\StoreProductRequest;
use Illuminate\Contracts\Validation\Factory as ValidationFactory;
use Tests\TestCase;

class ProductRequestValidationTest extends TestCase
{
    public function test_rejects_stock_values_above_the_postgresql_integer_limit(): void
    {
        $createPayload = [
            'name' => 'Air Mineral',
            'unit' => 'botol',
            'sellingPrice' => 3500,
            'stockQuantity' => 2147483648,
            'minimumStock' => 6,
        ];

        $updatePayload = [
            'name' => 'Air Mineral',
            'unit' => 'botol',
            'sellingPrice' => 3500,
            'minimumStock' => 2147483648,
        ];

        $cases = [
            [$this->postJson('/api/v1/products', $createPayload), '/stockQuantity'],
            [$this->postJson('/api/v1/products', array_replace($createPayload, ['stockQuantity' => 24, 'minimumStock' => 2147483648])), '/minimumStock'],
            [$this->putJson('/api/v1/products/00000000-0000-4000-8000-000000000000', $updatePayload), '/minimumStock'],
        ];

        foreach ($cases as [$response, $pointer]) {
            $response->assertUnprocessable()
                ->assertHeader('Content-Type', 'application/problem+json')
                ->assertJsonPath('type', 'https://warung-pak-hendy.example/problems/validation-error')
                ->assertJsonPath('title', 'Validation failed')
                ->assertJsonPath('status', 422)
                ->assertJsonPath('detail', 'One or more fields do not satisfy the request constraints.')
                ->assertJsonPath('errors.0.pointer', $pointer)
                ->assertJsonPath('errors.0.code', 'invalid_value');
        }
    }

    public function test_accepts_the_postgresql_integer_limit_for_stock_values(): void
    {
        $validator = $this->app->make(ValidationFactory::class)->make(
            [
                'name' => 'Air Mineral',
                'unit' => 'botol',
                'sellingPrice' => 3500,
                'stockQuantity' => 2147483647,
                'minimumStock' => 2147483647,
            ],
            (new StoreProductRequest)->rules()
        );

        $this->assertFalse($validator->fails());
    }

    public function test_rejects_names_and_units_containing_only_whitespace_on_create_and_update(): void
    {
        $createPayload = [
            'name' => 'Air Mineral',
            'unit' => 'botol',
            'sellingPrice' => 3500,
            'stockQuantity' => 24,
            'minimumStock' => 6,
        ];

        $updatePayload = [
            'name' => 'Air Mineral',
            'unit' => 'botol',
            'sellingPrice' => 3500,
            'minimumStock' => 6,
        ];

        $cases = [
            [$this->postJson('/api/v1/products', array_replace($createPayload, ['name' => " \t\n"])), '/name'],
            [$this->postJson('/api/v1/products', array_replace($createPayload, ['unit' => " \t\n"])), '/unit'],
            [$this->putJson('/api/v1/products/00000000-0000-4000-8000-000000000000', array_replace($updatePayload, ['name' => " \t\n"])), '/name'],
            [$this->putJson('/api/v1/products/00000000-0000-4000-8000-000000000000', array_replace($updatePayload, ['unit' => " \t\n"])), '/unit'],
        ];

        foreach ($cases as [$response, $pointer]) {
            $response->assertUnprocessable()
                ->assertJsonPath('errors.0.pointer', $pointer)
                ->assertJsonPath('errors.0.code', 'invalid_value');
        }
    }

    public function test_rejects_unknown_product_fields_on_create_and_update(): void
    {
        $createResponse = $this->postJson('/api/v1/products', [
            'name' => 'Air Mineral',
            'unit' => 'botol',
            'sellingPrice' => 3500,
            'stockQuantity' => 24,
            'minimumStock' => 6,
            'unexpectedField' => 'not allowed',
        ]);

        $updateResponse = $this->putJson('/api/v1/products/00000000-0000-4000-8000-000000000000', [
            'name' => 'Air Mineral',
            'unit' => 'botol',
            'sellingPrice' => 3500,
            'minimumStock' => 6,
            'stockQuantity' => 24,
        ]);

        foreach ([$createResponse, $updateResponse] as $response) {
            $response->assertUnprocessable()
                ->assertHeader('Content-Type', 'application/problem+json')
                ->assertJsonPath('errors.0.code', 'invalid_value');
        }

        $createResponse->assertJsonPath('errors.0.pointer', '/unexpectedField');
        $createResponse->assertJsonPath(
            'errors.0.detail',
            'The unexpectedField field is prohibited.'
        );
        $updateResponse->assertJsonPath('errors.0.pointer', '/stockQuantity');
    }

    public function test_rejects_negative_or_fractional_rupiah_prices_on_create_and_update(): void
    {
        $createPayload = [
            'name' => 'Air Mineral',
            'unit' => 'botol',
            'sellingPrice' => -1,
            'stockQuantity' => 24,
            'minimumStock' => 6,
        ];

        $updatePayload = [
            'name' => 'Air Mineral',
            'unit' => 'botol',
            'sellingPrice' => 3500.5,
            'minimumStock' => 6,
        ];

        $this->postJson('/api/v1/products', $createPayload)
            ->assertUnprocessable()
            ->assertJsonPath('errors.0.pointer', '/sellingPrice');

        $this->putJson('/api/v1/products/00000000-0000-4000-8000-000000000000', $updatePayload)
            ->assertUnprocessable()
            ->assertJsonPath('errors.0.pointer', '/sellingPrice');
    }

    public function test_returns_rfc9457_problem_details_for_invalid_product_uuid(): void
    {
        $this->getJson('/api/v1/products/not-a-uuid')
            ->assertNotFound()
            ->assertHeader('Content-Type', 'application/problem+json')
            ->assertExactJson([
                'type' => 'https://warung-pak-hendy.example/problems/not-found',
                'title' => 'Resource not found',
                'status' => 404,
                'detail' => 'The requested resource could not be found.',
                'instance' => '/api/v1/products/not-a-uuid',
            ]);
    }

    public function test_returns_rfc9457_problem_details_for_malformed_json(): void
    {
        $this->call(
            'POST',
            '/api/v1/products',
            [],
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_ACCEPT' => 'application/json',
            ],
            '{"name":'
        )
            ->assertBadRequest()
            ->assertHeader('Content-Type', 'application/problem+json')
            ->assertExactJson([
                'type' => 'https://warung-pak-hendy.example/problems/bad-request',
                'title' => 'Bad Request',
                'status' => 400,
                'detail' => 'The request could not be understood.',
                'instance' => '/api/v1/products',
            ]);
    }
}
