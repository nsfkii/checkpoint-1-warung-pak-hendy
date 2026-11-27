<?php

namespace Tests\Unit;

use App\Http\Resources\TransactionResource;
use App\Models\Transaction;
use App\Models\TransactionItem;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;

class TransactionResourceTest extends TestCase
{
    public function test_transaction_resource_fields_match_the_openapi_schema(): void
    {
        $transaction = new Transaction;
        $transaction->forceFill([
            'id' => 'c2eafc53-3f5e-41e0-9a15-479a6cda73cc',
            'total_amount' => 7000,
            'transaction_at' => Carbon::parse('2026-11-02T08:30:00Z'),
            'created_at' => Carbon::parse('2026-11-02T08:30:00Z'),
            'updated_at' => Carbon::parse('2026-11-02T08:30:00Z'),
        ]);

        $transaction->setRelation('items', new Collection([
            new TransactionItem([
                'product_id' => 'b2e0b1f4-f8c1-4d9c-9ee1-44fdd3c88f01',
                'product_name_snapshot' => 'Air Mineral 600 ml',
                'unit_snapshot' => 'botol',
                'quantity' => 2,
                'unit_price_snapshot' => 3500,
                'subtotal' => 7000,
            ]),
        ]));

        $resource = (new TransactionResource($transaction))->toArray(
            Request::create('/api/v1/transactions')
        );

        $schemas = Yaml::parseFile(dirname(__DIR__, 2).'/openapi.yaml')['components']['schemas'];

        $this->assertSame(
            $this->sorted($schemas['Transaction']['required']),
            $this->sorted(array_keys($resource))
        );
        $this->assertSame(
            $this->sorted(array_keys($schemas['TransactionItem']['properties'])),
            $this->sorted(array_keys($resource['items']->first()))
        );
        $this->assertSame(
            '2026-11-02T08:30:00.000000Z',
            $resource['createdAt']
        );
        $this->assertSame(
            '2026-11-02T08:30:00.000000Z',
            $resource['updatedAt']
        );
    }

    private function sorted(array $values): array
    {
        sort($values);

        return $values;
    }
}
