<?php

use Pinoox\Component\Date\GregorianDate;
use Pinoox\Component\Date\JalaliDate;
use Pinoox\Component\Test\AppTestKit;
use Pinoox\Portal\Date;

beforeEach(function () {
    AppTestKit::boot();
});

it('formats jalali dates with valid utf-8 output', function () {
    $date = Date::jalali('2024-01-15 12:00:00')->format('l d F Y');

    expect(mb_check_encoding($date, 'UTF-8'))->toBeTrue()
        ->and(json_encode(['date' => $date]))->not->toBeFalse();
});

it('exposes carbon-like helpers on jalali and gregorian instances', function () {
    $jalali = Date::jalali('2024-06-01');
    $gregorian = Date::gregorian('2024-06-01');

    expect($jalali)->toBeInstanceOf(JalaliDate::class)
        ->and($gregorian)->toBeInstanceOf(GregorianDate::class)
        ->and($jalali->calendar())->toBe('jalali')
        ->and($gregorian->calendar())->toBe('gregorian')
        ->and($jalali->timestamp())->toBeInt()
        ->and($jalali->addDays(1)->gt($jalali))->toBeTrue();
});

it('resolves active calendar through make and display helpers', function () {
    $jalali = Date::usingCalendar('jalali')->make('2024-01-01');
    $gregorian = Date::usingCalendar('gregorian')->make('2024-01-01');

    expect($jalali->formatKey('date'))->toMatch('/\d{4}\/\d{2}\/\d{2}/')
        ->and($gregorian->formatKey('date'))->toMatch('/\d{4}-\d{2}-\d{2}/')
        ->and(Date::usingCalendar('jalali')->display('2024-01-01', 'datetime'))->toBeString();
});

it('compares jalali dates through the manager', function () {
    expect(Date::compare('1402/10/11', '1402/10/12', '<', true, 'Y/m/d'))->toBeTrue()
        ->and(Date::compareBetween('1402/10/11', '1402/10/01', '1402/10/15', true, 'Y/m/d'))->toBeTrue();
});
