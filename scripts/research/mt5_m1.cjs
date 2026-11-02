// Strict reader for the supplied MT5 M1 TSV export. Time is an arithmetic
// coordinate over printed fields, not an assertion that broker time is UTC.
const fs = require('node:fs');
const path = require('node:path');
const crypto = require('node:crypto');

function parseWallTime(value) {
  const match = /^(\d{4})[.-](\d{2})[.-](\d{2})[ T](\d{2}):(\d{2}):(\d{2})$/.exec(value);
  if (!match) throw new Error(`Invalid printed date/time: ${value}`);
  const [, yy, mm, dd, hh, mi, ss] = match;
  const parts = [yy, mm, dd, hh, mi, ss].map(Number);
  if (parts[0] < 1900 || parts[1] < 1 || parts[1] > 12 || parts[2] < 1 || parts[2] > 31
      || parts[3] > 23 || parts[4] > 59 || parts[5] > 59) throw new Error(`Invalid calendar fields: ${value}`);
  const date = new Date(Date.UTC(parts[0], parts[1] - 1, parts[2], parts[3], parts[4], parts[5]));
  if (date.getUTCFullYear() !== parts[0] || date.getUTCMonth() + 1 !== parts[1] || date.getUTCDate() !== parts[2]) {
    throw new Error(`Invalid calendar date: ${value}`);
  }
  return date.getTime() / 1000;
}

function wallString(seconds) { return new Date(seconds * 1000).toISOString().slice(0, 19); }

function distribution(values) {
  const sorted = [...values].sort((a, b) => a - b);
  if (!sorted.length) return { count: 0, min: null, median: null, max: null };
  const middle = Math.floor(sorted.length / 2);
  return { count: sorted.length, min: sorted[0], median: sorted.length % 2 ? sorted[middle] : (sorted[middle - 1] + sorted[middle]) / 2, max: sorted.at(-1) };
}

function readM1(file) {
  const bytes = fs.readFileSync(file);
  const lines = bytes.toString('utf8').replace(/^\uFEFF/, '').trimEnd().split(/\r?\n/);
  const expected = ['<DATE>', '<TIME>', '<OPEN>', '<HIGH>', '<LOW>', '<CLOSE>', '<TICKVOL>', '<VOL>', '<SPREAD>'];
  if (JSON.stringify(lines[0].split('\t')) !== JSON.stringify(expected)) throw new Error('Expected the MT5 nine-column M1 TSV schema.');
  const bars = [];
  const gaps = [];
  const decimalCounts = new Map();
  const spreadCounts = new Map();
  const dailyCounts = new Map();
  let previous = null;
  let weekendBars = 0;
  let lowestLow = Infinity, highestHigh = -Infinity;
  for (let i = 1; i < lines.length; i++) {
    const columns = lines[i].split('\t');
    if (columns.length !== expected.length) throw new Error(`Unexpected column count at file line ${i + 1}.`);
    const time = parseWallTime(columns[0] + ' ' + columns[1]);
    if (time % 60 !== 0 || (previous !== null && time <= previous)) throw new Error(`Non-M1, duplicated or unordered timestamp at file line ${i + 1}.`);
    const numbers = columns.slice(2).map(value => {
      if (!/^\d+(?:\.\d+)?$/.test(value)) throw new Error(`Invalid numeric cell at file line ${i + 1}.`);
      const number = Number(value);
      if (!Number.isFinite(number)) throw new Error(`Nonfinite value at file line ${i + 1}.`);
      return number;
    });
    const [open, high, low, close, tickVolume, realVolume, spreadPoints] = numbers;
    if (Math.min(open, high, low, close) <= 0 || high < Math.max(open, low, close) || low > Math.min(open, high, close)) {
      throw new Error(`Inconsistent OHLC at file line ${i + 1}.`);
    }
    lowestLow = Math.min(lowestLow, low);
    highestHigh = Math.max(highestHigh, high);
    if (![tickVolume, realVolume, spreadPoints].every(Number.isSafeInteger)) throw new Error(`Volume/spread cells must be nonnegative integers at file line ${i + 1}.`);
    for (const value of columns.slice(2, 6)) {
      const places = value.includes('.') ? value.split('.')[1].length : 0;
      decimalCounts.set(places, (decimalCounts.get(places) || 0) + 1);
    }
    spreadCounts.set(spreadPoints, (spreadCounts.get(spreadPoints) || 0) + 1);
    const timestamp = wallString(time);
    const date = timestamp.slice(0, 10);
    dailyCounts.set(date, (dailyCounts.get(date) || 0) + 1);
    if ([0, 6].includes(new Date(time * 1000).getUTCDay())) weekendBars++;
    if (previous !== null && time - previous > 60) {
      gaps.push({ previous_open_printed: wallString(previous), next_open_printed: timestamp,
        elapsed_minutes: (time - previous) / 60, absent_minute_intervals: (time - previous) / 60 - 1 });
    }
    bars.push({ time, timestamp, open, high, low, close, spreadPoints, tickVolume, realVolume });
    previous = time;
  }
  if (!bars.length) throw new Error('M1 file contains no candles.');
  return {
    bars,
    metadata: {
      file: path.basename(file), bytes: bytes.length, sha256: crypto.createHash('sha256').update(bytes).digest('hex'),
      timestamp_semantics: 'Printed broker wall-clock fields. Epoch seconds are arithmetic coordinates only; broker UTC offset not established.',
      schema: expected, timeframe: 'M1', rows: bars.length,
      first_open_printed: bars[0].timestamp, last_open_printed: bars.at(-1).timestamp,
      last_bar_closes_printed: wallString(bars.at(-1).time + 60),
      strict_ohlc_and_ascending_timestamp_validation: 'passed',
      distinct_printed_dates: dailyCounts.size, weekend_calendar_date_bars: weekendBars,
      price_decimal_places_observed: Object.fromEntries([...decimalCounts].sort((a, b) => a[0] - b[0])),
      point_size: null,
      point_size_note: 'Printed decimal precision is not independently verified SYMBOL_POINT or tick size. SPREAD is in broker points.',
      spread_points: distribution(bars.map(bar => bar.spreadPoints)),
      spread_points_counts: Object.fromEntries([...spreadCounts].sort((a, b) => a[0] - b[0])),
      price_range: { lowest_low: lowestLow, highest_high: highestHigh },
      nonconsecutive_intervals: gaps.length,
      absent_minute_intervals_including_scheduled_closures: gaps.reduce((n, gap) => n + gap.absent_minute_intervals, 0),
      gap_note: 'Intervals include scheduled broker closures as well as possible missing data. Session metadata is absent; gaps are not assumed tradable.',
      gaps, daily_counts_printed_dates: [...dailyCounts].map(([date, rows]) => ({ date, rows })),
    },
  };
}

function parseCsv(text) {
  const rows = [];
  let cells = [], cell = '', quoted = false;
  text = text.replace(/^\uFEFF/, '');
  for (let i = 0; i < text.length; i++) {
    const char = text[i];
    if (char === '"') {
      if (quoted && text[i + 1] === '"') { cell += '"'; i++; }
      else quoted = !quoted;
    } else if (char === ',' && !quoted) { cells.push(cell); cell = ''; }
    else if ((char === '\n' || char === '\r') && !quoted) {
      if (char === '\r' && text[i + 1] === '\n') i++;
      cells.push(cell); if (cells.some(value => value !== '')) rows.push(cells);
      cells = []; cell = '';
    } else cell += char;
  }
  if (quoted) throw new Error('Unterminated quoted CSV field.');
  if (cell || cells.length) { cells.push(cell); rows.push(cells); }
  return rows;
}

function readSignals(file) {
  const [header, ...rows] = parseCsv(fs.readFileSync(file, 'utf8'));
  const required = ['register_id', 'strategy', 'signal_timestamp_printed', 'side', 'entry', 'stop_loss', 'tp1', 'tp2'];
  if (!required.every(key => header.includes(key))) throw new Error('Signal CSV is missing audit columns.');
  return rows.map((cells, i) => {
    if (cells.length !== header.length) throw new Error(`Signal CSV row ${i + 2} has wrong field count.`);
    const row = Object.fromEntries(header.map((key, j) => [key, cells[j]]));
    const signal = { ...row, time: parseWallTime(row.signal_timestamp_printed) };
    for (const field of ['entry', 'stop_loss', 'tp1', 'tp2', 'risk_per_unit']) {
      if (!(field in row)) continue;
      signal[field] = row[field] === '' ? null : Number(row[field]);
      if (signal[field] !== null && !Number.isFinite(signal[field])) throw new Error(`Invalid ${field} in signal row ${i + 2}.`);
    }
    if (!['BUY', 'SELL'].includes(row.side) || signal.entry <= 0 || signal.stop_loss <= 0) throw new Error(`Invalid signal geometry in row ${i + 2}.`);
    return signal;
  });
}

function profileAlignment(bars, signals) {
  const byMinute = new Map(bars.map(bar => [bar.time, bar]));
  const groups = new Map();
  for (const signal of signals) {
    if (!groups.has(signal.strategy)) groups.set(signal.strategy, []);
    groups.get(signal.strategy).push(signal);
  }
  return [...groups].map(([strategy, rows]) => {
    const offsets = [];
    for (let offset = -12; offset <= 14; offset++) {
      const absolute = [], normalized = [];
      let inside = 0, exact = 0, week = 0;
      const closestExamples = [];
      for (const signal of rows) {
        const at = signal.time + offset * 3600;
        if (at >= bars[0].time && at <= bars.at(-1).time) inside++;
        if ([0, 6].includes(new Date(at * 1000).getUTCDay())) week++;
        const bar = byMinute.get(at);
        if (!bar) continue;
        exact++;
        const difference = Math.abs(signal.entry - bar.open);
        const risk = Math.abs(signal.entry - signal.stop_loss);
        absolute.push(difference); normalized.push(difference / risk);
        closestExamples.push({ register_id: signal.register_id, signal_printed: signal.signal_timestamp_printed,
          mapped_broker_open_printed: bar.timestamp, entry: signal.entry, broker_open: bar.open,
          absolute_deviation_quote: difference, deviation_r: difference / risk });
      }
      offsets.push({ broker_wall_minus_signal_wall_hours: offset, alerts: rows.length,
        inside_export_timestamp_bounds: inside, exact_m1_open_present: exact,
        missing_m1_open: rows.length - exact, mapped_weekend_calendar_dates: week,
        within_0_1_r: normalized.filter(value => value <= 0.1).length,
        within_0_5_r: normalized.filter(value => value <= 0.5).length,
        within_1_r: normalized.filter(value => value <= 1).length,
        absolute_entry_open_deviation_quote: distribution(absolute),
        entry_open_deviation_r: distribution(normalized),
        closest_examples: closestExamples.sort((a, b) => a.deviation_r - b.deviation_r).slice(0, 3) });
    }
    const diagnosticRanking = [...offsets].sort((a, b) => (a.entry_open_deviation_r.median ?? Infinity) - (b.entry_open_deviation_r.median ?? Infinity));
    return { strategy, alerts: rows.length,
      diagnostic_ranking_note: 'Ordered by median absolute entry/open deviation in R over available exact minutes. Not a timezone estimate, performance optimization, or proof of matching feeds. Different offsets can compare different subsets.',
      lowest_median_deviation_offsets: diagnosticRanking.slice(0, 5).map(row => ({ offset_hours: row.broker_wall_minus_signal_wall_hours,
        exact_minutes: row.exact_m1_open_present, median_deviation_quote: row.absolute_entry_open_deviation_quote.median, median_deviation_r: row.entry_open_deviation_r.median })),
      offsets };
  });
}

module.exports = { readM1, readSignals, parseCsv, parseWallTime, wallString, distribution, profileAlignment };

if (require.main === module) {
  const file = process.argv[2];
  if (!file) { console.error('Usage: node scripts/research/mt5_m1.cjs <MT5 M1 TSV> [signal CSV] [output JSON]'); process.exit(1); }
  const { bars, metadata } = readM1(file);
  const signalFile = process.argv[3] || 'docs/research/signal-audit-2026-11-01/signals.csv';
  const result = { price_data: metadata, alignment_method: 'Map printed signal time plus integer offset to an exact M1 opening timestamp. Compare source alert entry with broker bid open. No interpolation, nearest-day matching, trade simulation, or timezone assertion.',
    alignment_diagnostics: profileAlignment(bars, readSignals(signalFile)) };
  const output = process.argv[4] || 'docs/research/signal-replay-2026-11-01/price-profile.json';
  fs.mkdirSync(path.dirname(output), { recursive: true });
  fs.writeFileSync(output, JSON.stringify(result, null, 2) + '\n');
  console.log(JSON.stringify({ output, ...Object.fromEntries(Object.entries(metadata).filter(([key]) => !['gaps', 'daily_counts_printed_dates'].includes(key))),
    best_deviation_diagnostics_not_timezone_estimates: result.alignment_diagnostics.map(row => ({ strategy: row.strategy, diagnostic_offsets: row.lowest_median_deviation_offsets })) }, null, 2));
}
