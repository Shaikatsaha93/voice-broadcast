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

    public function updated(): void
    {
        $this->resetPage();
    }

    /** Same scoping + filters for the table, the summary and the CSV export. */
    private function filteredQuery()
    {
        return CallAttempt::visibleTo(Auth::user())->with('campaign:id,name', 'did:id,number')
            ->when($this->campaignId, fn ($q, $v) => $q->where('campaign_id', $v))
            ->when($this->didId, fn ($q, $v) => $q->where('did_id', $v))
            ->when($this->status !== '', fn ($q) => $q->whereIn('status', self::STATUS_FILTERS[$this->status] ?? [$this->status]))
            ->when($this->phone !== '', fn ($q) => $q->where('phone', 'like', preg_replace('/\D/', '', $this->phone).'%'))
            ->when($this->dateMode === 'single' && self::validDate($this->date), fn ($q) => $q->whereDate('call_attempts.created_at', $this->date))
            ->when($this->dateMode !== 'single' && self::validDate($this->from), fn ($q) => $q->whereDate('call_attempts.created_at', '>=', $this->from))
            ->when($this->dateMode !== 'single' && self::validDate($this->to), fn ($q) => $q->whereDate('call_attempts.created_at', '<=', $this->to));
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
        $this->dateMode = 'range';
        $this->date = $this->from = $this->to = '';
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

        return response()->streamDownload(function () use ($query) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF"); // UTF-8 BOM so Excel reads names correctly
            fputcsv($out, ['Time', 'Campaign', 'DID', 'Phone', 'Attempt', 'Status', 'Answered at', 'Duration (s)', 'Billsec (s)', 'Pulses', 'Cost']);
            $query->lazyById(1000, 'call_attempts.id', 'id')->each(function ($a) use ($out) {
                fputcsv($out, array_map([self::class, 'csvSafe'], [
                    $a->created_at, $a->campaign?->name, $a->did?->number, $a->phone, '#'.$a->attempt_no,
                    $a->status->value, $a->answered_at, $a->duration, $a->billsec, $a->pulses, $a->cost,
                ]));
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

    public function render()
    {
        $user = Auth::user();
        $q = $this->filteredQuery();

        $summary = (clone $q)->selectRaw("count(*) total, sum(answered_at is not null) answered, sum(status='NO_ANSWER') no_answer, sum(status='BUSY') busy, sum(status in ('FAILED','TEMPORARY_FAILURE','INVALID_NUMBER')) failed, coalesce(sum(duration),0) duration, coalesce(sum(billsec),0) billsec, coalesce(sum(cost),0) cost")->first();

        return view('livewire.call-report', [
            'attempts' => $q->latest('call_attempts.id')->paginate(25),
            'summary' => $summary,
            'campaigns' => Campaign::visibleTo($user)->orderBy('name')->get(['id', 'name']),
            'dids' => (match (true) {
                $user->isSuperAdmin() => Did::query(),
                $user->isAdmin() => Did::whereIn('dids.id', CallAttempt::visibleTo($user)->select('call_attempts.did_id')),
                default => $user->dids(),
            })->orderBy('number')->get(['dids.id', 'dids.number']),
            'statuses' => array_keys(self::STATUS_FILTERS),
        ]);
    }
}
