<?php

namespace App\Filament\Resources\SubscriptionTiers\Pages;

use App\Filament\Resources\SubscriptionTiers\SubscriptionTierResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditSubscriptionTier extends EditRecord
{
    protected static string $resource = SubscriptionTierResource::class;

    protected function getHeaderActions(): array
    {
        return [DeleteAction::make()];
    }
}
