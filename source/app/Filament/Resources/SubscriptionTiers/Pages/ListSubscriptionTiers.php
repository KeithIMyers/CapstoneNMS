<?php

namespace App\Filament\Resources\SubscriptionTiers\Pages;

use App\Filament\Resources\SubscriptionTiers\SubscriptionTierResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListSubscriptionTiers extends ListRecords
{
    protected static string $resource = SubscriptionTierResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
