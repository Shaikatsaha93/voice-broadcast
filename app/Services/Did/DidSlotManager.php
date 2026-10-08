<?php

namespace App\Services\Did;

use App\Models\CallAttempt;
use App\Models\Did;
use App\Models\DidSlot;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * DID concurrency engine.
 *
 * One did_slots row == one in-flight call. The database is the source of truth.
 * Acquisition is serialised per DID by (1) a distributed cache lock (Redis in
 * production) and (2) a SELECT ... FOR UPDATE on the DID row inside a
 * transaction, so capacity can never be exceeded even if the cache lock is
 * lost (Redis failure) or workers run in parallel.
 */
class DidSlotManager
{
    /** Why the last acquire() said no: 'capacity' | 'balance' | 'inactive' | null (granted). */
    public ?string $lastDenial = null;

    public function acquire(CallAttempt $attempt): bool
    {
        $this->lastDenial = null;
        $lock = Cache::lock('did-slot:'.$attempt->did_id, 10);

        try {
            $lock->block(5);
        } catch (LockTimeoutException) {
            return false;
        } catch (\Throwable) {
            // Redis down: fall through, the DB row lock below still guarantees safety.
            $lock = null;
        }

        try {
            return DB::transaction(function () use ($attempt) {
                $did = Did::whereKey($attempt->did_id)->lockForUpdate()->first();

                if (! $did || ! $did->isActive()) {
                    $this->lastDenial = 'inactive';

                    return false;
                }

                if (DidSlot::where('call_attempt_id', $attempt->id)->exists()) {
                    return true; // idempotent: duplicate job
                }

                $inFlight = DidSlot::where('did_id', $did->id)->count();
                if ($inFlight >= $did->max_concurrent_calls) {
                    $this->lastDenial = 'capacity';

                    return false;
                }

                $campaign = $attempt->campaign()->first();
                $campaignActive = DidSlot::whereIn('call_attempt_id', CallAttempt::where('campaign_id', $attempt->campaign_id)->select('id'))->count();
                if ($campaignActive >= min($campaign->requested_concurrency, $did->max_concurrent_calls)) {
                    $this->lastDenial = 'capacity';

                    return false;
                }

                // Prepaid balance: it must cover one pulse for this call plus one for every call already in flight.
                if (! $did->canAffordAnotherCall($inFlight)) {
                    $this->lastDenial = 'balance';

                    return false;
                }

                DidSlot::create([
                    'did_id' => $did->id,
                    'call_attempt_id' => $attempt->id,
                    'acquired_at' => now(),
                    'expires_at' => now()->addSeconds((int) config('broadcast.slot_ttl')),
                ]);

                return true;
            });
        } finally {
            optional($lock)->release();
        }
    }

    public function release(CallAttempt|int $attempt): void
    {
        DidSlot::where('call_attempt_id', $attempt instanceof CallAttempt ? $attempt->id : $attempt)->delete();
    }

    public function touch(CallAttempt $attempt): void
    {
        DidSlot::where('call_attempt_id', $attempt->id)->update(['expires_at' => now()->addSeconds((int) config('broadcast.slot_ttl'))]);
    }

    public function active(Did|int $did): int
    {
        return DidSlot::where('did_id', $did instanceof Did ? $did->id : $did)->count();
    }

    public function available(Did $did): int
    {
        return max(0, $did->max_concurrent_calls - $this->active($did));
    }
}
