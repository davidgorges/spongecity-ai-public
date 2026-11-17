<?php

declare(strict_types=1);

namespace App\SpongeCity\SimStadt;

use Symfony\Component\DependencyInjection\Attribute\AutowireLocator;
use Symfony\Component\DependencyInjection\ServiceLocator;

/**
 * The SimStadt template of each workflow the agent can run.
 */
final readonly class SimStadtTemplates
{
    /**
     * @param ServiceLocator<SimStadtTemplate> $templates indexed by the workflow's value
     */
    public function __construct(
        #[AutowireLocator(SimStadtTemplate::class)]
        private ServiceLocator $templates,
    ) {
    }

    public function for(SimStadtWorkflow $workflow): SimStadtTemplate
    {
        return $this->templates->get($workflow->value);
    }
}
