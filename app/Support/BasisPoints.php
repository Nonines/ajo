<?php

namespace App\Support;

use Brick\Math\BigRational;
use Brick\Math\RoundingMode;
use InvalidArgumentException;

final readonly class BasisPoints
{
    public int $value;

    public function __construct(mixed $value)
    {
        if (! is_int($value) || $value < 0) {
            throw new InvalidArgumentException('Basis points must be nonnegative.');
        }
        $this->value = $value;
    }

    public function apply(Money $amount): Money
    {
        $rounded = BigRational::of($amount->kobo)->multipliedBy($this->value)->dividedBy(10000)->toScale(0, RoundingMode::HalfEven);

        return new Money((string) $rounded);
    }
}
