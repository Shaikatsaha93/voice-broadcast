<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Did;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class AuditLogController extends Controller
{
    /**
     * Super Admin: everything. Admin: only what they did, what the normal users they created did,
     * and changes to those accounts and to their own DIDs. Other admins' data is never shown.
     */
    public function index(Request $request)
    {
        Gate::authorize('manage', User::class);
        $viewer = $request->user();

        $logs = AuditLog::with('actor:id,name')
            ->when(! $viewer->isSuperAdmin(), fn ($q) => $q->where(function ($w) use ($viewer) {
                $w->where('actor_id', $viewer->id)
                    ->orWhereIn('actor_id', $viewer->managedUserIds())
                    ->orWhere(fn ($u) => $u->where('entity', 'User')->whereIn('entity_id', $viewer->managedUserIds()))
                    ->orWhere(fn ($u) => $u->where('entity', 'Did')->whereIn('entity_id', Did::where('created_by', $viewer->id)->select('id')));
            }))
            ->latest('id')->paginate(50);

        return view('admin.audit', compact('logs'));
    }
}
