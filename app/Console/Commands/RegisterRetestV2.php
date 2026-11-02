<?php

namespace App\Console\Commands;

use App\Models\Strategy;
use Illuminate\Console\Command;

class RegisterRetestV2 extends Command
{
    protected $signature = 'signals:register-retest-v2 {--paper-alerts : Register explicitly opted-in research email alerts}';

    protected $description = 'Register the V2 research strategy only after paper-alert opt-in; sends no email or order itself';

    public function handle(): int
    {
        if (! $this->option('paper-alerts') || ! filter_var(config('services.signal_alerts.retest_v2_enabled', false), FILTER_VALIDATE_BOOLEAN)) {
            $this->error('Research alerts are disabled. For paper evaluation, set RETEST_V2_EMAILS_ENABLED=true, clear config, and use --paper-alerts.');
            $this->line('Current decisions remain available through signals:current without registering alerts.');

            return self::FAILURE;
        }
        $strategy = Strategy::firstOrCreate(['slug' => 'gold-trend-retest-v2'], [
            'name' => 'Gold Trend Retest V2 (Research)',
            'description' => 'Local four-bar retests with fixed indicator history and verified current quotes. Research/paper alerts only; profitability is not established.',
            'price' => 0,
        ]);
        $this->info('Registered '.$strategy->name.'. The existing email scheduler can now evaluate this opted-in research strategy.');
        $this->line('No email or order was sent. Disable with RETEST_V2_EMAILS_ENABLED=false and clear config.');

        return self::SUCCESS;
    }
}
