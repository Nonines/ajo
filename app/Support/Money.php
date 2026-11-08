<?php

namespace App\Support;

use Brick\Math\BigInteger;
use InvalidArgumentException;
use JsonSerializable;

/**
 * Stores signed integer kobo and serializes amounts as strings for lossless JSON transport.
 */
final readonly class Money implements JsonSerializable
{
    public int $kobo;

    /**
     * Accepts integer kobo or a canonical integer string, rejecting floats and overflow.
     */
    public function __construct(mixed $kobo)
    {
        if ((! is_int($kobo) && ! is_string($kobo)) || ! preg_match('/^-?(0|[1-9][0-9]*)$/D', (string) $kobo)) {
            throw new InvalidArgumentException('Money requires integer kobo, never floating point.');
        }
        $this->kobo = BigInteger::of($kobo)->toInt();
    }

    /**
     * Returns the opposite signed amount, failing if it cannot fit a PHP integer.
     */
    public function negate(): self
    {
        return new self((string) BigInteger::of($this->kobo)->negated());
    }

    public function jsonSerialize(): string
    {
        return (string) $this->kobo;
    }
}
