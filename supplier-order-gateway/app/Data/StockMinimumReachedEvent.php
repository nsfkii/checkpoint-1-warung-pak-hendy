<?php

namespace App\Data;

use DateTimeImmutable;
use Illuminate\Support\Str;
use InvalidArgumentException;
use JsonException;
use stdClass;

final readonly class StockMinimumReachedEvent
{
    private const MAX_STOCK_VALUE = 2_147_483_647;

    private function __construct(
        public string $eventId,
        public string $aggregateId,
        public DateTimeImmutable $occurredAt,
        public string $productName,
        public string $productUnit,
        public int $stockBefore,
        public int $stockAfter,
        public int $minimumThreshold,
        public string $payload,
    ) {}

    public static function fromJson(string $payload): self
    {
        try {
            $data = json_decode($payload, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new InvalidArgumentException('The event payload must be valid JSON.', previous: $exception);
        }

        if (! $data instanceof stdClass) {
            throw new InvalidArgumentException('The event payload must be a JSON object.');
        }

        $eventId = self::requiredString($data, 'eventId');
        $eventType = self::requiredString($data, 'eventType');
        $aggregateId = self::requiredString($data, 'aggregateId');
        $occurredAtValue = self::requiredString($data, 'occurredAt');

        if ($eventType !== 'StockMinimumReached') {
            throw new InvalidArgumentException('The event type is not supported.');
        }

        if (! Str::isUuid($eventId) || ! Str::isUuid($aggregateId)) {
            throw new InvalidArgumentException('Event and aggregate identifiers must be UUIDs.');
        }

        if (! preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+-]\d{2}:\d{2})$/D', $occurredAtValue)) {
            throw new InvalidArgumentException('occurredAt must be an RFC 3339 date-time.');
        }

        $parsedDate = date_parse($occurredAtValue);

        if ($parsedDate['error_count'] !== 0 || $parsedDate['warning_count'] !== 0) {
            throw new InvalidArgumentException('occurredAt must be a valid RFC 3339 date-time.');
        }

        $occurredAt = new DateTimeImmutable($occurredAtValue);
        $product = self::requiredObject($data, 'product');
        $stock = self::requiredObject($data, 'stock');
        $productId = self::requiredString($product, 'id');
        $productName = self::requiredString($product, 'name');
        $productUnit = self::requiredString($product, 'unit');

        if (! Str::isUuid($productId) || Str::lower($productId) !== Str::lower($aggregateId)) {
            throw new InvalidArgumentException('product.id must match aggregateId.');
        }

        if (
            trim($productName) === '' ||
            mb_strlen($productName, 'UTF-8') > 160 ||
            trim($productUnit) === '' ||
            mb_strlen($productUnit, 'UTF-8') > 32
        ) {
            throw new InvalidArgumentException('Product name or unit is invalid.');
        }

        $stockBefore = self::requiredNonNegativeInteger($stock, 'stockBefore');
        $stockAfter = self::requiredNonNegativeInteger($stock, 'stockAfter');
        $minimumThreshold = self::requiredNonNegativeInteger($stock, 'minimumThreshold');

        if ($stockBefore <= $minimumThreshold || $stockAfter > $minimumThreshold || $stockAfter > $stockBefore) {
            throw new InvalidArgumentException('Stock values do not represent a minimum-stock transition.');
        }

        return new self(
            Str::lower($eventId),
            Str::lower($aggregateId),
            $occurredAt,
            $productName,
            $productUnit,
            $stockBefore,
            $stockAfter,
            $minimumThreshold,
            $payload,
        );
    }

    public function occurredAtForDatabase(): string
    {
        return $this->occurredAt->format('Y-m-d H:i:s.uP');
    }

    private static function requiredObject(stdClass $data, string $key): stdClass
    {
        $value = $data->{$key} ?? null;

        if (! $value instanceof stdClass) {
            throw new InvalidArgumentException("The {$key} field must be an object.");
        }

        return $value;
    }

    private static function requiredString(stdClass $data, string $key): string
    {
        $value = $data->{$key} ?? null;

        if (! is_string($value) || $value === '') {
            throw new InvalidArgumentException("The {$key} field must be a non-empty string.");
        }

        return $value;
    }

    private static function requiredNonNegativeInteger(stdClass $data, string $key): int
    {
        $value = $data->{$key} ?? null;

        if (! is_int($value) || $value < 0 || $value > self::MAX_STOCK_VALUE) {
            throw new InvalidArgumentException("The {$key} field must be a non-negative integer.");
        }

        return $value;
    }
}
