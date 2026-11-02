const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const os = require('node:os');
const { readM1, parseWallTime, readSignals, profileAlignment } = require('./mt5_m1.cjs');

const header = '<DATE>\t<TIME>\t<OPEN>\t<HIGH>\t<LOW>\t<CLOSE>\t<TICKVOL>\t<VOL>\t<SPREAD>\n';
const candle = (date = '2026.09.14', time = '10:00:00', prices = '2000.00\t2001.00\t1999.00\t2000.50') => `${date}\t${time}\t${prices}\t10\t0\t21\n`;

function withFixture(contents, callback) {
  const directory = fs.mkdtempSync(path.join(os.tmpdir(), 'gold-m1-'));
  const file = path.join(directory, 'fixture.csv');
  fs.writeFileSync(file, contents);
  try { callback(file); }
  finally { fs.unlinkSync(file); fs.rmdirSync(directory); }
}

test('M1 parser validates dates, preserves broker wall fields, reports gaps without inventing prices', () => {
  withFixture(header + candle() + candle('2026.09.14', '10:02:00'), file => {
    const result = readM1(file);
    assert.equal(result.bars.length, 2);
    assert.equal(result.bars[0].timestamp, '2026-09-14T10:00:00');
    assert.equal(result.bars[0].time, parseWallTime('2026-09-14 10:00:00'));
    assert.equal(result.bars[0].spreadPoints, 21);
    assert.equal(result.metadata.point_size, null);
    assert.deepEqual(result.metadata.price_decimal_places_observed, { 2: 8 });
    assert.equal(result.metadata.nonconsecutive_intervals, 1);
    assert.equal(result.metadata.gaps[0].absent_minute_intervals, 1);
    assert.match(result.metadata.sha256, /^[0-9a-f]{64}$/);
  });
});

test('M1 parser rejects impossible dates and times, relative times, bad OHLC and duplicate minutes', () => {
  for (const contents of [header + candle('2026.02.30'), header + candle('2026.09.14', '24:00:00'),
    header + candle('tomorrow'), header + candle('2026.09.14', '10:00:01'),
    header + candle('2026.09.14', '10:00:00', '2000.00\t1998.00\t1999.00\t2000.50'),
    header + candle() + candle()]) {
    withFixture(contents, file => assert.throws(() => readM1(file)));
  }
});

test('alignment compares only exact mapped minutes and never carries prices across a missing minute', () => {
  const time = parseWallTime('2026-09-14 13:00:00');
  const bars = [{ time, timestamp: '2026-09-14T13:00:00', open: 2000, high: 2001, low: 1999, close: 2000, spreadPoints: 21 }];
  const signals = [
    { register_id: 'ONE', strategy: 'test', signal_timestamp_printed: '2026-09-14 10:00:00', time: time - 10800, entry: 2000, stop_loss: 1990 },
    { register_id: 'TWO', strategy: 'test', signal_timestamp_printed: '2026-09-14 10:01:00', time: time - 10740, entry: 2000, stop_loss: 1990 },
  ];
  const offsets = profileAlignment(bars, signals)[0].offsets;
  assert.equal(offsets.find(row => row.broker_wall_minus_signal_wall_hours === 3).exact_m1_open_present, 1);
  assert.equal(offsets.find(row => row.broker_wall_minus_signal_wall_hours === 3).missing_m1_open, 1);
  assert.equal(offsets.find(row => row.broker_wall_minus_signal_wall_hours === 0).exact_m1_open_present, 0);
});

test('signal reader preserves quoted comma fields and nullable TP2', () => {
  const csv = 'register_id,strategy,signal_timestamp_printed,side,entry,stop_loss,tp1,tp2\nA,"strategy, research",2026-09-14 10:00:00,BUY,2000,1990,2020,\n';
  withFixture(csv, file => {
    const [signal] = readSignals(file);
    assert.equal(signal.strategy, 'strategy, research');
    assert.equal(signal.tp2, null);
    assert.equal(signal.entry, 2000);
  });
});
