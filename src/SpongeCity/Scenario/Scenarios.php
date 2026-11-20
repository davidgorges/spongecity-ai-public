<?php

declare(strict_types=1);

namespace App\SpongeCity\Scenario;

/**
 * The scenarios of one plan, by name; saving under a name that exists replaces it.
 */
final readonly class Scenarios
{
    public const int MAX = 12;

    /**
     * @param array<string, Scenario> $byName in the order saved
     */
    public function __construct(public array $byName = [])
    {
    }

    public function with(Scenario $scenario): self
    {
        $byName = $this->byName;
        unset($byName[$scenario->name]);
        $byName[$scenario->name] = $scenario;

        // the oldest go first when there are too many
        return new self(array_slice($byName, -self::MAX, null, true));
    }

    public function without(string $name): self
    {
        $byName = $this->byName;
        unset($byName[$name]);

        return new self($byName);
    }

    public function get(string $name): ?Scenario
    {
        return $this->byName[$name] ?? null;
    }
}
