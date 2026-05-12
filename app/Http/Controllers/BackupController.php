<?php

namespace App\Http\Controllers;

use App\Models\Link;
use Illuminate\Http\Request;
use Orchid\Support\Facades\Toast;

class BackupController extends Controller
{
    public function export()
    {
        $links = Link::all(['name', 'original_url', 'type'])->toArray();

        $backup = [
            'exported_at' => now()->toIso8601String(),
            'count' => count($links),
            'links' => $links,
        ];

        $filename = 'streamproxy-backup-' . now()->format('Y-m-d-His') . '.json';

        return response()->json($backup)
            ->header('Content-Disposition', 'attachment; filename="' . $filename . '"');
    }

    public function import(Request $request)
    {
        $request->validate([
            'backup_file' => 'required|file|mimes:json,txt|max:10240',
        ]);

        $content = file_get_contents($request->file('backup_file')->getRealPath());
        $data = json_decode($content, true);

        if (!isset($data['links']) || !is_array($data['links'])) {
            Toast::error('Invalid backup file format.');
            return redirect()->route('platform.link.list');
        }

        $imported = 0;
        $skipped = 0;

        foreach ($data['links'] as $linkData) {
            if (empty($linkData['original_url'])) {
                $skipped++;
                continue;
            }

            $exists = Link::where('original_url', $linkData['original_url'])->exists();
            if ($exists) {
                $skipped++;
                continue;
            }

            $validTypes = ['auto', 'm3u8', 'mp4', 'hls', 'ts', 'mpegts'];
            $type = $linkData['type'] ?? 'auto';
            if (!in_array($type, $validTypes)) {
                $type = 'auto';
            }

            Link::create([
                'name' => $linkData['name'] ?? 'Imported',
                'original_url' => $linkData['original_url'],
                'type' => $type,
            ]);
            $imported++;
        }

        Toast::info("Restored {$imported} links ({$skipped} skipped/duplicates).");
        return redirect()->route('platform.link.list');
    }
}
