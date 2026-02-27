<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Strategy;
use App\Models\User;
use App\Services\SignalAlertService;
use App\Services\TradingService;
use Illuminate\Http\Request;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class AdminController extends Controller
{
    public function dashboard(): View
    {
        $now = now();

        $summary = [
            'total_users' => User::count(),
            'total_admins' => User::where('role', 'admin')->count(),
            'total_strategies' => Strategy::count(),
            'total_subscribers' => DB::table('strategy_user')->count(),
            'active_subscribers' => DB::table('strategy_user')
                ->where('status', 'active')
                ->where('expires_at', '>', $now)
                ->count(),
            'pending_subscribers' => DB::table('strategy_user')
                ->where('status', 'pending')
                ->count(),
            'expired_subscribers' => DB::table('strategy_user')
                ->where('status', 'active')
                ->whereNotNull('expires_at')
                ->where('expires_at', '<=', $now)
                ->count(),
        ];

        $recentSubscribers = DB::table('strategy_user')
            ->join('users', 'strategy_user.user_id', '=', 'users.id')
            ->join('strategies', 'strategy_user.strategy_id', '=', 'strategies.id')
            ->select(
                'strategy_user.id',
                'users.name as user_name',
                'users.email as user_email',
                'strategies.name as strategy_name',
                'strategy_user.status',
                'strategy_user.expires_at',
                'strategy_user.created_at'
            )
            ->orderByDesc('strategy_user.created_at')
            ->limit(8)
            ->get();

        return view('admin.dashboard', [
            'summary' => $summary,
            'recentSubscribers' => $recentSubscribers,
        ]);
    }

    public function strategies(): View
    {
        $now = now();

        $strategies = Strategy::query()
            ->withCount('users')
            ->withCount([
                'users as active_subscribers_count' => function ($query) use ($now) {
                    $query->wherePivot('status', 'active')
                        ->wherePivot('expires_at', '>', $now);
                },
            ])
            ->withCount([
                'users as pending_subscribers_count' => function ($query) {
                    $query->wherePivot('status', 'pending');
                },
            ])
            ->orderBy('name')
            ->get();

        return view('admin.strategies', [
            'strategies' => $strategies,
        ]);
    }

    public function signals(Request $request, TradingService $service): View
    {
        $position = $request->query('position');
        $strategies = Strategy::query()->orderBy('name')->get();

        $signals = [];
        foreach ($strategies as $strategy) {
            $signals[$strategy->id] = $service->getSignalForStrategy($strategy->slug, $position);
        }

        return view('admin.signals', [
            'strategies' => $strategies,
            'signals' => $signals,
            'position' => $position,
        ]);
    }

    public function sendSignalsNow(SignalAlertService $service): RedirectResponse
    {
        $result = $service->dispatch(force: true);

        $message = sprintf(
            'Manual signal email run complete. strategies=%d sent=%d skipped=%d recipients=%d',
            $result['strategies'],
            $result['sent'],
            $result['skipped'],
            $result['recipients']
        );

        $redirect = back()->with('success', $message);

        if (!empty($result['errors'])) {
            $preview = array_slice($result['errors'], 0, 2);
            $suffix = count($result['errors']) > 2 ? ' (plus more errors)' : '';
            $redirect = $redirect->with('error', implode(' | ', $preview) . $suffix);
        }

        return $redirect;
    }

    public function subscribers(Request $request): View
    {
        $status = $request->query('status');

        $subscribers = DB::table('strategy_user')
            ->join('users', 'strategy_user.user_id', '=', 'users.id')
            ->join('strategies', 'strategy_user.strategy_id', '=', 'strategies.id')
            ->select(
                'strategy_user.id',
                'users.name as user_name',
                'users.email as user_email',
                'strategies.name as strategy_name',
                'strategy_user.status',
                'strategy_user.expires_at',
                'strategy_user.created_at'
            )
            ->when($status, function ($query, $status) {
                $query->where('strategy_user.status', $status);
            })
            ->orderByDesc('strategy_user.created_at')
            ->paginate(20)
            ->withQueryString();

        return view('admin.subscribers', [
            'subscribers' => $subscribers,
            'status' => $status,
        ]);
    }

    public function users(): View
    {
        $users = User::query()
            ->withCount('strategies')
            ->orderByDesc('created_at')
            ->paginate(20);

        return view('admin.users', [
            'users' => $users,
        ]);
    }
}
