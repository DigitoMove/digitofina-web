<?php

use App\Jobs\PostInterest;
use App\Jobs\RunBackupJob;
use App\Services\OperationsMonitor;
use App\Services\SavingsInterestService;
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
Schedule::command('loan-savings-deductions:process')
    ->everyMinute()
    ->timezone(config('app.timezone'))
    ->withoutOverlapping(10)
    ->onOneServer()
    ->name('loan-savings-deductions:process');
Schedule::command('investments:mark-matured')->dailyAt('18:30')->withoutOverlapping();
Schedule::command('savings:mark-fixed-matured')->dailyAt('18:40')->withoutOverlapping();
Schedule::command('end-of-day:process')->dailyAt('19:00')->withoutOverlapping();
// These two operations run inline from the scheduler. cPanel commonly runs
// schedule:run without a queue worker, so queue-only scheduled jobs would
// appear in the schedule but never execute.
Schedule::call(function (): void {
    app(PostInterest::class)->handle(app(SavingsInterestService::class));
})
    ->dailyAt('23:00')->name('savings:post-interest')->withoutOverlapping();
Schedule::call(function (): void {
    app(RunBackupJob::class)->handle();
})
    ->dailyAt('02:00')->name('backups:run')->withoutOverlapping();
