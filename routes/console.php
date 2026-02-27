<?php

use Illuminate\Foundation\Inspiring;
use App\Services\SignalAlertService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('signals:email-admin {--force : Send alerts even if unchanged}', function (SignalAlertService $service) {
    $result = $service->dispatch((bool) $this->option('force'));

    $this->info(sprintf(
        'Signal email run complete. strategies=%d sent=%d skipped=%d recipients=%d',
        $result['strategies'],
        $result['sent'],
        $result['skipped'],
        $result['recipients']
    ));

    foreach ($result['errors'] as $error) {
        $this->error($error);
    }
})->purpose('Email admins when strategy signals change');

Schedule::command('signals:email-admin')
    ->everyMinute()
    ->withoutOverlapping();
