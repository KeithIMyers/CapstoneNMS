<?php

namespace App\Filament\Resources\WireSources\Pages;

use App\Filament\Resources\WireSources\WireSourceResource;
use App\Services\Wire\WireIngestor;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;

class ListWireSources extends ListRecords
{
    protected static string $resource = WireSourceResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('ingestAll')
                ->label('Ingest all now')
                ->icon('heroicon-o-arrow-down-on-square-stack')
                ->color('gray')
                ->action(function () {
                    $rows = app(WireIngestor::class)->ingestAll();
                    $created = array_sum(array_column($rows, 'created'));
                    $errors  = array_sum(array_column($rows, 'errors'));
                    Notification::make()
                        ->title(count($rows).' source(s) processed')
                        ->body("Created {$created} draft(s) · {$errors} error(s)")
                        ->color($errors > 0 ? 'warning' : 'success')
                        ->send();
                }),
            CreateAction::make(),
        ];
    }
}
