<?php

namespace App\Services\Did;

use App\Models\AudioFile;
use App\Models\CallAttempt;
use App\Models\DidSlot;

/**
 * Credit reservation (prepaid, like A2Billing / MagnusBilling): before a call starts, the balance must cover
 * the pulses of every call in flight AND this one, each at its expected length (audio length + a grace period).
 * The call is then hung up at the reserved length, so a balance can never go below 0.
 */
class CallCredit
{
    /** How long a call is expected to run: audio length + grace, capped. */
    public function expectedSeconds(?AudioFile $audio): int
    {
        $len = $audio && (float) $audio->duration > 0 ? (int) ceil((float) $audio->duration) : (int) config('broadcast.default_call_seconds');

        return min(max(1, $len + (int) config('broadcast.call_grace_seconds')), (int) config('broadcast.max_call_seconds'));
    }

    public function pulses(int $seconds, int $pulseSeconds): int
    {
        return max(1, (int) ceil($seconds / max(1, $pulseSeconds)));
    }

    /** Seconds a reservation of $seconds really covers: whole pulses. */
    public function coveredSeconds(int $seconds, int $pulseSeconds): int
    {
        return $this->pulses($seconds, $pulseSeconds) * max(1, $pulseSeconds);
    }

    /**
     * Pulses reserved by the calls already in flight (given as a DidSlot query).
     *
     * @param  \Illuminate\Database\Eloquent\Builder<DidSlot>  $slots
     */
    public function inFlightPulses($slots, int $pulseSeconds): int
    {
        $durations = (clone $slots)
            ->join('call_attempts as ca', 'ca.id', '=', 'did_slots.call_attempt_id')
            ->join('campaigns as c', 'c.id', '=', 'ca.campaign_id')
            ->leftJoin('audio_files as af', 'af.id', '=', 'c.audio_file_id')
            ->pluck('af.duration');

        return (int) $durations->sum(fn ($d) => $this->pulses($this->expectedSeconds($d === null ? null : new AudioFile(['duration' => $d])), $pulseSeconds));
    }

    /** Longest the attempt may run once answered: the shorter reservation of the billed levels (null = nobody is billed). */
    public function limitSeconds(int $expectedSeconds, array $billed): ?int
    {
        $covers = [];
        foreach ($billed as [$rate, $pulseSeconds]) {
            if ($rate > 0) {
                $covers[] = $this->coveredSeconds($expectedSeconds, $pulseSeconds);
            }
        }

        return $covers ? min($covers) : null;
    }
}
