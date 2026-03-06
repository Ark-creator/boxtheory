<x-admin-layout title="Signals Matrix">
    @php
        $now = now();
        $freshWindow = $now->copy()->subHours(4);
        $spotlightWindow = $now->copy()->subMinutes(30);
        $strategyCount = $strategies->count();
        $pairCount = count($pairs);
        $totalCells = max(1, $pairCount * max($strategyCount, 1));
        $positionValue = strtolower(trim((string) ($position ?? '')));
        $positionLabel = match ($positionValue) {
            'long' => 'Long bias only',
            'short' => 'Short bias only',
            default => 'All position contexts',
        };
        $actionTotals = [
            'BUY' => 0,
            'SELL' => 0,
            'HOLD' => 0,
            'CLOSE' => 0,
            'NO_DATA' => 0,
        ];
        $actionStyles = [
            'BUY' => [
                'surface' => 'border-emerald-400/25 bg-gradient-to-br from-emerald-500/20 via-emerald-500/8 to-slate-950/70',
                'hover' => 'hover:border-emerald-400/60 hover:shadow-lg hover:shadow-emerald-500/20 hover:-translate-y-1',
                'badge' => 'border-emerald-300/25 bg-emerald-400/15 text-emerald-50',
                'text' => 'text-emerald-100',
                'muted' => 'text-emerald-200/70',
                'dot' => 'bg-emerald-400',
            ],
            'SELL' => [
                'surface' => 'border-rose-400/25 bg-gradient-to-br from-rose-500/20 via-rose-500/8 to-slate-950/70',
                'hover' => 'hover:border-rose-400/60 hover:shadow-lg hover:shadow-rose-500/20 hover:-translate-y-1',
                'badge' => 'border-rose-300/25 bg-rose-400/15 text-rose-50',
                'text' => 'text-rose-100',
                'muted' => 'text-rose-200/70',
                'dot' => 'bg-rose-400',
            ],
            'HOLD' => [
                'surface' => 'border-sky-400/20 bg-gradient-to-br from-sky-500/18 via-sky-500/8 to-slate-950/70',
                'hover' => 'hover:border-sky-400/60 hover:shadow-lg hover:shadow-sky-500/20 hover:-translate-y-1',
                'badge' => 'border-sky-300/25 bg-sky-400/15 text-sky-50',
                'text' => 'text-sky-100',
                'muted' => 'text-sky-200/70',
                'dot' => 'bg-sky-400',
            ],
            'CLOSE' => [
                'surface' => 'border-amber-400/25 bg-gradient-to-br from-amber-500/20 via-amber-500/8 to-slate-950/70',
                'hover' => 'hover:border-amber-400/60 hover:shadow-lg hover:shadow-amber-500/20 hover:-translate-y-1',
                'badge' => 'border-amber-300/25 bg-amber-400/15 text-amber-50',
                'text' => 'text-amber-100',
                'muted' => 'text-amber-200/70',
                'dot' => 'bg-amber-400',
            ],
            'MIXED' => [
                'surface' => 'border-fuchsia-400/20 bg-gradient-to-br from-fuchsia-500/20 via-fuchsia-500/8 to-slate-950/70',
                'hover' => 'hover:border-fuchsia-400/60 hover:shadow-lg hover:shadow-fuchsia-500/20 hover:-translate-y-1',
                'badge' => 'border-fuchsia-300/20 bg-fuchsia-400/15 text-fuchsia-50',
                'text' => 'text-fuchsia-100',
                'muted' => 'text-fuchsia-200/70',
                'dot' => 'bg-fuchsia-400',
            ],
            'NO_DATA' => [
                'surface' => 'border-white/10 bg-gradient-to-br from-white/5 via-slate-900/40 to-slate-950/80',
                'hover' => 'hover:border-white/30 hover:shadow-lg hover:shadow-white/5 hover:-translate-y-1',
                'badge' => 'border-white/10 bg-white/5 text-slate-300',
                'text' => 'text-slate-100',
                'muted' => 'text-slate-400',
                'dot' => 'bg-slate-500',
            ],
        ];
        $pairSummaries = [];
        $liveSignalCount = 0;
        $freshSignalCount = 0;
        $bullishTrendCount = 0;
        $bearishTrendCount = 0;
        $neutralTrendCount = 0;
        $opportunityPairCount = 0;
        $mixedPairCount = 0;

        foreach ($pairs as $pairCode => $pairItem) {
            $pairActionCounts = [
                'BUY' => 0,
                'SELL' => 0,
                'HOLD' => 0,
                'CLOSE' => 0,
                'NO_DATA' => 0,
            ];
            $pairTrendCounts = [
                'bullish' => 0,
                'bearish' => 0,
                'neutral' => 0,
            ];
            $latestTimestamp = null;

            foreach ($strategies as $strategy) {
                $signal = $matrix[$pairCode][$strategy->id] ?? null;
                $action = strtoupper((string) ($signal['action'] ?? 'NO_DATA'));

                if (! array_key_exists($action, $pairActionCounts)) {
                    $pairActionCounts[$action] = 0;
                }

                if (! array_key_exists($action, $actionTotals)) {
                    $actionTotals[$action] = 0;
                }

                $pairActionCounts[$action]++;
                $actionTotals[$action]++;

                if ($action === 'NO_DATA') {
                    continue;
                }

                $liveSignalCount++;

                $trend = strtoupper((string) ($signal['trend'] ?? 'UNKNOWN'));
                if (str_contains($trend, 'BULL') || str_contains($trend, 'UP')) {
                    $pairTrendCounts['bullish']++;
                    $bullishTrendCount++;
                } elseif (str_contains($trend, 'BEAR') || str_contains($trend, 'DOWN')) {
                    $pairTrendCounts['bearish']++;
                    $bearishTrendCount++;
                } else {
                    $pairTrendCounts['neutral']++;
                    $neutralTrendCount++;
                }

                $timestamp = null;
                if (! empty($signal['timestamp'])) {
                    try {
                        $timestamp = \Illuminate\Support\Carbon::parse($signal['timestamp']);
                    } catch (\Throwable $exception) {
                        $timestamp = null;
                    }
                }

                if ($timestamp !== null) {
                    if ($latestTimestamp === null || $timestamp->greaterThan($latestTimestamp)) {
                        $latestTimestamp = $timestamp;
                    }

                    if ($timestamp->greaterThan($freshWindow)) {
                        $freshSignalCount++;
                    }
                }
            }

            $dominantAction = 'NO_DATA';
            $consensusCount = 0;
            $topActionCount = 0;
            $topActionCountMatches = 0;

            foreach (['BUY', 'SELL', 'HOLD', 'CLOSE'] as $candidateAction) {
                $candidateCount = (int) ($pairActionCounts[$candidateAction] ?? 0);

                if ($candidateCount === 0) {
                    continue;
                }

                if ($candidateCount > $topActionCount) {
                    $topActionCount = $candidateCount;
                    $dominantAction = $candidateAction;
                    $consensusCount = $candidateCount;
                    $topActionCountMatches = 1;
                } elseif ($candidateCount === $topActionCount) {
                    $topActionCountMatches++;
                }
            }

            if ($topActionCountMatches > 1) {
                $dominantAction = 'MIXED';
            }

            $trendLabel = 'Awaiting live trends';
            $trendStrength = 0;
            foreach (['bullish' => 'Bullish drift', 'bearish' => 'Bearish drift', 'neutral' => 'Balanced tape'] as $trendKey => $label) {
                if (($pairTrendCounts[$trendKey] ?? 0) > $trendStrength) {
                    $trendStrength = (int) $pairTrendCounts[$trendKey];
                    $trendLabel = $label;
                }
            }

            $opportunityCount = (int) ($pairActionCounts['BUY'] ?? 0) + (int) ($pairActionCounts['SELL'] ?? 0) + (int) ($pairActionCounts['CLOSE'] ?? 0);
            if ($opportunityCount > 0) {
                $opportunityPairCount++;
            }

            if ($dominantAction === 'MIXED') {
                $mixedPairCount++;
            }

            $pairSummaries[$pairCode] = [
                'action_counts' => $pairActionCounts,
                'consensus_count' => $consensusCount,
                'dominant_action' => $dominantAction,
                'latest_timestamp' => $latestTimestamp,
                'opportunity_count' => $opportunityCount,
                'trend_counts' => $pairTrendCounts,
                'trend_label' => $trendLabel,
            ];
        }

        $coverage = (int) round(($liveSignalCount / $totalCells) * 100);
        $directionalBalance = (int) ($actionTotals['BUY'] ?? 0) - (int) ($actionTotals['SELL'] ?? 0);
        $marketTilt = match (true) {
            $directionalBalance > 0 => 'Buy pressure',
            $directionalBalance < 0 => 'Sell pressure',
            default => 'Balanced tape',
        };
        $momentumLabel = match (true) {
            $bullishTrendCount > $bearishTrendCount => 'Bullish drift',
            $bearishTrendCount > $bullishTrendCount => 'Bearish drift',
            default => 'Two-way market',
        };
    @endphp

    @once
        <style>
            .signals-matrix-page .signal-copy-clamp {
                display: -webkit-box;
                overflow: hidden;
                -webkit-box-orient: vertical;
                -webkit-line-clamp: 3;
            }

            .signals-matrix-page .matrix-scrollbar {
                scrollbar-width: thin;
                scrollbar-color: rgba(148, 163, 184, 0.35) transparent;
            }

            .signals-matrix-page .matrix-scrollbar::-webkit-scrollbar {
                height: 10px;
                width: 10px;
            }

            .signals-matrix-page .matrix-scrollbar::-webkit-scrollbar-thumb {
                background: rgba(148, 163, 184, 0.3);
                border-radius: 999px;
            }

            @keyframes blob {
                0% { transform: translate(0px, 0px) scale(1); }
                33% { transform: translate(30px, -50px) scale(1.1); }
                66% { transform: translate(-20px, 20px) scale(0.9); }
                100% { transform: translate(0px, 0px) scale(1); }
            }
            .animate-blob {
                animation: blob 10s infinite;
            }
            .animation-delay-2000 {
                animation-delay: 2s;
            }
            .animation-delay-4000 {
                animation-delay: 4s;
            }
            .animate-slide-up {
                animation: slideUp 0.6s cubic-bezier(0.16, 1, 0.3, 1) forwards;
                opacity: 0;
                transform: translateY(20px);
            }
            @keyframes slideUp {
                to { opacity: 1; transform: translateY(0); }
            }
        </style>
    @endonce

    <div class="signals-matrix-page space-y-6 lg:space-y-8" data-signals-matrix>
        <section class="relative overflow-hidden rounded-[32px] border border-white/10 bg-slate-950/70 p-6 shadow-2xl shadow-black/30 lg:p-8 animate-slide-up">
            <div class="absolute inset-0 pointer-events-none">
                <div class="absolute -top-24 right-0 h-64 w-64 rounded-full bg-amber-500/15 blur-3xl animate-blob"></div>
                <div class="absolute bottom-0 left-12 h-56 w-56 rounded-full bg-cyan-500/10 blur-3xl animate-blob animation-delay-2000"></div>
                <div class="absolute top-1/2 left-1/2 h-64 w-64 -translate-x-1/2 -translate-y-1/2 rounded-full bg-purple-500/10 blur-3xl animate-blob animation-delay-4000"></div>
                <div class="absolute inset-0 bg-[radial-gradient(circle_at_top_left,rgba(255,255,255,0.08),transparent_28%),linear-gradient(135deg,rgba(15,23,42,0.75),rgba(2,6,23,0.92))]"></div>
            </div>

            <div class="relative">
                <div class="flex flex-col gap-8 xl:flex-row xl:items-start xl:justify-between">
                    <div class="max-w-3xl">
                        <span class="inline-flex items-center gap-2 rounded-full border border-amber-300/20 bg-amber-400/10 px-3 py-1 text-[11px] font-semibold uppercase tracking-[0.22em] text-amber-200 shadow-[0_0_10px_rgba(251,191,36,0.1)]">
                            <span class="h-2 w-2 rounded-full bg-amber-300 animate-pulse"></span>
                            Signal command center
                        </span>
                        <h1 class="mt-4 text-3xl font-black tracking-tight text-white lg:text-5xl">Signals Matrix</h1>
                        <p class="mt-4 max-w-2xl text-sm leading-7 text-slate-300 lg:text-base">
                            Scan every tracked pair in one board, isolate the strongest directional setups, and move into pair-level analysis without losing market context.
                        </p>

                        <div class="mt-5 flex flex-wrap gap-2 text-xs text-slate-300">
                            <span class="rounded-full border border-white/10 bg-white/5 px-3 py-1.5">Generated {{ $generatedAt->format('M d, Y H:i') }}</span>
                            <span class="rounded-full border border-white/10 bg-white/5 px-3 py-1.5">{{ $positionLabel }}</span>
                            <span class="rounded-full border border-white/10 bg-white/5 px-3 py-1.5">{{ $pairCount }} pairs</span>
                            <span class="rounded-full border border-white/10 bg-white/5 px-3 py-1.5">{{ $strategyCount }} strategies</span>
                            <span class="rounded-full border border-white/10 bg-white/5 px-3 py-1.5">Cache {{ (int) round($matrixCacheSeconds / 60) }} min</span>
                        </div>
                    </div>

                    <div class="grid w-full gap-3 sm:grid-cols-3 xl:w-[420px]">
                        <div class="rounded-2xl border border-emerald-300/15 bg-emerald-500/10 p-4 transition duration-300 hover:bg-emerald-500/20 hover:scale-105">
                            <p class="text-[11px] uppercase tracking-[0.2em] text-emerald-100/70">Coverage</p>
                            <p class="mt-2 text-3xl font-black text-white">{{ $coverage }}%</p>
                            <p class="mt-2 text-xs text-emerald-100/75">{{ $liveSignalCount }} live cells out of {{ $totalCells }}</p>
                        </div>
                        <div class="rounded-2xl border border-sky-300/15 bg-sky-500/10 p-4 transition duration-300 hover:bg-sky-500/20 hover:scale-105">
                            <p class="text-[11px] uppercase tracking-[0.2em] text-sky-100/70">Market Tilt</p>
                            <p class="mt-2 text-2xl font-black text-white">{{ $marketTilt }}</p>
                            <p class="mt-2 text-xs text-sky-100/75">{{ $momentumLabel }}</p>
                        </div>
                        <div class="rounded-2xl border border-amber-300/15 bg-amber-500/10 p-4 transition duration-300 hover:bg-amber-500/20 hover:scale-105">
                            <p class="text-[11px] uppercase tracking-[0.2em] text-amber-100/70">Warm-Up</p>
                            <p class="mt-2 text-3xl font-black text-white">{{ $pendingPairs }}</p>
                            <p class="mt-2 text-xs text-amber-100/75">Pairs still filling the cache</p>
                        </div>
                    </div>
                </div>

                <div class="mt-8 grid gap-4 xl:grid-cols-[minmax(0,1fr)_360px] animate-slide-up" style="animation-delay: 100ms;">
                    <div class="rounded-[28px] border border-white/10 bg-slate-900/70 p-4 lg:p-5">
                        <div class="flex flex-col gap-4 xl:flex-row xl:items-end xl:justify-between">
                            <form method="GET" action="{{ route('admin.signals.all-pairs') }}" class="grid flex-1 gap-3 md:grid-cols-[minmax(0,240px)_auto_auto]">
                                <label class="block">
                                    <span class="mb-2 block text-[11px] font-semibold uppercase tracking-[0.22em] text-slate-400">Position context</span>
                                    <select id="position" name="position" onchange="this.form.submit()" class="w-full rounded-2xl border border-white/10 bg-slate-950/80 px-4 py-3 text-sm text-slate-100 outline-none transition focus:border-amber-300/50 focus:ring-2 focus:ring-amber-400/20">
                                        <option value="" @selected($positionValue === '')>All positions</option>
                                        <option value="long" @selected($positionValue === 'long')>Long / buy setups</option>
                                        <option value="short" @selected($positionValue === 'short')>Short / sell setups</option>
                                    </select>
                                </label>

                                <button type="submit" class="inline-flex items-center justify-center rounded-2xl bg-amber-400 px-4 py-3 text-xs font-bold uppercase tracking-[0.18em] text-slate-950 transition hover:bg-amber-300">
                                    Reload Matrix
                                </button>

                                <a href="{{ route('admin.signals', ['position' => ($position ?: null)]) }}" class="inline-flex items-center justify-center rounded-2xl border border-white/10 bg-white/5 px-4 py-3 text-xs font-bold uppercase tracking-[0.18em] text-slate-200 transition hover:border-white/20 hover:bg-white/10 hover:text-white">
                                    Single Pair View
                                </a>
                            </form>

                            <div class="rounded-2xl border border-white/10 bg-black/20 px-4 py-3 text-xs text-slate-300">
                                <div class="flex flex-wrap items-center gap-2">
                                    <span>Showing {{ $matrixPairCount }} matrix pairs</span>
                                    <span class="text-slate-500">|</span>
                                    <span>{{ $refreshedPairs }} refreshed this request</span>
                                    <span class="text-slate-500">|</span>
                                    <span>Budget {{ $pairRefreshBudget }}</span>
                                </div>
                            </div>
                        </div>

                        <div class="mt-4 grid gap-3 lg:grid-cols-[minmax(0,1fr)_auto]">
                            <label class="block">
                                <span class="mb-2 block text-[11px] font-semibold uppercase tracking-[0.22em] text-slate-400">Search pairs</span>
                                <input
                                    type="search"
                                    placeholder="Filter by pair code, market name, or bias..."
                                    class="w-full rounded-2xl border border-white/10 bg-slate-950/80 px-4 py-3 text-sm text-slate-100 outline-none transition placeholder:text-slate-500 focus:border-cyan-300/50 focus:ring-2 focus:ring-cyan-400/20"
                                    data-pair-search
                                >
                            </label>

                            <div class="flex items-end">
                                <button type="button" class="w-full rounded-2xl border border-white/10 bg-white/5 px-4 py-3 text-xs font-bold uppercase tracking-[0.18em] text-slate-300 transition hover:bg-white/10 hover:text-white lg:w-auto" data-clear-filters>
                                    Clear filters
                                </button>
                            </div>
                        </div>

                        <div class="mt-4 flex flex-wrap gap-2">
                            <button type="button" class="rounded-full border border-white/10 bg-white/5 px-3 py-2 text-[11px] font-semibold uppercase tracking-[0.18em] text-slate-300 transition" data-action-filter="all">
                                All {{ $totalCells }}
                            </button>
                            <button type="button" class="rounded-full border border-white/10 bg-white/5 px-3 py-2 text-[11px] font-semibold uppercase tracking-[0.18em] text-slate-300 transition" data-action-filter="opportunity">
                                Opportunity {{ (int) ($actionTotals['BUY'] ?? 0) + (int) ($actionTotals['SELL'] ?? 0) + (int) ($actionTotals['CLOSE'] ?? 0) }}
                            </button>
                            <button type="button" class="rounded-full border border-white/10 bg-white/5 px-3 py-2 text-[11px] font-semibold uppercase tracking-[0.18em] text-slate-300 transition" data-action-filter="buy">
                                Buy {{ $actionTotals['BUY'] ?? 0 }}
                            </button>
                            <button type="button" class="rounded-full border border-white/10 bg-white/5 px-3 py-2 text-[11px] font-semibold uppercase tracking-[0.18em] text-slate-300 transition" data-action-filter="sell">
                                Sell {{ $actionTotals['SELL'] ?? 0 }}
                            </button>
                            <button type="button" class="rounded-full border border-white/10 bg-white/5 px-3 py-2 text-[11px] font-semibold uppercase tracking-[0.18em] text-slate-300 transition" data-action-filter="hold">
                                Hold {{ $actionTotals['HOLD'] ?? 0 }}
                            </button>
                            <button type="button" class="rounded-full border border-white/10 bg-white/5 px-3 py-2 text-[11px] font-semibold uppercase tracking-[0.18em] text-slate-300 transition" data-action-filter="close">
                                Close {{ $actionTotals['CLOSE'] ?? 0 }}
                            </button>
                            <button type="button" class="rounded-full border border-white/10 bg-white/5 px-3 py-2 text-[11px] font-semibold uppercase tracking-[0.18em] text-slate-300 transition" data-action-filter="pending">
                                Pending {{ $actionTotals['NO_DATA'] ?? 0 }}
                            </button>
                            <button type="button" class="rounded-full border border-white/10 bg-white/5 px-3 py-2 text-[11px] font-semibold uppercase tracking-[0.18em] text-slate-300 transition" data-action-filter="live">
                                Live {{ $liveSignalCount }}
                            </button>
                        </div>
                    </div>

                    <div class="rounded-[28px] border border-white/10 bg-slate-900/70 p-4 lg:p-5">
                        <div class="flex items-center justify-between gap-3">
                            <div>
                                <p class="text-[11px] font-semibold uppercase tracking-[0.22em] text-slate-400">How to read this board</p>
                                <p class="mt-2 text-sm text-slate-300">Each pair groups all strategy calls so you can judge alignment before opening the deeper signal view.</p>
                            </div>
                            <span class="rounded-full border border-white/10 bg-white/5 px-3 py-1 text-[11px] font-semibold uppercase tracking-[0.18em] text-slate-300" data-filter-count>
                                {{ $pairCount }} pairs visible
                            </span>
                        </div>

                        <div class="mt-4 flex flex-wrap gap-2 text-[11px]">
                            <span class="rounded-full border border-emerald-300/20 bg-emerald-400/10 px-3 py-1.5 text-emerald-100">Buy = bullish entry trigger</span>
                            <span class="rounded-full border border-rose-300/20 bg-rose-400/10 px-3 py-1.5 text-rose-100">Sell = bearish entry trigger</span>
                            <span class="rounded-full border border-sky-300/20 bg-sky-400/10 px-3 py-1.5 text-sky-100">Hold = wait / manage</span>
                            <span class="rounded-full border border-amber-300/20 bg-amber-400/10 px-3 py-1.5 text-amber-100">Close = exit bias</span>
                            <span class="rounded-full border border-white/10 bg-white/5 px-3 py-1.5 text-slate-300">Pending = cache warm-up</span>
                        </div>

                        <div class="matrix-scrollbar mt-4 overflow-x-auto pb-1">
                            <div class="flex min-w-max gap-2">
                                @foreach($strategies as $strategy)
                                    <span class="inline-flex items-center gap-2 rounded-full border border-white/10 bg-white/5 px-3 py-1.5 text-xs text-slate-200">
                                        <span class="inline-flex h-6 w-6 items-center justify-center rounded-full bg-white/10 text-[10px] font-black text-white">{{ strtoupper(substr($strategy->name, 0, 2)) }}</span>
                                        {{ $strategy->name }}
                                    </span>
                                @endforeach
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <section class="grid gap-4 md:grid-cols-2 xl:grid-cols-4 animate-slide-up" style="animation-delay: 200ms;">
            <div class="rounded-[28px] border border-white/10 bg-slate-950/70 p-5 transition duration-300 hover:border-white/20 hover:bg-slate-900/80">
                <p class="text-[11px] font-semibold uppercase tracking-[0.22em] text-slate-400">Opportunity Pairs</p>
                <div class="mt-3 flex items-end justify-between gap-3">
                    <p class="text-4xl font-black text-white">{{ $opportunityPairCount }}</p>
                    <p class="max-w-[10rem] text-right text-xs text-slate-400">Pairs with at least one buy, sell, or close decision.</p>
                </div>
            </div>

            <div class="rounded-[28px] border border-white/10 bg-slate-950/70 p-5 transition duration-300 hover:border-white/20 hover:bg-slate-900/80">
                <p class="text-[11px] font-semibold uppercase tracking-[0.22em] text-slate-400">Fresh Signals</p>
                <div class="mt-3 flex items-end justify-between gap-3">
                    <p class="text-4xl font-black text-white">{{ $freshSignalCount }}</p>
                    <p class="max-w-[10rem] text-right text-xs text-slate-400">Updated inside the last 4 hours.</p>
                </div>
            </div>

            <div class="rounded-[28px] border border-white/10 bg-slate-950/70 p-5 transition duration-300 hover:border-white/20 hover:bg-slate-900/80">
                <p class="text-[11px] font-semibold uppercase tracking-[0.22em] text-slate-400">Mixed Consensus</p>
                <div class="mt-3 flex items-end justify-between gap-3">
                    <p class="text-4xl font-black text-white">{{ $mixedPairCount }}</p>
                    <p class="max-w-[10rem] text-right text-xs text-slate-400">Pairs where strategies disagree at the top level.</p>
                </div>
            </div>

            <div class="rounded-[28px] border border-white/10 bg-slate-950/70 p-5 transition duration-300 hover:border-white/20 hover:bg-slate-900/80">
                <p class="text-[11px] font-semibold uppercase tracking-[0.22em] text-slate-400">Trend Mix</p>
                <div class="mt-3 grid grid-cols-3 gap-2 text-center text-xs">
                    <div class="rounded-2xl border border-emerald-300/15 bg-emerald-500/10 px-2 py-3 text-emerald-100">
                        <div class="text-2xl font-black">{{ $bullishTrendCount }}</div>
                        <div class="mt-1 text-[10px] uppercase tracking-[0.18em] text-emerald-100/70">Bull</div>
                    </div>
                    <div class="rounded-2xl border border-rose-300/15 bg-rose-500/10 px-2 py-3 text-rose-100">
                        <div class="text-2xl font-black">{{ $bearishTrendCount }}</div>
                        <div class="mt-1 text-[10px] uppercase tracking-[0.18em] text-rose-100/70">Bear</div>
                    </div>
                    <div class="rounded-2xl border border-slate-300/10 bg-white/5 px-2 py-3 text-slate-100">
                        <div class="text-2xl font-black">{{ $neutralTrendCount }}</div>
                        <div class="mt-1 text-[10px] uppercase tracking-[0.18em] text-slate-400">Neutral</div>
                    </div>
                </div>
            </div>
        </section>

        @if($pendingPairs > 0)
            <section class="rounded-[28px] border border-amber-300/20 bg-amber-500/10 p-4 lg:p-5 animate-slide-up" style="animation-delay: 300ms;">
                <div class="flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
                    <div class="flex items-start gap-3">
                        <span class="mt-0.5 inline-flex h-3 w-3 rounded-full bg-amber-300 shadow-[0_0_0_8px_rgba(251,191,36,0.12)]"></span>
                        <div>
                            <p class="text-sm font-semibold text-amber-100">Matrix cache is still warming up.</p>
                            <p class="mt-1 text-sm text-amber-50/80">
                                {{ $pendingPairs }} pairs have placeholder cells to stay within your API quota. Reload after the next cache window for fuller coverage.
                            </p>
                        </div>
                    </div>
                    <div class="rounded-2xl border border-amber-200/20 bg-black/10 px-4 py-2 text-xs text-amber-50/80">
                        Refresh budget: {{ $pairRefreshBudget }} pairs per request
                    </div>
                </div>
            </section>
        @endif

        <section class="space-y-4">
            @forelse($pairs as $pairCode => $pairItem)
                @php
                    $pairSummary = $pairSummaries[$pairCode] ?? [];
                    $dominantAction = $pairSummary['dominant_action'] ?? 'NO_DATA';
                    $dominantStyle = $actionStyles[$dominantAction] ?? $actionStyles['NO_DATA'];
                    $latestTimestamp = $pairSummary['latest_timestamp'] ?? null;
                    $latestLabel = $latestTimestamp ? $latestTimestamp->diffForHumans() : 'Awaiting live data';
                    $searchableText = strtolower(implode(' ', [
                        $pairCode,
                        $pairItem['display'] ?? $pairCode,
                        $pairItem['name'] ?? $pairCode,
                        $pairItem['asset_class'] ?? 'market',
                        $dominantAction,
                        $pairSummary['trend_label'] ?? '',
                    ]));
                    $delay = min(1000, 300 + ($loop->index * 50));
                @endphp

                <article class="pair-row rounded-[30px] border border-white/10 bg-slate-950/70 shadow-xl shadow-black/20 transition duration-200 hover:border-white/20 hover:shadow-2xl hover:shadow-black/40 animate-slide-up" style="animation-delay: {{ $delay }}ms;" data-pair-row data-search="{{ $searchableText }}">
                    <div class="grid gap-px rounded-[30px] bg-white/5 xl:grid-cols-[320px_minmax(0,1fr)]">
                        <div class="relative overflow-hidden rounded-t-[30px] bg-slate-950/95 p-5 xl:rounded-l-[30px] xl:rounded-tr-none lg:p-6">
                            <div class="absolute inset-0 bg-[radial-gradient(circle_at_top_right,rgba(251,191,36,0.14),transparent_30%),linear-gradient(180deg,rgba(15,23,42,0.75),rgba(2,6,23,0.92))]"></div>
                            <div class="relative">
                                <div class="flex items-start justify-between gap-3">
                                    <div>
                                        <div class="flex items-center gap-2">
                                            <h2 class="text-2xl font-black tracking-tight text-white">{{ $pairItem['display'] ?? $pairCode }}</h2>
                                            <span class="rounded-full border border-white/10 bg-white/5 px-2 py-1 text-[10px] font-semibold uppercase tracking-[0.18em] text-slate-300">
                                                {{ $pairItem['asset_class'] ?? 'Market' }}
                                            </span>
                                        </div>
                                        <p class="mt-2 text-sm text-slate-400">{{ $pairItem['name'] ?? $pairCode }}</p>
                                    </div>

                                    <span class="inline-flex items-center gap-2 rounded-full border px-3 py-1.5 text-[11px] font-semibold uppercase tracking-[0.2em] {{ $dominantStyle['badge'] }}">
                                        <span class="h-2.5 w-2.5 rounded-full {{ $dominantStyle['dot'] }}"></span>
                                        {{ $dominantAction === 'NO_DATA' ? 'Pending' : $dominantAction }}
                                    </span>
                                </div>

                                <div class="mt-5 grid grid-cols-2 gap-3 text-sm">
                                    <div class="rounded-2xl border border-white/10 bg-white/5 p-3">
                                        <p class="text-[10px] font-semibold uppercase tracking-[0.18em] text-slate-500">Consensus</p>
                                        <p class="mt-2 text-lg font-bold text-white">
                                            @if($dominantAction === 'MIXED')
                                                Split board
                                            @elseif($dominantAction === 'NO_DATA')
                                                Waiting
                                            @else
                                                {{ $pairSummary['consensus_count'] ?? 0 }} / {{ $strategyCount }}
                                            @endif
                                        </p>
                                    </div>
                                    <div class="rounded-2xl border border-white/10 bg-white/5 p-3">
                                        <p class="text-[10px] font-semibold uppercase tracking-[0.18em] text-slate-500">Latest update</p>
                                        <p class="mt-2 text-lg font-bold text-white">{{ $latestLabel }}</p>
                                    </div>
                                </div>

                                <div class="mt-5 grid grid-cols-2 gap-2 text-xs">
                                    <div class="rounded-2xl border border-emerald-300/15 bg-emerald-500/10 px-3 py-2 text-emerald-100">
                                        <div class="font-semibold uppercase tracking-[0.18em] text-emerald-100/70">Buy</div>
                                        <div class="mt-1 text-lg font-black">{{ $pairSummary['action_counts']['BUY'] ?? 0 }}</div>
                                    </div>
                                    <div class="rounded-2xl border border-rose-300/15 bg-rose-500/10 px-3 py-2 text-rose-100">
                                        <div class="font-semibold uppercase tracking-[0.18em] text-rose-100/70">Sell</div>
                                        <div class="mt-1 text-lg font-black">{{ $pairSummary['action_counts']['SELL'] ?? 0 }}</div>
                                    </div>
                                    <div class="rounded-2xl border border-sky-300/15 bg-sky-500/10 px-3 py-2 text-sky-100">
                                        <div class="font-semibold uppercase tracking-[0.18em] text-sky-100/70">Hold</div>
                                        <div class="mt-1 text-lg font-black">{{ $pairSummary['action_counts']['HOLD'] ?? 0 }}</div>
                                    </div>
                                    <div class="rounded-2xl border border-amber-300/15 bg-amber-500/10 px-3 py-2 text-amber-100">
                                        <div class="font-semibold uppercase tracking-[0.18em] text-amber-100/70">Close</div>
                                        <div class="mt-1 text-lg font-black">{{ $pairSummary['action_counts']['CLOSE'] ?? 0 }}</div>
                                    </div>
                                </div>

                                <div class="mt-5 flex flex-wrap gap-2 text-[11px] text-slate-300">
                                    <span class="rounded-full border border-white/10 bg-white/5 px-3 py-1.5">{{ $pairSummary['trend_label'] ?? 'Awaiting live trends' }}</span>
                                    <span class="rounded-full border border-white/10 bg-white/5 px-3 py-1.5">{{ $pairSummary['opportunity_count'] ?? 0 }} tactical signals</span>
                                    <span class="rounded-full border border-white/10 bg-white/5 px-3 py-1.5" data-visible-cards>{{ $strategyCount }} / {{ $strategyCount }} cards shown</span>
                                </div>
                            </div>
                        </div>

                        <div class="matrix-scrollbar overflow-x-auto rounded-b-[30px] bg-slate-950/70 p-4 lg:p-5 xl:rounded-r-[30px] xl:rounded-bl-none">
                            <div class="grid min-w-full gap-3 md:grid-cols-2 2xl:grid-cols-3">
                                @foreach($strategies as $strategy)
                                    @php
                                        $signal = $matrix[$pairCode][$strategy->id] ?? null;
                                        $action = strtoupper((string) ($signal['action'] ?? 'NO_DATA'));
                                        $style = $actionStyles[$action] ?? $actionStyles['NO_DATA'];
                                        $trend = strtoupper((string) ($signal['trend'] ?? 'UNKNOWN'));
                                        $trendLabel = $trend === '' ? 'UNKNOWN' : $trend;
                                        $plan = $signal['trade_plan'] ?? [];
                                        $timestamp = null;
                                        if (! empty($signal['timestamp'])) {
                                            try {
                                                $timestamp = \Illuminate\Support\Carbon::parse($signal['timestamp']);
                                            } catch (\Throwable $exception) {
                                                $timestamp = null;
                                            }
                                        }
                                        $isFresh = $timestamp !== null && $timestamp->greaterThan($spotlightWindow);
                                        $priceValue = isset($signal['price']) && (float) $signal['price'] > 0 ? '$'.number_format((float) $signal['price'], 4) : '--';
                                        $entryValue = isset($plan['entry_price']) && $plan['entry_price'] !== null ? '$'.number_format((float) $plan['entry_price'], 4) : 'N/A';
                                        $stopLossValue = isset($plan['stop_loss']) && $plan['stop_loss'] !== null ? '$'.number_format((float) $plan['stop_loss'], 4) : 'N/A';
                                        $takeProfitValue = isset($plan['take_profit_1']) && $plan['take_profit_1'] !== null ? '$'.number_format((float) $plan['take_profit_1'], 4) : 'N/A';
                                    @endphp

                                    <section class="rounded-[26px] border p-4 transition duration-300 lg:p-5 {{ $style['surface'] }} {{ $style['hover'] ?? '' }}" data-signal-card data-action="{{ strtolower($action) }}">
                                        <div class="flex items-start justify-between gap-3">
                                            <div class="flex min-w-0 items-center gap-3">
                                                <span class="inline-flex h-10 w-10 shrink-0 items-center justify-center rounded-2xl border border-white/10 bg-black/20 text-xs font-black text-white">
                                                    {{ strtoupper(substr($strategy->name, 0, 2)) }}
                                                </span>
                                                <div class="min-w-0">
                                                    <h3 class="truncate text-sm font-bold text-white">{{ $strategy->name }}</h3>
                                                    <p class="truncate text-[11px] uppercase tracking-[0.18em] text-slate-400">{{ $strategy->slug }}</p>
                                                </div>
                                            </div>

                                            <div class="flex flex-col items-end gap-2 text-right">
                                                <span class="inline-flex items-center gap-2 rounded-full border px-3 py-1 text-[11px] font-semibold uppercase tracking-[0.18em] {{ $style['badge'] }}">
                                                    <span class="h-2.5 w-2.5 rounded-full {{ $style['dot'] }}"></span>
                                                    {{ $action === 'NO_DATA' ? 'Pending' : $action }}
                                                </span>
                                                @if($isFresh)
                                                    <span class="rounded-full border border-cyan-300/20 bg-cyan-400/10 px-2.5 py-1 text-[10px] font-semibold uppercase tracking-[0.18em] text-cyan-100 animate-pulse">
                                                        Fresh
                                                    </span>
                                                @endif
                                            </div>
                                        </div>

                                        <div class="mt-4 grid grid-cols-2 gap-3 text-sm">
                                            <div class="rounded-2xl border border-white/10 bg-black/20 p-3">
                                                <p class="text-[10px] font-semibold uppercase tracking-[0.18em] text-slate-500">Price</p>
                                                <p class="mt-2 text-lg font-bold text-white">{{ $priceValue }}</p>
                                            </div>
                                            <div class="rounded-2xl border border-white/10 bg-black/20 p-3">
                                                <p class="text-[10px] font-semibold uppercase tracking-[0.18em] text-slate-500">Trend</p>
                                                <p class="mt-2 text-lg font-bold {{ $style['text'] }}">{{ $trendLabel }}</p>
                                            </div>
                                        </div>

                                        <div class="mt-3 grid grid-cols-3 gap-2 text-xs">
                                            <div class="rounded-2xl border border-white/10 bg-black/20 px-3 py-2">
                                                <div class="text-[10px] uppercase tracking-[0.18em] text-slate-500">Entry</div>
                                                <div class="mt-1 font-semibold text-slate-200">{{ $entryValue }}</div>
                                            </div>
                                            <div class="rounded-2xl border border-white/10 bg-black/20 px-3 py-2">
                                                <div class="text-[10px] uppercase tracking-[0.18em] text-slate-500">SL</div>
                                                <div class="mt-1 font-semibold text-slate-200">{{ $stopLossValue }}</div>
                                            </div>
                                            <div class="rounded-2xl border border-white/10 bg-black/20 px-3 py-2">
                                                <div class="text-[10px] uppercase tracking-[0.18em] text-slate-500">TP1</div>
                                                <div class="mt-1 font-semibold text-slate-200">{{ $takeProfitValue }}</div>
                                            </div>
                                        </div>

                                        <div class="mt-4 rounded-2xl border border-white/10 bg-black/20 p-3">
                                            <div class="flex items-center justify-between gap-3 text-[11px] text-slate-400">
                                                <span class="uppercase tracking-[0.18em]">Last update</span>
                                                <span>{{ $timestamp ? $timestamp->format('M d, H:i') : 'Pending' }}</span>
                                            </div>
                                            <p class="signal-copy-clamp mt-3 text-sm leading-6 text-slate-300">
                                                {{ $signal['message'] ?? 'Waiting for the next matrix refresh to populate this strategy card.' }}
                                            </p>
                                        </div>

                                        <div class="mt-4 flex items-center gap-2">
                                            <a
                                                href="{{ route('signals.show', ['strategy' => $strategy->slug, 'pair' => $pairCode, 'position' => ($position ?: null)]) }}"
                                                class="inline-flex flex-1 items-center justify-center rounded-2xl bg-white/10 px-4 py-2.5 text-[11px] font-bold uppercase tracking-[0.18em] text-white transition hover:bg-white/15"
                                            >
                                                Open analysis
                                            </a>
                                            <a
                                                href="{{ route('signals.latest', ['strategy' => $strategy->slug, 'pair' => $pairCode, 'position' => ($position ?: null)]) }}"
                                                class="inline-flex items-center justify-center rounded-2xl border border-white/10 bg-black/20 px-4 py-2.5 text-[11px] font-bold uppercase tracking-[0.18em] text-slate-300 transition hover:border-white/20 hover:text-white"
                                            >
                                                JSON
                                            </a>
                                        </div>
                                    </section>
                                @endforeach
                            </div>
                        </div>
                    </div>
                </article>
            @empty
                <div class="rounded-[30px] border border-white/10 bg-slate-950/70 p-8 text-center">
                    <p class="text-lg font-semibold text-white">No pairs are configured for the matrix yet.</p>
                    <p class="mt-2 text-sm text-slate-400">Add matrix pair codes in the trading service configuration to populate this board.</p>
                </div>
            @endforelse

            <div class="hidden rounded-[30px] border border-white/10 bg-slate-950/70 p-8 text-center" data-empty-state>
                <p class="text-lg font-semibold text-white">No pairs match the current filters.</p>
                <p class="mt-2 text-sm text-slate-400">Clear the search or switch the action filter to broaden the board.</p>
            </div>
        </section>
    </div>

    @once
        <script>
            document.addEventListener('DOMContentLoaded', () => {
                const page = document.querySelector('[data-signals-matrix]');
                if (!page) {
                    return;
                }

                const searchInput = page.querySelector('[data-pair-search]');
                const clearButton = page.querySelector('[data-clear-filters]');
                const resultCount = page.querySelector('[data-filter-count]');
                const emptyState = page.querySelector('[data-empty-state]');
                const filterButtons = Array.from(page.querySelectorAll('[data-action-filter]'));
                const rows = Array.from(page.querySelectorAll('[data-pair-row]'));
                let activeAction = 'all';

                const buttonStates = {
                    active: ['bg-amber-400', 'text-slate-950', 'border-amber-300/60', 'shadow-lg', 'shadow-amber-500/20'],
                    inactive: ['bg-white/5', 'text-slate-300', 'border-white/10'],
                };

                const setButtonState = () => {
                    filterButtons.forEach((button) => {
                        const isActive = button.dataset.actionFilter === activeAction;
                        button.setAttribute('aria-pressed', isActive ? 'true' : 'false');

                        buttonStates.active.forEach((className) => button.classList.toggle(className, isActive));
                        buttonStates.inactive.forEach((className) => button.classList.toggle(className, !isActive));
                    });
                };

                const matchesAction = (action) => {
                    if (activeAction === 'all') {
                        return true;
                    }

                    if (activeAction === 'live') {
                        return action !== 'no_data';
                    }

                    if (activeAction === 'opportunity') {
                        return ['buy', 'sell', 'close'].includes(action);
                    }

                    if (activeAction === 'pending') {
                        return action === 'no_data';
                    }

                    return action === activeAction;
                };

                const applyFilters = () => {
                    const query = (searchInput?.value || '').trim().toLowerCase();
                    let visibleRows = 0;

                    rows.forEach((row) => {
                        const cards = Array.from(row.querySelectorAll('[data-signal-card]'));
                        let visibleCards = 0;

                        cards.forEach((card) => {
                            const cardAction = card.dataset.action || 'no_data';
                            const visible = matchesAction(cardAction);
                            card.classList.toggle('hidden', !visible);
                            if (visible) {
                                visibleCards += 1;
                            }
                        });

                        const matchesSearch = (row.dataset.search || '').includes(query);
                        const rowVisible = matchesSearch && visibleCards > 0;
                        row.classList.toggle('hidden', !rowVisible);

                        const visibleCardsLabel = row.querySelector('[data-visible-cards]');
                        if (visibleCardsLabel) {
                            visibleCardsLabel.textContent = `${visibleCards} / ${cards.length} cards shown`;
                        }

                        if (rowVisible) {
                            visibleRows += 1;
                        }
                    });

                    if (resultCount) {
                        resultCount.textContent = `${visibleRows} ${visibleRows === 1 ? 'pair' : 'pairs'} visible`;
                    }

                    if (emptyState) {
                        emptyState.classList.toggle('hidden', visibleRows !== 0 || rows.length === 0);
                    }
                };

                filterButtons.forEach((button) => {
                    button.addEventListener('click', () => {
                        activeAction = button.dataset.actionFilter || 'all';
                        setButtonState();
                        applyFilters();
                    });
                });

                searchInput?.addEventListener('input', applyFilters);

                clearButton?.addEventListener('click', () => {
                    if (searchInput) {
                        searchInput.value = '';
                    }

                    activeAction = 'all';
                    setButtonState();
                    applyFilters();
                });

                setButtonState();
                applyFilters();
            });
        </script>
    @endonce
</x-admin-layout>
