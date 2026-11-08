<?php

namespace App\Support\Money;

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

    /**
     * Parses nonnegative decimal naira exactly; callers retain their own amount limits.
     */
    public static function fromNaira(string $amount): self
    {
        if (! preg_match('/^(0|[1-9][0-9]*)(\.[0-9]{1,2})?$/D', $amount)) {
            throw new InvalidArgumentException('Use decimal naira with at most two fractional digits.');
        }

        [$naira, $fraction] = array_pad(explode('.', $amount, 2), 2, '');

        return new self((string) BigInteger::of($naira)->multipliedBy(100)->plus(str_pad($fraction, 2, '0')));
    }

    /**
     * Formats two decimal places without floats or negating the minimum signed integer.
     */
    public function toNaira(): string
    {
        $digits = str_pad(ltrim((string) $this->kobo, '-'), 3, '0', STR_PAD_LEFT);

        return ($this->kobo < 0 ? '-' : '').substr($digits, 0, -2).'.'.substr($digits, -2);
    }

    /**
     * Preserves integer precision when amounts cross a JSON boundary.
     */
    public function jsonSerialize(): string
    {
        return (string) $this->kobo;
    }
}
