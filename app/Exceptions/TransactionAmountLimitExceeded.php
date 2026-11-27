<?php

namespace App\Exceptions;

use Illuminate\Validation\ValidationException;

class TransactionAmountLimitExceeded extends ValidationException
{
    public function __construct(string $attribute, string $message)
    {
        parent::__construct(
            validator: validator([], []),
            response: null,
            errorBag: 'default',
        );

        $this->validator->errors()->add($attribute, $message);
    }

    public function problemCode(): string
    {
        return 'amount_limit_exceeded';
    }
}
