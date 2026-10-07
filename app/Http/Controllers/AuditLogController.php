<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use Illuminate\Support\Facades\Gate;

class AuditLogController extends Controller
{
    public function index()
    {
        Gate::authorize('manage', \App\Models\User::class);

        return view('admin.audit', ['logs' => AuditLog::with('actor:id,name')->latest('id')->paginate(50)]);
    }
}
