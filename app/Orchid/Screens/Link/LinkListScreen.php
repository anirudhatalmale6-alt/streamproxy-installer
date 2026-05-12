<?php

namespace App\Orchid\Screens\Link;

use App\Models\Link;
use App\Services\LinkCrypt;
use Orchid\Screen\Screen;
use Orchid\Screen\Actions\Link as LinkAction;
use Orchid\Support\Facades\Layout;
use Orchid\Screen\TD;

class LinkListScreen extends Screen
{
    public function name(): string
    {
        return 'Links';
    }

    public function description(): string
    {
        return 'All proxy links';
    }

    public function commandBar(): array
    {
        return [
            LinkAction::make('Add Link')
                ->route('platform.link.bulk-create')
                ->icon('plus'),

            LinkAction::make('Import M3U')
                ->route('platform.m3u.import')
                ->icon('cloud-upload'),

            LinkAction::make('Backup / Restore')
                ->route('platform.backup')
                ->icon('cloud-download'),
        ];
    }

    public function query(): array
    {
        return [
            'links' => Link::orderByDesc('created_at')->paginate(50),
        ];
    }

    public function layout(): array
    {
        return [
            Layout::table('links', [
                TD::make('name', 'Name')
                    ->render(fn (Link $link) => e($link->name)),

                TD::make('original_url', 'Original URL')
                    ->render(function (Link $link) {
                        $full = e($link->original_url);
                        $short = e(\Str::limit($link->original_url, 55));
                        return '<div style="display:flex;align-items:center;gap:6px;min-width:0">'
                            . '<span title="' . $full . '" style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap;flex:1;font-size:0.82em">' . $short . '</span>'
                            . '<button type="button" onclick="navigator.clipboard.writeText(this.dataset.url)" data-url="' . $full . '" title="Copy" style="flex-shrink:0;border:none;background:transparent;cursor:pointer;font-size:0.8em;padding:2px 6px;color:#6c757d">copy</button>'
                            . '</div>';
                    }),

                TD::make('type', 'Type')
                    ->render(fn (Link $link) => e($link->type)),

                TD::make('proxied_url', 'Proxied URL')
                    ->render(function (Link $link) {
                        $full = e($link->proxied_url);
                        $short = e(\Str::limit($link->proxied_url, 55));
                        return '<div style="display:flex;align-items:center;gap:6px;min-width:0">'
                            . '<span title="' . $full . '" style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap;flex:1;font-size:0.82em">' . $short . '</span>'
                            . '<button type="button" onclick="navigator.clipboard.writeText(this.dataset.url)" data-url="' . $full . '" title="Copy" style="flex-shrink:0;border:none;background:transparent;cursor:pointer;font-size:0.8em;padding:2px 6px;color:#6c757d">copy</button>'
                            . '</div>';
                    }),

                TD::make('created_at', 'Created')
                    ->render(fn (Link $link) => $link->created_at?->format('Y-m-d H:i')),

                TD::make('Actions')
                    ->render(fn (Link $link) =>
                        '<a href="' . route('platform.link.edit', $link) . '" class="btn btn-sm btn-default">Edit</a>'
                    ),
            ]),
        ];
    }
}
