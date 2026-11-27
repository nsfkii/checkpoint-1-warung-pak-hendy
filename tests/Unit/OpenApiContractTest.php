<?php

namespace Tests\Unit;

use App\Http\Requests\StoreProductRequest;
use App\Http\Requests\UpdateProductRequest;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

class OpenApiContractTest extends TestCase
{
    public function test_product_stock_limits_match_form_request_validation(): void
    {
        $openApi = $this->openApiDocument();

        $cases = [
            [
                $openApi['components']['schemas']['CreateProductRequest']['properties'],
                (new StoreProductRequest)->rules(),
                ['stockQuantity', 'minimumStock'],
            ],
            [
                $openApi['components']['schemas']['UpdateProductRequest']['properties'],
                (new UpdateProductRequest)->rules(),
                ['minimumStock'],
            ],
        ];

        foreach ($cases as [$properties, $rules, $fields]) {
            foreach ($fields as $field) {
                $maxRules = array_values(array_filter(
                    $rules[$field],
                    fn (string $rule): bool => str_starts_with($rule, 'max:')
                ));
                $maxRule = $maxRules[0] ?? null;

                $this->assertNotNull($maxRule);
                $this->assertSame(2147483647, $properties[$field]['maximum']);
                $this->assertSame(2147483647, (int) substr($maxRule, 4));
            }
        }
    }

    public function test_product_request_schemas_disallow_additional_properties(): void
    {
        $schemas = $this->openApiDocument()['components']['schemas'];

        $this->assertFalse($schemas['CreateProductRequest']['additionalProperties']);
        $this->assertFalse($schemas['UpdateProductRequest']['additionalProperties']);
    }

    private function openApiDocument(): array
    {
        return Yaml::parseFile(dirname(__DIR__, 2).'/openapi.yaml');
    }
}
