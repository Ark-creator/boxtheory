<?php

// Offline bridge: boots no application kernel and never reads API/mail credentials.
require getcwd().'/vendor/autoload.php';

use App\Services\RsiScalperMinuteBacktester;

if ($argc !== 4) {
    throw new InvalidArgumentException('Expected data JSON, experiment JSON, and raw output directory.');
}
$data = json_decode(file_get_contents($argv[1]), true, 512, JSON_THROW_ON_ERROR);
$experiment = json_decode(file_get_contents($argv[2]), true, 512, JSON_THROW_ON_ERROR);
$directory = realpath($argv[3]);
if ($directory === false || ! is_dir($directory)) {
    throw new InvalidArgumentException('The raw output directory must already exist.');
}
$tester = new RsiScalperMinuteBacktester;
$prepared = $tester->prepare($data);
$rows = [];
foreach ($experiment['cases'] as $case) {
    if (! preg_match('/^[a-z0-9-]+\.json$/D', $case['file'])) {
        throw new InvalidArgumentException('Experiment case requires a plain JSON filename.');
    }
    $result = $tester->run($data, $prepared, $case['options']);
    $json = json_encode($result, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
    file_put_contents($directory.DIRECTORY_SEPARATOR.$case['file'], $json."\n");
    unset($result['trades']);
    $rows[] = $case + ['result_sha256' => hash('sha256', $json."\n"), 'result' => $result];
}
$signalRegister = [
    'diagnostics' => $prepared['diagnostics'],
    'entries' => $prepared['entries'],
    'close_long_at' => array_keys($prepared['close_long']),
    'close_short_at' => array_keys($prepared['close_short']),
];
file_put_contents($directory.DIRECTORY_SEPARATOR.$experiment['signal_file'], json_encode($signalRegister, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)."\n");
echo json_encode(['diagnostics' => $prepared['diagnostics'], 'rows' => $rows], JSON_THROW_ON_ERROR);
