<?php

use App\Jobs\PostInterest;
use App\Jobs\RunBackupJob;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('loans:apply-penalties')->dailyAt('18:00')->withoutOverlapping();
Schedule::job(new PostInterest)->dailyAt('23:00')->withoutOverlapping();
Schedule::job(new RunBackupJob)->dailyAt('02:00')->withoutOverlapping();
