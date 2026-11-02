#!/usr/bin/env node
'use strict';

// Frozen additional-history check. No data acquisition, orders, mail, paid API
// calls, production evaluator imports, or parameter/date selection occurs here.
const fs = require('node:fs');
const path = require('node:path');
const crypto = require('node:crypto');
const assert = require('node:assert/strict');
const vm = require('node:vm');
const repo = path.resolve(__dirname, '../..');
const PERIOD_START = Date.parse('2026-06-01T00:00:00Z') / 1000;
const PERIOD_END = Date.parse('2026-09-01T00:00:00Z') / 1000;
const ASSUMED_SPREAD_USD = 0.37;
const MODELS = ['baseline', 'trend', 'trend-session'];
const COSTS = {
  gross: { spread_multiplier: 0, slippage_each_fill: 0, round_trip_fee: 0 },
  'spread-only': { spread_multiplier: 1, slippage_each_fill: 0, round_trip_fee: 0 },
  'assumed-costs': { spread_multiplier: 1, slippage_each_fill: 0.1, round_trip_fee: 0.07 },
  'doubled-costs': { spread_multiplier: 2, slippage_each_fill: 0.2, round_trip_fee: 0.14 },
};
const sha256 = bytes => crypto.createHash('sha256').update(bytes).digest('hex');

function loadFrozenMath() {
  // Reuse only pure math/session helpers from the independently verified oracle;
  // requiring its CLI would rerun a different study and write unrelated files.
  const file = path.join(__dirname, 'verify_scalper_candidates.cjs');
  const bytes = fs.readFileSync(file);
  const source = bytes.toString('utf8').replace(/\r\n/g, '\n');
  const start = source.indexOf('const iso = seconds =>');
  const end = source.indexOf('function replay(');
  assert(start >= 0 && end > start, 'Frozen independent math boundaries are missing.');
  const core = source.slice(start, end);
  const coreHash = sha256(core);
  assert.equal(coreHash, '74105055096b8ef497ec6bf9f06444741936184b4fae48f80ec6dcbefe8f93b2',
    'Verified independent math changed; do not silently revise a frozen external check.');
  const sandbox = { module: { exports: {} }, Intl, Date, Math, Map };
  vm.runInNewContext(core + '\nmodule.exports = { iso, aggregate, discover, metrics, grouped, isoWeek, marketOpen, average, round };', sandbox,
    { filename: 'frozen-independent-scalper-math.cjs', timeout: 1000 });
  return { ...sandbox.module.exports, core_sha256: coreHash, source_file_sha256: sha256(bytes) };
}
const math = loadFrozenMath();
const { iso, aggregate, discover, metrics, grouped, isoWeek, marketOpen } = math;

function timestamp(value) {
  if (typeof value !== 'string' || !/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:00(?:Z|\+00:00)$/.test(value)) {
    throw new Error('Every M1 timestamp must be an explicit UTC minute open, ISO Z or +00:00.');
  }
  const time = Date.parse(value) / 1000;
  if (!Number.isFinite(time) || iso(time) !== value.replace(/Z$/, '+00:00')) throw new Error('Invalid calendar timestamp: ' + value);
  return time;
}
function ohlc(bar, prefix = '') {
  const result = {};
  for (const key of ['open', 'high', 'low', 'close']) {
    const value = bar[prefix + key];
    if (typeof value !== 'number' || !Number.isFinite(value) || value <= 0) throw new Error('OHLC prices must be positive finite JSON numbers.');
    result[key] = value;
  }
  if (result.high < Math.max(result.open, result.low, result.close) || result.low > Math.min(result.open, result.close)) {
    throw new Error('Inconsistent OHLC bounds.');
  }
  return result;
}
function normalize(data) {
  if (!data || !['twelvedata_history', 'dukascopy_history'].includes(data.source) || data.symbol !== 'XAUUSD') {
    throw new Error('Expected normalized twelvedata_history or dukascopy_history XAUUSD data; never label third-party data MT5.');
  }
  if (typeof data.timestamp_assumption !== 'string' || !/\bUTC\b/.test(data.timestamp_assumption)
    || !data.provenance || typeof data.provenance !== 'object' || Array.isArray(data.provenance)
    || !Object.keys(data.provenance).length || !Array.isArray(data.m1) || !data.m1.length) {
    throw new Error('Source UTC semantics, nonempty provenance and nonempty M1 history are required.');
  }
  const minutes = [];
  let previous = null, askCount = 0, spreadCount = 0;
  for (const row of data.m1) {
    if (!row || typeof row !== 'object' || Array.isArray(row)) throw new Error('Invalid M1 row.');
    const time = timestamp(row.timestamp);
    if (previous !== null && time <= previous) throw new Error('M1 timestamps must be unique and strictly ascending; no sorting or deduplication is invented.');
    previous = time;
    const bid = ohlc(row);
    const hasAsk = ['ask_open', 'ask_high', 'ask_low', 'ask_close'].some(key => key in row) || 'ask' in row;
    let ask = null;
    if (hasAsk) {
      ask = row.ask && typeof row.ask === 'object' && !Array.isArray(row.ask) ? ohlc(row.ask) : ohlc(row, 'ask_');
      for (const key of ['open', 'high', 'low', 'close']) if (ask[key] < bid[key]) throw new Error('ASK OHLC cannot be below the corresponding BID field.');
      askCount++;
    }
    let spread = null;
    if ('spread_usd' in row) {
      if (typeof row.spread_usd !== 'number' || !Number.isFinite(row.spread_usd) || row.spread_usd < 0) throw new Error('spread_usd must be finite and nonnegative.');
      spread = row.spread_usd; spreadCount++;
    }
    minutes.push({ time, ...bid, ask, spread_usd: spread });
  }
  if (askCount !== 0 && askCount !== minutes.length) throw new Error('Partial ASK history is not replaced with assumed prices; supply a complete matched series.');
  if (!askCount && spreadCount !== 0 && spreadCount !== minutes.length) throw new Error('Per-minute spreads must cover every row or be omitted consistently.');
  const declaredConstant = !askCount && data.provenance.spread_is_observed_history === false
    && data.provenance.spread_assumption_usd === ASSUMED_SPREAD_USD;
  if (declaredConstant && minutes.some(minute => minute.spread_usd !== null && minute.spread_usd !== ASSUMED_SPREAD_USD)) {
    throw new Error('Per-minute spreads contradict the declared constant $0.37 assumption.');
  }
  return { minutes, quote_mode: askCount ? 'native_bid_ask_ohlc' : declaredConstant || !spreadCount
    ? 'constant_0.37_usd_assumed_spread' : 'supplied_per_minute_spread_proxy' };
}
function sanitize(value, key = '') {
  if (/api[_-]?key|token|secret|password|authorization|credential/i.test(key)) return '[redacted]';
  if (Array.isArray(value)) return value.map(item => sanitize(item));
  if (value && typeof value === 'object') return Object.fromEntries(Object.entries(value).map(([name, item]) => [name, sanitize(item, name)]));
  if (typeof value === 'string') return value.replace(/([?&](?:apikey|api_key|token|key|secret)=)[^&#\s]*/ig, '$1[redacted]');
  return value;
}
function askPrices(minute, multiplier) {
  const result = {};
  for (const key of ['open', 'high', 'low', 'close']) {
    const spread = minute.ask ? minute.ask[key] - minute[key] : (minute.spread_usd ?? ASSUMED_SPREAD_USD);
    result[key] = minute[key] + multiplier * spread;
  }
  // Doubling field-wise ask-minus-bid is only a stress proxy. If it cannot even
  // preserve OHLC ordering, fail that case instead of fabricating a repaired path.
  if (result.high < Math.max(result.open, result.low, result.close) || result.low > Math.min(result.open, result.close)) {
    throw new Error('Field-wise spread stress produced inconsistent ASK OHLC; this stress case is not evaluable.');
  }
  return result;
}
function replay(minutes, entries, cost, from, to) {
  const eligible = new Map([...entries].filter(([time]) => time >= from && time < to));
  const processed = new Set();
  const rejected = { existing_position: 0, market_closed: 0, quote_drift: 0, quote_bracket: 0, fill_bracket: 0, no_immediate_next_open: 0 };
  let previous = null, position = null, gaps = 0, ambiguous = 0;
  const trades = [];
  for (const minute of minutes) {
    const time = minute.time, gap = previous !== null && time - previous > 60;
    previous = time;
    if (time < from || time >= to) continue;
    if (gap) gaps++;
    if (gap && position) position.crossed_nonconsecutive_minutes = true;
    const ask = askPrices(minute, cost.spread_multiplier);
    const signal = eligible.get(time);
    if (signal) {
      processed.add(time);
      if (position) rejected.existing_position++;
      else if (!marketOpen(time)) rejected.market_closed++;
      else {
        const quote = signal.side === 1 ? ask.open : minute.open;
        const printedRisk = (signal.original_entry - signal.sl) * signal.side;
        if (Math.abs(quote - signal.original_entry) > 0.1 * printedRisk) rejected.quote_drift++;
        else if ((quote - signal.sl) * signal.side <= 0 || (signal.tp1 - quote) * signal.side <= 0) rejected.quote_bracket++;
        else {
          const entry = quote + signal.side * cost.slippage_each_fill;
          const risk = (entry - signal.sl) * signal.side;
          if (risk <= 0 || (signal.tp1 - entry) * signal.side <= 0) rejected.fill_bracket++;
          else position = { side: signal.side, opened_at: iso(time), decision_at: iso(signal.time),
            signal_timestamp_open: iso(signal.source_bar_open), signal_entry_price: signal.original_entry,
            entry_quote_before_slippage: quote, entry, sl: signal.sl, tp: signal.tp1, risk,
            quote_drift_r: Math.abs(quote - signal.original_entry) / printedRisk, crossed_nonconsecutive_minutes: false };
        }
      }
    }
    if (!position) continue;
    const p = position;
    const exitBar = p.side === 1 ? minute : ask;
    const stop = p.side === 1 ? exitBar.low <= p.sl : exitBar.high >= p.sl;
    const target = p.side === 1 ? exitBar.high >= p.tp : exitBar.low <= p.tp;
    if (!stop && !target) continue;
    const gapStop = (exitBar.open - p.sl) * p.side <= 0, gapTarget = (exitBar.open - p.tp) * p.side >= 0;
    const both = stop && target && !gapStop && !gapTarget;
    if (both) ambiguous++;
    const exit = (gapStop ? exitBar.open : gapTarget ? p.tp : stop ? p.sl : p.tp) - p.side * cost.slippage_each_fill;
    const netR = ((exit - p.entry) * p.side - cost.round_trip_fee) / p.risk;
    trades.push({ ...p, exit_minute_open: iso(time), exit_minute_close: iso(time + 60), exit_time: time, exit,
      exit_reason: gapStop ? 'gap_stop' : gapTarget ? 'gap_target' : both ? 'both_hit_stop_first' : stop ? 'stop_loss' : 'take_profit',
      both_hit_minute: both, net_r: netR, outcome: netR > 0 ? 'win' : netR < 0 ? 'loss' : 'breakeven' });
    position = null;
  }
  rejected.no_immediate_next_open = eligible.size - processed.size;
  return { signals: eligible.size, entry_rejections: rejected, metrics: metrics(trades),
    contiguous_path_metrics: metrics(trades.filter(trade => !trade.crossed_nonconsecutive_minutes)),
    monthly_exit_metrics: grouped(trades, trade => iso(trade.exit_time).slice(0, 7)),
    iso_week_exit_metrics: grouped(trades, trade => isoWeek(trade.exit_time)),
    side_metrics: grouped(trades, trade => trade.side === 1 ? 'BUY' : 'SELL'),
    nonconsecutive_intervals_in_evaluation: gaps, ambiguous_minutes_stop_first: ambiguous,
    trades_crossing_nonconsecutive_minutes: trades.filter(trade => trade.crossed_nonconsecutive_minutes).length,
    open_position_at_end: position, trades };
}
function coverage(minutes, m5) {
  const inside = minutes.filter(minute => minute.time >= PERIOD_START && minute.time < PERIOD_END);
  if (!inside.length) throw new Error('No observations fall in the frozen June-August period; this is not a zero-trade validation result.');
  const byTime = new Set(inside.map(minute => minute.time));
  const months = {};
  for (const month of ['2026-06', '2026-07', '2026-08']) months[month] = { rows: 0, expected_regular_session_minutes: 0, missing_regular_session_minutes: 0 };
  for (const minute of inside) months[iso(minute.time).slice(0, 7)].rows++;
  for (let time = PERIOD_START; time < PERIOD_END; time += 60) if (marketOpen(time)) {
    const month = months[iso(time).slice(0, 7)];
    month.expected_regular_session_minutes++;
    if (!byTime.has(time)) month.missing_regular_session_minutes++;
  }
  const absentMonths = Object.keys(months).filter(month => months[month].rows === 0);
  const warmup = m5.filter(bar => bar.time + 300 <= PERIOD_START).length;
  return { monthly: months, absent_months: absentMonths, observed_m1_in_frozen_period: inside.length,
    first_observation_utc: iso(inside[0].time), last_minute_open_utc: iso(inside.at(-1).time),
    warmup_completed_m5_before_period: warmup, full_trend_warmup_available_before_period: warmup >= 203,
    first_200_bar_decision_utc: m5.length >= 200 ? iso(m5[199].time + 300) : null,
    note: 'Expected regular NY sessions are a template, not a verified historical holiday/broker calendar. Missing intervals can be scheduled closures or missing data.' };
}
function canonicalPlans(original, bars, file, inputHash) {
  const bytes = fs.readFileSync(file);
  const packet = JSON.parse(bytes.toString('utf8'));
  if (packet.input_sha256 !== inputHash || packet.independent_all_entry_discovery_verified !== true || !Array.isArray(packet.snapshots)) {
    throw new Error('Canonical PHP plans must verify independent entry discovery and bind to this exact normalized dataset SHA256.');
  }
  const inside = new Map([...original].filter(([time]) => time >= PERIOD_START && time < PERIOD_END));
  if (packet.snapshots.length !== inside.size) throw new Error('Canonical and independent baseline crossover counts differ.');
  const canonical = new Map(original);
  const byOpen = new Map(bars.map(bar => [bar.time, bar]));
  const seen = new Set(), roundingDifferences = [];
  for (const row of packet.snapshots) {
    const time = timestamp(row.decision_at);
    const own = inside.get(time);
    if (!own || seen.has(time)) throw new Error('Canonical PHP entry times differ from independently discovered entries.');
    seen.add(time);
    const sourceClose = byOpen.get(own.source_bar_open).close;
    if (row.action !== (own.side === 1 ? 'BUY' : 'SELL') || row.signal_open !== iso(own.source_bar_open)
      || row.trend_pass !== own.trend_pass || row.session_pass !== own.session_pass
      || row.trend_session_pass !== (own.trend_pass && own.session_pass) || row.history_bars !== own.history_bars) {
      throw new Error('Canonical PHP direction, source time, history, or filter eligibility differs from independent discovery.');
    }
    for (const [key, expected] of Object.entries({ source_close_full_precision: sourceClose, rsi_unrounded: own.rsi,
      atr_unrounded: own.atr, sma200: own.slow, sma200_three_active_bars_earlier: own.prior_slow })) {
      if (typeof row[key] !== 'number' || !Number.isFinite(row[key]) || Math.abs(row[key] - expected) > 1e-8) {
        throw new Error('Canonical PHP raw indicator/source value differs: ' + key);
      }
    }
    for (const [key, expected] of Object.entries({ entry_price: own.original_entry, stop_loss: own.sl,
      take_profit_1: own.tp1, take_profit_2: own.tp2 })) {
      if (typeof row[key] !== 'number' || !Number.isFinite(row[key]) || row[key] <= 0 || Math.abs(row[key] - expected) > 0.00010001) {
        throw new Error('Canonical plan differs beyond one four-decimal rounding unit: ' + key);
      }
      if (Math.abs(row[key] - expected) > 1e-8) roundingDifferences.push({ decision_at: row.decision_at, field: key,
        independent_js_value: expected, canonical_php_value: row[key] });
    }
    if ((row.entry_price - row.stop_loss) * own.side <= 0 || (row.take_profit_1 - row.entry_price) * own.side <= 0) {
      throw new Error('Canonical PHP trade bracket is invalid.');
    }
    canonical.set(time, { ...own, original_entry: row.entry_price, sl: row.stop_loss, tp1: row.take_profit_1,
      tp2: row.take_profit_2, trend_pass: row.trend_pass, session_pass: row.session_pass });
  }
  return { entries: canonical, reference: { method: 'Actual PHP-rounded legacy plans and actual V2 eligibility, independently checked against frozen JS entry discovery and raw indicators.',
    file: path.basename(file), sha256: sha256(bytes), input_sha256: inputHash, baseline_entries_checked: inside.size,
    entry_time_direction_raw_indicator_or_filter_differences: 0, four_decimal_plan_rounding_differences: roundingDifferences,
    production_source_hashes: packet.source_and_inputs_verified_unchanged_during_run || null,
    note: 'Numerical compatibility correction only. No entry rules, filters, dates, targets, costs or source prices changed.' } };
}
function runExternal(file, output, canonicalFile = null) {
  const planFile = path.join(repo, 'docs/research/scalper-upgrade-2026-11-02/EXTERNAL_VALIDATION_PLAN.md');
  const planBytes = fs.readFileSync(planFile);
  const planHash = sha256(planBytes.toString('utf8').replace(/\r\n/g, '\n'));
  assert.equal(planHash, '8f55fe3553559df4990ceda05578a9ccf360ae852f2b7f88712badf8d877ec8b', 'Frozen external protocol changed; review before further evaluation.');
  const bytes = fs.readFileSync(file);
  const data = JSON.parse(bytes.toString('utf8').replace(/^\uFEFF/, ''));
  const normalized = normalize(data);
  const beforeEnd = normalized.minutes.filter(minute => minute.time < PERIOD_END);
  // Historical spot APIs can publish indicative prices in closed sessions.
  // Those records cannot execute protective exits any more than they can enter.
  const activeMinutes = beforeEnd.filter(minute => marketOpen(minute.time) && marketOpen(minute.time + 59));
  const aggregateM5 = aggregate(activeMinutes);
  if (aggregateM5.bars.length < 203) throw new Error('Fewer than 203 complete active M5 bars; the frozen baseline/trend models cannot be evaluated.');
  const quality = coverage(activeMinutes, aggregateM5.bars);
  quality.raw_provider_m1_in_period = beforeEnd.filter(minute => minute.time >= PERIOD_START).length;
  quality.off_session_m1_removed_including_warmup = beforeEnd.length - activeMinutes.length;
  quality.off_session_m1_removed_in_period = quality.raw_provider_m1_in_period - quality.observed_m1_in_frozen_period;
  quality.holiday_status = 'No source-specific historical holiday or early-close status is verified. Regular NY filtering cannot prove that every retained indicative quote was executable.';
  const missingRegularMinutes = Object.values(quality.monthly).reduce((sum, month) => sum + month.missing_regular_session_minutes, 0);
  let original = discover(aggregateM5.bars);
  let canonicalReference = null;
  if (canonicalFile) {
    const corrected = canonicalPlans(original, aggregateM5.bars, canonicalFile, sha256(bytes));
    original = corrected.entries;
    canonicalReference = corrected.reference;
  }
  const cases = [];
  if (!quality.absent_months.length) for (const model of MODELS) {
    const entries = new Map([...original].filter(([, signal]) => model === 'baseline' || signal.trend_pass && (model !== 'trend-session' || signal.session_pass)));
    for (const [costName, cost] of Object.entries(COSTS)) {
      try {
        cases.push({ model, cost: costName, status: 'conditional_diagnostic', cost_parameters: cost,
          ...replay(activeMinutes, entries, cost, PERIOD_START, PERIOD_END) });
      } catch (error) {
        cases.push({ model, cost: costName, status: 'not_evaluable', error: error.message });
      }
    }
  }
  const result = {
    status: quality.absent_months.length ? 'incomplete_history_no_validation_metrics' : cases.some(row => row.status === 'not_evaluable') ? 'partial_case_failure'
      : missingRegularMinutes ? 'conditional_diagnostics_with_missing_session_minutes' : 'conditional_diagnostics_completed',
    method: 'Frozen additional-history baseline/trend/trend-session, full TP1, independently verified RSI/SMA/ATR and causal M1 replay.',
    fixed_period: { from_inclusive_utc: iso(PERIOD_START), to_exclusive_utc: iso(PERIOD_END) },
    source: data.source, symbol: data.symbol, timestamp_assumption: data.timestamp_assumption,
    input_file: path.basename(file), input_sha256: sha256(bytes), input_rows: normalized.minutes.length,
    input_provenance: sanitize(data.provenance), input_provenance_sha256: sha256(JSON.stringify(data.provenance)),
    normalized_quote_mode: normalized.quote_mode,
    bid_ohlc_interpretation: data.provenance.price_convention || data.provenance.price_basis || data.provenance.price_side
      || 'Provider OHLC treated as bid proxy; actual executable bid convention is not independently established.',
    frozen_plan_normalized_sha256: planHash, independent_math_core_sha256: math.core_sha256,
    independent_math_source_sha256: math.source_file_sha256, runner_sha256: sha256(fs.readFileSync(__filename)),
    canonical_php_plan_reference: canonicalReference,
    coverage: quality, aggregate_m5: { complete_active_session: aggregateM5.bars.length, incomplete_buckets_omitted: aggregateM5.incomplete,
      closed_session_buckets_omitted: aggregateM5.closed },
    post_period_input_rows_ignored: normalized.minutes.filter(minute => minute.time >= PERIOD_END).length,
    costing: {
      ordinary: normalized.quote_mode === 'native_bid_ask_ohlc' ? 'Native ASK open for buys and native ASK OHLC for short exits; no invented constant spread.'
        : normalized.quote_mode === 'supplied_per_minute_spread_proxy' ? 'Explicit supplied per-minute USD spread proxy added to provider/bid OHLC.'
          : 'Explicit assumed constant $0.37/oz spread added to provider OHLC; not a historical spread measurement.',
      ordinary_slippage_each_fill_usd_per_ounce: 0.1, ordinary_round_trip_fee_usd_per_ounce: 0.07,
      doubled: 'Double the supplied/assumed spread and slippage/fee assumptions. For native ASK data, bid + 2*(ask-bid) is only a field-wise stress proxy; independent extrema do not reconstruct true doubled-spread ticks.',
      gross: 'Zero spread/slippage/fees is a counterfactual bid-price diagnostic, not an executable trade result.',
    },
    limitations: [
      'This previously uninspected history precedes development chronologically. It is external historical confirmation, not forward testing or a chronological post-training holdout.',
      'Provider prices can differ from the user broker. These results are never labeled MT5 account history or realized account profits.',
      'Input spread proxies, the $0.37 constant and slippage/fees are assumptions unless explicit native bid/ask candles establish the relevant fields.',
      'Complete M5 buckets omit partial minutes. Native provider candles may not equal these aggregates; missing periods and insufficient warmup are disclosed.',
      'Closed-session M1 records are removed from BOTH indicators and execution. Sunday or maintenance indicative quotes cannot trigger stops/targets; holiday/early-close status remains unverified.',
      'One position, no same-minute reentry after an existing position closes, exact next-minute entries and live 0.1R pre-fill bracket/drift checks apply.',
      'Both-hit minutes resolve stop first. Gap stops use adverse opening quotes and target gaps receive no favorable improvement; exact hit seconds remain unknown.',
      'Unobserved intervals may conceal executions. Gap-crossing trades are conditional; contiguous-path metrics are a subset, not a separately simulated account.',
      'Open positions are censored. Closed-trade drawdown excludes floating loss. No swaps, news gating, lot rounding, margin, funding or account-currency sizing are modeled.',
      'No candidate parameters, dates, targets or preferred direction are retuned from these results. Conflicting or small samples cannot certify a profitable edge.',
      canonicalReference ? 'Rounded absolute plans use the separately audited actual PHP evaluator. Independent entry times, directions, raw RSI/ATR and trend/session decisions agree; documented four-decimal ties are not strategy changes.'
        : 'Absolute plan prices use JavaScript rounding. Exact PHP compatibility on new higher-precision feeds requires the separate production-parity check and --canonical-plans reference.',
    ], cases,
  };
  // Independent production-parity input, not an extra candidate or retuning.
  const byOpen = new Map(aggregateM5.bars.map((bar, index) => [bar.time, index]));
  const snapshots = [...original.values()].filter(signal => signal.time >= PERIOD_START && signal.time < PERIOD_END).map(signal => {
    const index = byOpen.get(signal.source_bar_open);
    const closes = aggregateM5.bars.slice(index - 199, index + 1).map(bar => bar.close);
    return { decision_at: iso(signal.time), signal_open: iso(signal.source_bar_open), action: signal.side === 1 ? 'BUY' : 'SELL',
      source_close_full_precision: aggregateM5.bars[index].close, entry_price: signal.original_entry,
      stop_loss: signal.sl, take_profit_1: signal.tp1, take_profit_2: signal.tp2,
      rsi_unrounded: signal.rsi, atr_unrounded: signal.atr,
      indicators: { rsi: math.round(signal.rsi, 2), atr: math.round(signal.atr, 4),
        short_ma: math.round(math.average(closes.slice(-9)), 4), long_ma: math.round(math.average(closes.slice(-21)), 4) },
      sma200: signal.slow, sma200_three_active_bars_earlier: signal.prior_slow,
      trend_pass: signal.trend_pass, session_pass: signal.session_pass, trend_session_pass: signal.trend_pass && signal.session_pass,
      history_bars: signal.history_bars };
  });
  const snapshotFile = path.join(path.dirname(output), 'external-entry-snapshots.json');
  const snapshotPacket = { source: data.source, input_sha256: result.input_sha256, independent_math_core_sha256: math.core_sha256,
    fixed_period: result.fixed_period, candidates: MODELS, baseline_candidates: snapshots.length,
    note: canonicalReference ? 'Full-precision source closes and independent raw indicators/filter decisions are retained; rounded absolute plans use the separately audited actual PHP snapshot reference. No H1 filter is part of these models.'
      : 'Full-precision source closes are retained. All plan/indicator values are independent JS outputs for a separate PHP production parity check; no H1 filter is part of these models.', snapshots };
  result.entry_snapshot_file = path.basename(snapshotFile);
  result.entry_snapshot_sha256 = sha256(JSON.stringify(snapshotPacket, null, 2) + '\n');
  if (canonicalReference) {
    if (sha256(fs.readFileSync(canonicalFile)) !== canonicalReference.sha256) throw new Error('Canonical PHP reference changed during replay; rerun against a stable file.');
    const v1File = path.join(path.dirname(output), 'external-validation-results-js-rounding-v1.json');
    if (fs.existsSync(v1File)) {
      const v1Bytes = fs.readFileSync(v1File), prior = JSON.parse(v1Bytes.toString('utf8'));
      if (prior.input_sha256 !== result.input_sha256 || prior.frozen_plan_normalized_sha256 !== planHash) throw new Error('Archived JS-v1 results are not from this same frozen dataset and protocol.');
      const differences = cases.map(row => {
        const old = prior.cases.find(other => other.model === row.model && other.cost === row.cost);
        if (!old) throw new Error('Archived JS-v1 case is missing.');
        return { model: row.model, cost: row.cost, closed_trade_count_change: row.metrics.closed_trades - old.metrics.closed_trades,
          win_count_change: row.metrics.wins - old.metrics.wins, loss_count_change: row.metrics.losses - old.metrics.losses,
          net_r_change: row.metrics.net_r - old.metrics.net_r,
          entry_or_exit_schedule_changed: row.trades.length !== old.trades.length || row.trades.some((trade, index) =>
            trade.opened_at !== old.trades[index].opened_at || trade.exit_minute_open !== old.trades[index].exit_minute_open
              || trade.outcome !== old.trades[index].outcome) };
      });
      result.numerical_replay_comparison = { archived_js_v1_file: path.basename(v1File), archived_js_v1_sha256: sha256(v1Bytes),
        cases: differences, note: 'Only PHP-compatible four-decimal plan values changed; all model parameters and the independent M1 replay are unchanged.' };
    }
  }
  fs.mkdirSync(path.dirname(output), { recursive: true });
  fs.writeFileSync(snapshotFile, JSON.stringify(snapshotPacket, null, 2) + '\n');
  fs.writeFileSync(output, JSON.stringify(result, null, 2) + '\n');
  console.log(JSON.stringify({ output, status: result.status, source: result.source, quote_mode: result.normalized_quote_mode,
    coverage: quality, cases: cases.map(({ model, cost, status, metrics: summary, error }) => ({ model, cost, status, metrics: summary, error })) }, null, 2));
  if (quality.absent_months.length || cases.some(row => row.status === 'not_evaluable')) process.exitCode = 1;
}
function selfCheck(file) {
  const { readM1 } = require('./mt5_m1.cjs');
  const parsed = readM1(file);
  const raw = parsed.bars.map(bar => ({ time: bar.time - 3 * 3600, open: bar.open, high: bar.high, low: bar.low, close: bar.close,
    ask: null, spread_usd: bar.spreadPoints * 0.01 }));
  const minutes = raw.filter(minute => marketOpen(minute.time) && marketOpen(minute.time + 59));
  const bars = aggregate(minutes).bars;
  const result = replay(minutes, discover(bars), COSTS['assumed-costs'], minutes[0].time, minutes.at(-1).time + 60);
  assert.equal(result.metrics.closed_trades, 141);
  assert.equal(result.metrics.wins, 55);
  assert.equal(result.metrics.losses, 86);
  assert(Math.abs(result.metrics.net_r - (-7.3269308934721)) < 1e-8);
  console.log(JSON.stringify({ mode: 'self_check_only_not_external_validation', source_sha256: parsed.metadata.sha256,
    status: 'passed', off_session_m1_removed: raw.length - minutes.length,
    independent_math_core_sha256: math.core_sha256, metrics: result.metrics }, null, 2));
}
try {
  const args = process.argv.slice(2);
  if (args[0] === '--self-check' && args[1] && args.length === 2) selfCheck(args[1]);
  else {
    const positional = [];
    let canonicalFile = null;
    for (let i = 0; i < args.length; i++) {
      if (args[i] === '--canonical-plans' && args[i + 1]) canonicalFile = path.resolve(args[++i]);
      else if (args[i].startsWith('--')) throw new Error('Unknown or incomplete option: ' + args[i]);
      else positional.push(args[i]);
    }
    if (!positional[0] || positional.length > 2) throw new Error('Usage: node scripts/research/validate_external_scalper.cjs <normalized-history.json> [output.json] [--canonical-plans actual-php-report.json]');
    const file = path.resolve(positional[0]);
    const output = path.resolve(positional[1] || path.join(path.dirname(file), 'external-scalper-validation-results.json'));
    if (file.toLowerCase() === output.toLowerCase()) throw new Error('Validation output must not overwrite the source dataset.');
    if (canonicalFile && canonicalFile.toLowerCase() === output.toLowerCase()) throw new Error('Validation output must not overwrite the canonical PHP reference.');
    runExternal(file, output, canonicalFile);
  }
} catch (error) {
  console.error(JSON.stringify({ status: 'validation_failed_no_results', error: error.message }));
  process.exitCode = 1;
}
