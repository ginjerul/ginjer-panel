<?php

namespace Ginjer\Network\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum MemberRole: string implements HasColor, HasLabel
{
    case Redis = 'redis';
    case Shard = 'shard';
    case Proxy = 'proxy';
    case Other = 'other';

    public function getLabel(): string
    {
        return match ($this) {
            self::Redis => 'Redis',
            self::Shard => 'Reef shard',
            self::Proxy => 'Velocity proxy',
            self::Other => 'Other',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Redis => 'danger',
            self::Shard => 'success',
            self::Proxy => 'info',
            self::Other => 'gray',
        };
    }
}
