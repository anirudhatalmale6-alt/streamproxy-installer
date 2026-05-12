<?php

declare(strict_types=1);

use App\Orchid\Screens\Link\LinkListScreen;
use App\Orchid\Screens\Link\LinkEditScreen;
use App\Orchid\Screens\Link\LinkBulkCreateScreen;
use App\Orchid\Screens\Link\BackupRestoreScreen;
use App\Orchid\Screens\M3uImportScreen;
use App\Orchid\Screens\User\UserProfileScreen;
use Tabuna\Breadcrumbs\Trail;

Route::screen('/links', LinkListScreen::class)
    ->name('platform.link.list');

Route::screen('/links/create', LinkEditScreen::class)
    ->name('platform.link.create');

Route::screen('/links/bulk-create', LinkBulkCreateScreen::class)
    ->name('platform.link.bulk-create');

Route::screen('/links/{link}/edit', LinkEditScreen::class)
    ->name('platform.link.edit');

Route::screen('/m3u-import', M3uImportScreen::class)
    ->name('platform.m3u.import');

Route::screen('/backup', BackupRestoreScreen::class)
    ->name('platform.backup');

Route::get('/backup/export', [\App\Http\Controllers\BackupController::class, 'export'])
    ->name('backup.export');

Route::screen('profile', UserProfileScreen::class)
    ->name('platform.profile');

Route::screen('/main', \App\Orchid\Screens\PlatformScreen::class)
    ->name('platform.main');
