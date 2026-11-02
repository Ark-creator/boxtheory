// Normalize acquired provider responses; do not infer broker fills or a historical bid/ask spread.
const fs = require('node:fs');
const path = require('node:path');
const crypto = require('node:crypto');
const assert = require('node:assert/strict');
const directory = path.resolve(process.argv[2] || 'storage/app/private/signal-research/external-validation');
const start = Date.parse('2026-06-01T00:00:00Z'), end = Date.parse('2026-09-01T00:00:00Z');
const chunks = [], m1 = [];
let previous = null;
for (let i = -1; i < 31; i++) {
  const file = path.join(directory, i === -1 ? 'twelvedata-warmup.json' : `twelvedata-chunk-${String(i).padStart(2, '0')}.json`);
  if (!fs.existsSync(file)) throw new Error(`Missing fixed-period source chunk ${i}; no partial result is substituted.`);
  const bytes = fs.readFileSync(file), data = JSON.parse(bytes.toString('utf8'));
  const from = start + i * 3 * 86400000, until = Math.min(from + 3 * 86400000, end);
  assert.equal(data.source, 'Twelve Data historical API');
  assert.equal(data.request.symbol, 'XAU/USD');
  assert.equal(data.request.interval, '1min');
  assert.equal(data.request.timezone, 'UTC');
  assert.equal(data.meta.symbol, 'XAU/USD');
  assert.equal(data.meta.interval, '1min');
  assert.equal(Date.parse(data.request.from_inclusive), from);
  assert.equal(Date.parse(data.request.to_exclusive), until);
  assert.ok(Array.isArray(data.values) && data.values.length > 0);
  for (const row of data.values) {
    assert.match(row.datetime, /^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:00$/);
    const time = Date.parse(row.datetime.replace(' ', 'T') + 'Z');
    assert.ok(time >= from && time < until && (previous === null || time > previous), 'Repeated, unordered or out-of-window source minute.');
    const bar = { timestamp: new Date(time).toISOString().replace('.000Z', '+00:00') };
    for (const field of ['open', 'high', 'low', 'close']) {
      assert.ok(Number.isFinite(Number(row[field])) && Number(row[field]) > 0);
      bar[field] = Number(row[field]);
    }
    assert.ok(bar.high >= Math.max(bar.open, bar.low, bar.close) && bar.low <= Math.min(bar.open, bar.close));
    bar.spread_usd = 0.37;
    m1.push(bar); previous = time;
  }
  chunks.push({ file: path.basename(file), sha256: crypto.createHash('sha256').update(bytes).digest('hex'),
    rows: data.values.length, requested_from_utc: new Date(from).toISOString(), requested_to_exclusive_utc: new Date(until).toISOString(),
    native_instrument_timezone: data.meta.exchange_timezone ?? null, retrieved_at_utc: data.retrieved_at_utc });
}
assert.ok(m1.length > 0);
const provenance = { provider: 'Twelve Data', endpoint: 'https://api.twelvedata.com/time_series',
  symbol: 'XAU/USD', interval: '1min', requested_timezone: 'UTC',
  requested_from_inclusive: '2026-05-29T00:00:00+00:00', requested_to_exclusive: '2026-09-01T00:00:00+00:00',
  evaluation_from_inclusive: '2026-06-01T00:00:00+00:00', warmup_from_inclusive: '2026-05-29T00:00:00+00:00',
  observed_first_utc: m1[0].timestamp, observed_last_utc: m1.at(-1).timestamp, raw_rows: m1.length,
  price_side: 'Provider OHLC; broker bid/ask is not independently established. Bid-side convention is an execution proxy.',
  spread_assumption_usd: 0.37, spread_is_observed_history: false,
  closed_session_note: 'Unfiltered raw source includes indicative closed-session records; the validator must remove those before M1 execution and M5 aggregation.',
  historical_holiday_open_status_available: false, actual_account_fills: false, source_chunks: chunks };
const normalized = { source: 'twelvedata_history', symbol: 'XAUUSD', timestamp_assumption: 'UTC explicitly requested from historical provider; not a broker wall-clock inference', m1, provenance };
const bytes = Buffer.from(JSON.stringify(normalized));
const target = path.join(directory, 'twelvedata-jun-aug-2026-m1.json');
fs.writeFileSync(target, bytes);
const record = { normalized_file: target, sha256: crypto.createHash('sha256').update(bytes).digest('hex'), provenance };
fs.writeFileSync(path.join(directory, 'twelvedata-provenance.json'), JSON.stringify(record, null, 2) + '\n');
console.log(JSON.stringify({ normalized_file: target, sha256: record.sha256, source_rows: m1.length, chunks: chunks.length,
  first_utc: m1[0].timestamp, last_utc: m1.at(-1).timestamp, observed_bid_ask: false, prices_printed: false }, null, 2));
