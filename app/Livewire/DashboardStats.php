<?php

namespace App\Livewire;

use App\Models\CallAttempt;
use App\Models\Campaign;
use App\Models\Did;
use App\Models\DidSlot;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

class DashboardStats extends Component
{
    public function render()
    {
        $user = Auth::user();
        $admin = $user->isSuperAdmin();

        $calls = CallAttempt::visibleTo($user)->whereDate('call_attempts.created_at', today());
        $today = [
            'Answered' => (clone $calls)->whereNotNull('answered_at')->count(),
            'No Answer' => (clone $calls)->where('status', 'NO_ANSWER')->count(),
            'Busy' => (clone $calls)->where('status', 'BUSY')->count(),
            'Failed' => (clone $calls)->whereIn('status', ['FAILED', 'TEMPORARY_FAILURE', 'INVALID_NUMBER'])->count(),
        ];

        $campaigns = Campaign::visibleTo($user);
        $didQuery = $admin ? Did::query() : $user->dids();
        $dids = $didQuery->withCount('slots as active_calls')->orderBy('id')->get()
            ->map(fn ($d) => ['number' => $d->number, 'active' => $d->active_calls, 'max' => $d->max_concurrent_calls, 'free' => max(0, $d->max_concurrent_calls - $d->active_calls)]);

        $cards = $admin
            ? ['Total users' => User::count(), 'Total DIDs' => Did::count(), 'Active DIDs' => Did::where('status', 'active')->count(),
                'Pending campaigns' => (clone $campaigns)->where('status', 'PENDING_APPROVAL')->count(), 'Running campaigns' => (clone $campaigns)->where('status', 'RUNNING')->count(),
                'Active calls' => DidSlot::count(), "Today's calls" => (clone $calls)->count()]
            : ['Assigned DIDs' => $dids->count(), 'My campaigns' => (clone $campaigns)->count(), 'Pending approval' => (clone $campaigns)->where('status', 'PENDING_APPROVAL')->count(),
                'Running' => (clone $campaigns)->where('status', 'RUNNING')->count(), 'Completed' => (clone $campaigns)->where('status', 'COMPLETED')->count()];

        return view('livewire.dashboard-stats', compact('cards', 'today', 'dids'));
    }
}
