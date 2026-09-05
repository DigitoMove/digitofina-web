<?php

use Illuminate\Console\Scheduling\Schedule;

it('runs loan penalties once every midnight with concurrency protection', function () {
    $events = collect(app(Schedule::class)->events())
        ->filter(fn ($event): bool => str_contains($event->command ?? '', 'loans:apply-penalties'));

    expect($events)->toHaveCount(1);

    $event = $events->first();

    expect($event->getExpression())->toBe('0 0 * * *')
        ->and($event->timezone)->toBe(config('app.timezone'))
        ->and($event->withoutOverlapping)->toBeTrue()
        ->and($event->expiresAt)->toBe(60)
        ->and($event->onOneServer)->toBeTrue()
        ->and($event->description)->toBe('loans:apply-penalties:midnight');
});
