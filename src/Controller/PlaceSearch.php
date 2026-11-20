<?php

declare(strict_types=1);

namespace App\Controller;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * What the home page's form posts: the place to plan, by name or address.
 */
final readonly class PlaceSearch
{
    /** the form field the place is posted in */
    public const string FIELD = 'place';

    public function __construct(
        #[Assert\Length(max: 200)]
        public string $place = '',
    ) {
    }
}
