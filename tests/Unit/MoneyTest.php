<?php

use App\Support\BasisPoints;
use App\Support\Money;
use Brick\Math\Exception\IntegerOverflowException;

test('money transports signed integer limits as exact decimal strings', function (int|string $input, string $expected) {
    expect(json_encode(new Money($input)))->toBe('"'.$expected.'"');
})->with([[0, '0'], ['9007199254740993', '9007199254740993'], [PHP_INT_MAX, '9223372036854775807'], [PHP_INT_MIN, '-9223372036854775808']]);

test('money rejects inexact or malformed inputs', function (mixed $input) {
    expect(fn () => new Money($input))->toThrow(InvalidArgumentException::class);
})->with([1.5, 1.0, '1.00', '1e3', '01', '', true, null]);

test('money rejects values and negation outside integer storage bounds', function () {
    expect(fn () => new Money('9223372036854775808'))->toThrow(IntegerOverflowException::class);
    expect(fn () => (new Money(PHP_INT_MIN))->negate())->toThrow(IntegerOverflowException::class);
});

test('basis points round whole kobo ties to even', function (int $kobo, int $expected) {
    expect((new BasisPoints(5000))->apply(new Money($kobo))->kobo)->toBe($expected);
})->with([[5, 2], [7, 4], [-5, -2], [-7, -4]]);

test('basis points reject negative and inexact rate inputs', function (mixed $value) {
    expect(fn () => new BasisPoints($value))->toThrow(InvalidArgumentException::class);
})->with([-1, 1.5, '1000', true]);
