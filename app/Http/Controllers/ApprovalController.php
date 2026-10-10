<?php

namespace App\Http\Controllers;

use App\Enums\CampaignStatus;
use App\Http\Requests\RejectCampaignRequest;
use App\Models\Campaign;
use App\Services\Calls\CampaignService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class ApprovalController extends Controller
{
    public function __construct(private CampaignService $service)
    {
    }

    /** Super Admin: every pending campaign. Admin: only those of the normal users they created. */
    public function index(Request $request)
    {
        abort_unless($request->user()->isManager(), 403);
        $campaigns = Campaign::visibleTo($request->user())->where('status', CampaignStatus::PENDING_APPROVAL)->with('user:id,name', 'did', 'audio')->withCount('recipients')->oldest('submitted_at')->paginate(20);

        return view('admin.approvals', compact('campaigns'));
    }

    public function approve(Request $request, Campaign $campaign)
    {
        Gate::authorize('approve', $campaign);
        $this->service->approve($campaign, $request->user());

        $campaign->refresh();

        return redirect()->route('admin.approvals')->with('status', $campaign->status->value === 'QUEUED' ? 'Campaign approved. It starts automatically at its scheduled time.' : 'Campaign approved and started.');
    }

    public function reject(RejectCampaignRequest $request, Campaign $campaign)
    {
        Gate::authorize('approve', $campaign);
        $this->service->reject($campaign, $request->user(), $request->reason);

        return redirect()->route('admin.approvals')->with('status', 'Campaign rejected.');
    }
}
