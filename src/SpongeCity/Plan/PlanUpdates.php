<?php

declare(strict_types=1);

namespace App\SpongeCity\Plan;

use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;

/**
 * Tells every page showing a plan that it changed, through the Mercure hub: the page re-renders
 * its 3D view, impact cards and measures (plan_updates_controller.js). Whoever changed it, the
 * in-app agent or one a person connected over MCP, works in another process than the page.
 * Public updates, like the plan itself, which anyone with its id can open.
 */
final readonly class PlanUpdates
{
    public const string TOPIC_FORMAT = 'spongecity/plan/%s';
    public const string KEY_CHANGED = 'changed';

    public function __construct(
        private HubInterface $hub,
    ) {
    }

    public static function topic(string $planId): string
    {
        return sprintf(self::TOPIC_FORMAT, $planId);
    }

    /**
     * @param string $cause what changed it, e.g. the tool's name: for the page's log, not shown
     */
    public function changed(string $planId, string $cause): void
    {
        $this->hub->publish(new Update(self::topic($planId), json_encode([self::KEY_CHANGED => $cause], \JSON_THROW_ON_ERROR)));
    }
}
