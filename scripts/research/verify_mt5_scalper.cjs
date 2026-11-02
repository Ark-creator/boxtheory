// Offline translated-source checks. Never starts MetaTrader or sends requests.
const fs = require('node:fs');
const path = require('node:path');
const crypto = require('node:crypto');
const vm = require('node:vm');
const assert = require('node:assert/strict');

const coreFile = 'mt5/GoldLogic_RSI_Scalper_V2/ScalperCore.mqh';
const eaFile = 'mt5/GoldLogic_RSI_Scalper_V2/GoldLogic_RSI_Scalper_V2.mq5';
const fixtureFile = process.argv[2] || 'storage/app/private/signal-research/mt5-port-2026-11-02/verification-fixtures.json';
const files = [coreFile, eaFile, fixtureFile, 'scripts/research/verify_mt5_scalper.cjs'];
const hash = p => crypto.createHash('sha256').update(fs.readFileSync(p)).digest('hex');
const hashes = () => Object.fromEntries(files.map(p => [p.replace(/\\/g, '/'), hash(p)]));
const before = hashes();
const core = fs.readFileSync(coreFile, 'utf8');
const ea = fs.readFileSync(eaFile, 'utf8');
const fixtures = JSON.parse(fs.readFileSync(fixtureFile, 'utf8'));
const near = (actual, expected, why, epsilon = 1e-8) => assert.ok(Number.isFinite(actual)
  && Number.isFinite(expected) && Math.abs(actual - expected) <= epsilon, `${why}: ${actual} != ${expected}`);

// This translator deliberately supports only the current pure C-like functions.
// It retains their expressions/branches/loops; declarations, scalar references,
// casts and documented Math/Date/Array APIs receive explicit JS shims. It is
// neither an MQL compiler nor an order/platform simulator.
function matching(text, at, open = '(', close = ')') {
  let depth = 0, quoted = false;
  for (let i = at; i < text.length; i++) {
    if (text[i] === '"' && text[i - 1] !== '\\') quoted = !quoted;
    if (quoted) continue;
    if (text[i] === open) depth++;
    if (text[i] === close && --depth === 0) return i;
  }
  throw Error(`Unbalanced ${open} in translated source`);
}
function splitArgs(text) {
  const rows = []; let begin = 0, depth = 0, quoted = false;
  for (let i = 0; i < text.length; i++) {
    if (text[i] === '"' && text[i - 1] !== '\\') quoted = !quoted;
    if (quoted) continue;
    if ('([{'.includes(text[i])) depth++;
    if (')]}'.includes(text[i])) depth--;
    if (text[i] === ',' && depth === 0) { rows.push(text.slice(begin, i).trim()); begin = i + 1; }
  }
  if (text.slice(begin).trim()) rows.push(text.slice(begin).trim());
  return rows;
}
function parseFunctions(source) {
  const clean = source.replace(/\/\*[\s\S]*?\*\//g, '').replace(/\/\/[^\r\n]*/g, '');
  const functions = new Map();
  const pattern = /^(?:void|bool|int|double|datetime)\s+(GL\w+)\s*\(([^)]*)\)\s*\{/gm;
  let match;
  while ((match = pattern.exec(clean))) {
    const start = pattern.lastIndex - 1, end = matching(clean, start, '{', '}');
    const args = splitArgs(match[2]).map(p => {
      const m = p.match(/^(?:const\s+)?(\w+)\s*(&)?\s*(\w+)\s*(\[\])?\s*(?:=\s*(.*))?$/);
      assert.ok(m, `Unsupported MQL parameter: ${p}`);
      return { type: m[1], reference: Boolean(m[2]), name: m[3], array: Boolean(m[4]), default: m[5] };
    });
    functions.set(match[1], { name: match[1], args, body: clean.slice(start + 1, end) });
    pattern.lastIndex = end + 1;
  }
  return functions;
}
const allFunctions = new Map([...parseFunctions(core), ...parseFunctions(ea)]);
const coreNames = [...parseFunctions(core).keys()];
const executionNames = ['GLGridPrice', 'GLFillMode', 'GLRiskVolume', 'GLBuildRequest', 'GLTickFresh'];
const names = [...coreNames, ...executionNames];
const scalarRef = a => a.reference && !a.array && !['GLSignal', 'MqlRates', 'MqlTick', 'MqlTradeRequest'].includes(a.type);
const referenceCalls = Object.fromEntries(names.map(name => [name, allFunctions.get(name).args.flatMap((a, i) => scalarRef(a) ? [i] : [])]));
referenceCalls.OrderCalcProfit = [5];
function translate(fn) {
  let body = fn.body;
  const calls = [...body.matchAll(/\b([A-Za-z_]\w*)\s*\(/g)].filter(m => referenceCalls[m[1]]?.length);
  for (const call of calls.reverse()) {
    const at = call.index + call[0].lastIndexOf('('), end = matching(body, at);
    const args = splitArgs(body.slice(at + 1, end));
    for (const index of referenceCalls[call[1]]) {
      assert.match(args[index], /^[A-Za-z_]\w*(?:\.\w+)?$/, 'Scalar-reference shim requires a simple variable');
      args[index] = `__ref(()=>(${args[index]}),__value=>{${args[index]}=__value;})`;
    }
    body = body.slice(0, at + 1) + args.join(',') + body.slice(end);
  }
  const refs = new Set(fn.args.filter(scalarRef).map(a => a.name));
  body = body.replace(/"(?:\\.|[^"\\])*"|[A-Za-z_]\w*/g, token => refs.has(token) ? `${token}.value` : token);
  body = body.replace(/\((?:datetime|long|int|double|ulong)\)/g, '');
  body = body.replace(/\bMqlDateTime\s+(\w+)\s*;/g, 'let $1={};');
  body = body.replace(/\b(?:const\s+)?(?:double|int|long|bool|datetime|string|ulong|ENUM_ORDER_TYPE|ENUM_ORDER_TYPE_FILLING)\s+(\w+)/g, 'let $1');
  assert.doesNotMatch(body, /\b(?:OrderSend|OrderCheck|History\w+|Position\w+|File\w+|Print|require|process|fetch|eval|Function)\s*\(/,
    'Translated functions must contain no execution, filesystem or network API');
  return `function ${fn.name}(${fn.args.map(a => a.name + (a.default === undefined ? '' : '=' + a.default)).join(',')}) {${body}\n}`;
}

let metadata = {};
const constants = ['SYMBOL_VOLUME_MIN', 'SYMBOL_VOLUME_MAX', 'SYMBOL_VOLUME_STEP', 'SYMBOL_POINT', 'SYMBOL_DIGITS',
  'SYMBOL_TRADE_TICK_SIZE', 'SYMBOL_TRADE_STOPS_LEVEL', 'SYMBOL_TRADE_EXEMODE', 'SYMBOL_FILLING_MODE', 'ACCOUNT_EQUITY'];
const sandbox = {
  GL_REQUIRED_BARS: 203, _Symbol: 'OFFLINE_XAUUSD', InpMaxDeviationPoints: 10, InpRiskPercent: 0.5,
  InpCommissionPerLotRoundTurn: 0, InpMagic: 2601102, InpBrokerWinterUtcMinutes: 180,
  InpBrokerSummerUtcMinutes: 180, InpBrokerDstRule: 0,
  ORDER_TYPE_BUY: 0, ORDER_TYPE_SELL: 1, ORDER_FILLING_FOK: 0, ORDER_FILLING_IOC: 1, ORDER_FILLING_RETURN: 2,
  SYMBOL_FILLING_FOK: 1, SYMBOL_FILLING_IOC: 2, SYMBOL_TRADE_EXECUTION_REQUEST: 0, SYMBOL_TRADE_EXECUTION_INSTANT: 1,
  SYMBOL_TRADE_EXECUTION_MARKET: 2, SYMBOL_TRADE_EXECUTION_EXCHANGE: 3, TRADE_ACTION_DEAL: 1, ORDER_TIME_GTC: 0,
  ArraySize: a => a.length, ArrayGetAsSeries: a => a.__series === true,
  MathIsValidNumber: Number.isFinite, MathAbs: Math.abs, MathFloor: Math.floor, MathCeil: Math.ceil,
  MathMin: Math.min, MathMax: Math.max, NormalizeDouble: (n, p) => Number(n.toFixed(p)),
  __ref: (get, set) => Object.defineProperty({}, 'value', { get, set }),
  ZeroMemory: obj => { for (const key of Object.keys(obj)) delete obj[key]; },
  TimeToStruct: (epoch, out) => {
    const d = new Date(epoch * 1000); if (!Number.isFinite(d.getTime())) return false;
    Object.assign(out, { year: d.getUTCFullYear(), mon: d.getUTCMonth() + 1, day: d.getUTCDate(),
      hour: d.getUTCHours(), min: d.getUTCMinutes(), sec: d.getUTCSeconds(), day_of_week: d.getUTCDay(),
      day_of_year: Math.floor((Date.UTC(d.getUTCFullYear(), d.getUTCMonth(), d.getUTCDate()) - Date.UTC(d.getUTCFullYear(), 0, 1)) / 86400000) });
    return true;
  },
  StructToTime: p => Date.UTC(p.year, p.mon - 1, p.day, p.hour, p.min, p.sec) / 1000,
  SymbolInfoDouble: (_symbol, field) => metadata[field], SymbolInfoInteger: (_symbol, field) => metadata[field],
  AccountInfoDouble: field => metadata[field],
  OrderCalcProfit: (type, _symbol, volume, entry, exit, out) => {
    if (metadata.order_calc_fails) return false;
    out.value = (exit - entry) * (type === 0 ? 1 : -1) * volume * 100;
    return true;
  },
};
for (const name of constants) sandbox[name] = name;
const context = vm.createContext(sandbox, { codeGeneration: { strings: false, wasm: false } });
new vm.Script(names.map(name => translate(allFunctions.get(name))).join('\n'), { filename: 'translated-current-mql-source.js' })
  .runInContext(context, { timeout: 1000 });

const counts = {}, fields = ['side', 'entry', 'stop', 'target', 'risk', 'atr', 'rsi', 'fast', 'slow', 'trend_now', 'trend_prior', 'decision_utc'];
let numbers = 0;
for (const fixture of fixtures.rows) {
  const signal = {}, reason = { value: '' };
  assert.equal(context.GLEvaluate(fixture.bars, signal, reason), true, `${fixture.name}: ${reason.value}`);
  const cohort = fixture.name.split(':')[0];
  counts[cohort] ||= { frames: 0, buy: 0, sell: 0, hold: 0 };
  counts[cohort].frames++;
  counts[cohort][signal.side === 1 ? 'buy' : signal.side === -1 ? 'sell' : 'hold']++;
  for (const field of fields) { near(signal[field], fixture.expected[field], `${fixture.name}.${field}`); numbers++; }
}
for (const row of fixtures.rounding) near(context.GLPhpRound(row.value, row.places), row.expected, `PHP half-up ${row.value}/${row.places}`, 0);

let sourceFailures = 0;
const valid = fixtures.rows[0].bars;
for (const fault of ['short', 'long', 'series', 'duplicate', 'reverse', 'misaligned', 'zero', 'negative', 'nan', 'bad_ohlc', 'closed', 'unsupported']) {
  const bars = structuredClone(valid);
  if (fault === 'short') bars.pop();
  if (fault === 'long') bars.push({ ...bars.at(-1), time: bars.at(-1).time + 300 });
  if (fault === 'series') bars.__series = true;
  if (fault === 'duplicate') bars[100].time = bars[99].time;
  if (fault === 'reverse') bars.reverse();
  if (fault === 'misaligned') bars[100].time++;
  if (fault === 'zero') bars[100].close = 0;
  if (fault === 'negative') bars[100].open = -1;
  if (fault === 'nan') bars[100].high = NaN;
  if (fault === 'bad_ohlc') bars[100].high = bars[100].low - 1;
  if (fault === 'closed') bars[0].time = Date.parse('2026-08-29T12:00:00Z') / 1000;
  if (fault === 'unsupported') bars[0].time = Date.parse('2006-01-01T00:00:00Z') / 1000;
  assert.equal(context.GLEvaluate(bars, {}, { value: '' }), false, `Source guard failed: ${fault}`); sourceFailures++;
}

const epoch = date => Date.parse(date) / 1000;
let clockChecks = 0;
for (const [time, open] of [
  ['2026-03-08T21:59:59Z', false], ['2026-03-08T22:00:00Z', true],
  ['2026-11-01T22:59:59Z', false], ['2026-11-01T23:00:00Z', true],
  ['2026-07-06T20:59:59Z', true], ['2026-07-06T21:00:00Z', false], ['2026-07-06T22:00:00Z', true],
  ['2026-11-06T21:59:59Z', true], ['2026-11-06T22:00:00Z', false], ['2026-11-07T12:00:00Z', false],
]) { assert.equal(context.GLGoldOpen(epoch(time)), open, `NY session ${time}`); clockChecks++; }
for (const [time, allowed] of [['2026-11-02T06:59:59Z', false], ['2026-11-02T07:00:00Z', true],
  ['2026-11-02T16:59:59Z', true], ['2026-11-02T17:00:00Z', false], ['2026-11-01T12:00:00Z', false]]) {
  assert.equal(context.GLEntryWindow(epoch(time)), allowed, `Entry close clock ${time}`); clockChecks++;
}
for (const [server, winter, summer, rule, expected] of [
  ['2026-09-01T12:00:00Z', 180, 180, 0, '2026-09-01T09:00:00Z'],
  ['2026-07-01T12:00:00Z', 120, 180, 1, '2026-07-01T09:00:00Z'],
  ['2026-12-01T12:00:00Z', 120, 180, 1, '2026-12-01T10:00:00Z'],
  ['2026-03-08T09:30:00Z', 120, 180, 1, null], ['2026-11-01T08:30:00Z', 120, 180, 1, null],
  ['2026-03-29T03:30:00Z', 120, 180, 2, null], ['2026-10-25T03:30:00Z', 120, 180, 2, null],
]) {
  const utc = { value: 0 }; const ok = context.GLBrokerToUtc(epoch(server), winter, summer, rule, utc);
  assert.equal(ok, expected !== null, `Broker DST ${server}/${rule}`);
  if (ok) assert.equal(utc.value, epoch(expected)); clockChecks++;
}
for (const [age, ok] of [[0, true], [90, true], [91, false], [-1, false]]) {
  const now = epoch('2026-11-02T12:00:00Z');
  const tick = { time: now - age + 10800, bid: 2000, ask: 2000.2 };
  assert.equal(context.GLTickFresh(tick, now, { value: '' }), ok, `Quote age ${age}`); clockChecks++;
}

const baseMeta = () => ({ SYMBOL_VOLUME_MIN: 0.01, SYMBOL_VOLUME_MAX: 100, SYMBOL_VOLUME_STEP: 0.01,
  SYMBOL_POINT: 0.01, SYMBOL_DIGITS: 2, SYMBOL_TRADE_TICK_SIZE: 0.01, SYMBOL_TRADE_STOPS_LEVEL: 10,
  SYMBOL_TRADE_EXEMODE: 2, SYMBOL_FILLING_MODE: 1, ACCOUNT_EQUITY: 10000 });
let executionChecks = 0;
for (const [execution, modes, expected] of [[0, 0, 0], [1, 0, 0], [2, 1, 0], [2, 2, 1], [2, 3, 0], [2, 0, null], [3, 0, 2]]) {
  metadata = { ...baseMeta(), SYMBOL_TRADE_EXEMODE: execution, SYMBOL_FILLING_MODE: modes };
  const fill = { value: -1 }; assert.equal(context.GLFillMode(fill), expected !== null);
  if (expected !== null) assert.equal(fill.value, expected); executionChecks++;
}
for (const [type, equity, fails, expected] of [[0, 100, false, false], [1, 100, false, false],
  [0, 10000, false, true], [1, 10000, false, true], [0, 10000, true, false]]) {
  metadata = { ...baseMeta(), ACCOUNT_EQUITY: equity, order_calc_fails: fails };
  const stop = type === 0 ? 1996 : 2004, volume = { value: 0 };
  assert.equal(context.GLRiskVolume(type, 2000, stop, volume, { value: '' }), expected);
  if (expected) assert.ok(volume.value * 100 * 4.1 <= equity * 0.005 + 1e-8, 'Budget exceeded'); executionChecks++;
}
metadata = { ...baseMeta(), SYMBOL_VOLUME_MIN: 0.25, SYMBOL_VOLUME_STEP: 0.25, ACCOUNT_EQUITY: 100000 };
const quarterVolume = { value: 0 };
assert.equal(context.GLRiskVolume(0, 2000, 1996, quarterVolume, { value: '' }), true);
assert.equal(quarterVolume.value % 0.25, 0); executionChecks++;
for (const side of [1, -1]) for (const drift of [0, 0.099, 0.101]) {
  metadata = baseMeta();
  const signal = { side, entry: 2000, stop: 2000 - side * 4.1234, target: 2000 + side * 6.1851 };
  const quote = signal.entry + side * drift * 4.1234;
  const tick = side === 1 ? { ask: quote, bid: quote - 0.2 } : { bid: quote, ask: quote + 0.2 };
  const request = {};
  assert.equal(context.GLBuildRequest(signal, tick, request, { value: '' }), drift < 0.1);
  if (drift < 0.1) {
    assert.ok(side * (signal.stop - request.sl) >= -1e-8, 'Stop rounded inward');
    assert.ok(side * (signal.target - request.tp) >= -1e-8, 'Target rounded outward');
    assert.ok(request.sl > 0 && request.tp > 0);
  }
  executionChecks++;
}

// Static wiring guards supplement translated pure-function checks. They cannot
// establish broker behavior, thread/event ordering, successful fills or P/L.
const staticChecks = [
  ['closed bars copied', /CopyRates\(_Symbol,PERIOD_M5,1,InpHistoryBars,raw\)/],
  ['dynamic chronological bars', /MqlRates raw\[\];\s*ArraySetAsSeries\(raw,false\)/],
  ['equity-based risk', /OrderCalcProfit\(type,_Symbol,minimum,worst,stop,pnl\)/],
  ['history failures block', /if\(!HistorySelect\(since_server,now_server\)\)[\s\S]*?return true;/],
  ['exclusive signal expiry', /utc-signal\.decision_utc>=180/],
  ['OrderCheck success zero distinguished from OrderSend', /check\.retcode!=0\s*&&\s*check\.retcode!=TRADE_RETCODE_DONE/],
  ['rechecks quote after OrderCheck', /OrderCheck\(request,check\)[\s\S]*?MqlTick final_tick=\{\};[\s\S]*?GLTickFresh\(final_tick,utc,reason\)/],
  ['changed checked quote skipped', /final_tick\.bid!=tick\.bid\s*\|\|\s*final_tick\.ask!=tick\.ask/],
  ['persistent atomic candle claim', /GlobalVariableSetOnCondition\(g_lock_key/],
  ['no real account execution', /ACCOUNT_TRADE_MODE_DEMO/],
  ['pending uncertain order halts', /TRADE_RETCODE_PLACED[\s\S]*?TRADE_RETCODE_TIMEOUT[\s\S]*?g_halted=true/],
  ['history-based deal confirmation', /TRADE_TRANSACTION_DEAL_ADD[\s\S]*?HistoryDealSelect\(transaction\.deal\)/],
];
for (const [name, expression] of staticChecks) assert.match(ea, expression, name);
assert.doesNotMatch(ea, /\biMA\(|\biRSI\(|\biATR\(|PositionClosePartial\(|PositionModify\(/, 'No substituted Wilder indicators or untested management rules');
assert.equal(hashes()[coreFile], before[coreFile]);
assert.deepEqual(hashes(), before, 'Sources or golden fixtures changed during verification');
console.log(JSON.stringify({ status: 'passed', method: 'Actual MQL numeric source translated to JavaScript with explicit Math/Array/UTC/scalar-reference shims; not native MQL execution',
  fixture_file: path.relative(process.cwd(), path.resolve(fixtureFile)).replace(/\\/g, '/'),
  golden_frames: fixtures.rows.length, golden_numeric_fields: numbers, php_rounding_cases: fixtures.rounding.length,
  cohorts: counts, malformed_source_checks: sourceFailures, clock_and_quote_checks: clockChecks,
  mocked_execution_checks: executionChecks, static_wiring_checks: staticChecks.length + 1,
  differences: 0, hashes_verified_equal_before_after: before,
  limits: ['This is translated-source numerical verification; MetaEditor compile logs are separate.',
    'No terminal, account, provider request, order, MQL runtime, Strategy Tester pass or broker fill was executed.',
    'Execution APIs are deterministic mocks for selected pure request/risk functions; actual platform event ordering and broker rules require demo testing.',
    'Existing historical crossover frames are implementation fixtures, not new profitability validation; separate malformed inputs and clock/risk boundaries supplement them.',
    'JavaScript uses IEEE754 doubles, but VM/API shims do not establish full MQL runtime equivalence.'] }, null, 2));
