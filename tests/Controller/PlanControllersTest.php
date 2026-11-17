<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Controller\Plan\ChatController;
use App\Controller\Plan\ChatMessage;
use App\Controller\Plan\ConditionsController;
use App\SpongeCity\Plan\PlanStore;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The plan's endpoints receive the plan of their {id}, or answer 404; the chat checks its message first.
 */
final class PlanControllersTest extends WebTestCase
{
    #[Test]
    public function anUnknownPlanIsNotFound(): void
    {
        $client = static::createClient();

        $client->request('GET', $client->getContainer()->get('router')->generate(ConditionsController::ROUTE, ['id' => 'nope']));

        self::assertResponseStatusCodeSame(404);
    }

    #[Test]
    public function theChatRejectsAnEmptyOrOverlongMessageBeforeAskingTheAgent(): void
    {
        $client = static::createClient();
        $plan = static::getContainer()->get(PlanStore::class)->create('Berlin', 52.52, 13.40);
        $url = $client->getContainer()->get('router')->generate(ChatController::ROUTE, ['id' => $plan->id]);

        foreach (['   ', str_repeat('x', ChatMessage::MAX_LENGTH + 1)] as $message) {
            $client->jsonRequest('POST', $url, ['message' => $message]);

            self::assertResponseStatusCodeSame(422);
        }
    }
}
