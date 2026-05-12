<?php

namespace App\Orchid\Screens\Link;

use App\Models\Link;
use Orchid\Screen\Screen;
use Orchid\Screen\Actions\Button;
use Orchid\Screen\Fields\Input;
use Orchid\Screen\Fields\TextArea;
use Orchid\Support\Facades\Layout;
use Orchid\Support\Facades\Toast;
use Illuminate\Http\Request;

class LinkEditScreen extends Screen
{
    public ?Link $link = null;

    public function name(): string
    {
        return $this->link?->exists ? 'Edit Link' : 'Create Link';
    }

    public function query(Link $link): array
    {
        return [
            'link' => $link,
        ];
    }

    public function commandBar(): array
    {
        return [
            Button::make('Save')
                ->icon('check')
                ->method('save'),

            Button::make('Delete')
                ->icon('trash')
                ->method('remove')
                ->confirm('Are you sure you want to delete this link?')
                ->canSee($this->link?->exists ?? false),
        ];
    }

    public function layout(): array
    {
        return [
            Layout::rows([
                Input::make('link.name')
                    ->title('Name')
                    ->required(),

                TextArea::make('link.original_url')
                    ->title('Original URL')
                    ->placeholder('https://origin.example.com/stream.m3u8')
                    ->rows(2)
                    ->required(),

                Input::make('link.type')
                    ->title('Type')
                    ->value('hls')
                    ->readonly(),
            ]),
        ];
    }

    public function save(Link $link, Request $request): \Illuminate\Http\RedirectResponse
    {
        $link->fill($request->get('link'))->save();
        Toast::info('Link saved.');
        return redirect()->route('platform.link.list');
    }

    public function remove(Link $link): \Illuminate\Http\RedirectResponse
    {
        $link->delete();
        Toast::info('Link deleted.');
        return redirect()->route('platform.link.list');
    }
}
