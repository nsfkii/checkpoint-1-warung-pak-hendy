<?php

namespace Tests\Unit;

use App\Models\IdempotencyKey;
use App\Models\OutboxEvent;
use App\Models\TransactionItem;
use Tests\TestCase;

class ModelSchemaContractTest extends TestCase
{
    public function test_idempotency_key_model_matches_its_migration_columns(): void
    {
        $model = new IdempotencyKey;

        $this->assertModelColumnsExistInMigration(
            $model,
            '2026_11_01_000003_create_idempotency_keys_table.php'
        );
        $this->assertFalse($model->getIncrementing());
        $this->assertSame('string', $model->getKeyType());
        $this->assertTrue($model->usesTimestamps());
        $this->assertSame('created_at', $model->getCreatedAtColumn());
        $this->assertNull($model->getUpdatedAtColumn());
        $this->assertSame('transaction_id', $model->transaction()->getForeignKeyName());
    }

    public function test_transaction_item_model_matches_its_migration_columns(): void
    {
        $model = new TransactionItem;

        $this->assertModelColumnsExistInMigration(
            $model,
            '2026_11_01_000002_create_transaction_items_table.php'
        );
        $this->assertFalse($model->getIncrementing());
        $this->assertSame('string', $model->getKeyType());
        $this->assertFalse($model->usesTimestamps());
        $this->assertSame('transaction_id', $model->transaction()->getForeignKeyName());
        $this->assertSame('product_id', $model->product()->getForeignKeyName());
    }

    public function test_outbox_event_model_matches_its_migration_columns_and_uuid_key(): void
    {
        $model = new OutboxEvent;

        $this->assertModelColumnsExistInMigration(
            $model,
            '2026_11_01_000004_create_outbox_events_table.php'
        );
        $this->assertSame('event_id', $model->getKeyName());
        $this->assertFalse($model->getIncrementing());
        $this->assertSame('string', $model->getKeyType());
        $this->assertTrue($model->usesTimestamps());
        $this->assertSame('created_at', $model->getCreatedAtColumn());
        $this->assertNull($model->getUpdatedAtColumn());
        $this->assertSame('aggregate_id', $model->product()->getForeignKeyName());
    }

    private function assertModelColumnsExistInMigration(object $model, string $migrationFile): void
    {
        $migrationPath = dirname(__DIR__, 2).'/database/migrations/'.$migrationFile;
        $migration = file_get_contents($migrationPath);

        $this->assertNotFalse($migration);
        preg_match_all('/\$table->\w+\([\'"]([a-z_]+)[\'"]/', $migration, $matches);

        $columns = $matches[1];

        $this->assertContains($model->getKeyName(), $columns);

        foreach ($model->getFillable() as $fillableColumn) {
            $this->assertContains($fillableColumn, $columns);
        }
    }
}
