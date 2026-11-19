<?php

declare(strict_types=1);

namespace App\Tests\Configurator;

use Core\Configurator\Product\SpongeCity\Impact\Polygon;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class PolygonTest extends TestCase
{
    /** a 40 × 30 m U open to the south, its courtyard 20 × 20 m */
    private const array U = [[0, 0], [40000, 0], [40000, 30000], [30000, 30000], [30000, 10000], [10000, 10000], [10000, 30000], [0, 30000]];

    #[Test]
    public function measuresWhatTwoRectanglesShare(): void
    {
        $a = [[0, 0], [10000, 0], [10000, 6000], [0, 6000]];
        $b = [[8000, 2000], [20000, 2000], [20000, 20000], [8000, 20000]];

        self::assertEqualsWithDelta(2000 * 4000, Polygon::overlapArea($a, $b), 1);
        self::assertEqualsWithDelta(2000 * 4000, Polygon::overlapArea($b, $a), 1);
    }

    #[Test]
    public function aShapeInTheCourtyardDoesNotOverlapItsUShapedBuilding(): void
    {
        $courtyard = [[12000, 12000], [28000, 12000], [28000, 30000], [12000, 30000]];
        $acrossAWing = [[5000, 20000], [15000, 20000], [15000, 25000], [5000, 25000]];

        self::assertSame(0.0, Polygon::overlapArea($courtyard, self::U));
        self::assertEqualsWithDelta(5000 * 5000, Polygon::overlapArea($acrossAWing, self::U), 1, 'only the part on the west wing');
    }

    #[Test]
    public function aShapeAgainstAWallOnlyTouchesIt(): void
    {
        $alongTheWall = [[40000, 0], [45000, 0], [45000, 10000], [40000, 10000]];

        self::assertSame(0.0, Polygon::overlapArea($alongTheWall, self::U));
    }

    #[Test]
    public function cutsAConcaveOutlineIntoTrianglesOfTheSameArea(): void
    {
        $area = array_sum(array_map(Polygon::area(...), Polygon::triangles(self::U)));

        self::assertEqualsWithDelta(Polygon::area(self::U), $area, 1);
        // clockwise, a closing corner repeated and a straight corner: the same area
        $messy = [[0, 30000], [10000, 30000], [10000, 10000], [30000, 10000], [30000, 30000], [40000, 30000], [40000, 0], [20000, 0], [0, 0], [0, 30000]];
        self::assertEqualsWithDelta(Polygon::area(self::U), array_sum(array_map(Polygon::area(...), Polygon::triangles($messy))), 1);
    }

    #[Test]
    public function findsTheRainGardensDrawnIntoTheSchoolAtKamminerStrasse(): void
    {
        // Hamburg's outline of the school's main building, and the rain gardens an agent drew around low spots next to it
        $school = [[75113, -66292], [41518, -49178], [58726, -15258], [60854, -16325], [67224, -3749], [65101, -2660], [71342, 9671], [100000, -4882], [100000, -17123], [94178, -28591], [91417, -27156], [89439, -30912], [92307, -32362]];

        self::assertGreaterThan(10_000_000, Polygon::overlapArea([[45000, -35000], [55000, -35000], [55000, -29000], [45000, -29000]], $school));
        self::assertGreaterThan(1_000_000, Polygon::overlapArea([[62000, -3000], [72000, -3000], [72000, 3000], [62000, 3000]], $school));
        self::assertSame(0.0, Polygon::overlapArea([[40000, -8000], [52000, -8000], [52000, 2000], [40000, 2000]], $school));
    }
}
