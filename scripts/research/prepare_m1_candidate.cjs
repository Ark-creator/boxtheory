// Reproduce conditional tests of the frozen candidate; never fit parameters here.
const fs = require('node:fs');
const path = require('node:path');
const cp = require('node:child_process');
const { readM1 } = require('./mt5_m1.cjs');

function iso(seconds) { return new Date(seconds * 1000).toISOString().replace('.000Z', '+00:00'); }

function aggregate(minutes, seconds) {
  const groups = new Map();
  for (const bar of minutes) {
    const bucket = Math.floor(bar.time / seconds) * seconds;
    if (!groups.has(bucket)) groups.set(bucket, []);
    groups.get(bucket).push(bar);
  }
  const result = [];
  let dropped = 0;
  for (const [at, bars] of groups) {
    if (bars.length !== seconds / 60 || bars.some((bar, i) => bar.time !== at + i * 60)) { dropped++; continue; }
    result.push({ timestamp: iso(at), open: bars[0].open, high: Math.max(...bars.map(b => b.high)),
      low: Math.min(...bars.map(b => b.low)), close: bars.at(-1).close });
  }
  return { bars: result, dropped_incomplete_buckets: dropped };
}

const file = process.argv[2] || 'C:/Users/Romark/Downloads/XAUUSD-STD_M1_202609010100_202610302356.csv';
const out = path.resolve(process.argv[3] || 'docs/research/signal-replay-2026-11-01');
const temp = path.resolve('storage/app/private/signal-research');
fs.mkdirSync(out, { recursive: true });
fs.mkdirSync(temp, { recursive: true });
const input = readM1(file);
const allResults = [];
const php = `require getcwd().'/vendor/autoload.php';
$data=json_decode(file_get_contents($argv[1]),true,512,JSON_THROW_ON_ERROR);
$config=json_decode($argv[2],true,512,JSON_THROW_ON_ERROR);
$from=$config['from'] ? Illuminate\\Support\\Carbon::parse($config['from']) : null;
$to=$config['to'] ? Illuminate\\Support\\Carbon::parse($config['to']) : null;
$result=(new App\\Services\\GoldRetestMinuteBacktester)->run($data,0.01,$config['slippage'],$config['fee'],$from,$to,$config['spreadMultiplier']);
echo json_encode($result,JSON_THROW_ON_ERROR);`;

for (const offset of [3, 0]) {
  const shifted = input.bars.map(b => ({ ...b, time: b.time - offset * 3600 }));
  const m15 = aggregate(shifted, 900);
  const h1 = aggregate(shifted, 3600);
  const label = `Assume CSV broker wall time is UTC+${offset} for the whole supplied period; this is not confirmed terminal metadata.`;
  const data = { source: 'mt5', symbol: 'XAUUSD-STD', timestamp_assumption: label,
    m1: shifted.map(b => ({ timestamp: iso(b.time), open: b.open, high: b.high, low: b.low, close: b.close, spread_points: b.spreadPoints })),
    m15: m15.bars, h1: h1.bars };
  const dataset = path.join(temp, `candidate-m1-offset-${offset}.json`);
  fs.writeFileSync(dataset, JSON.stringify(data));
  const first = shifted[0].time;
  const end = shifted.at(-1).time + 60;
  const split = Math.ceil((first + (end - first) * 0.7) / 900) * 900;
  for (const segment of [
    { name: 'full', from: null, to: null },
    { name: 'first70', from: null, to: iso(split) },
    { name: 'last30', from: iso(split), to: null },
  ]) {
    for (const cost of [
      { name: 'gross', spreadMultiplier: 0, slippage: 0, fee: 0 },
      { name: 'proxy-cost', spreadMultiplier: 1, slippage: 0.10, fee: 0.07 },
      { name: 'double-cost', spreadMultiplier: 2, slippage: 0.20, fee: 0.14 },
    ]) {
      const config = { ...segment, ...cost };
      const raw = cp.execFileSync('php', ['-r', php, dataset, JSON.stringify(config)], { encoding: 'utf8', maxBuffer: 20 * 1024 * 1024 });
      const result = JSON.parse(raw);
      const outputFile = `candidate-utcplus${offset}-${segment.name}-${cost.name}.json`;
      fs.writeFileSync(path.join(out, outputFile), JSON.stringify(result, null, 2) + '\n');
      allResults.push({ offset_hours: offset, segment: segment.name, cost: cost.name, file: outputFile,
        aggregate_counts: { m1: shifted.length, m15: m15.bars.length, h1: h1.bars.length,
          omitted_incomplete_m15: m15.dropped_incomplete_buckets, omitted_incomplete_h1: h1.dropped_incomplete_buckets },
        timestamp_assumption: label, split_utc: iso(split), signals: result.signals,
        entry_rejections: result.entry_rejections, metrics: result.metrics, contiguous_path_metrics: result.contiguous_path_metrics,
        trades_crossing_nonconsecutive_minutes: result.trades_crossing_nonconsecutive_minutes,
        ambiguous_minutes_stop_first: result.ambiguous_minutes_stop_first,
        open_position_at_end: result.open_position_at_end });
    }
  }
}
const summary = { source: input.metadata, candidate_rules: 'GoldTrendRetestStrategy unchanged from pre-CSV implementation; no parameter search',
  cost_warning: '0.01 point size and slippage/fees are unverified assumptions; minute SPREAD is a minimum, not a true constant quote spread.',
  split_warning: '70/30 split uses elapsed calendar span, includes prior warmup, resets positions/gates at segment start, and is a preliminary chronological diagnostic, not independent evidence.',
  scenarios: allResults };
fs.writeFileSync(path.join(out, 'candidate-summary.json'), JSON.stringify(summary, null, 2) + '\n');
console.log(JSON.stringify(allResults.map(r => ({ offset: r.offset_hours, segment: r.segment, cost: r.cost, ...r.metrics,
  contiguous_closed: r.contiguous_path_metrics.closed_trades, ambiguous: r.ambiguous_minutes_stop_first, gap_trades: r.trades_crossing_nonconsecutive_minutes })), null, 2));
