<?php

use App\Services\BusinessCalendarService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('moves immutable monthly schedule anchors by twenty six business days', function () {
    $startDate = CarbonImmutable::parse('2026-06-01');
    $dueDate = (new BusinessCalendarService)->dueDate($startDate, 1, 'monthly');

    expect($dueDate->toDateString())->toBe('2026-07-01')
        ->and($startDate->toDateString())->toBe('2026-06-01');
});
