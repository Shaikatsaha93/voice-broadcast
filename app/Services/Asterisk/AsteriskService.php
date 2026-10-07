<?php

namespace App\Services\Asterisk;

use App\Models\CallAttempt;

/**
 * Single entry point between Laravel and Asterisk. Controllers/jobs depend on
 * this class only; transport details live in the AMI / ARI services.
 * Bind a fake in tests via $this->app->instance(AsteriskService::class, ...).
 */
class AsteriskService
{
    public function __construct(private AsteriskAMIService $ami, private AsteriskARIService $ari)
    {
    }

    public function isDryRun(): bool
    {
        return (bool) config('broadcast.asterisk.dry_run');
    }

    /**
     * Originate the broadcast call. The AMI ChannelId is the attempt's call_ref,
     * so every later AMI event carries our unique reference as Uniqueid.
     * Caller ID always comes from the DID assigned to the campaign.
     */
    public function originate(CallAttempt $attempt): bool
    {
        if ($this->isDryRun()) {
            return true;
        }

        $campaign = $attempt->campaign()->with('audio', 'did')->first();
        $did = $campaign->did;
        $audio = $campaign->audio?->normalized_path ?? $campaign->audio?->path;

        return $this->ami->originate([
            'Channel' => str_replace('{number}', $attempt->phone, $did->trunk ?: config('broadcast.asterisk.trunk')),
            'Context' => config('broadcast.asterisk.context'),
            'Exten' => 's',
            'Priority' => 1,
            'CallerID' => sprintf('"%s" <%s>', $did->label ?: $did->number, $did->number),
            'ChannelId' => $attempt->call_ref,
            'Timeout' => config('broadcast.originate_timeout_ms'),
            'Async' => 'true',
            'Variable' => ['CALL_REF='.$attempt->call_ref, 'AUDIO_FILE='.pathinfo((string) $audio, PATHINFO_DIRNAME).'/'.pathinfo((string) $audio, PATHINFO_FILENAME)],
        ]);
    }

    /** @return array<string>|null call_refs / uniqueids of live channels, null when Asterisk is unreachable. */
    public function activeChannelIds(): ?array
    {
        if ($this->isDryRun()) {
            return null;
        }

        return $this->ari->channelIds();
    }

    public function hangup(string $callRef): void
    {
        if (! $this->isDryRun()) {
            $this->ari->hangup($callRef);
        }
    }

    /**
     * Trunks (PJSIP endpoints) registered in Asterisk, used to create DIDs.
     * Dry-run returns sample data so the UI works without a live Asterisk.
     *
     * @return array<int, array{name: string, state: string}>|null null => Asterisk unreachable
     */
    public function trunks(): ?array
    {
        if ($this->isDryRun()) {
            return [
                ['name' => 'trunk', 'state' => 'online'],
                ['name' => 'backup-trunk', 'state' => 'offline'],
            ];
        }

        return $this->ari->pjsipEndpoints();
    }
}
