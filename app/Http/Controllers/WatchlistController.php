<?php

namespace App\Http\Controllers;

use App\Models\Strategy;
use App\Services\TradingService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\View\View;

class WatchlistController extends Controller
{
    public function index(Request $request, TradingService $service): View
    {
        $user = $request->user();
        $pairs = $service->getAvailablePairs();
        $maxPairs = max(1, (int) config('trading.watchlist.max_pairs', 6));
        $selectedPairCodes = $this->resolveSelectedPairCodes(
            is_array($user->watchlist_pairs) ? $user->watchlist_pairs : [],
            $pairs,
            $service,
            $maxPairs
        );

        $position = $request->query('position');
        $positionKey = $position === null || trim((string) $position) === '' ? 'flat' : strtolower(trim((string) $position));
        $cacheSeconds = max(30, (int) config('trading.watchlist.cache_seconds', 180));

        $strategies = $user->isAdmin()
            ? Strategy::query()->orderBy('name')->get()
            : $user->strategies()
                ->wherePivot('status', 'active')
                ->wherePivot('expires_at', '>', now())
                ->orderBy('strategies.name')
                ->get();

        $matrix = [];
        foreach ($selectedPairCodes as $pairCode) {
            foreach ($strategies as $strategy) {
                $cacheKey = sprintf(
                    'watchlist:%d:%s:%d:%s',
                    (int) $user->id,
                    strtolower((string) $pairCode),
                    (int) $strategy->id,
                    $positionKey
                );

                $matrix[$pairCode][$strategy->id] = Cache::remember($cacheKey, $cacheSeconds, function () use ($service, $strategy, $position, $pairCode) {
                    return $service->getSignalForStrategy($strategy->slug, $position, $pairCode);
                });
            }
        }

        return view('watchlist.index', [
            'pairs' => $pairs,
            'selectedPairCodes' => $selectedPairCodes,
            'strategies' => $strategies,
            'matrix' => $matrix,
            'position' => $position,
            'maxPairs' => $maxPairs,
        ]);
    }

    public function update(Request $request, TradingService $service): RedirectResponse
    {
        $pairs = $service->getAvailablePairs();
        $maxPairs = max(1, (int) config('trading.watchlist.max_pairs', 6));
        $inputPairCodes = $request->input('pairs', []);
        if (!is_array($inputPairCodes)) {
            $inputPairCodes = [];
        }

        $selectedPairCodes = $this->sanitizePairCodes($inputPairCodes, $pairs, $service, $maxPairs);
        if ($selectedPairCodes === []) {
            $selectedPairCodes = $this->defaultPairCodes($pairs, $service, $maxPairs);
        }

        $request->user()->forceFill([
            'watchlist_pairs' => $selectedPairCodes,
        ])->save();

        return redirect()
            ->route('watchlist.index', ['position' => $request->input('position')])
            ->with('success', sprintf(
                'Watchlist updated: %d pair%s saved.',
                count($selectedPairCodes),
                count($selectedPairCodes) === 1 ? '' : 's'
            ));
    }

    /**
     * @param array<int,string> $savedPairCodes
     * @param array<string,array<string,mixed>> $pairs
     * @return array<int,string>
     */
    private function resolveSelectedPairCodes(array $savedPairCodes, array $pairs, TradingService $service, int $maxPairs): array
    {
        $selected = $this->sanitizePairCodes($savedPairCodes, $pairs, $service, $maxPairs);
        if ($selected !== []) {
            return $selected;
        }

        return $this->defaultPairCodes($pairs, $service, $maxPairs);
    }

    /**
     * @param array<int,mixed> $codes
     * @param array<string,array<string,mixed>> $pairs
     * @return array<int,string>
     */
    private function sanitizePairCodes(array $codes, array $pairs, TradingService $service, int $maxPairs): array
    {
        $selected = [];

        foreach ($codes as $rawCode) {
            $normalized = $service->normalizePairCode(is_string($rawCode) ? $rawCode : '');
            if (!array_key_exists($normalized, $pairs)) {
                continue;
            }

            if (!in_array($normalized, $selected, true)) {
                $selected[] = $normalized;
            }

            if (count($selected) >= $maxPairs) {
                break;
            }
        }

        return $selected;
    }

    /**
     * @param array<string,array<string,mixed>> $pairs
     * @return array<int,string>
     */
    private function defaultPairCodes(array $pairs, TradingService $service, int $maxPairs): array
    {
        $configured = (string) config('trading.watchlist.default_pair_codes', 'XAUUSD,EURUSD,GBPUSD,USDJPY');
        $configuredCodes = array_map(
            static fn (string $item): string => trim($item),
            explode(',', $configured)
        );

        $selected = $this->sanitizePairCodes($configuredCodes, $pairs, $service, $maxPairs);
        if ($selected !== []) {
            return $selected;
        }

        $fallback = array_key_first($pairs);
        if ($fallback === null) {
            return ['XAUUSD'];
        }

        return [$fallback];
    }
}
