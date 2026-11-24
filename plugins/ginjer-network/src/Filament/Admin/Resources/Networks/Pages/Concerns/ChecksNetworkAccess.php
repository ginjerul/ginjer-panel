<?php

namespace Ginjer\Network\Filament\Admin\Resources\Networks\Pages\Concerns;

/** Network pages check the user's network permission on every request, not only when opened. */
trait ChecksNetworkAccess
{
    /** The NetworkPolicy ability this page needs. */
    protected function networkAbility(): string
    {
        return 'view';
    }

    protected function checkNetworkAccess(): void
    {
        abort_unless(auth()->user()?->can($this->networkAbility(), $this->record), 403);
    }

    public function hydrateChecksNetworkAccess(): void
    {
        $this->checkNetworkAccess();
    }

    protected function userCan(string $ability): bool
    {
        return (bool) auth()->user()?->can($ability, $this->record);
    }
}
