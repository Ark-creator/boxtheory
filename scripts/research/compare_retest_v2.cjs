// Prespecified engineering comparison. This script never searches strategy parameters.
const fs = require('node:fs');
const path = require('node:path');
const crypto = require('node:crypto');
const cp = require('node:child_process');
const assert = require('node:assert/strict');
const { readM1 } = require('./mt5_m1.cjs');

function iso(seconds) { return new Date(seconds * 1000).toISOString().replace('.000Z', '+00:00'); }

function aggregate(minutes, seconds) {
  const grouped = new Map();
  for (const bar of minutes) {
    const bucket = Math.floor(bar.time / seconds) * seconds;
    if (!grouped.has(bucket)) grouped.set(bucket, []);
    grouped.get(bucket).push(bar);
  }
  const bars = [];
  let incomplete = 0;
  for (const [time, rows] of grouped) {
    if (rows.length !== seconds / 60 || rows.some((row, i) => row.time !== time + i * 60)) { incomplete++; continue; }
    bars.push({ timestamp: iso(time), open: rows[0].open, high: Math.max(...rows.map(row => row.high)),
      low: Math.min(...rows.map(row => row.low)), close: rows.at(-1).close });
  }
  return { bars, incomplete_buckets_omitted: incomplete };
}

const file = process.argv[2] || 'C:/Users/Romark/Downloads/XAUUSD-STD_M1_202609010100_202610302356.csv';
const output = path.resolve(process.argv[3] || 'docs/research/retest-v2-2026-11-02');
const temporary = path.resolve('storage/app/private/signal-research');
const originalFile = 'app/Services/GoldTrendRetestStrategy.php';
const revisedFile = 'app/Services/GoldTrendRetestV2Strategy.php';
if (!fs.existsSync(revisedFile)) throw new Error('GoldTrendRetestV2Strategy is not implemented; no comparison is fabricated.');
const input = readM1(file);
const sourceHash = file => crypto.createHash('sha256').update(fs.readFileSync(file)).digest('hex');
const sources = { v1: originalFile, v2: revisedFile, minute_backtester: 'app/Services/GoldRetestMinuteBacktester.php',
  input_parser: 'scripts/research/mt5_m1.cjs', preparation_script: __filename };
const initialHashes = Object.fromEntries(Object.entries(sources).map(([name, file]) => [name, sourceHash(file)]));
fs.mkdirSync(output, { recursive: true });
fs.mkdirSync(temporary, { recursive: true });

// Each real evaluator runs once per clock scenario. Identity-preserving subclasses
// reuse those exact snapshots across execution-cost and chronological diagnostics.
const php = `require getcwd().'/vendor/autoload.php';
if (!class_exists(App\\Services\\GoldTrendRetestV2Strategy::class)) throw new RuntimeException('V2 evaluator is unavailable.');
class FrozenV1PreparedSnapshots extends App\\Services\\GoldTrendRetestStrategy {
    public function __construct(private array $snapshots) {}
    public function evaluateHistory(array $m15, array $h1, string $dataSource='VT Markets MT5'): array { return $this->snapshots; }
}
class EngineeringV2PreparedSnapshots extends App\\Services\\GoldTrendRetestV2Strategy {
    public function __construct(private array $snapshots) {}
    public function evaluateHistory(array $m15, array $h1, string $dataSource='VT Markets MT5'): array { return $this->snapshots; }
}
$data=json_decode(file_get_contents($argv[1]),true,512,JSON_THROW_ON_ERROR);
$config=json_decode($argv[2],true,512,JSON_THROW_ON_ERROR);
$rows=[];
$rollingDiagnostic=null;
foreach ([
    ['version'=>'v1','engine'=>new App\\Services\\GoldTrendRetestStrategy],
    ['version'=>'v2','engine'=>new App\\Services\\GoldTrendRetestV2Strategy]
] as $variant) {
    $engine=$variant['engine'];
    $snapshots=$engine->evaluateHistory($data['m15'],$data['h1'],'MT5 '.$data['symbol']);
    $prepared=$variant['version']==='v1' ? new FrozenV1PreparedSnapshots($snapshots) : new EngineeringV2PreparedSnapshots($snapshots);
    $tester=new App\\Services\\GoldRetestMinuteBacktester($prepared);
    foreach ($config['segments'] as $segment) foreach ($config['costs'] as $cost) {
        $from=$segment['from'] ? Illuminate\\Support\\Carbon::parse($segment['from']) : null;
        $to=$segment['to'] ? Illuminate\\Support\\Carbon::parse($segment['to']) : null;
        $result=$tester->run($data,0.01,$cost['slippage'],$cost['fee'],$from,$to,$cost['spreadMultiplier']);
        $rows[]=['version'=>$variant['version'],'engine_class'=>$engine::class,'engine_slug'=>$engine::SLUG,
            'signal_history_sha256'=>hash('sha256',json_encode($snapshots,JSON_THROW_ON_ERROR)),
            'full_history_signal_count'=>count($snapshots),'segment'=>$segment['name'],'cost'=>$cost['name'],'result'=>$result];
    }
    if ($variant['version']==='v1' && $config['diagnose_original_rolling300']) {
        $rolling=[]; $hourCount=0;
        foreach ($data['m15'] as $i=>$bar) {
            $at=Illuminate\\Support\\Carbon::parse($bar['timestamp'])->addMinutes(15);
            while (isset($data['h1'][$hourCount]) && Illuminate\\Support\\Carbon::parse($data['h1'][$hourCount]['timestamp'])->addHour()->lte($at)) $hourCount++;
            if ($i<49 || $hourCount<220) continue;
            $slice=array_slice($data['m15'],max(0,$i+1-300),min(300,$i+1));
            $hours=array_slice($data['h1'],max(0,$hourCount-300),min(300,$hourCount));
            $signal=$engine->evaluate($slice,$hours,$at,null,'MT5 '.$data['symbol']);
            if (in_array($signal['action'],['BUY','SELL'],true)) $rolling[$signal['timestamp']]=$signal;
        }
        $contentDifferences=$planDifferences=[];
        foreach (array_intersect_key($snapshots,$rolling) as $at=>$snapshot) {
            if (json_encode($snapshot,JSON_THROW_ON_ERROR)!==json_encode($rolling[$at],JSON_THROW_ON_ERROR)) $contentDifferences[]=$at;
            if (json_encode($snapshot['trade_plan'],JSON_THROW_ON_ERROR)!==json_encode($rolling[$at]['trade_plan'],JSON_THROW_ON_ERROR)) $planDifferences[]=$at;
        }
        $rollingDiagnostic=['purpose'=>'V1 history-length consistency diagnostic; same rules, no fitted parameters',
            'full_history_signal_count'=>count($snapshots),'rolling300_signal_count'=>count($rolling),
            'full_only_timestamps'=>array_keys(array_diff_key($snapshots,$rolling)),
            'rolling_only_timestamps'=>array_keys(array_diff_key($rolling,$snapshots)),
            'shared_timestamp_content_mismatches'=>$contentDifferences,'shared_timestamp_trade_plan_mismatches'=>$planDifferences,
            'result'=>(new App\\Services\\GoldRetestMinuteBacktester(new FrozenV1PreparedSnapshots($rolling)))->run($data,0.01,0.10,0.07)];
    }
}
echo json_encode(['rows'=>$rows,'original_rolling300_diagnostic'=>$rollingDiagnostic],JSON_THROW_ON_ERROR);`;

const comparisons = [];
for (const offset of [3, 0]) {
  const shifted = input.bars.map(bar => ({ ...bar, time: bar.time - offset * 3600 }));
  const m15 = aggregate(shifted, 900);
  const h1 = aggregate(shifted, 3600);
  const first = shifted[0].time;
  const end = shifted.at(-1).time + 60;
  const split = Math.ceil((first + 0.7 * (end - first)) / 900) * 900;
  const assumption = `Assume CSV wall time is UTC+${offset} throughout. Broker timezone is not independently verified.`;
  const data = { source: 'mt5', symbol: 'XAUUSD-STD', timestamp_assumption: assumption,
    m1: shifted.map(bar => ({ timestamp: iso(bar.time), open: bar.open, high: bar.high, low: bar.low, close: bar.close, spread_points: bar.spreadPoints })),
    m15: m15.bars, h1: h1.bars };
  const dataset = path.join(temporary, `retest-v2-input-utcplus${offset}.json`);
  fs.writeFileSync(dataset, JSON.stringify(data));
  const configuration = {
    diagnose_original_rolling300: offset === 3,
    segments: [{ name: 'full', from: null, to: null }, { name: 'first70', from: null, to: iso(split) }, { name: 'last30', from: iso(split), to: null }],
    costs: [
      { name: 'gross', spreadMultiplier: 0, slippage: 0, fee: 0 },
      { name: 'minimum-spread-only', spreadMultiplier: 1, slippage: 0, fee: 0 },
      { name: 'assumed-costs', spreadMultiplier: 1, slippage: 0.10, fee: 0.07 },
      { name: 'double-assumed-costs', spreadMultiplier: 2, slippage: 0.20, fee: 0.14 },
    ],
  };
  const experiment = JSON.parse(cp.execFileSync('php', ['-r', php, dataset, JSON.stringify(configuration)],
    { encoding: 'utf8', maxBuffer: 25 * 1024 * 1024 }));
  if (experiment.original_rolling300_diagnostic) {
    fs.writeFileSync(path.join(output, 'diagnostic-v1-rolling300-utcplus3.json'), JSON.stringify(experiment.original_rolling300_diagnostic, null, 2) + '\n');
  }
  for (const row of experiment.rows) {
    const result = row.result;
    const metric = result.metrics;
    assert.equal(metric.wins + metric.losses + metric.breakeven, metric.closed_trades, 'Closed outcomes must reconcile.');
    assert.equal(metric.closed_trades + Number(result.open_position_at_end !== null)
      + Object.values(result.entry_rejections).reduce((sum, count) => sum + count, 0), result.signals, 'Entry accounting must reconcile.');
    assert.equal(result.strategy, row.engine_slug, 'The minute diagnostic must identify the actual strategy.');
    const filename = `${row.version}-utcplus${offset}-${row.segment}-${row.cost}.json`;
    fs.writeFileSync(path.join(output, filename), JSON.stringify(result, null, 2) + '\n');
    comparisons.push({ version: row.version, offset_hours: offset, segment: row.segment, cost: row.cost, file: filename,
      engine_class: row.engine_class, engine_slug: row.engine_slug,
      signal_history_sha256: row.signal_history_sha256, full_history_signal_count: row.full_history_signal_count,
      split_utc: iso(split), timestamp_assumption: assumption,
      aggregate_counts: { m1: shifted.length, m15: m15.bars.length, h1: h1.bars.length,
        omitted_incomplete_m15: m15.incomplete_buckets_omitted, omitted_incomplete_h1: h1.incomplete_buckets_omitted },
      signals: result.signals, entry_rejections: result.entry_rejections, metrics: result.metrics,
      contiguous_path_metrics: result.contiguous_path_metrics,
      trades_crossing_nonconsecutive_minutes: result.trades_crossing_nonconsecutive_minutes,
      ambiguous_minutes_stop_first: result.ambiguous_minutes_stop_first, open_position_at_end: result.open_position_at_end });
  }
}
const finalHashes = Object.fromEntries(Object.entries(sources).map(([name, file]) => [name, sourceHash(file)]));
assert.deepEqual(finalHashes, initialHashes, 'Source files changed during this experiment; rerun against stable code.');
assert.equal(sourceHash(file), input.metadata.sha256, 'The price CSV changed during this experiment.');
const summary = { analysis_date: '2026-11-02', source: input.metadata,
  source_files_sha256_before_and_after_verified_equal: initialHashes,
  objective: 'Compare one prespecified bounded-state engineering revision with the frozen original. No parameter grid or return-selected timezone.',
  cautions: [
    'UTC+3 is the working broker-clock assumption; UTC is a sensitivity. Neither is confirmed terminal metadata.',
    'Broker point size0.01 is assumed. MT5 M1 spread is the minute MINIMUM and cannot reconstruct intraminute ask extrema or spread at entry.',
    'Per-fill slippage0.10 and round-trip fee0.07 USD/oz are declared research assumptions, not verified charges.',
    'The already-inspected final30% is a chronological diagnostic, not an untouched holdout. Segments retain prior warmup and reset position/execution gates at their start.',
    'Both-hit M1 candles resolve stop first in this diagnostic. Gap-crossing paths are reported separately. No actual account P/L, portfolio equity sizing, swaps, news or margin simulation.',
    'More signals, positive sample returns or a higher sample win rate do not prove superiority or establish a current live setup.',
  ], comparisons };
fs.writeFileSync(path.join(output, 'comparison-summary.json'), JSON.stringify(summary, null, 2) + '\n');
const fields = ['version', 'offset_hours', 'segment', 'cost', 'signals', 'closed_trades', 'wins', 'losses', 'breakeven',
  'net_r', 'expectancy_r', 'profit_factor_r', 'max_closed_trade_drawdown_r', 'contiguous_closed', 'gap_trades', 'ambiguous_minutes'];
const flat = comparisons.map(row => ({ version: row.version, offset_hours: row.offset_hours, segment: row.segment, cost: row.cost,
  signals: row.signals, ...row.metrics, contiguous_closed: row.contiguous_path_metrics.closed_trades,
  gap_trades: row.trades_crossing_nonconsecutive_minutes, ambiguous_minutes: row.ambiguous_minutes_stop_first }));
const cell = value => '"' + String(value ?? '').replace(/"/g, '""') + '"';
fs.writeFileSync(path.join(output, 'comparison.csv'), fields.map(cell).join(',') + '\n'
  + flat.map(row => fields.map(field => cell(row[field])).join(',')).join('\n') + '\n');
console.log(JSON.stringify(flat.filter(row => row.cost === 'assumed-costs'), null, 2));
