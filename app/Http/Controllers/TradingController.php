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
        $signal = $service->getSignalForStrategy($strategy->slug, $position);

        return view('signals.show', [
            'strategy' => $strategy,
            'signal' => $signal,
            'position' => $position,
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

        $signal = $service->getSignalForStrategy($strategy->slug, $request->query('position'));

        return response()->json($signal);
    }
}
