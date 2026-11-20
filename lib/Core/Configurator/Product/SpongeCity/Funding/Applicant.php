<?php

declare(strict_types=1);

namespace Core\Configurator\Product\SpongeCity\Funding;

/**
 * Who would apply for funding: the programmes pay different shares to private owners and small,
 * medium or large companies, and often nothing to the public sector (which has its own budgets).
 */
enum Applicant: string
{
    /** nobody said: funding is shown at the highest share a programme pays, as "up to" */
    case Unknown = 'unknown';
    /** a private owner, an association or a small company */
    case Private = 'private';
    case MediumCompany = 'medium';
    case LargeCompany = 'large';
    /** the city, a district, a public school's owner */
    case Public = 'public';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $a): string => $a->value, self::cases());
    }
}
