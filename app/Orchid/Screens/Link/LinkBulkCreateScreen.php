<?php

namespace App\Orchid\Screens\Link;

use App\Models\Link;
use App\Services\LinkCrypt;
use Orchid\Screen\Screen;
use Orchid\Screen\Actions\Button;
use Orchid\Support\Facades\Layout;
use Orchid\Support\Facades\Toast;
use Illuminate\Http\Request;
use Throwable;

class LinkBulkCreateScreen extends Screen
{
    public function name(): string
    {
        return 'Add Multiple Links';
    }

    public function description(): string
    {
        return 'Create several HLS links at once. Fill in as many rows as you need.';
    }

    public function query(): array
    {
        return [];
    }

    public function commandBar(): array
    {
        return [
            Button::make('Save All')
                ->icon('check')
                ->method('save'),
        ];
    }

    public function layout(): array
    {
        // Render a plain HTML form with JS-driven "Add row" functionality.
        // Orchid doesn't have a repeater field, so we use a custom view component.
        return [
            Layout::view('orchid.bulk-links'),
        ];
    }

    public function save(Request $request): \Illuminate\Http\RedirectResponse
    {
        $rows  = $request->input('links', []);
        $crypt = app(LinkCrypt::class);
        $saved = 0;

        // Same as Link.php: store only the plain origin URL.
        // If the user pasted a proxied URL (https://…/view/…), unwrap it first.
        $ownBase = rtrim(url('/'), '/') . '/view/';

        foreach ($rows as $row) {
            $name = trim($row['name'] ?? '');
            $url  = trim($row['original_url'] ?? '');

            if ($name === '' || $url === '') {
                continue;
            }

            // Unwrap self-referencing proxy URLs (e.g. copied from the admin list)
            if (str_starts_with($url, $ownBase)) {
                $payload = preg_replace('/\.m3u$/i', '', substr($url, strlen($ownBase)));
                try {
                    $url = $crypt->decrypt($payload);
                } catch (Throwable) {
                    // Not a valid proxy payload — keep original
                }
            }

            if (! filter_var($url, FILTER_VALIDATE_URL)) {
                continue;
            }

            Link::firstOrCreate(
                ['original_url' => $url],
                ['name' => $name, 'type' => 'hls']
            );

            $saved++;
        }

        Toast::info("Saved {$saved} link(s).");
        return redirect()->route('platform.link.list');
    }
}
