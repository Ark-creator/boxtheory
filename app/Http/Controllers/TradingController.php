<?php

namespace App\Http\Controllers;

use App\Models\Strategy;
use App\Services\TradingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TradingController extends Controller
{
    public function show(Strategy $strategy, Request $request, TradingService $service)
    {
        $hasAccess = auth()->user()->hasAccessTo($strategy->id);

        if (!$hasAccess) {
            return redirect()->route('strategies.index')
                ->with('error', 'Subscription required or expired. Please upload a new receipt.');
        }

        $position = $request->query('position');
        $pair = $service->normalizePairCode($request->query('pair'));
        $signal = $service->getSignalForStrategy($strategy->slug, $position, $pair);

        return view('signals.show', [
            'strategy' => $strategy,
            'signal' => $signal,
            'position' => $position,
            'pair' => $pair,
            'pairs' => $service->getAvailablePairs(),
        ]);
    }

    public function latest(Strategy $strategy, Request $request, TradingService $service): JsonResponse
    {
        $hasAccess = auth()->user()->hasAccessTo($strategy->id);

        if (!$hasAccess) {
            return response()->json([
                'message' => 'Subscription required or expired.',
            ], 403);
        }

        $pair = $service->normalizePairCode($request->query('pair'));
        $signal = $service->getSignalForStrategy($strategy->slug, $request->query('position'), $pair);

        return response()->json($signal);
    }
}
