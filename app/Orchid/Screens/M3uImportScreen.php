<?php

namespace App\Orchid\Screens;

use App\Models\Link;
use App\Services\LinkCrypt;
use Orchid\Screen\Screen;
use Orchid\Screen\Actions\Button;
use Orchid\Screen\Fields\Input;
use Orchid\Support\Facades\Layout;
use Orchid\Support\Facades\Toast;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class M3uImportScreen extends Screen
{
    public function name(): string
    {
        return 'Import M3U';
    }

    public function description(): string
    {
        return 'Upload a .m3u / .m3u8 playlist — all URLs will be cloaked and a rewritten playlist returned for download.';
    }

    public function query(): array
    {
        return [];
    }

    public function commandBar(): array
    {
        return [
            Button::make('Import & Download')
                ->icon('cloud-upload')
                ->method('importAndDownload'),
        ];
    }

    public function layout(): array
    {
        return [
            Layout::rows([
                Input::make('m3u_file')
                    ->type('file')
                    ->title('M3U / M3U8 Playlist File')
                    ->help('Accepted: .m3u, .m3u8, .txt — max 20 MB')
                    ->required(),
            ]),
        ];
    }

    public function importAndDownload(Request $request): \Symfony\Component\HttpFoundation\BinaryFileResponse|\Illuminate\Http\RedirectResponse
    {
        $request->validate([
            'm3u_file' => 'required|file|max:20480',
        ]);

        $content = file_get_contents($request->file('m3u_file')->getRealPath());

        if (! $content) {
            Toast::error('Could not read uploaded file.');
            return back();
        }

        $crypt   = app(LinkCrypt::class);
        $lines   = explode("\n", $content);
        $output  = [];
        $count   = 0;
        $extInf  = null;

        foreach ($lines as $line) {
            $trimmed = trim($line);

            if (str_starts_with($trimmed, '#EXTINF:')) {
                $extInf   = $trimmed;
                $output[] = $line;
                continue;
            }

            if (str_starts_with($trimmed, '#') || $trimmed === '') {
                $output[] = $line;
                continue;
            }

            // Unwrap already-proxied URLs to avoid double-proxying.
            // If the URL points to our own /view/ endpoint, decrypt it back to the origin first.
            $originUrl = $trimmed;
            $ownBase   = rtrim(url('/view/'), '/') . '/';
            if (str_starts_with($trimmed, $ownBase)) {
                $payload = preg_replace('/\.m3u$/i', '', substr($trimmed, strlen($ownBase)));
                try {
                    $originUrl = $crypt->decrypt($payload);
                } catch (\Throwable) {
                    // Not a valid encrypted payload — treat as plain URL
                    $originUrl = $trimmed;
                }
            }

            // Encrypt and rewrite the stream URL
            $proxied  = url('/view/' . $crypt->encrypt($originUrl));
            $output[] = $proxied;

            $name = 'Link ' . ($count + 1);
            if ($extInf && preg_match('/,(.+)$/', $extInf, $m)) {
                $name = trim($m[1]);
            }

            Link::firstOrCreate(
                ['original_url' => $originUrl],
                [
                    'name' => $name,
                    'type' => 'hls',
                ]
            );

            $count++;
            $extInf = null;
        }

        // Write cloaked playlist to temp file
        $uuid    = (string) Str::uuid();
        $tmpPath = storage_path("app/temp/{$uuid}.m3u");
        @mkdir(dirname($tmpPath), 0775, true);
        file_put_contents($tmpPath, implode("\n", $output));

        Toast::info("Imported {$count} links. Downloading cloaked playlist…");

        return response()->download($tmpPath, 'cloaked.m3u', [
            'Content-Type' => 'audio/x-mpegurl',
        ])->deleteFileAfterSend(true);
    }
}
