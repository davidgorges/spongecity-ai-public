<?php

declare(strict_types=1);

namespace App\Controller\ArgumentResolver;

use App\SpongeCity\Plan\Plan;
use App\SpongeCity\Plan\PlanStore;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Controller\ValueResolverInterface;
use Symfony\Component\HttpKernel\ControllerMetadata\ArgumentMetadata;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * A controller argument typed Plan is the plan of the route's {id}; 404 when there is none.
 */
final readonly class PlanValueResolver implements ValueResolverInterface
{
    public const string ROUTE_PARAMETER = 'id';

    public function __construct(
        private PlanStore $plans,
    ) {
    }

    /**
     * @return iterable<Plan>
     */
    public function resolve(Request $request, ArgumentMetadata $argument): iterable
    {
        $id = $request->attributes->get(self::ROUTE_PARAMETER);
        if (Plan::class !== $argument->getType() || !is_string($id)) {
            return [];
        }

        return [$this->plans->find($id) ?? throw new NotFoundHttpException(sprintf('No plan "%s".', $id))];
    }
}
