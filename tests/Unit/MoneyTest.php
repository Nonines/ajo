<?php

use App\Support\Money\BasisPoints;
use App\Support\Money\Money;
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

test('decimal naira converts to exact integer kobo', function (string $amount, int $expected) {
    expect(Money::fromNaira($amount)->kobo)->toBe($expected);
})->with([
    ['0', 0], ['0.01', 1], ['1000', 100000], ['1000.1', 100010], ['1000.12', 100012],
    ['90071992547409.93', 9007199254740993], ['92233720368547758.07', PHP_INT_MAX],
]);

test('decimal naira rejects noncanonical and fractional kobo inputs', function (string $amount) {
    expect(fn () => Money::fromNaira($amount))->toThrow(InvalidArgumentException::class);
})->with(['', '-1', '01', '1.', '.5', '1.001', '1e3', ' 1', "1\n"]);

test('decimal naira rejects integer kobo overflow', function () {
    expect(fn () => Money::fromNaira('92233720368547758.08'))->toThrow(IntegerOverflowException::class);
});

test('money displays exact naira across signed integer limits', function (int $kobo, string $expected) {
    expect((new Money($kobo))->toNaira())->toBe($expected);
})->with([
    [0, '0.00'], [1, '0.01'], [10, '0.10'], [100012, '1000.12'], [-1, '-0.01'], [-123, '-1.23'],
    [9007199254740993, '90071992547409.93'], [PHP_INT_MAX, '92233720368547758.07'], [PHP_INT_MIN, '-92233720368547758.08'],
]);

test('basis points round whole kobo ties to even', function (int $kobo, int $expected) {
    expect((new BasisPoints(5000))->apply(new Money($kobo))->kobo)->toBe($expected);
})->with([[5, 2], [7, 4], [-5, -2], [-7, -4]]);

test('basis points reject negative and inexact rate inputs', function (mixed $value) {
    expect(fn () => new BasisPoints($value))->toThrow(InvalidArgumentException::class);
})->with([-1, 1.5, '1000', true]);
