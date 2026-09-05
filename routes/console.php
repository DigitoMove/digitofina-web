<?php

use App\Jobs\PostInterest;
use App\Jobs\RunBackupJob;
use App\Services\OperationsMonitor;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::call(fn () => app(OperationsMonitor::class)->heartbeat())->everyMinute()->name('operations:heartbeat');

Schedule::command('loans:apply-penalties')
    ->dailyAt('00:00')
    ->timezone(config('app.timezone'))
    ->withoutOverlapping(60)
    ->onOneServer()
    ->name('loans:apply-penalties:midnight');
Schedule::command('investments:mark-matured')->dailyAt('18:30')->withoutOverlapping();
Schedule::command('savings:mark-fixed-matured')->dailyAt('18:40')->withoutOverlapping();
Schedule::command('end-of-day:process')->dailyAt('19:00')->withoutOverlapping();
Schedule::job(new PostInterest)->dailyAt('23:00')->withoutOverlapping();
Schedule::job(new RunBackupJob)->dailyAt('02:00')->withoutOverlapping();
