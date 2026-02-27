<!doctype html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Signal Alert</title>
</head>
<body style="margin:0; padding:0; background:#f2f4f8; font-family:Arial, Helvetica, sans-serif; color:#17212b;">
    @php
        $action = strtoupper((string) ($signal['action'] ?? 'NO_DATA'));
        $actionBg = match($action) {
            'BUY' => '#0f9d58',
            'SELL' => '#c62828',
            'CLOSE' => '#ef6c00',
            'HOLD' => '#1565c0',
            default => '#5f6368',
        };
        $plan = $signal['trade_plan'] ?? [];
        $indicators = $signal['indicators'] ?? [];
    @endphp

    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f2f4f8; padding:24px 12px;">
        <tr>
            <td align="center">
                <table role="presentation" width="680" cellpadding="0" cellspacing="0" style="width:100%; max-width:680px; background:#ffffff; border-radius:14px; overflow:hidden; box-shadow:0 10px 30px rgba(0,0,0,0.08);">
                    <tr>
                        <td style="padding:24px; background:linear-gradient(135deg, #0b132b 0%, #1c2541 65%, #3a506b 100%); color:#ffffff;">
                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0">
                                <tr>
                                    <td style="font-size:12px; letter-spacing:1px; text-transform:uppercase; opacity:0.85;">GoldLogic Signal Desk</td>
                                    <td align="right">
                                        <span style="display:inline-block; padding:6px 12px; border-radius:999px; font-size:12px; font-weight:700; color:#ffffff; background:{{ $actionBg }};">
                                            {{ $action }}
                                        </span>
                                    </td>
                                </tr>
                                <tr>
                                    <td colspan="2" style="padding-top:12px;">
                                        <div style="font-size:26px; font-weight:800; line-height:1.2;">{{ $strategy->name }}</div>
                                        <div style="margin-top:6px; font-size:13px; opacity:0.9;">{{ $strategy->slug }} | Generated {{ $generatedAt->format('Y-m-d H:i:s') }}</div>
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>

                    <tr>
                        <td style="padding:20px 24px 0 24px;">
                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border-collapse:separate; border-spacing:0 10px;">
                                <tr>
                                    <td style="width:33.33%; background:#f7f9fc; border:1px solid #e4e9f2; border-radius:10px; padding:12px;">
                                        <div style="font-size:11px; color:#5b6778; text-transform:uppercase; letter-spacing:0.5px;">Price</div>
                                        <div style="margin-top:4px; font-size:20px; font-weight:800;">${{ number_format((float) ($signal['price'] ?? 0), 4) }}</div>
                                    </td>
                                    <td style="width:33.33%; background:#f7f9fc; border:1px solid #e4e9f2; border-radius:10px; padding:12px;">
                                        <div style="font-size:11px; color:#5b6778; text-transform:uppercase; letter-spacing:0.5px;">Timestamp</div>
                                        <div style="margin-top:4px; font-size:14px; font-weight:700;">{{ $signal['timestamp'] ?? 'N/A' }}</div>
                                    </td>
                                    <td style="width:33.33%; background:#f7f9fc; border:1px solid #e4e9f2; border-radius:10px; padding:12px;">
                                        <div style="font-size:11px; color:#5b6778; text-transform:uppercase; letter-spacing:0.5px;">Trend / Source</div>
                                        <div style="margin-top:4px; font-size:14px; font-weight:700;">
                                            {{ strtoupper((string) ($signal['trend'] ?? 'unknown')) }}
                                        </div>
                                        <div style="margin-top:2px; font-size:12px; color:#657285;">
                                            {{ $indicators['data_source'] ?? 'n/a' }}
                                        </div>
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>

                    <tr>
                        <td style="padding:14px 24px 4px 24px;">
                            <div style="font-size:13px; color:#364255; line-height:1.6;">
                                <strong>Signal Summary:</strong>
                                {{ $signal['message'] ?? 'No message available.' }}
                            </div>
                        </td>
                    </tr>

                    <tr>
                        <td style="padding:10px 24px 0 24px;">
                            <div style="font-size:13px; font-weight:800; color:#162033; text-transform:uppercase; letter-spacing:0.6px;">Trade Plan</div>
                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin-top:10px; border:1px solid #e4e9f2; border-radius:10px; overflow:hidden;">
                                <tr style="background:#f7f9fc;">
                                    <th align="left" style="padding:10px 12px; font-size:11px; color:#5b6778; text-transform:uppercase;">Entry</th>
                                    <th align="left" style="padding:10px 12px; font-size:11px; color:#5b6778; text-transform:uppercase;">Stop Loss</th>
                                    <th align="left" style="padding:10px 12px; font-size:11px; color:#5b6778; text-transform:uppercase;">Take Profit 1</th>
                                    <th align="left" style="padding:10px 12px; font-size:11px; color:#5b6778; text-transform:uppercase;">Take Profit 2</th>
                                </tr>
                                <tr>
                                    <td style="padding:11px 12px; border-top:1px solid #e4e9f2; font-weight:700;">
                                        {{ isset($plan['entry_price']) && $plan['entry_price'] !== null ? '$'.number_format((float)$plan['entry_price'], 4) : 'N/A' }}
                                    </td>
                                    <td style="padding:11px 12px; border-top:1px solid #e4e9f2; font-weight:700;">
                                        {{ isset($plan['stop_loss']) && $plan['stop_loss'] !== null ? '$'.number_format((float)$plan['stop_loss'], 4) : 'N/A' }}
                                    </td>
                                    <td style="padding:11px 12px; border-top:1px solid #e4e9f2; font-weight:700;">
                                        {{ isset($plan['take_profit_1']) && $plan['take_profit_1'] !== null ? '$'.number_format((float)$plan['take_profit_1'], 4) : 'N/A' }}
                                    </td>
                                    <td style="padding:11px 12px; border-top:1px solid #e4e9f2; font-weight:700;">
                                        {{ isset($plan['take_profit_2']) && $plan['take_profit_2'] !== null ? '$'.number_format((float)$plan['take_profit_2'], 4) : 'N/A' }}
                                    </td>
                                </tr>
                            </table>
                            @if(!empty($plan['notes']))
                                <div style="font-size:12px; color:#5b6778; margin-top:8px;">{{ $plan['notes'] }}</div>
                            @endif
                        </td>
                    </tr>

                    <tr>
                        <td style="padding:16px 24px 24px 24px;">
                            <div style="font-size:13px; font-weight:800; color:#162033; text-transform:uppercase; letter-spacing:0.6px; margin-bottom:8px;">Key Indicators</div>
                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border:1px solid #e4e9f2; border-radius:10px; overflow:hidden;">
                                @forelse($indicators as $label => $value)
                                    <tr>
                                        <td style="padding:9px 12px; border-top:1px solid #eef2f7; background:#fafcff; width:50%; font-size:12px; color:#5b6778; text-transform:uppercase;">
                                            {{ str_replace('_', ' ', $label) }}
                                        </td>
                                        <td style="padding:9px 12px; border-top:1px solid #eef2f7; font-size:13px; font-weight:700; color:#223047;">
                                            @if(is_bool($value))
                                                {{ $value ? 'Yes' : 'No' }}
                                            @else
                                                {{ $value === null || $value === '' ? 'N/A' : $value }}
                                            @endif
                                        </td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="2" style="padding:12px; color:#5b6778; font-size:13px;">No indicators provided.</td>
                                    </tr>
                                @endforelse
                            </table>
                        </td>
                    </tr>

                    <tr>
                        <td style="padding:14px 24px; background:#f7f9fc; color:#5f6f82; font-size:11px; line-height:1.6;">
                            This alert is auto-generated when a strategy signal snapshot changes. Always validate risk and execution conditions before trading.
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>

