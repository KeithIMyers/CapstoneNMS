<?php

namespace App\Filament\Resources\AiProviders\Pages;

use App\Filament\Resources\AiProviders\AiProviderResource;
use App\Services\Ai\AiClient;
use App\Services\Ai\AiException;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;

class EditAiProvider extends EditRecord
{
    protected static string $resource = AiProviderResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('testConnection')
                ->label('Test connection')
                ->icon('heroicon-o-signal')
                ->color('gray')
                ->action(function () {
                    try {
                        $driver = app(AiClient::class)->driverFor($this->record);
                        $driver->ping();
                        Notification::make()
                            ->title('Connection OK')
                            ->body('Provider "'.$this->record->name.'" responded successfully.')
                            ->success()
                            ->send();
                    } catch (AiException $e) {
                        Notification::make()
                            ->title('Connection failed')
                            ->body(\Illuminate\Support\Str::limit($e->getMessage(), 500))
                            ->danger()
                            ->send();
                    } catch (\Throwable $e) {
                        Notification::make()
                            ->title('Unexpected error')
                            ->body(\Illuminate\Support\Str::limit($e->getMessage(), 500))
                            ->danger()
                            ->send();
                    }
                }),
            DeleteAction::make(),
        ];
    }
}
