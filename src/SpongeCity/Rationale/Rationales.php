<?php

declare(strict_types=1);

namespace App\SpongeCity\Rationale;

/**
 * The rationales of one plan, by the id of the object they explain.
 */
final readonly class Rationales
{
    /**
     * @param array<string, Rationale> $byObject
     */
    public function __construct(public array $byObject = [])
    {
    }

    public function for(string $objectId): ?Rationale
    {
        return $this->byObject[$objectId] ?? null;
    }

    public function with(Rationale $rationale): self
    {
        return new self([...$this->byObject, $rationale->objectId => $rationale]);
    }

    public function without(string $objectId): self
    {
        $byObject = $this->byObject;
        unset($byObject[$objectId]);

        return new self($byObject);
    }
}
