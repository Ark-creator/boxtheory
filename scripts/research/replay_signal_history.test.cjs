const test = require('node:test');
const assert = require('node:assert/strict');
const { replayOne, summary } = require('./replay_signal_history.cjs');
const { parseWallTime, wallString } = require('./mt5_m1.cjs');
const at = parseWallTime('2026-09-14T10:00:00');
const options = { id: 'fixture', costId: 'fixture', brokerOffset: 0, legacyOffset: 0, legacyDelayMinutes: 0,
  pointSize: 0.01, spreadMultiplier: 1, slippage: 0, fee: 0 };
const signal = (overrides = {}) => ({ register_id: 'BOX-001', strategy: 'Test', time: at,
  signal_timestamp_printed: wallString(at), side: 'BUY', entry: 2000, stop_loss: 1990, tp1: 2020, tp2: 2030, ...overrides });
const bar = (time = at, overrides = {}) => ({ time, timestamp: wallString(time), open: 2000, high: 2002, low: 1998, close: 2000, spreadPoints: 20, ...overrides });
const run = (s, bars, opts = options, policy) => replayOne(s, bars, new Map(bars.map((b, i) => [b.time, i])), opts, policy);

test('market replay buys at ask and preserves original absolute target', () => {
  const result = run(signal(), [bar(at, { high: 2021 })]);
  assert.equal(result.outcome, 'win');
  assert.equal(result.simulated_entry, 2000.2);
  assert.equal(result.exit_price, 2020);
  assert.ok(Math.abs(result.net_r - 19.8 / 10.2) < 1e-9);
});

test('single-screenshot spread is applied only as an explicit stress floor', () => {
  const result = run(signal(), [bar(at, { high: 2021 })], { ...options, spreadFloor: 0.37 });
  assert.equal(result.entry_spread_proxy, 0.37);
  assert.equal(result.simulated_entry, 2000.37);
});

test('short target uses proxy ask and cannot fill from bid low alone', () => {
  const result = run(signal({ side: 'SELL', stop_loss: 2010, tp1: 1980, tp2: 1970 }), [
    bar(at, { low: 1980, close: 1981 }),
    bar(at + 60, { open: 1981, high: 1982, low: 1979, close: 1980 }),
  ]);
  assert.equal(result.outcome, 'win');
  assert.equal(result.exit_time_bar_open, wallString(at + 60));
});

test('both-hit minute remains ambiguous with both payoffs rather than an invented hit order', () => {
  const result = run(signal(), [bar(at, { high: 2030, low: 1980 })]);
  assert.equal(result.outcome, 'ambiguous');
  assert.equal(result.net_r, null);
  assert.equal(result.ambiguous_stop_first_net_r, -1);
  assert.ok(result.ambiguous_target_first_net_r > 0);
});

test('nonconsecutive path is censored in strict counts and kept separately under closure assumption', () => {
  const result = run(signal(), [bar(), bar(at + 180, { open: 1985, high: 1988, low: 1980, close: 1982 })]);
  assert.equal(result.outcome, 'unresolved_gap');
  assert.equal(result.path_outcome_assuming_gaps_closed, 'loss');
  assert.equal(result.exit_price, 1985);
  assert.equal(result.crossed_gap_count, 1);
  assert.ok(result.net_r < -1);
});

test('invalid initial bracket and missing entry minute are not counted as losing trades', () => {
  assert.equal(run(signal(), [bar(at, { open: 2021, high: 2022, low: 2020, close: 2021 })]).outcome, 'unexecutable_entry');
  assert.equal(run(signal({ time: at + 60 }), [bar(), bar(at + 120)]).outcome, 'no_entry_bar');
  assert.equal(run(signal({ time: at + 180 }), [bar(), bar(at + 120)]).outcome, 'outside_export');
});

test('Gold explicitly UTC is preserved while Philippine conversion applies only to legacy payloads', () => {
  const settings = { ...options, brokerOffset: 3, legacyOffset: 8 };
  const bars = [bar(at + 3 * 3600, { high: 2021 })];
  const gold = run(signal({ register_id: 'GRB-001' }), bars, settings);
  const legacy = run(signal(), bars, settings);
  assert.equal(gold.signal_offset_hours, 0);
  assert.equal(gold.outcome, 'win');
  assert.equal(legacy.signal_offset_hours, 8);
  assert.equal(legacy.outcome, 'outside_export');
});

test('five-minute delay starts a new replay and cannot use the earlier price path', () => {
  const bars = [bar(at, { high: 2021 }), bar(at + 300)];
  assert.equal(run(signal(), bars).outcome, 'win');
  assert.equal(run(signal(), bars, { ...options, legacyDelayMinutes: 5 }).outcome, 'open_at_export_end');
});

test('TP2 is an alternative full exit, and Gold single target remains the only target', () => {
  const bars = [bar(at, { high: 2021 })];
  assert.equal(run(signal(), bars, options, 'tp1_full_exit').outcome, 'win');
  assert.equal(run(signal(), bars, options, 'tp2_full_exit_when_available').outcome, 'open_at_export_end');
  assert.equal(run(signal({ tp2: null }), bars, options, 'tp2_full_exit_when_available').outcome, 'win');
});

test('net fees can make a target hit a loss; unresolved counts reconcile', () => {
  const result = run(signal(), [bar(at, { high: 2021, spreadPoints: 0 })], { ...options, fee: 25 });
  assert.equal(result.exit_reason, 'take_profit');
  assert.equal(result.outcome, 'loss');
  assert.equal(result.net_r, -0.5);
  const unknown = run(signal({ time: at + 60 }), [bar()]);
  const totals = summary([result, unknown]);
  assert.equal(totals.alerts, 2);
  assert.equal(totals.resolved, 1);
  assert.equal(totals.loss, 1);
  assert.equal(totals.outside_export, 1);
});
