<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Command\DoctorCommand;
use App\SpongeCity\Chat\PlanAssistant;
use PHPUnit\Framework\Attributes\Test;
use Symfony\AI\Agent\Toolbox\ToolboxInterface;
use Symfony\AI\Agent\Toolbox\ToolResult;
use Symfony\AI\Platform\Result\ToolCall;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

final class DoctorCommandTest extends KernelTestCase
{
    private const string APP_URL = 'http://127.0.0.1:8123/';

    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir().'/doctor_'.bin2hex(random_bytes(4));
        (new Filesystem())->mkdir($this->directory);
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->directory);
        parent::tearDown();
    }

    #[Test]
    public function aCompleteSetupPasses(): void
    {
        $tester = $this->doctor($this->servingApp(), docker: $this->fakeDocker(imagePulled: true));

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        $display = $tester->getDisplay();
        self::assertStringContainsString('Serving at '.self::APP_URL, $display);
        self::assertMatchesRegularExpression('/The agent gets \d+ tools from bin\/console mcp:server/', $display, 'the real child process, as the agent starts it');
        self::assertStringContainsString('Everything is set up.', $display);
        self::assertStringNotContainsString('To fix', $display);
    }

    #[Test]
    public function aStoppedServerFailsAndSaysHowToStartIt(): void
    {
        $nothingListens = new MockHttpClient(static fn (): MockResponse => new MockResponse('', ['error' => 'Connection refused']));

        $tester = $this->doctor($nothingListens, docker: $this->fakeDocker(imagePulled: true));

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('Nothing answers at '.self::APP_URL, $tester->getDisplay());
        self::assertStringContainsString('symfony server:start -d --no-tls', $tester->getDisplay());
    }

    #[Test]
    public function anAgentWithoutToolsFails(): void
    {
        $noTools = new class implements ToolboxInterface {
            public function getTools(): array
            {
                return [];
            }

            public function execute(ToolCall $toolCall): ToolResult
            {
                throw new \LogicException('not called');
            }
        };

        $tester = $this->doctor($this->servingApp(), docker: $this->fakeDocker(imagePulled: true), tools: $noTools);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('php bin/console mcp:server', $tester->getDisplay());
    }

    #[Test]
    public function waitingImportsPointAtTheWorker(): void
    {
        $queue = new InMemoryTransport();
        $queue->send(new Envelope(new \stdClass()));

        $tester = $this->doctor($this->servingApp(), docker: $this->fakeDocker(imagePulled: true), queue: $queue);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('1 site import(s) waiting', $tester->getDisplay());
    }

    #[Test]
    public function optionalFeaturesOnlyWarn(): void
    {
        $tester = $this->doctor($this->servingApp(), docker: $this->fakeDocker(imagePulled: false), apiKey: '', userAgent: DoctorCommand::DEFAULT_USER_AGENT);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        $display = $tester->getDisplay();
        self::assertStringContainsString('ANTHROPIC_API_KEY', $display);
        self::assertStringContainsString('OSM_USER_AGENT', $display);
        self::assertStringContainsString('docker pull --platform linux/amd64 simstadt/simstadt:cli', $display);
        self::assertStringContainsString('The warnings only affect optional features.', $display);
    }

    #[Test]
    public function withoutDockerSimStadtIsOnlyAWarning(): void
    {
        $tester = $this->doctor($this->servingApp(), docker: $this->directory.'/no-docker-here');

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('Docker is not running', $tester->getDisplay());
    }

    private function doctor(MockHttpClient $http, string $docker, ?InMemoryTransport $queue = null, string $apiKey = 'sk-ant-test', string $userAgent = 'Tests (tests@example.com)', ?ToolboxInterface $tools = null): CommandTester
    {
        self::bootKernel();
        static::getContainer()->set(DoctorCommand::class, new DoctorCommand(
            $http,
            $queue ?? new InMemoryTransport(),
            self::APP_URL,
            $tools ?? static::getContainer()->get(PlanAssistant::PLANNING_TOOLBOX),
            $apiKey,
            $userAgent,
            $docker,
            'simstadt/simstadt:cli',
            'linux/amd64',
        ));

        $tester = new CommandTester((new Application(self::$kernel))->find('app:doctor'));
        $tester->execute([]);

        return $tester;
    }

    private function servingApp(): MockHttpClient
    {
        return new MockHttpClient(static fn (string $method, string $url): MockResponse => match ([$method, $url]) {
            ['GET', self::APP_URL] => new MockResponse('<html></html>'),
            default => new MockResponse('', ['http_code' => 404]),
        });
    }

    /**
     * A stand-in for the docker CLI: `docker info` succeeds, `docker image inspect` only when pulled.
     */
    private function fakeDocker(bool $imagePulled): string
    {
        $path = $this->directory.'/docker';
        (new Filesystem())->dumpFile($path, sprintf("#!/bin/sh\n[ \"\$1\" = image ] && exit %d\nexit 0\n", $imagePulled ? 0 : 1));
        chmod($path, 0o755);

        return $path;
    }
}
