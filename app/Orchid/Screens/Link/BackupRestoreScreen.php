<?php

namespace App\Orchid\Screens\Link;

use Orchid\Screen\Screen;
use Orchid\Screen\Fields\Upload;
use Orchid\Screen\Actions\Button;
use Orchid\Screen\Actions\Link;
use Orchid\Support\Facades\Layout;

class BackupRestoreScreen extends Screen
{
    public function name(): string
    {
        return 'Backup & Restore Links';
    }

    public function description(): string
    {
        return 'Download a backup of all links or restore from a previous backup.';
    }

    public function commandBar(): array
    {
        return [
            Link::make('Download Backup')
                ->href(route('backup.export'))
                ->icon('cloud-download'),
        ];
    }

    public function query(): array
    {
        return [];
    }

    public function layout(): array
    {
        return [
            Layout::rows([
                \Orchid\Screen\Fields\Input::make('backup_file')
                    ->type('file')
                    ->accept('.json')
                    ->title('Restore from backup file')
                    ->help('Upload a .json backup file to restore links. Duplicate links will be skipped.'),

                Button::make('Restore')
                    ->icon('cloud-upload')
                    ->method('restore')
                    ->confirm('This will import links from the backup file. Existing links will not be duplicated. Continue?'),
            ]),
        ];
    }

    public function restore(\Illuminate\Http\Request $request)
    {
        $file = $request->file('backup_file');

        if (!$file) {
            \Orchid\Support\Facades\Toast::error('Please select a backup file.');
            return redirect()->route('platform.backup');
        }

        $content = file_get_contents($file->getRealPath());
        $data = json_decode($content, true);

        if (!isset($data['links']) || !is_array($data['links'])) {
            \Orchid\Support\Facades\Toast::error('Invalid backup file format.');
            return redirect()->route('platform.backup');
        }

        $imported = 0;
        $skipped = 0;

        foreach ($data['links'] as $linkData) {
            if (empty($linkData['original_url'])) {
                $skipped++;
                continue;
            }

            if (\App\Models\Link::where('original_url', $linkData['original_url'])->exists()) {
                $skipped++;
                continue;
            }

            $validTypes = ['auto', 'm3u8', 'mp4', 'hls', 'ts', 'mpegts'];
            $type = $linkData['type'] ?? 'auto';
            if (!in_array($type, $validTypes)) {
                $type = 'auto';
            }

            \App\Models\Link::create([
                'name' => $linkData['name'] ?? 'Imported',
                'original_url' => $linkData['original_url'],
                'type' => $type,
            ]);
            $imported++;
        }

        \Orchid\Support\Facades\Toast::info("Restored {$imported} links ({$skipped} skipped/duplicates).");
        return redirect()->route('platform.link.list');
    }
}
