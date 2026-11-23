<?php

namespace Ginjer\Network\Services\Optimizer;

use Closure;

/**
 * One recommended setting.
 *
 * $values maps a profile ("balanced", "performance") to the value it wants; a profile that isn't
 * listed leaves the setting alone. $when decides whether the rule applies to a server at all and
 * returns null (applies) or the reason it doesn't.
 */
final class Rule
{
    public const BALANCED = 'balanced';

    public const PERFORMANCE = 'performance';

    /**
     * @param  'properties'|'yaml'|'startup'  $format
     * @param  array<string, string|int|float|bool|Closure>  $values  a Closure gets the Context and returns the value
     * @param  null|Closure(Context): ?string  $when
     */
    public function __construct(
        public string $id,
        public string $file,
        public string $format,
        public string $key,
        public string $title,
        public string $reason,
        public array $values,
        public bool $gameplay = false,
        public ?Closure $when = null,
    ) {}

    public function valueFor(string $profile, Context $context): string|int|float|bool|null
    {
        // performance includes everything balanced does
        $value = $this->values[$profile] ?? ($profile === self::PERFORMANCE ? ($this->values[self::BALANCED] ?? null) : null);

        return $value instanceof Closure ? $value($context) : $value;
    }

    public function skipReason(Context $context): ?string
    {
        return $this->when ? ($this->when)($context) : null;
    }
}
