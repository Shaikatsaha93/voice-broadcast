<?php

namespace App\Services\Asterisk;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/** ARI is used only for reconciliation (list live channels) and forced hangup. */
class AsteriskARIService
{
    private function http()
    {
        $c = config('broadcast.asterisk');

        return Http::baseUrl((string) $c['ari_url'])->withBasicAuth((string) $c['ari_username'], (string) $c['ari_password'])->timeout(5);
    }

    /** @return array<string>|null null => Asterisk unreachable */
    public function channelIds(): ?array
    {
        try {
            $r = $this->http()->get('/channels');
            if (! $r->successful()) {
                return null;
            }

            return collect($r->json())->pluck('id')->all();
        } catch (\Throwable $e) {
            Log::warning('ARI unreachable', ['error' => $e->getMessage()]);

            return null;
        }
    }

    public function hangup(string $channelId): void
    {
        try {
            $this->http()->delete('/channels/'.rawurlencode($channelId));
        } catch (\Throwable $e) {
            Log::warning('ARI hangup failed', ['error' => $e->getMessage()]);
        }
    }

    /**
     * PJSIP endpoints known to Asterisk (trunks).
     *
     * @return array<int, array{name: string, state: string}>|null null => Asterisk unreachable
     */
    public function pjsipEndpoints(): ?array
    {
        try {
            $r = $this->http()->get('/endpoints/PJSIP');
            if (! $r->successful()) {
                return null;
            }

            return collect($r->json())->map(fn ($e) => [
                'name' => (string) ($e['resource'] ?? ''),
                'state' => (string) ($e['state'] ?? 'unknown'),
            ])->filter(fn ($e) => $e['name'] !== '')->values()->all();
        } catch (\Throwable $e) {
            Log::warning('ARI endpoints failed', ['error' => $e->getMessage()]);

            return null;
        }
    }
}
