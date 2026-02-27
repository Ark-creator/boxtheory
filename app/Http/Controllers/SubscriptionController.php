<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class SubscriptionController extends Controller
{
    
public function store(Request $request)
{
    $request->validate([
        'strategy_id' => 'required|exists:strategies,id',
        'receipt' => 'required|image|max:2048',
    ]);

    $path = $request->file('receipt')->store('receipts', 'private');

    // Attach strategy to user with 'pending' status
    auth()->user()->strategies()->attach($request->strategy_id, [
        'receipt_path' => $path,
        'status' => 'pending'
    ]);

    return back()->with('success', 'Receipt uploaded! Awaiting approval.');
}//
public function approve($id)
{
    DB::table('strategy_user')->where('id', $id)->update([
        'status' => 'active',
        'expires_at' => now()->addDays(30),
        'updated_at' => now(),
    ]);

    return back()->with('success', 'User approved for 30 days.');
}

public function reject($id)
{
    DB::table('strategy_user')->where('id', $id)->update([
        'status' => 'rejected',
        'expires_at' => null,
        'updated_at' => now(),
    ]);

    return back()->with('success', 'Subscription was rejected.');
}

public function pendingApprovals()
{
    $pending = DB::table('strategy_user')
        ->join('users', 'strategy_user.user_id', '=', 'users.id')
        ->join('strategies', 'strategy_user.strategy_id', '=', 'strategies.id')
        ->select(
            'strategy_user.*',
            'users.name as user_name',
            'users.email as user_email',
            'strategies.name as strategy_name'
        )
        ->where('strategy_user.status', 'pending')
        ->orderByDesc('strategy_user.created_at')
        ->paginate(20);

    return view('admin.approvals', compact('pending'));
}

public function viewReceipt($filename)
{
    return Storage::disk('private')->response("receipts/{$filename}");
}

}
