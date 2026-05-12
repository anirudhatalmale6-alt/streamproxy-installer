<?php

declare(strict_types=1);

namespace App\Orchid;

use Orchid\Platform\Dashboard;
use Orchid\Platform\ItemPermission;
use Orchid\Platform\OrchidServiceProvider;
use Orchid\Screen\Actions\Menu;

class PlatformProvider extends OrchidServiceProvider
{
    public function boot(Dashboard $dashboard): void
    {
        parent::boot($dashboard);
    }

    public function menu(): array
    {
        return [
            Menu::make('Links')
                ->icon('link')
                ->route('platform.link.list'),

            Menu::make('Import M3U')
                ->icon('cloud-upload')
                ->route('platform.m3u.import'),

            Menu::make('Backup / Restore')
                ->icon('cloud-download')
                ->route('platform.backup'),
        ];
    }

    public function permissions(): array
    {
        return [];
    }
}
