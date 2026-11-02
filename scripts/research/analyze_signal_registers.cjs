#!/usr/bin/env node
'use strict';

// Audit the text extracted from the four supplied signal registers. Entry quotes
// are observations, not a continuous price path or proof of executed trades.
const fs = require('node:fs');
const path = require('node:path');
const assert = require('node:assert/strict');

const inputDir = path.resolve(process.argv[2] || 'storage/app/private/signal-research');
const outputDir = path.resolve(process.argv[3] || 'docs/research/signal-audit-2026-11-01');
const definitions = [
  ['CON', 'Conservative V2 Final', 'Conservative_V2_Final_Signal_History', 1],
  ['GRB', 'Gold Risk Breakout (Research)', 'Gold_Risk_Breakout_Research_Signal_History', 3],
  ['BOX', 'Box Theory Pro', 'Box_Theory_Pro_Signal_History', 279],
  ['RSI', 'RSI Gold Scalper', 'RSI_Gold_Scalper_Signal_History', 104],
];

function csv(rows, fields) {
  const cell = value => '"' + String(value ?? '').replace(/"/g, '""') + '"';
  return fields.map(cell).join(',') + '\n' + rows.map(row => fields.map(field => cell(row[field])).join(',')).join('\n') + '\n';
}

function stats(numbers) {
  const values = numbers.filter(Number.isFinite).sort((a, b) => a - b);
  if (!values.length) return { count: 0, min: null, median: null, max: null };
  const mid = Math.floor(values.length / 2);
  return { count: values.length, min: values[0], median: values.length % 2 ? values[mid] : (values[mid - 1] + values[mid]) / 2, max: values.at(-1) };
}

// UTC is used only as arithmetic over printed calendar fields. Unknown source
// timezone is never thereby asserted to be UTC, or aligned across strategies.
function printedTime(value) { return Date.parse(value.replace(' ', 'T') + 'Z'); }
const rounded = value => value === null ? null : Math.round(value * 1e8) / 1e8;
const rows = [];
const issues = [];
const duplicateRows = [];
const summaries = [];

for (const [prefix, strategy, basename, expectedCount] of definitions) {
  const text = fs.readFileSync(path.join(inputDir, basename + '.txt'), 'utf8');
  const header = text.match(/(\d+) unique signals \| (\d+) source entries \| (\d+) duplicate entries removed/);
  assert(header, `Missing register totals: ${basename}`);
  const [unique, sourceEntries, duplicatesRemoved] = header.slice(1).map(Number);
  assert.equal(unique, expectedCount, `Unexpected supplied unique total: ${basename}`);
  assert.equal(unique + duplicatesRemoved, sourceEntries, `Source reconciliation failed: ${basename}`);
  const sources = Object.fromEntries([...text.matchAll(/^(S\d+) - (.+)$/gm)].map(m => [m[1], m[2].trim()]));
  const byStrategy = [];
  let inDuplicateAudit = false;
  const duplicateGroups = [];

  for (const pageMatch of text.matchAll(/--- PAGE (\d+) ---\n([\s\S]*?)(?=\n--- PAGE \d+ ---|$)/g)) {
    const page = Number(pageMatch[1]);
    const pageText = pageMatch[2];
    if (/^Duplicate audit$/m.test(pageText)) inDuplicateAudit = true;
    if (inDuplicateAudit) {
      const duplicatePattern = /^(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})\n(BUY|SELL)\n(\d+)\n(S\d+(?:, S\d+)*)(?=\n|$)/gm;
      for (const match of pageText.matchAll(duplicatePattern)) {
        const group = { strategy, pdf_file: basename + '.pdf', pdf_page: page,
          signal_timestamp_printed: match[1], side: match[2], copies_in_sources: Number(match[3]),
          extra_copies_removed: Number(match[3]) - 1, source_refs: match[4] };
        duplicateGroups.push(group);
        duplicateRows.push(group);
      }
      continue;
    }
    const rowPattern = /^(\d+)\n(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})\n(BUY|SELL)\n([\d.]+)\n([\d.]+)\n([\d.]+|N\/A)\n([\d.]+|N\/A)\n(S\d+)(?=\n|$)/gm;
    for (const match of pageText.matchAll(rowPattern)) {
      const [, signalNoText, timestamp, side, entryText, stopText, tp1Text, tp2Text, sourceRef] = match;
      const signalNo = Number(signalNoText);
      const entry = Number(entryText);
      const stopLoss = Number(stopText);
      const tp1 = tp1Text === 'N/A' ? null : Number(tp1Text);
      const tp2 = tp2Text === 'N/A' ? null : Number(tp2Text);
      const direction = side === 'BUY' ? 1 : -1;
      const risk = direction * (entry - stopLoss);
      const reward1 = tp1 === null ? null : direction * (tp1 - entry);
      const reward2 = tp2 === null ? null : direction * (tp2 - entry);
      const geometry = entry > 0 && risk > 0 && reward1 > 0 && (reward2 === null || reward2 >= reward1) ? 'valid' : 'invalid';
      const row = {
        register_id: `${prefix}-${String(signalNo).padStart(3, '0')}`,
        strategy, signal_no: signalNo, pdf_file: basename + '.pdf', pdf_page: page,
        signal_timestamp_printed: timestamp,
        timezone: prefix === 'GRB' ? 'UTC (+00:00), stated in register' : 'unspecified in supplied register',
        side, entry, stop_loss: stopLoss, tp1, tp2, source_ref: sourceRef,
        source_document: sources[sourceRef] || null,
        risk_per_unit: rounded(risk),
        risk_percent_of_entry: rounded(risk / entry * 100),
        tp1_reward_per_unit: rounded(reward1), tp2_reward_per_unit: rounded(reward2),
        rr_tp1: reward1 !== null && risk > 0 ? rounded(reward1 / risk) : null,
        rr_tp2: reward2 !== null && risk > 0 ? rounded(reward2 / risk) : null,
        plan_geometry: geometry, actual_outcome: 'unknown',
        outcome_evidence: 'No execution, exit, P/L, or continuous broker price path supplied',
      };
      assert(sources[sourceRef], `Missing source mapping: ${row.register_id}`);
      assert(Number.isFinite(printedTime(timestamp)), `Invalid timestamp: ${row.register_id}`);
      byStrategy.push(row);
      rows.push(row);
    }
  }

  assert.equal(byStrategy.length, unique, `Parsed count does not match PDF header: ${basename}`);
  assert.equal(duplicateGroups.reduce((n, group) => n + group.extra_copies_removed, 0), duplicatesRemoved, `Duplicate audit does not reconcile: ${basename}`);
  byStrategy.forEach((row, index) => {
    assert.equal(row.signal_no, index + 1, `Signal sequence missing: ${basename}`);
    if (index) assert(printedTime(row.signal_timestamp_printed) >= printedTime(byStrategy[index - 1].signal_timestamp_printed), `Nonchronological rows: ${basename}`);
  });
  const headerSides = text.match(/BUY: (\d+) \| SELL: (\d+)/);
  const buys = byStrategy.filter(r => r.side === 'BUY').length;
  const sells = byStrategy.length - buys;
  assert.equal(buys, Number(headerSides[1]), `BUY total mismatch: ${basename}`);
  assert.equal(sells, Number(headerSides[2]), `SELL total mismatch: ${basename}`);

  const addIssue = (row, issue, detail) => issues.push({
    register_id: row?.register_id || prefix + '-ALL', strategy,
    pdf_file: basename + '.pdf', pdf_page: row?.pdf_page || 1,
    signal_timestamp_printed: row?.signal_timestamp_printed || '', issue, detail,
  });
  addIssue(null, 'outcomes_unavailable', `${unique} unique alerts have no executed fill, exit, net P/L, or continuous broker price history; wins/losses cannot be determined.`);
  if (prefix !== 'GRB') addIssue(null, 'timezone_unspecified', 'Printed timestamps have no stated UTC offset. Do not align with UTC Gold Risk Breakout alerts or broker history until resolved.');
  if (duplicatesRemoved) addIssue(null, 'source_duplicates_already_removed', `${duplicatesRemoved} duplicate source entries were already removed by the supplied register; do not count them as additional trades.`);

  const plans = new Map();
  const days = new Map();
  const shortGaps = [];
  const weekend = [];
  let chain = [];
  let longestChain = [];
  for (const [index, row] of byStrategy.entries()) {
    const key = JSON.stringify([row.side, row.entry, row.stop_loss, row.tp1, row.tp2]);
    if (!plans.has(key)) plans.set(key, []);
    plans.get(key).push(row);
    const date = row.signal_timestamp_printed.slice(0, 10);
    if (!days.has(date)) days.set(date, { date_printed: date, total: 0, buy: 0, sell: 0 });
    const day = days.get(date); day.total++; day[row.side.toLowerCase()]++;
    if ([0, 6].includes(new Date(printedTime(row.signal_timestamp_printed)).getUTCDay())) {
      weekend.push(row.register_id);
      addIssue(row, 'weekend_calendar_date_as_printed', 'Printed date is Saturday/Sunday. Timezone, broker session, stale data, and source timestamp need review; this is not evidence of a losing trade.');
    }
    if (row.plan_geometry !== 'valid') addIssue(row, 'invalid_trade_plan_geometry', `Side=${row.side}, entry=${row.entry}, SL=${row.stop_loss}, TP1=${row.tp1}, TP2=${row.tp2}.`);
    const previous = byStrategy[index - 1];
    const gap = previous ? (printedTime(row.signal_timestamp_printed) - printedTime(previous.signal_timestamp_printed)) / 60000 : null;
    if (previous && row.side === previous.side && gap > 0 && gap <= 5) {
      shortGaps.push({ previous: previous.register_id, current: row.register_id, gap_minutes_printed: gap });
      if (!chain.length) chain = [previous.register_id];
      chain.push(row.register_id);
      addIssue(row, 'same_side_alert_within_five_minutes', `${previous.register_id} preceded this alert by ${gap} minutes as printed. These may be correlated alerts; they are not proven independent trades or duplicates.`);
    } else {
      if (chain.length > longestChain.length) longestChain = chain;
      chain = [];
    }
  }
  if (chain.length > longestChain.length) longestChain = chain;
  const repeatedPlans = [...plans.values()].filter(group => group.length > 1).map(group => ({
    side: group[0].side, entry: group[0].entry, stop_loss: group[0].stop_loss,
    tp1: group[0].tp1, tp2: group[0].tp2,
    alerts: group.map(r => ({ id: r.register_id, timestamp_printed: r.signal_timestamp_printed, page: r.pdf_page })),
  }));
  for (const plan of repeatedPlans) {
    for (const alert of plan.alerts.slice(1)) {
      const row = byStrategy.find(r => r.register_id === alert.id);
      addIssue(row, 'identical_price_plan_at_different_timestamp', `Same direction and entry/SL/targets as ${plan.alerts[0].id}; timestamps differ. Retained as separate alerts under the register rule, not assumed to be separate executed trades.`);
    }
  }
  const timestampGroups = new Map();
  for (const row of byStrategy) {
    const key = row.signal_timestamp_printed;
    if (!timestampGroups.has(key)) timestampGroups.set(key, []);
    timestampGroups.get(key).push(row);
  }
  const simultaneousOpposing = [...timestampGroups.values()].filter(group => new Set(group.map(r => r.side)).size > 1).map(group => group.map(r => r.register_id));
  const valid = byStrategy.filter(row => row.plan_geometry === 'valid');
  summaries.push({
    strategy, id_prefix: prefix, supplied_pdf: basename + '.pdf',
    unique_signals: unique, source_entries: sourceEntries, source_duplicates_removed: duplicatesRemoved,
    source_duplicate_signal_groups: duplicateGroups.length,
    buy: buys, sell: sells,
    actual_results: { winning: null, losing: null, breakeven: null, unknown: unique, confirmed_winning_records: 0, confirmed_losing_records: 0, win_rate: null },
    first_timestamp_printed: byStrategy[0].signal_timestamp_printed,
    last_timestamp_printed: byStrategy.at(-1).signal_timestamp_printed,
    timezone: byStrategy[0].timezone,
    distinct_printed_dates: days.size,
    plan_geometry: { valid: valid.length, invalid: unique - valid.length },
    risk_per_unit: stats(valid.map(r => r.risk_per_unit)),
    risk_percent_of_entry: stats(valid.map(r => r.risk_percent_of_entry)),
    rr_tp1: stats(valid.map(r => r.rr_tp1)),
    rr_tp2: stats(valid.map(r => r.rr_tp2)),
    weekend_printed_date_alerts: weekend.length, weekend_alert_ids: weekend,
    consecutive_same_side_alert_pairs_within_five_minutes: shortGaps.length,
    short_gap_pairs: shortGaps,
    longest_same_side_chain_with_gaps_at_most_five_minutes: { alert_count: longestChain.length, ids: longestChain },
    repeated_identical_price_plan_groups: repeatedPlans.length,
    alerts_in_repeated_price_plans: repeatedPlans.reduce((n, group) => n + group.alerts.length, 0),
    repeated_price_plan_groups: repeatedPlans,
    same_strategy_simultaneous_opposing_alert_groups: simultaneousOpposing,
    daily_counts_printed_dates: [...days.values()],
  });
}

assert.equal(rows.length, 387, 'Four supplied registers must yield exactly 387 unique rows.');
const result = {
  analysis_date: '2026-11-01', input_type: 'User-supplied historical signal registers; no results or continuous market prices',
  methodology: {
    uniqueness: 'Retain register rows exactly; registers already removed same strategy/direction/timestamp/entry/SL/TP duplicates.',
    source_quality: 'The registers state that prices were transcribed from rendered source PDFs with broken text encoding. This audit uses the supplied register values and does not independently verify the original email pages.',
    outcome_rule: 'Unknown unless execution and exit net P/L or an explicitly specified, complete price-path simulation establishes an outcome.',
    trade_count_limitation: 'Unique alerts are not necessarily independent or executed trades.',
    geometry_rule: 'SL lies on adverse side of entry; TP1 on favorable side; optional TP2 at least as far as TP1.',
    ratio_units: 'Absolute quote-price difference per unit; no lot-size, account-currency or PHP profit inference.',
    time_arithmetic: 'Calendar fields parsed as an arithmetic coordinate only; no timezone conversion or implicit UTC assignment for unspecified timestamps.',
    cross_strategy_timestamp_conflicts: 'Not assessed: only Gold Risk Breakout supplies an explicit timezone; no other strategies can be reliably aligned.',
    repeated_price_plan_rule: 'Same side and entry/SL/TP1/TP2 across different printed timestamps; a review flag, not proof of a duplicate.',
  },
  totals: {
    source_entries: summaries.reduce((n, s) => n + s.source_entries, 0),
    duplicates_already_removed: summaries.reduce((n, s) => n + s.source_duplicates_removed, 0),
    unique_alerts: rows.length, buy: rows.filter(r => r.side === 'BUY').length, sell: rows.filter(r => r.side === 'SELL').length,
    winning: null, losing: null, breakeven: null, unknown: rows.length, win_rate: null,
    confirmed_winning_records: 0, confirmed_losing_records: 0,
    valid_plan_geometry: rows.filter(r => r.plan_geometry === 'valid').length,
    invalid_plan_geometry: rows.filter(r => r.plan_geometry === 'invalid').length,
  },
  strategies: summaries,
};

fs.mkdirSync(outputDir, { recursive: true });
fs.writeFileSync(path.join(outputDir, 'signals.csv'), csv(rows, Object.keys(rows[0])));
fs.writeFileSync(path.join(outputDir, 'summary.json'), JSON.stringify(result, null, 2) + '\n');
fs.writeFileSync(path.join(outputDir, 'data-issues.csv'), csv(issues, ['register_id', 'strategy', 'pdf_file', 'pdf_page', 'signal_timestamp_printed', 'issue', 'detail']));
fs.writeFileSync(path.join(outputDir, 'duplicate-audit.csv'), csv(duplicateRows, ['strategy', 'pdf_file', 'pdf_page', 'signal_timestamp_printed', 'side', 'copies_in_sources', 'extra_copies_removed', 'source_refs']));
const manifestFile = path.join(inputDir, 'source-manifest.json');
if (fs.existsSync(manifestFile)) fs.copyFileSync(manifestFile, path.join(outputDir, 'source-manifest.json'));
console.log(JSON.stringify({ output_dir: outputDir, totals: result.totals, strategies: summaries.map(s => ({
  strategy: s.strategy, unique: s.unique_signals, buy: s.buy, sell: s.sell,
  valid_geometry: s.plan_geometry, risk: s.risk_per_unit, rr_tp1: s.rr_tp1, rr_tp2: s.rr_tp2,
  weekend_printed_dates: s.weekend_printed_date_alerts,
  short_gap_pairs: s.consecutive_same_side_alert_pairs_within_five_minutes,
  longest_chain_alerts: s.longest_same_side_chain_with_gaps_at_most_five_minutes.alert_count,
  repeated_price_plan_groups: s.repeated_identical_price_plan_groups,
})) }, null, 2));
