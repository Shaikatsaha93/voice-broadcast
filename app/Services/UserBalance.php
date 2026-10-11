<?php

namespace App\Services;

use App\Models\User;
use App\Models\UserTransaction;
use App\Services\Audit\Audit;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Balance given to an Admin account: opening balance, top-ups and removals, all in a ledger. */
class UserBalance
{
    public function recordOpening(User $user, ?int $by): void
    {
        if ((float) $user->balance > 0) {
            $this->ledger($user, 'opening', (float) $user->balance, 'Opening balance', $by);
        }
    }

    /** Add (positive) or remove (negative) balance. Returns the new balance. */
    public function adjust(User $user, float $amount, string $type, ?string $note, ?int $by): float
    {
        return DB::transaction(function () use ($user, $amount, $type, $note, $by) {
            $locked = User::whereKey($user->id)->lockForUpdate()->first();
            if ($amount < 0 && (float) $locked->balance + $amount < 0) {
                throw ValidationException::withMessages(['amount' => 'You cannot remove more than the current balance ('.Money::tk($locked->balance).').']);
            }
            $locked->update(['balance' => DB::raw('coalesce(balance, 0) + '.number_format($amount, 4, '.', ''))]);
            $locked->refresh();
            $this->ledger($locked, $type, $amount, $note, $by);
            Audit::log('user.balance_'.$type, $locked, null, null, ['amount' => $amount, 'balance' => (float) $locked->balance, 'note' => $note]);
            $user->setRawAttributes($locked->getAttributes(), true);

            return (float) $locked->balance;
        });
    }

    private function ledger(User $user, string $type, float $amount, ?string $note, ?int $by): void
    {
        UserTransaction::create([
            'user_id' => $user->id, 'type' => $type, 'amount' => $amount, 'balance_after' => $user->balance,
            'note' => $note ? mb_substr($note, 0, 190) : null, 'created_by' => $by, 'created_at' => now(),
        ]);
    }
}
