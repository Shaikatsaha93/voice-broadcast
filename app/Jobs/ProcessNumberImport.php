<?php

namespace App\Jobs;

use App\Models\CampaignRecipient;
use App\Models\NumberImport;
use App\Services\Numbers\PhoneNormalizer;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Storage;

/** Streams the CSV line by line and inserts in chunks - never loads the file into memory. */
class ProcessNumberImport implements ShouldQueue
{
    use Queueable;

    public int $timeout = 900;

    public function __construct(public int $importId)
    {
    }

    public function handle(): void
    {
        $import = NumberImport::find($this->importId);
        if (! $import || $import->status === 'DONE') {
            return;
        }
        $import->update(['status' => 'PROCESSING', 'total_rows' => 0, 'valid_rows' => 0, 'invalid_rows' => 0, 'duplicate_rows' => 0, 'imported_rows' => 0]);

        $fh = fopen(Storage::disk('local')->path($import->path), 'r');
        if (! $fh) {
            $import->update(['status' => 'FAILED', 'error' => 'Cannot open file']);

            return;
        }

        $stats = ['total' => 0, 'valid' => 0, 'invalid' => 0, 'dup' => 0, 'imported' => 0];
        $chunk = [];
        $size = (int) config('broadcast.import_chunk');

        $first = true;
        while (($row = fgetcsv($fh)) !== false) {
            if ($row === [null]) {
                continue; // blank line
            }
            // Skip a header line such as "phone" (first row with no digits at all).
            if ($first && ! preg_match('/\d/', (string) ($row[0] ?? ''))) {
                $first = false;

                continue;
            }
            $first = false;
            $stats['total']++;
            $phone = PhoneNormalizer::normalize((string) ($row[0] ?? ''));
            if (! $phone) {
                $stats['invalid']++;

                continue;
            }
            $stats['valid']++;
            if (isset($chunk[$phone])) {
                $stats['dup']++;

                continue;
            }
            $chunk[$phone] = true;

            if (count($chunk) >= $size) {
                $this->flush($import, array_keys($chunk), $stats);
                $chunk = [];
            }
        }
        fclose($fh);
        $chunk && $this->flush($import, array_keys($chunk), $stats);

        $import->update(['status' => 'DONE', 'total_rows' => $stats['total'], 'valid_rows' => $stats['valid'], 'invalid_rows' => $stats['invalid'], 'duplicate_rows' => $stats['dup'], 'imported_rows' => $stats['imported']]);
        Storage::disk('local')->delete($import->path);
    }

    private function flush(NumberImport $import, array $phones, array &$stats): void
    {
        $existing = CampaignRecipient::where('campaign_id', $import->campaign_id)->whereIn('phone', $phones)->pluck('phone')->all();
        $new = array_diff($phones, $existing);
        $stats['dup'] += count($existing);

        $now = now();
        CampaignRecipient::insertOrIgnore(array_map(fn ($p) => ['campaign_id' => $import->campaign_id, 'phone' => $p, 'status' => 'PENDING', 'attempts_count' => 0, 'created_at' => $now, 'updated_at' => $now], $new));
        $stats['imported'] += count($new);
    }
}
