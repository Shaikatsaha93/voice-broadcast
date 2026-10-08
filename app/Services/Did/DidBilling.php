<?php

namespace App\Services\Did;

use App\Models\CallAttempt;
use App\Models\Did;
use App\Models\DidTransaction;
use App\Services\Audit\Audit;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Prepaid DID balance. Every change goes through here, under a row lock on the DID,
 * and leaves a line in did_transactions (the ledger).
 */
class DidBilling
{
    /** Pulses billed for an answered call: ceil(billsec / pulse length), at least one. */
    public function pulsesFor(Did $did, int $billsec): int
    {
        return max(1, (int) ceil($billsec / max(1, $did->pulse_seconds)));
    }

    /**
     * Charge one finished, answered call. Call inside the transaction that finalizes the attempt.
     * Idempotent: billed_at and the unique ledger key stop double charging.
     */
    public function charge(CallAttempt $a): void
    {
        if (! $a->answered_at || $a->billed_at) {
            return;
        }

        $did = Did::whereKey($a->did_id)->lockForUpdate()->first();
        if (! $did) {
            return;
        }

        $pulses = $this->pulsesFor($did, (int) $a->billsec);
        $cost = round($pulses * (float) $did->rate_per_pulse, 4);
        $a->forceFill(['pulses' => $pulses, 'cost' => $cost, 'billed_at' => now()])->save();

        if ($cost > 0) {
            $did->update(['balance' => DB::raw('balance - '.number_format($cost, 4, '.', ''))]);
            $this->ledger($did->refresh(), 'charge', -$cost, "Call to {$a->phone} ({$pulses} pulse".($pulses === 1 ? '' : 's').')', null, $a->id);
        }
    }

    /** Add (positive) or remove (negative) balance by hand. Returns the new balance. */
    public function adjust(Did $did, float $amount, string $type, ?string $note, ?int $userId): float
    {
        return DB::transaction(function () use ($did, $amount, $type, $note, $userId) {
            $locked = Did::whereKey($did->id)->lockForUpdate()->first();
            if ($amount < 0 && (float) $locked->balance + $amount < 0) {
                throw ValidationException::withMessages(['amount' => 'You cannot remove more than the current balance ('.\App\Support\Money::tk($locked->balance).').']);
            }
            $locked->update(['balance' => DB::raw('balance + '.number_format($amount, 4, '.', ''))]);
            $locked->refresh();
            $this->ledger($locked, $type, $amount, $note, $userId);
            Audit::log('did.balance_'.$type, $locked, null, null, ['amount' => $amount, 'balance' => (float) $locked->balance, 'note' => $note]);
            $did->setRawAttributes($locked->getAttributes(), true);

            return (float) $locked->balance;
        });
    }

    private function ledger(Did $did, string $type, float $amount, ?string $note, ?int $userId, ?int $attemptId = null): void
    {
        DidTransaction::create([
            'did_id' => $did->id, 'call_attempt_id' => $attemptId, 'type' => $type, 'amount' => $amount,
            'balance_after' => $did->balance, 'note' => $note ? mb_substr($note, 0, 190) : null, 'created_by' => $userId, 'created_at' => now(),
        ]);
    }

    public function recordOpening(Did $did, ?int $userId): void
    {
        if ((float) $did->balance > 0) {
            $this->ledger($did, 'opening', (float) $did->balance, 'Opening balance', $userId);
        }
    }
}
