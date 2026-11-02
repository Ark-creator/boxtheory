// Hypothetical independent-alert replay. Does not reconstruct real account trades.
const fs = require('node:fs');
const path = require('node:path');
const { readM1, readSignals, wallString } = require('./mt5_m1.cjs');

const timezoneScenarios = [
  { id: 'server3_payloadUTC', brokerOffset: 3, legacyOffset: 0, note: 'CSV assumed UTC+3; older signal payload times assumed UTC. Gold payload remains explicit UTC.' },
  { id: 'server3_payloadPHT', brokerOffset: 3, legacyOffset: 8, note: 'CSV assumed UTC+3; older signal payload times assumed Philippine UTC+8. Gold remains explicit UTC.' },
  { id: 'csvUTC_payloadUTC', brokerOffset: 0, legacyOffset: 0, note: 'CSV and older payload assumed UTC; sensitivity only.' },
  { id: 'server2_payloadUTC', brokerOffset: 2, legacyOffset: 0, note: 'CSV assumed UTC+2; older payload assumed UTC; sensitivity only.' },
];
const costScenarios = [
  { id: 'minimum_spread_only', pointSize: 0.01, spreadMultiplier: 1, slippage: 0, fee: 0 },
  { id: 'assumed_slippage_and_fee', pointSize: 0.01, spreadMultiplier: 1, slippage: 0.1, fee: 0.07 },
  { id: 'double_assumed_costs', pointSize: 0.01, spreadMultiplier: 2, slippage: 0.2, fee: 0.14 },
  { id: 'screenshot_spread_floor_stress', pointSize: 0.01, spreadMultiplier: 1, spreadFloor: 0.37, slippage: 0, fee: 0 },
];

function replayOne(signal, bars, byMinute, settings, targetPolicy = 'tp1_full_exit') {
  const isGold = signal.register_id.startsWith('GRB-');
  const signalOffset = isGold ? 0 : settings.legacyOffset;
  const delay = isGold ? 0 : settings.legacyDelayMinutes;
  const mapped = signal.time + (settings.brokerOffset - signalOffset) * 3600 + delay * 60;
  const side = signal.side === 'BUY' ? 1 : -1;
  const target = targetPolicy === 'tp2_full_exit_when_available' && signal.tp2 !== null ? signal.tp2 : signal.tp1;
  const row = {
    register_id: signal.register_id, strategy: signal.strategy, pdf_file: signal.pdf_file, pdf_page: signal.pdf_page,
    signal_timestamp_printed: signal.signal_timestamp_printed, side: signal.side,
    mapped_broker_entry_time: wallString(mapped), target_policy: targetPolicy,
    target_used: target, quoted_entry: signal.entry, stop_loss: signal.stop_loss,
    price_scenario: settings.id, signal_offset_hours: signalOffset, broker_offset_hours: settings.brokerOffset,
    assumed_delay_minutes: delay, costs: settings.costId,
    outcome: null, path_outcome_assuming_gaps_closed: null, actual_outcome: 'unknown',
    entry_bid: null, entry_spread_proxy: null, simulated_entry: null, entry_deviation_in_quoted_r: null,
    simulated_entry_risk: null, executable_reward_risk: null, exit_time_bar_open: null,
    exit_reason: null, exit_price: null, net_r: null, ambiguous_stop_first_net_r: null,
    ambiguous_target_first_net_r: null, crossed_gap_count: 0, first_gap_next_open: null,
  };
  const fail = (outcome, reason) => ({ ...row, outcome, path_outcome_assuming_gaps_closed: outcome, exit_reason: reason });
  if (!Number.isFinite(target)) return fail('missing_target', 'No usable target in the register.');
  if (mapped < bars[0].time || mapped > bars.at(-1).time) return fail('outside_export', 'Mapped entry is outside supplied candle coverage.');
  const index = byMinute.get(mapped);
  if (index === undefined) return fail('no_entry_bar', 'No M1 candle at the mapped entry; do not move weekend/night alerts to the next session.');
  const first = bars[index];
  const spreadAt = bar => Math.max(settings.spreadFloor ?? 0, bar.spreadPoints * settings.pointSize * settings.spreadMultiplier);
  const spread = spreadAt(first);
  const entry = first.open + (side === 1 ? spread : 0) + side * settings.slippage;
  const risk = (entry - signal.stop_loss) * side;
  const reward = (target - entry) * side;
  const exitQuote = first.open + (side === -1 ? spread : 0);
  Object.assign(row, { entry_bid: first.open, entry_spread_proxy: spread, simulated_entry: entry,
    simulated_entry_risk: risk, executable_reward_risk: risk > 0 ? reward / risk : null,
    entry_deviation_in_quoted_r: (entry - signal.entry) * side / Math.abs(signal.entry - signal.stop_loss) });
  // An entry with SL/TP already on the wrong side of executable quotes is not a loss.
  if (risk <= 0 || reward <= 0 || (exitQuote - signal.stop_loss) * side <= 0 || (target - exitQuote) * side <= 0) {
    return fail('unexecutable_entry', 'Original absolute stop/target bracket is invalid at the assumed market-entry quote.');
  }
  const classify = value => value > 1e-10 ? 'win' : (value < -1e-10 ? 'loss' : 'breakeven');
  const netR = price => ((price - entry) * side - settings.fee) / risk;
  for (let i = index; i < bars.length; i++) {
    const bar = bars[i];
    if (i > index && bar.time - bars[i - 1].time !== 60) {
      row.crossed_gap_count++;
      row.first_gap_next_open ??= bar.timestamp;
    }
    const offset = side === -1 ? spreadAt(bar) : 0;
    const open = bar.open + offset, high = bar.high + offset, low = bar.low + offset;
    const stop = side === 1 ? low <= signal.stop_loss : high >= signal.stop_loss;
    const take = side === 1 ? high >= target : low <= target;
    if (!stop && !take) continue;
    const gapStop = (open - signal.stop_loss) * side <= 0;
    const gapTarget = (open - target) * side >= 0;
    const ambiguous = stop && take && !gapStop && !gapTarget;
    row.exit_time_bar_open = bar.timestamp;
    if (ambiguous) {
      row.exit_reason = 'both_SL_and_TP_inside_same_M1_bar';
      row.ambiguous_stop_first_net_r = netR(signal.stop_loss - side * settings.slippage);
      row.ambiguous_target_first_net_r = netR(target - side * settings.slippage);
      row.path_outcome_assuming_gaps_closed = 'ambiguous';
      row.outcome = row.crossed_gap_count ? 'unresolved_gap' : 'ambiguous';
      return row;
    }
    const exit = (gapStop ? open : (gapTarget ? target : (stop ? signal.stop_loss : target))) - side * settings.slippage;
    row.exit_reason = gapStop ? 'opening_quote_past_stop' : (gapTarget ? 'opening_quote_past_target' : (stop ? 'stop_loss' : 'take_profit'));
    row.exit_price = exit;
    row.net_r = netR(exit);
    row.path_outcome_assuming_gaps_closed = classify(row.net_r);
    row.outcome = row.crossed_gap_count ? 'unresolved_gap' : row.path_outcome_assuming_gaps_closed;
    return row;
  }
  row.outcome = row.crossed_gap_count ? 'unresolved_gap' : 'open_at_export_end';
  row.path_outcome_assuming_gaps_closed = 'open_at_export_end';
  row.exit_reason = 'Neither stop nor target resolved within supplied history.';
  return row;
}

function summary(rows, key = 'outcome') {
  const categories = ['win', 'loss', 'breakeven', 'ambiguous', 'unresolved_gap', 'open_at_export_end', 'outside_export', 'no_entry_bar', 'unexecutable_entry', 'missing_target'];
  const result = { alerts: rows.length, ...Object.fromEntries(categories.map(name => [name, rows.filter(row => row[key] === name).length])) };
  if (categories.reduce((n, name) => n + result[name], 0) !== rows.length) throw new Error('Outcome categories do not reconcile.');
  const resolved = rows.filter(row => ['win', 'loss', 'breakeven'].includes(row[key]));
  const positive = resolved.filter(row => row.net_r > 0).reduce((n, row) => n + row.net_r, 0);
  const negative = -resolved.filter(row => row.net_r < 0).reduce((n, row) => n + row.net_r, 0);
  return { ...result, resolved: resolved.length, win_rate_resolved_only: resolved.length ? result.win / resolved.length : null,
    resolved_independent_alert_net_r: resolved.reduce((n, row) => n + row.net_r, 0),
    resolved_profit_factor_r: negative > 0 ? positive / negative : null,
    alert_paths_crossing_gaps: rows.filter(row => row.crossed_gap_count > 0).length };
}

function runReplay(signals, bars, settings, targetPolicy) {
  const byMinute = new Map(bars.map((bar, i) => [bar.time, i]));
  const rows = signals.map(signal => replayOne(signal, bars, byMinute, settings, targetPolicy));
  return { rows, totals: summary(rows), strategies: [...new Set(signals.map(signal => signal.strategy))].map(strategy => {
    const group = rows.filter(row => row.strategy === strategy);
    return { strategy, strict_contiguous: summary(group), assuming_all_gaps_are_market_closures: summary(group, 'path_outcome_assuming_gaps_closed') };
  }) };
}

function csv(rows, fields = Object.keys(rows[0])) {
  const cell = value => '"' + String(value ?? '').replace(/"/g, '""') + '"';
  return fields.map(cell).join(',') + '\n' + rows.map(row => fields.map(field => cell(row[field])).join(',')).join('\n') + '\n';
}

module.exports = { replayOne, runReplay, summary, timezoneScenarios, costScenarios };

if (require.main === module) {
  const file = process.argv[2] || 'C:/Users/Romark/Downloads/XAUUSD-STD_M1_202609010100_202610302356.csv';
  const output = process.argv[3] || 'docs/research/signal-replay-2026-11-01';
  const { bars, metadata } = readM1(file);
  const signals = readSignals('docs/research/signal-audit-2026-11-01/signals.csv');
  const scenarios = [], flat = [];
  fs.mkdirSync(output, { recursive: true });
  for (const timezone of timezoneScenarios) for (const delay of [0, 5]) for (const costs of costScenarios) for (const policy of ['tp1_full_exit', 'tp2_full_exit_when_available']) {
    const settings = { ...timezone, ...costs, id: timezone.id, costId: costs.id, legacyDelayMinutes: delay };
    const result = runReplay(signals, bars, settings, policy);
    const id = `${timezone.id}_legacyLag${delay}_${costs.id}_${policy}`;
    scenarios.push({ id, timezone, legacy_delay_minutes: delay, costs, target_policy: policy, totals: result.totals, strategies: result.strategies });
    for (const group of result.strategies) flat.push({ scenario: id, strategy: group.strategy, ...group.strict_contiguous });
    if (['server3_payloadUTC', 'server3_payloadPHT'].includes(timezone.id) && delay === 0 && costs.id === 'minimum_spread_only') {
      fs.writeFileSync(path.join(output, `${timezone.id}_${policy}_rows.csv`), csv(result.rows));
    }
  }
  const report = {
    study: 'Conditional independent-alert executable-quote replay of the 387 supplied signals',
    actual_account_outcomes: 'Unknown. This price file contains no executed account trades.',
    price_data: metadata,
    assumptions: [
      'No timezone is verified. Gold Breakout retains its explicit UTC payload label in every scenario. Older signals are compared as UTC and Philippine UTC+8.',
      'Current legacy code labels the latest five-minute candle OPEN, and permits forming candles. Actual email send time is absent. Zero and five-minute delays are assumptions, not a recovered publication time.',
      'Every alert is independently entered as a hypothetical market order at the exact mapped M1 open, buy at ask/sell at bid. Original absolute SL and target are preserved; quoted entry is NOT assumed fillable.',
      'No exact M1 entry bar means no simulated entry. Alerts in weekend/night gaps are never carried to the next session. Invalid initial brackets are unexecutable entries, not losses.',
      'TP1 and TP2 scenarios are alternative full-position exits. TP2 uses the sole target if a second target is absent. No partial/BE/trailing/opposite-alert exit or expiry is inferred.',
      'Bid bars assumed for OTC gold. Spread points use unverified point size 0.01. M1 spread is the MINIMUM within the minute, used as a constant proxy; this can understate costs and distort ask extrema.',
      'No margin/volume/stop-distance restrictions, swaps or account sizing are modeled. Slippage and fees are declared sensitivities, not verified broker charges.',
      'The screenshot-spread stress uses a 0.37 USD/oz floor at every minute, based on the single supplied image. That image is not a historical spread series.',
      'Both-hit minutes remain ambiguous; both possible R outcomes are retained. Opening quote beyond a stop fills at that quote; targets get no favorable gap improvement.',
      'Strict counts censor paths crossing nonconsecutive M1 bars. Separate secondary counts assume all such gaps are scheduled closures; that assumption is unverified.',
      'Overlapping signals remain independent hypothetical alerts. Summed R and win rates are not a feasible portfolio/account backtest, and missing/invalid/ambiguous alerts are excluded from resolved-only ratios.',
    ],
    scenarios,
  };
  fs.writeFileSync(path.join(output, 'replay-summary.json'), JSON.stringify(report, null, 2) + '\n');
  fs.writeFileSync(path.join(output, 'scenario-comparison.csv'), csv(flat));
  console.log(JSON.stringify(scenarios.filter(row => row.timezone.id.startsWith('server3') && row.legacy_delay_minutes === 0 && row.costs.id === 'minimum_spread_only').map(row => ({ id: row.id, strategies: row.strategies })), null, 2));
}
