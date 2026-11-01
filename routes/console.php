<?php

use Illuminate\Foundation\Inspiring;
use App\Services\AutoTraderService;
use App\Services\SignalAlertService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('signals:email-admin {--force : Send alerts even if unchanged} {--to= : Send only to this admin email address}', function (SignalAlertService $service) {
    $result = $service->dispatch((bool) $this->option('force'), $this->option('to'));

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

    return $result['errors'] === [] ? 0 : 1;
})->purpose('Email admins when strategy signals change');

Artisan::command('autotrader:tick', function (AutoTraderService $service) {
    $result = $service->tick();

    $this->info(sprintf(
        'Auto trader tick: %s - %s',
        $result['status'],
        $result['message']
    ));
})->purpose('Evaluate the configured gold auto trader and send eligible orders to the selected execution mode');

Schedule::command('signals:email-admin')
    ->everyMinute()
    ->withoutOverlapping();

Schedule::command('autotrader:tick')
    ->everyMinute()
    ->withoutOverlapping();
