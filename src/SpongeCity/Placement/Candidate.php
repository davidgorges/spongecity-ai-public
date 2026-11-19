<?php

declare(strict_types=1);

namespace App\SpongeCity\Placement;

use Core\Configurator\Site\SiteObject as O;

/**
 * A place where an intervention works, found by an InterventionSiting: the object as it would be
 * added, what it achieves there (numbers a solver could weigh later) and why it was chosen.
 */
final readonly class Candidate
{
    /**
     * @param array<string, mixed> $object   the site object to add (type, points, fields)
     * @param array<string, mixed> $metrics  what it achieves, in the units its keys name
     * @param list<string>         $reasons  why here, each with its source
     * @param list<string>         $warnings what to check or say
     */
    public function __construct(
        public string $id,
        public array $object,
        public array $metrics,
        public array $reasons,
        public array $warnings = [],
    ) {
    }

    /** the same candidate with one more metric */
    public function withMetric(string $key, mixed $value): self
    {
        return new self($this->id, $this->object, [...$this->metrics, $key => $value], $this->reasons, $this->warnings);
    }

    /** the same candidate under the id it gets once the candidates are ranked */
    public function withId(string $id): self
    {
        return new self($id, $this->object, $this->metrics, $this->reasons, $this->warnings);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            O::TYPE => $this->object[O::TYPE],
            O::POINTS => $this->object[O::POINTS],
            'metrics' => $this->metrics,
            'reasons' => $this->reasons,
            'warnings' => $this->warnings,
        ];
    }
}
