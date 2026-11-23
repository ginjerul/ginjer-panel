<?php

namespace Ginjer\Network\Filament\Admin\Resources\Networks\Pages;

use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Ginjer\Network\Filament\Admin\Resources\Networks\Actions\SplitServerAction;
use Ginjer\Network\Filament\Admin\Resources\Networks\NetworkResource;

class ListNetworks extends ListRecords
{
    protected static string $resource = NetworkResource::class;

    protected function getHeaderActions(): array
    {
        return [
            SplitServerAction::make(),
            CreateAction::make(),
        ];
    }
}
