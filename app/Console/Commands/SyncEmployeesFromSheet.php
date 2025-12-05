<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class SyncEmployeesFromSheet extends Command
{
    // The name of the command you will call
    protected $signature = 'employees:sync-from-sheet';

    // Description (for artisan list)
    protected $description = 'Fetch employees JSON from Apps Script and sync to local DB';

    public function handle()
    {
        // 1) Your Apps Script endpoint
        $sheetUrl = 'https://script.googleusercontent.com/a/macros/dswd.gov.ph/echo?user_content_key=AehSKLgdXExE76mhmuOCr6WVIJgHnAv-ENAz8TOujN9yYDWUexRUMS2jZWqwiDnreBOexohO4C3pN6mlSVveY2qrRbX8GhF-PqFaoXVs-HjkN3q5J8WtA1dbYRe8m9YZJDaTHi8-3bFLr3ryu184y2rmc7RCS2H-u6eyzgS5fnmnAjsmCi23inZGpa4KiPh6NRUF4k9lY24bn-v4qIVC1rRS1_TqkTOOr6EMV-NOn5Zi40fPTsMzWlHehDHAHyd-e9zmvGHi739E1Jm2SZEv-e1xnv8-TpAAj0UjTTbJXEc9kFccSUeSl0c&lib=M9OpfRqnK_XpO1nhJZ1oxCiJNsr4x2yAK';

        $this->info('Fetching JSON from Apps Script...');
        Log::info('[SyncEmployeesFromSheet] Fetching JSON from Apps Script');

        // 2) Call Apps Script endpoint
        $response = Http::get($sheetUrl);

        if ($response->failed()) {
            $this->error('Failed to fetch JSON. HTTP status: ' . $response->status());
            Log::error('[SyncEmployeesFromSheet] Failed to fetch JSON', [
                'status' => $response->status(),
                'body'   => $response->body(),
            ]);
            return Command::FAILURE;
        }

        $data = $response->json();

        if (!is_array($data)) {
            $this->error('Apps Script did not return a JSON array.');
            Log::error('[SyncEmployeesFromSheet] Invalid JSON structure from Apps Script', [
                'data' => $data,
            ]);
            return Command::FAILURE;
        }

        // 3) Call your own /api/employees/sync endpoint
        $apiUrl = rtrim(config('app.url'), '/') . '/api/employees/sync';

        $this->info('Sending payload to ' . $apiUrl);
        Log::info('[SyncEmployeesFromSheet] Sending payload to sync endpoint', [
            'url' => $apiUrl,
        ]);

        $syncResponse = Http::asJson()->post($apiUrl, $data);

        if ($syncResponse->failed()) {
            $this->error('Sync endpoint failed. Status: ' . $syncResponse->status());
            Log::error('[SyncEmployeesFromSheet] Sync failed', [
                'status' => $syncResponse->status(),
                'body'   => $syncResponse->body(),
            ]);
            return Command::FAILURE;
        }

        $this->info('Sync completed successfully.');
        Log::info('[SyncEmployeesFromSheet] Sync completed successfully', [
            'response' => $syncResponse->json(),
        ]);

        return Command::SUCCESS;
    }
}
