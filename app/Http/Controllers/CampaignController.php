<?php

namespace App\Http\Controllers;

use App\Enums\CampaignStatus;
use App\Http\Requests\CampaignRequest;
use App\Http\Requests\ImportNumbersRequest;
use App\Jobs\ProcessNumberImport;
use App\Models\Campaign;
use App\Models\NumberImport;
use App\Services\Audit\Audit;
use App\Services\Calls\CampaignService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;

class CampaignController extends Controller
{
    public function __construct(private CampaignService $service)
    {
    }

    public function index(Request $request)
    {
        $campaigns = Campaign::visibleTo($request->user())->with('did:id,number', 'user:id,name')->latest('id')->paginate(20);

        return view('campaigns.index', compact('campaigns'));
    }

    public function create(Request $request)
    {
        return view('campaigns.form', ['campaign' => new Campaign(['max_attempts' => 1, 'requested_concurrency' => 1]), 'dids' => $request->user()->dids()->where('status', 'active')->get(), 'audios' => $request->user()->isSuperAdmin() ? collect() : \App\Models\AudioFile::where('user_id', $request->user()->id)->where('status', 'READY')->get()]);
    }

    public function store(CampaignRequest $request)
    {
        $campaign = Campaign::create($request->safe()->except('numbers') + ['user_id' => $request->user()->id, 'status' => CampaignStatus::DRAFT, 'retry_delay_seconds' => $request->input('retry_delay_seconds') ?: config('broadcast.retry_delay_seconds')]);
        Audit::log('campaign.created', $campaign, null, null, $campaign->only('name', 'did_id', 'max_attempts', 'requested_concurrency'));

        if ($request->hasFile('numbers')) {
            $this->queueImport($request->file('numbers'), $campaign, $request->user()->id);

            return redirect()->route('campaigns.show', $campaign)->with('status', 'Campaign created as DRAFT and numbers import queued. Review it, then submit for approval.');
        }

        return redirect()->route('campaigns.show', $campaign)->with('status', 'Campaign created as DRAFT. Import numbers, then submit for approval.');
    }

    public function show(Campaign $campaign)
    {
        Gate::authorize('view', $campaign);
        $campaign->load('did', 'audio', 'user', 'imports');
        $stats = $campaign->recipients()->selectRaw('status, count(*) c')->groupBy('status')->pluck('c', 'status');

        return view('campaigns.show', ['campaign' => $campaign, 'stats' => $stats, 'active' => \App\Models\DidSlot::whereIn('call_attempt_id', $campaign->attempts()->select('id'))->count()]);
    }

    public function edit(Request $request, Campaign $campaign)
    {
        Gate::authorize('update', $campaign);

        return view('campaigns.form', ['campaign' => $campaign, 'dids' => $request->user()->dids()->where('status', 'active')->get(), 'audios' => \App\Models\AudioFile::where('user_id', $request->user()->id)->where('status', 'READY')->get()]);
    }

    public function update(CampaignRequest $request, Campaign $campaign)
    {
        Gate::authorize('update', $campaign);
        $old = $campaign->only('name', 'did_id', 'audio_file_id', 'max_attempts', 'requested_concurrency');
        $campaign->update($request->validated());
        Audit::log('campaign.updated', $campaign, null, $old, $campaign->only(array_keys($old)));

        return redirect()->route('campaigns.show', $campaign)->with('status', 'Campaign updated.');
    }

    public function destroy(Campaign $campaign)
    {
        Gate::authorize('delete', $campaign);
        $id = $campaign->id;
        $campaign->delete();
        Audit::log('campaign.deleted', 'Campaign', $id);

        return redirect()->route('campaigns.index')->with('status', 'Campaign deleted.');
    }

    public function import(ImportNumbersRequest $request, Campaign $campaign)
    {
        Gate::authorize('update', $campaign);
        $this->queueImport($request->file('numbers'), $campaign, $request->user()->id);

        return back()->with('status', 'Import queued. Refresh to see the summary.');
    }

    /**
     * Downloadable template. Rows are placeholders that fail validation on purpose,
     * so a forgotten example row can never trigger a real call.
     */
    public function sampleNumbers()
    {
        $prefix = config('broadcast.default_country_prefix');
        $lines = ['phone', $prefix.'1XXXXXXXXX', $prefix.'1XXXXXXXXX', $prefix.'1XXXXXXXXX'];

        return response()->streamDownload(
            fn () => print(implode("\r\n", $lines)."\r\n"),
            'campaign-numbers-sample.csv',
            ['Content-Type' => 'text/csv; charset=UTF-8'],
        );
    }

    private function queueImport(\Illuminate\Http\UploadedFile $file, Campaign $campaign, int $userId): void
    {
        $path = $file->store('imports/'.$userId);
        $import = NumberImport::create(['campaign_id' => $campaign->id, 'user_id' => $userId, 'path' => $path, 'status' => 'PENDING']);
        ProcessNumberImport::dispatch($import->id);
        Audit::log('campaign.numbers_uploaded', $campaign, null, null, ['import_id' => $import->id]);
    }

    public function submit(Campaign $campaign)
    {
        Gate::authorize('submit', $campaign);
        $this->service->submit($campaign);

        return back()->with('status', 'Submitted for approval.');
    }

    public function start(Campaign $campaign)
    {
        Gate::authorize('control', $campaign);
        $this->service->start($campaign);

        return back()->with('status', 'Campaign started.');
    }

    public function pause(Campaign $campaign)
    {
        Gate::authorize('control', $campaign);
        $this->service->pause($campaign);

        return back()->with('status', 'Campaign paused.');
    }

    public function resume(Campaign $campaign)
    {
        Gate::authorize('control', $campaign);
        $this->service->resume($campaign);

        return back()->with('status', 'Campaign resumed.');
    }

    public function cancel(Campaign $campaign)
    {
        Gate::authorize('control', $campaign);
        $this->service->cancel($campaign);

        return back()->with('status', 'Campaign cancelled.');
    }
}
