<?php

namespace App\Mail;

use App\Models\Strategy;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;

class StrategySignalAlertMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly Strategy $strategy,
        public readonly array $signal,
        public readonly Carbon $generatedAt
    ) {
    }

    public function envelope(): Envelope
    {
        $action = strtoupper((string) ($this->signal['action'] ?? 'SIGNAL'));
        $symbol = (string) ($this->signal['symbol'] ?? $this->signal['symbol_code'] ?? config('trading.default_pair', 'XAUUSD'));
        $fromAddress = (string) config('mail.from.address', 'hello@example.com');
        $fromName = (string) config('mail.from.name', config('app.name', 'GoldLogic'));

        if (filter_var($fromAddress, FILTER_VALIDATE_EMAIL) === false) {
            $fromAddress = 'hello@example.com';
        }

        return new Envelope(
            from: new Address($fromAddress, $fromName),
            subject: sprintf('[%s] %s %s | %s', config('app.name', 'GoldLogic'), $action, $symbol, $this->strategy->name)
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.signals.alert',
            with: [
                'strategy' => $this->strategy,
                'signal' => $this->signal,
                'generatedAt' => $this->generatedAt,
            ]
        );
    }
}
