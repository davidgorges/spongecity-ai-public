<?php

declare(strict_types=1);

namespace App\Tests\Configurator;

use Core\Configurator\Site\SiteObject as O;
use Core\Configurator\Site\SiteType;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A label longer than MAX_LABEL (a measure named after its zone) is cut after a whole word, with
 * an ellipsis, not in the middle of a name.
 */
final class SiteLabelTest extends TestCase
{
    #[Test]
    public function aLongLabelIsCutAfterAWordWithAnEllipsis(): void
    {
        $long = 'Grass pavers: Car park south-west of "Bürogebäude mit Läden und Wohnungen" next to the school yard';

        $short = SiteType::shortLabel($long);

        self::assertLessThanOrEqual(O::MAX_LABEL, mb_strlen($short));
        self::assertStringEndsWith(O::ELLIPSIS, $short);
        self::assertStringStartsWith(mb_substr($short, 0, -1), $long, 'whole words of the label');
        self::assertSame(' ', mb_substr($long, mb_strlen($short) - 1, 1), 'cut at a space');
        self::assertSame('Rain garden by the gym', SiteType::shortLabel('Rain garden by the gym'));

        $normalized = (new SiteType())->normalize(['objects' => [[O::TYPE => O::RAINGARDEN, O::POINTS => [[0, 0], [1000, 0], [1000, 1000]], O::LABEL => $long]]]);
        self::assertSame($short, json_decode($normalized, true)['objects'][0][O::LABEL] ?? null);
    }
}
