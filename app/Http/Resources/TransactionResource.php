<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TransactionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'totalAmount' => (int) $this->total_amount,
            'transactionAt' => $this->transaction_at?->toISOString(),
            'createdAt' => $this->created_at?->toISOString(),
            'updatedAt' => $this->updated_at?->toISOString(),

            'items' => $this->items->map(fn ($item) => [
                'productId' => $item->product_id,
                'productName' => $item->product_name_snapshot,
                'unit' => $item->unit_snapshot,
                'quantity' => (int) $item->quantity,
                'unitPrice' => (int) $item->unit_price_snapshot,
                'subtotal' => (int) $item->subtotal,
            ]),
        ];
    }
}
