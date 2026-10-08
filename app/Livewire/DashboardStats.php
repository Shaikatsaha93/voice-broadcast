<?php

namespace App\Livewire;

use App\Models\CallAttempt;
use App\Models\Campaign;
use App\Models\Did;
use App\Models\DidSlot;
use App\Models\User;
use App\Support\Money;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

class DashboardStats extends Component
{
    public function render()
    {
        $user = Auth::user();
        $admin = $user->isManager();
        $super = $user->isSuperAdmin();

        $calls = CallAttempt::visibleTo($user)->whereDate('call_attempts.created_at', today());
        $today = [
            'Answered' => (clone $calls)->whereNotNull('answered_at')->count(),
            'No Answer' => (clone $calls)->where('status', 'NO_ANSWER')->count(),
            'Busy' => (clone $calls)->where('status', 'BUSY')->count(),
            'Failed' => (clone $calls)->whereIn('status', ['FAILED', 'TEMPORARY_FAILURE', 'INVALID_NUMBER'])->count(),
        ];

        $campaigns = Campaign::visibleTo($user);
        $didQuery = match (true) {
            $super => Did::query(),
            $admin => Did::visibleTo($user),
            default => $user->dids(),
        };
        $dids = $didQuery->withCount('slots as active_calls')->orderBy('id')->get()
            ->map(fn ($d) => [
                'number' => $d->number, 'active' => $d->active_calls, 'max' => $d->max_concurrent_calls, 'free' => max(0, $d->max_concurrent_calls - $d->active_calls),
                'balance' => (float) $d->balance, 'rate' => (float) $d->rate_per_pulse, 'pulse' => $d->pulse_seconds, 'can_call' => $d->canAffordAnotherCall(),
            ]);
        $balance = Money::tk($dids->sum('balance'));

        $cards = $admin
            ? ['Total users' => $super ? User::count() : $user->managedUserIds()->count(), 'Total DIDs' => (clone $didQuery)->count(), 'Active DIDs' => (clone $didQuery)->where('status', 'active')->count(),
                'Pending campaigns' => (clone $campaigns)->where('status', 'PENDING_APPROVAL')->count(), 'Running campaigns' => (clone $campaigns)->where('status', 'RUNNING')->count(),
                'Active calls' => $super ? DidSlot::count() : DidSlot::whereIn('call_attempt_id', CallAttempt::visibleTo($user)->select('call_attempts.id'))->count(), "Today's calls" => (clone $calls)->count()]
            : ['Assigned DIDs' => $dids->count(), 'DID balance' => $balance, 'My campaigns' => (clone $campaigns)->count(), 'Pending approval' => (clone $campaigns)->where('status', 'PENDING_APPROVAL')->count(),
                'Running' => (clone $campaigns)->where('status', 'RUNNING')->count(), 'Completed' => (clone $campaigns)->where('status', 'COMPLETED')->count()];

        return view('livewire.dashboard-stats', compact('cards', 'today', 'dids'));
    }
}
