<?php

namespace Ginjer\Network\Filament\Admin\Resources\Networks\Pages;

use Filament\Resources\Pages\CreateRecord;
use Ginjer\Network\Filament\Admin\Resources\Networks\NetworkResource;

class CreateNetwork extends CreateRecord
{
    protected static string $resource = NetworkResource::class;

    protected static bool $canCreateAnother = false;

    /** Straight to the edit page, where servers are added. */
    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('edit', ['record' => $this->getRecord()]);
    }
}
