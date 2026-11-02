#!/usr/bin/env node
'use strict';

// Independent JS reconstruction. Production PHP evaluators and their signal
// maps are not used to discover entries or decide candidate eligibility.
const fs = require('node:fs');
const path = require('node:path');
const crypto = require('node:crypto');
const assert = require('node:assert/strict');
const { readM1 } = require('./mt5_m1.cjs');
const repo = path.resolve(__dirname, '../..');
const options = {};
for (let i = 2; i < process.argv.length; i++) {
  const name = process.argv[i];
  if (name === '--require-references') options.requireReferences = true;
  else if (['--source', '--reference-dir', '--output'].includes(name)) options[name.slice(2)] = process.argv[++i];
  else throw new Error('Unknown argument: ' + name);
}
const sourceFile = options.source || 'C:/Users/Romark/Downloads/XAUUSD-STD_M1_202609010100_202610302356.csv';
const protocolFile = path.join(repo, 'docs/research/scalper-upgrade-2026-11-02/PRE_REGISTERED.md');
const hash = bytes => crypto.createHash('sha256').update(bytes).digest('hex');
const protocolHash = hash(fs.readFileSync(protocolFile));
assert.equal(protocolHash, '9cc3d59c2ceb000eb8a06dd1eab4ff0a96e5c4a34078253bbb3a298d3f32aa49',
  'Protocol changed after independent candidate definitions were frozen; review the change before rerunning.');
const input = readM1(sourceFile);
const outputFile = options.output || path.join(repo, 'storage/app/private/signal-research/scalper-candidates-independent-2026-11-02.json');
const referenceDirs = options['reference-dir'] ? [options['reference-dir']] : [
  path.join(repo, 'docs/research/scalper-upgrade-2026-11-02'),
  path.join(repo, 'storage/app/private/signal-research/scalper-upgrade-2026-11-02'),
];
const iso = seconds => new Date(seconds * 1000).toISOString().replace('.000Z', '+00:00');
const round = (value, places) => Math.round(value * 10 ** places) / 10 ** places;
function average(values) { let sum = 0; for (const value of values) sum += value; return sum / values.length; }
const ny = new Intl.DateTimeFormat('en-US', { timeZone: 'America/New_York', weekday: 'short', hour: '2-digit', minute: '2-digit', hourCycle: 'h23' });
const marketCache = new Map();
function marketOpen(time) {
  const key = Math.floor(time / 60);
  if (marketCache.has(key)) return marketCache.get(key);
  const p = Object.fromEntries(ny.formatToParts(new Date(time * 1000)).map(item => [item.type, item.value]));
  const day = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'].indexOf(p.weekday) + 1;
  const minute = Number(p.hour) * 60 + Number(p.minute);
  const open = !(day === 6 || (day === 7 && minute < 1080) || (day === 5 && minute >= 1020)
    || (day >= 1 && day <= 4 && minute >= 1020 && minute < 1080));
  marketCache.set(key, open);
  return open;
}
function researchSession(time) {
  const date = new Date(time * 1000);
  return date.getUTCDay() >= 1 && date.getUTCDay() <= 5 && date.getUTCHours() >= 7 && date.getUTCHours() < 17;
}
function aggregate(minutes) {
  const buckets = new Map();
  for (const minute of minutes) {
    const at = Math.floor(minute.time / 300) * 300;
    if (!buckets.has(at)) buckets.set(at, []);
    buckets.get(at).push(minute);
  }
  const bars = [];
  let incomplete = 0, closed = 0;
  for (const [time, rows] of buckets) {
    if (rows.length !== 5 || rows.some((bar, i) => bar.time !== time + i * 60)) { incomplete++; continue; }
    if (!marketOpen(time) || !marketOpen(time + 299)) { closed++; continue; }
    bars.push({ time, open: rows[0].open, high: Math.max(...rows.map(b => b.high)),
      low: Math.min(...rows.map(b => b.low)), close: rows[4].close });
  }
  return { bars, incomplete, closed };
}
function discover(bars) {
  const entries = new Map();
  for (let i = 199; i < bars.length; i++) {
    const closes = bars.slice(i - 199, i + 1).map(b => b.close);
    const short = average(closes.slice(-9)), previousShort = average(closes.slice(-10, -1));
    const long = average(closes.slice(-21)), previousLong = average(closes.slice(-22, -1));
    let gains = 0, losses = 0;
    for (let j = closes.length - 14; j < closes.length; j++) {
      const change = closes[j] - closes[j - 1];
      if (change >= 0) gains += change; else losses -= change;
    }
    const rsi = losses === 0 ? 100 : 100 - 100 / (1 + (gains / 14) / (losses / 14));
    const up = previousShort <= previousLong && short > long;
    const down = previousShort >= previousLong && short < long;
    const side = up && rsi > 50 && rsi < 70 ? 1 : down && rsi < 50 && rsi > 30 ? -1 : 0;
    if (!side) continue;
    const ranges = [];
    for (let j = i - 13; j <= i; j++) ranges.push(Math.max(bars[j].high - bars[j].low,
      Math.abs(bars[j].high - bars[j - 1].close), Math.abs(bars[j].low - bars[j - 1].close)));
    let atr = average(ranges);
    const last = bars[i];
    if (atr <= 0) atr = Math.max(0.5, last.high - last.low);
    const swing = bars.slice(i - 11, i + 1);
    const proposedStop = side === 1 ? Math.min(last.close - atr * 1.2, Math.min(...swing.map(b => b.low)) - atr * 0.2)
      : Math.max(last.close + atr * 1.2, Math.max(...swing.map(b => b.high)) + atr * 0.2);
    const risk = Math.max((last.close - proposedStop) * side, atr * 0.8);
    const slow = round(average(closes), 8);
    const priorSlow = i >= 202 ? round(average(bars.slice(i - 202, i - 2).map(b => b.close)), 8) : null;
    const trend = priorSlow !== null && (side === 1 ? last.close > slow && slow > priorSlow : last.close < slow && slow < priorSlow);
    const at = last.time + 300;
    entries.set(at, { time: at, side, original_entry: round(last.close, 4), sl: round(last.close - side * risk, 4),
      tp1: round(last.close + side * risk * 1.5, 4), tp2: round(last.close + side * risk * 2.5, 4),
      source_bar_open: last.time, history_bars: i + 1, rsi, atr, slow, prior_slow: priorSlow, trend_pass: trend, session_pass: researchSession(at) });
  }
  return entries;
}
function metrics(trades) {
  let total = 0, peak = 0, drawdown = 0, gain = 0, loss = 0, consecutive = 0, longest = 0;
  for (const trade of trades) {
    const r = trade.net_r;
    total += r; peak = Math.max(peak, total); drawdown = Math.max(drawdown, peak - total);
    if (r > 0) gain += r; else loss -= r;
    consecutive = r < 0 ? consecutive + 1 : 0; longest = Math.max(longest, consecutive);
  }
  const wins = trades.filter(t => t.net_r > 0).length, losses = trades.filter(t => t.net_r < 0).length;
  return { closed_trades: trades.length, wins, losses, breakeven: trades.length - wins - losses,
    win_rate: trades.length ? wins / trades.length : null, net_r: total, expectancy_r: trades.length ? total / trades.length : null,
    profit_factor_r: loss ? gain / loss : null, max_closed_trade_drawdown_r: drawdown, max_losing_streak: longest };
}
function isoWeek(time) {
  const d = new Date(time * 1000);
  d.setUTCHours(0, 0, 0, 0);
  d.setUTCDate(d.getUTCDate() + 4 - (d.getUTCDay() || 7));
  const year = d.getUTCFullYear();
  const week = Math.ceil(((d - new Date(Date.UTC(year, 0, 1))) / 86400000 + 1) / 7);
  return year + '-W' + String(week).padStart(2, '0');
}
function grouped(trades, key) {
  const groups = new Map();
  for (const t of trades) {
    const label = key(t);
    if (!groups.has(label)) groups.set(label, []);
    groups.get(label).push(t);
  }
  return Object.fromEntries([...groups].map(([label, list]) => [label, { ...metrics(list),
    long_trades: list.filter(trade => trade.side === 1).length,
    short_trades: list.filter(trade => trade.side === -1).length,
    gap_crossing_trades: list.filter(trade => trade.crossed_nonconsecutive_minutes).length }]));
}
function replay(minutes, candidateEntries, target, costs, from, to) {
  const entries = new Map([...candidateEntries].filter(([time]) => time >= from && time < to));
  const processed = new Set();
  const rejected = { existing_position: 0, market_closed: 0, quote_drift: 0, quote_bracket: 0, fill_bracket: 0, no_immediate_next_open: 0 };
  let previous = null, position = null, gaps = 0, ambiguous = 0;
  const trades = [];
  for (const minute of minutes) {
    const time = minute.time;
    const gap = previous !== null && time - previous > 60;
    previous = time;
    if (time < from || time >= to) continue;
    if (gap) gaps++;
    if (gap && position) position.crossed_nonconsecutive_minutes = true;
    const spread = minute.spreadPoints * 0.01 * costs.spread;
    const signal = entries.get(time);
    if (signal) {
      processed.add(time);
      if (position) rejected.existing_position++;
      else if (!marketOpen(time)) rejected.market_closed++;
      else {
        const quote = minute.open + (signal.side === 1 ? spread : 0);
        const printedRisk = (signal.original_entry - signal.sl) * signal.side;
        if (Math.abs(quote - signal.original_entry) > 0.1 * printedRisk) rejected.quote_drift++;
        else if ((quote - signal.sl) * signal.side <= 0 || (signal.tp1 - quote) * signal.side <= 0) rejected.quote_bracket++;
        else {
          const entry = quote + signal.side * costs.slippage;
          const risk = (entry - signal.sl) * signal.side;
          const tp = signal[target];
          if (risk <= 0 || (tp - entry) * signal.side <= 0) rejected.fill_bracket++;
          else position = { side: signal.side, opened_at: iso(time), entry, sl: signal.sl, tp, risk,
            crossed_nonconsecutive_minutes: false };
        }
      }
    }
    if (!position) continue;
    const p = position;
    const offset = p.side === -1 ? spread : 0;
    const open = minute.open + offset, high = minute.high + offset, low = minute.low + offset;
    const stop = p.side === 1 ? low <= p.sl : high >= p.sl;
    const take = p.side === 1 ? high >= p.tp : low <= p.tp;
    if (!stop && !take) continue;
    const gapStop = (open - p.sl) * p.side <= 0, gapTarget = (open - p.tp) * p.side >= 0;
    const both = stop && take && !gapStop && !gapTarget;
    if (both) ambiguous++;
    const exit = (gapStop ? open : gapTarget ? p.tp : stop ? p.sl : p.tp) - p.side * costs.slippage;
    const net = ((exit - p.entry) * p.side - costs.fee) / p.risk;
    trades.push({ ...p, exit_minute_open: iso(time), exit_time: time, exit, net_r: net,
      exit_reason: gapStop ? 'gap_stop' : gapTarget ? 'gap_target' : both ? 'both_hit_stop_first' : stop ? 'stop_loss' : 'take_profit' });
    position = null;
  }
  rejected.no_immediate_next_open = entries.size - processed.size;
  const weekly = grouped(trades, t => isoWeek(t.exit_time));
  const bestWeek = Math.max(0, ...Object.values(weekly).map(week => week.net_r));
  const bestTrade = Math.max(0, ...trades.map(trade => trade.net_r));
  const totals = metrics(trades);
  const holds = trades.map(trade => (trade.exit_time - Date.parse(trade.opened_at) / 1000) / 60).sort((a, b) => a - b);
  const middle = Math.floor(holds.length / 2);
  return { signals: entries.size, entry_rejections: rejected, metrics: totals,
    contiguous_path_metrics: metrics(trades.filter(t => !t.crossed_nonconsecutive_minutes)),
    by_side: grouped(trades, t => t.side === 1 ? 'BUY' : 'SELL'),
    by_exit_month: grouped(trades, t => iso(t.exit_time).slice(0, 7)), by_exit_iso_week: weekly,
    descriptive_concentration: { best_winning_trade_r: bestTrade, net_r_excluding_best_winning_trade: totals.net_r - bestTrade,
      best_exit_week_net_r: bestWeek, net_r_excluding_best_exit_week: totals.net_r - bestWeek,
      note: 'Influence summaries only; not separately selected entry filters or new backtests.' },
    holding_minutes: { median: holds.length ? (holds.length % 2 ? holds[middle] : (holds[middle - 1] + holds[middle]) / 2) : null,
      mean: holds.length ? average(holds) : null, maximum: holds.length ? holds.at(-1) : null,
      above_60_minutes: holds.filter(value => value > 60).length, above_240_minutes: holds.filter(value => value > 240).length },
    trades_crossing_nonconsecutive_minutes: trades.filter(t => t.crossed_nonconsecutive_minutes).length,
    nonconsecutive_intervals_in_evaluation: gaps, ambiguous_minutes_stop_first: ambiguous,
    open_position_at_end: position, trades };
}
function compare(result, reference) {
  const differences = [];
  const check = (key, a, b) => {
    if (typeof a === 'number' && typeof b === 'number') {
      if (Math.abs(a - b) > 1e-8) differences.push({ key, independent: a, reference: b });
    } else if (a !== b) differences.push({ key, independent: a, reference: b });
  };
  check('signals', result.signals, reference.signals);
  check('trades.length', result.trades.length, reference.trades.length);
  for (const key of ['closed_trades', 'wins', 'losses', 'breakeven', 'net_r', 'win_rate', 'expectancy_r', 'profit_factor_r', 'max_closed_trade_drawdown_r']) {
    check('metrics.' + key, result.metrics[key], reference.metrics[key]);
    check('contiguous_path_metrics.' + key, result.contiguous_path_metrics[key], reference.contiguous_path_metrics[key]);
  }
  for (const key of Object.keys(result.entry_rejections)) check('entry_rejections.' + key, result.entry_rejections[key], reference.entry_rejections[key]);
  for (const key of ['trades_crossing_nonconsecutive_minutes', 'nonconsecutive_intervals_in_evaluation', 'ambiguous_minutes_stop_first']) check(key, result[key], reference[key]);
  for (const [own, other] of [
    [result.by_exit_month, reference.monthly_exit_metrics],
    [result.by_exit_iso_week, reference.iso_week_exit_metrics],
    [{ long: result.by_side.BUY, short: result.by_side.SELL }, reference.side_metrics],
  ]) {
    for (const label of new Set([...Object.keys(own), ...Object.keys(other || {})])) {
      const empty = { ...metrics([]), long_trades: 0, short_trades: 0, gap_crossing_trades: 0 };
      for (const key of ['closed_trades', 'wins', 'losses', 'net_r', 'expectancy_r', 'profit_factor_r', 'long_trades', 'short_trades', 'gap_crossing_trades']) {
        check('group.' + label + '.' + key, (own[label] || empty)[key], (other?.[label] || empty)[key]);
      }
    }
  }
  for (const key of ['median', 'mean', 'maximum', 'above_60_minutes', 'above_240_minutes']) {
    check('holding_minutes.' + key, result.holding_minutes[key], reference.holding_minutes?.[key]);
  }
  check('open_position', result.open_position_at_end === null, reference.open_position_at_end === null);
  if (result.open_position_at_end && reference.open_position_at_end) {
    for (const key of ['side', 'opened_at', 'entry', 'sl', 'tp', 'risk']) check('open_position.' + key, result.open_position_at_end[key], reference.open_position_at_end[key]);
  }
  for (let i = 0; i < Math.min(result.trades.length, reference.trades.length); i++) {
    for (const key of ['side', 'opened_at', 'entry', 'sl', 'tp', 'risk', 'exit_minute_open', 'exit_reason', 'exit', 'net_r', 'crossed_nonconsecutive_minutes']) {
      check('trades.' + i + '.' + key, result.trades[i][key], reference.trades[i][key]);
    }
  }
  return differences;
}
const candidates = ['baseline', 'trend', 'session', 'trend-session'];
const costModels = { gross: { spread: 0, slippage: 0, fee: 0 },
  'minimum-spread-only': { spread: 1, slippage: 0, fee: 0 },
  'assumed-costs': { spread: 1, slippage: 0.1, fee: 0.07 },
  'double-assumed-costs': { spread: 2, slippage: 0.2, fee: 0.14 } };
const cases = [], clocks = [], filterDecisionVerifications = [];
for (const offset of [3, 0]) {
  const minutes = input.bars.map(bar => ({ ...bar, time: bar.time - offset * 3600 }));
  const aggregateM5 = aggregate(minutes);
  const entries = discover(aggregateM5.bars);
  const decisionFile = referenceDirs.map(dir => path.join(dir, `filter-decisions-utcplus${offset}.json`)).find(file => fs.existsSync(file));
  if (decisionFile) {
    const packet = JSON.parse(fs.readFileSync(decisionFile, 'utf8'));
    const rows = new Map(packet.rows.map(row => [Date.parse(row.decision_at) / 1000, row]));
    const differences = [];
    if (rows.size !== entries.size) differences.push({ key: 'count', independent: entries.size, reference: rows.size });
    for (const [time, entry] of entries) {
      const row = rows.get(time);
      if (!row) { differences.push({ at: iso(time), key: 'missing_decision' }); continue; }
      const values = { signal_open: iso(entry.source_bar_open), action: entry.side === 1 ? 'BUY' : 'SELL',
        m5_close: entry.original_entry, sma200: entry.slow, sma200_three_active_bars_earlier: entry.prior_slow,
        trend_pass: entry.trend_pass, session_pass: entry.session_pass, history_bars: entry.history_bars };
      for (const [key, value] of Object.entries(values)) {
        if (typeof value === 'number' && typeof row[key] === 'number' ? Math.abs(value - row[key]) > 1e-8 : value !== row[key]) {
          differences.push({ at: iso(time), key, independent: value, reference: row[key] });
        }
      }
    }
    filterDecisionVerifications.push({ offset_hours_assumed: offset, file: decisionFile,
      sha256: hash(fs.readFileSync(decisionFile)), decisions_compared: entries.size, differences });
  }
  const first = minutes[0].time, end = minutes.at(-1).time + 60;
  const split = Math.ceil((first + 0.7 * (end - first)) / 900) * 900;
  const candidateSignals = Object.fromEntries(candidates.map(candidate => [candidate, new Map([...entries].filter(([, signal]) =>
    (candidate === 'baseline') || (candidate === 'trend' && signal.trend_pass)
      || (candidate === 'session' && signal.session_pass) || (candidate === 'trend-session' && signal.trend_pass && signal.session_pass)))]));
  clocks.push({ offset_hours_assumed: offset, complete_m5: aggregateM5.bars.length, incomplete_m5: aggregateM5.incomplete,
    closed_session_m5_removed: aggregateM5.closed, split_utc: iso(split),
    candidate_entry_counts: Object.fromEntries(candidates.map(c => [c, candidateSignals[c].size])) });
  for (const candidate of candidates) for (const segment of [
    { name: 'full', from: first, to: end }, { name: 'first70', from: first, to: split }, { name: 'last30', from: split, to: end },
  ]) for (const target of ['tp1', 'tp2']) for (const [cost, costs] of Object.entries(costModels)) {
    const result = replay(minutes, candidateSignals[candidate], target, costs, segment.from, segment.to);
    const name = `${candidate}-utcplus${offset}-${segment.name}-${target}-${cost}.json`;
    const referenceFile = referenceDirs.map(dir => path.join(dir, name)).find(file => fs.existsSync(file));
    const verification = referenceFile ? { status: 'compared', file: referenceFile, sha256: hash(fs.readFileSync(referenceFile)),
      differences: compare(result, JSON.parse(fs.readFileSync(referenceFile, 'utf8'))) } : { status: 'pending', expected: name };
    const { trades, ...summary } = result;
    cases.push({ candidate, offset_hours_assumed: offset, segment: segment.name, target, cost,
      primary: offset === 3 && target === 'tp1' && cost === 'assumed-costs', ...summary, verification });
  }
}
const primary = cases.filter(row => row.primary);
const mismatches = cases.filter(row => row.verification.differences?.length);
const output = {
  method: 'Independent JavaScript RSI/SMA/ATR discovery, causal filter eligibility and M1 bid/ask-proxy replay. Shared strict TSV parsing only.',
  source_sha256: input.metadata.sha256, source_file: input.metadata.file, script_sha256: hash(fs.readFileSync(__filename)),
  protocol_file: path.relative(repo, protocolFile), protocol_sha256: protocolHash,
  point_size_and_clock_status: '0.01 broker point and UTC+3 primary clock remain assumptions, not independently verified metadata.',
  preferred_hypothesis_prespecified: 'trend with unchanged full TP1; other candidates are comparison diagnostics, not return-ranked selection',
  new_unseen_data_found_in_downloads: false,
  inference_limits: [
    'The entire September-October file and first70/last30 periods were inspected before this filter study. These are development diagnostics, not untouched out-of-sample evidence.',
    'Earlier baseline/exit/cost/clock variants and prior V1/V2 investigations are part of the research history. The four new candidates are not the only trials ever inspected.',
    'Cost and clock sensitivities overlap strongly; they are not independent experiments. No defensible effective-trial count, selection-adjusted probability or deflated Sharpe ratio is inferred.',
    'Monthly/ISO-week summaries assign realized R to the exit minute. They do not mark open positions to market or measure weekly account-equity returns.',
    'Holding minutes use the containing exit minute opening time; the true hit can occur later within that minute. These durations are minute-band proxies, not exact seconds.',
    'Trades crossing gaps remain conditional on unseen execution. Contiguous-path metrics are a subset of the same replay and are not a newly simulated equity curve.',
    'Minimum M1 spread is a lower-bound proxy. Historical swaps, actual fills, account-currency sizing, news and intraminute ask paths are absent.',
    'Positive filtered returns would justify further independent data and paper forward testing, not proof that the production revision is profitable.',
  ],
  clocks, filter_decision_verifications: filterDecisionVerifications,
  verification_summary: { cases: cases.length, references_compared: cases.filter(row => row.verification.status === 'compared').length,
    primary_cases_expected: primary.length, primary_cases_compared: primary.filter(row => row.verification.status === 'compared').length,
    cases_with_differences: mismatches.length, filter_decisions_compared: filterDecisionVerifications.reduce((n, v) => n + v.decisions_compared, 0),
    filter_decision_differences: filterDecisionVerifications.reduce((n, v) => n + v.differences.length, 0) }, cases,
};
fs.mkdirSync(path.dirname(outputFile), { recursive: true });
fs.writeFileSync(outputFile, JSON.stringify(output, null, 2) + '\n');
console.log(JSON.stringify({ output: outputFile, ...output.verification_summary, clocks,
  primary: primary.map(row => ({ candidate: row.candidate, segment: row.segment, signals: row.signals, ...row.metrics,
    contiguous_net_r: row.contiguous_path_metrics.net_r, gap_crossing_trades: row.trades_crossing_nonconsecutive_minutes,
    open_at_end: row.open_position_at_end !== null, reference: row.verification.status, differences: row.verification.differences?.length ?? null })) }, null, 2));
if (mismatches.length || filterDecisionVerifications.some(row => row.differences.length)
  || (options.requireReferences && primary.some(row => row.verification.status !== 'compared'))) process.exitCode = 1;
