<?php

namespace App\Livewire;

use App\Models\CallAttempt;
use App\Models\Campaign;
use App\Models\Did;
use App\Services\Audit\Audit;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/** Attempt history report. Scoped server-side on every render: filters can never widen access. */
class CallReport extends Component
{
    use WithPagination;

    #[Url] public ?int $campaignId = null;
    #[Url] public ?int $didId = null;
    /** Filter choices shown to the user => the call statuses they cover. */
    public const STATUS_FILTERS = [
        'Answered' => ['COMPLETED'],
        'No answer' => ['NO_ANSWER'],
        'Busy' => ['BUSY'],
        'Failed' => ['FAILED', 'TEMPORARY_FAILURE', 'INVALID_NUMBER'],
        'Cancelled' => ['CANCELLED'],
    ];

    #[Url] public string $status = '';
    #[Url] public string $phone = '';
    #[Url] public string $dateMode = 'range'; // 'single' | 'range'
    #[Url] public string $date = '';
    #[Url] public string $from = '';
    #[Url] public string $to = '';

    /** Opening the page without any date in the URL shows the last 7 days (Reset goes back to it too; clear both dates and Search for all time). */
    public function mount(): void
    {
        if (! request()->hasAny(['from', 'to', 'date', 'dateMode'])) {
            $this->setLastDays(7);
        }
    }

    private function setLastDays(int $days): void
    {
        $this->dateMode = 'range';
        $this->date = '';
        $this->to = now()->toDateString();
        $this->from = now()->subDays($days - 1)->toDateString();
    }

    public function updated(): void
    {
        $this->resetPage();
    }

    /** Same scoping + filters for the table, the summary and the CSV export. */
    private function filteredQuery()
    {
        return CallAttempt::visibleTo(Auth::user())->with('campaign:id,name', 'did:id,number', 'user:id,name')
            ->when($this->campaignId, fn ($q, $v) => $q->where('campaign_id', $v))
            ->when($this->didId, fn ($q, $v) => $q->where('did_id', $v))
            ->when($this->status !== '', fn ($q) => $q->whereIn('status', self::STATUS_FILTERS[$this->status] ?? [$this->status]))
            ->when($this->phone !== '', fn ($q) => $q->where('phone', 'like', preg_replace('/\D/', '', $this->phone).'%'))
            ->when($this->dateMode === 'single' && self::validDate($this->date), fn ($q) => $q->whereDate('call_attempts.created_at', $this->date))
            ->when($this->dateMode !== 'single' && self::validDate($this->from), fn ($q) => $q->whereDate('call_attempts.created_at', '>=', $this->from))
            ->when($this->dateMode !== 'single' && self::validDate($this->to), fn ($q) => $q->whereDate('call_attempts.created_at', '<=', $this->to));
    }

    /** Plain-words description of the date filter that is applied right now, shown above the results. */
    public function dateLabel(): string
    {
        $fmt = fn (string $d) => \Carbon\Carbon::parse($d)->format('d M Y');
        if ($this->dateMode === 'single') {
            return self::validDate($this->date) ? ($this->date === now()->toDateString() ? 'Today, ' : '').$fmt($this->date) : 'All time';
        }
        $from = self::validDate($this->from) ? $this->from : null;
        $to = self::validDate($this->to) ? $this->to : null;
        if (! $from && ! $to) {
            return 'All time';
        }
        $label = $from && $to ? $fmt($from).' to '.$fmt($to) : ($from ? 'From '.$fmt($from) : 'Up to '.$fmt($to));
        if ($to === now()->toDateString()) {
            $days = $from ? (int) \Carbon\Carbon::parse($from)->diffInDays(now()->startOfDay()) + 1 : null;
            $label .= match ($days) { 1 => ' (today)', 7 => ' (last 7 days)', 30 => ' (last 30 days)', default => '' };
        }

        return $label;
    }

    private static function validDate(string $v): bool
    {
        return $v !== '' && \Carbon\Carbon::hasFormat($v, 'Y-m-d');
    }

    public function updatedDateMode(string $value): void
    {
        if (! in_array($value, ['single', 'range'], true)) {
            $this->dateMode = 'range';
        }
    }

    /** Filters are deferred (wire:model): they reach the server together when Search is pressed. */
    public function applyFilters(): void
    {
        $this->resetPage();
    }

    public function resetFilters(): void
    {
        $this->campaignId = $this->didId = null;
        $this->status = $this->phone = '';
        $this->setLastDays(7);
        $this->resetPage();
    }

    /** Download the currently filtered report as CSV (streamed, never loaded into memory). */
    public function export()
    {
        $user = Auth::user();
        $query = $this->filteredQuery();
        Audit::log('report.exported', 'CallAttempt', null, null, array_filter([
            'campaign_id' => $this->campaignId, 'did_id' => $this->didId, 'status' => $this->status,
            'phone' => $this->phone, 'date_mode' => $this->dateMode, 'date' => $this->dateMode === 'single' ? $this->date : '',
            'from' => $this->dateMode === 'range' ? $this->from : '', 'to' => $this->dateMode === 'range' ? $this->to : '',
        ]));

        $file = 'call-report-'.now()->format('Ymd-His').'.csv';

        $manager = $user->isManager();

        return response()->streamDownload(function () use ($query, $manager) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF"); // UTF-8 BOM so Excel reads names correctly
            fputcsv($out, array_merge(['Time', 'Campaign', 'DID', 'Phone', 'Attempt', 'Status', 'Answered at', 'Duration (s)', 'Billsec (s)', 'Pulses', $manager ? 'Charged' : 'Cost'], $manager ? ['User', 'Admin cost', 'Profit'] : []));
            $query->lazyById(1000, 'call_attempts.id', 'id')->each(function ($a) use ($out, $manager) {
                fputcsv($out, array_map([self::class, 'csvSafe'], array_merge([
                    $a->created_at, $a->campaign?->name, $a->did?->number, $a->phone, '#'.$a->attempt_no,
                    $a->status->value, $a->answered_at, $a->duration, $a->billsec, $a->pulses, $a->cost,
                ], $manager ? [$a->user?->name, $a->admin_id ? $a->admin_cost : '', $a->admin_id ? round((float) $a->cost - (float) $a->admin_cost, 4) : ''] : [])));
            });
            fclose($out);
        }, $file, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /** Neutralise spreadsheet formula injection (cells starting with = + - @). */
    public static function csvSafe(mixed $v): string
    {
        $v = (string) $v;

        return $v !== '' && str_contains("=+-@\t\r", $v[0]) ? "'".$v : $v;
    }

    /**
     * Cost by user and DID (managers): what each user was charged on each DID, what the Admin paid for the
     * same calls, and the Admin's profit (charged to the user minus the Admin's own cost).
     */
    private function breakdown($q)
    {
        return (clone $q)->setEagerLoads([])->reorder()
            ->join('users as u', 'u.id', '=', 'call_attempts.user_id')
            ->join('dids as d', 'd.id', '=', 'call_attempts.did_id')
            ->leftJoin('users as adm', 'adm.id', '=', 'call_attempts.admin_id')
            ->whereNotNull('call_attempts.billed_at')
            ->groupBy('call_attempts.user_id', 'call_attempts.did_id', 'call_attempts.admin_id', 'u.name', 'd.id', 'd.number', 'd.mrc', 'adm.name')
            ->selectRaw('u.name as user_name, d.number as did_number, d.mrc as did_mrc, d.id as did_id, adm.name as admin_name, call_attempts.admin_id as admin_id, count(*) calls, coalesce(sum(call_attempts.billsec),0) billsec, coalesce(sum(call_attempts.cost),0) charged, coalesce(sum(call_attempts.admin_cost),0) admin_cost')
            ->orderByDesc('charged')->limit(200)->get();
    }

    public function render()
    {
        $user = Auth::user();
        $q = $this->filteredQuery();

        $summary = (clone $q)->selectRaw("count(*) total, sum(answered_at is not null) answered, sum(status='NO_ANSWER') no_answer, sum(status='BUSY') busy, sum(status in ('FAILED','TEMPORARY_FAILURE','INVALID_NUMBER')) failed, coalesce(sum(duration),0) duration, coalesce(sum(billsec),0) billsec, coalesce(sum(cost),0) cost, coalesce(sum(admin_cost),0) admin_cost, coalesce(sum(case when admin_id is not null then cost - admin_cost else 0 end),0) profit")->first();
        $breakdown = $user->isManager() ? $this->breakdown($q) : collect();
        // Monthly recurring charge (MRC): every own DID, and reseller DIDs that have an MRC; for the DIDs the viewer manages (narrowed by the DID filter).
        $mrcDids = $user->isManager()
            ? Did::visibleTo($user)->where(fn ($w) => $w->where('did_type', 'own')->orWhere('dids.mrc', '>', 0))->when($this->didId, fn ($q, $v) => $q->where('dids.id', $v))->orderBy('number')->get(['dids.id', 'dids.number', 'dids.label', 'dids.did_type', 'dids.mrc', 'dids.valid_until', 'dids.status'])
            : collect();

        return view('livewire.call-report', [
            'attempts' => $q->latest('call_attempts.id')->paginate(25),
            'summary' => $summary,
            'breakdown' => $breakdown,
            'mrcDids' => $mrcDids,
            'campaigns' => Campaign::visibleTo($user)->orderBy('name')->get(['id', 'name']),
            'dids' => (match (true) {
                $user->isSystemAdmin() => Did::query(),
                $user->isManager() => Did::whereIn('dids.id', CallAttempt::visibleTo($user)->select('call_attempts.did_id')),
                default => $user->dids(),
            })->orderBy('number')->get(['dids.id', 'dids.number']),
            'statuses' => array_keys(self::STATUS_FILTERS),
        ]);
    }
}
