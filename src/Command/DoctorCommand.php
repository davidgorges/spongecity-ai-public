<?php

declare(strict_types=1);

namespace App\Command;

use App\Command\Doctor\Check;
use App\Command\Doctor\CheckStatus;
use App\SpongeCity\Chat\PlanAssistant;
use Symfony\AI\Agent\Toolbox\ToolboxInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\Transport\Receiver\MessageCountAwareInterface;
use Symfony\Component\Process\Exception\ExceptionInterface as ProcessException;
use Symfony\Component\Process\Process;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpException;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Checks a developer machine: is the app served, does the agent get its tools, do the workers
 * take messages, are the API key and the OSM user agent set, can SimStadt run.
 * Each problem comes with the command that fixes it.
 */
#[AsCommand(name: 'app:doctor', description: 'Checks that this machine can run SpongeCity AI and says how to fix what is missing')]
final readonly class DoctorCommand
{
    /** the committed default in .env: public Overpass and Nominatim instances want to know who is asking */
    public const string DEFAULT_USER_AGENT = 'SpongeCityAI/0.1 (hackathon demo)';
    private const float TIMEOUT_SECONDS = 5.0;
    private const string START_SERVER = 'symfony server:start -d --no-tls';

    public function __construct(
        private HttpClientInterface $http,
        #[Autowire(service: 'messenger.transport.async')]
        private MessageCountAwareInterface $importQueue,
        #[Autowire(env: 'DEFAULT_URI')]
        private string $appUrl,
        #[Autowire(service: PlanAssistant::PLANNING_TOOLBOX)]
        private ToolboxInterface $planningTools,
        #[Autowire(env: 'ANTHROPIC_API_KEY')]
        private string $anthropicApiKey,
        #[Autowire(env: 'OSM_USER_AGENT')]
        private string $userAgent,
        #[Autowire(env: 'SIMSTADT_DOCKER')]
        private string $docker,
        #[Autowire(env: 'SIMSTADT_IMAGE')]
        private string $simStadtImage,
        #[Autowire(env: 'SIMSTADT_PLATFORM')]
        private string $simStadtPlatform,
    ) {
    }

    public function __invoke(SymfonyStyle $io): int
    {
        $io->title('SpongeCity AI: checking this machine');

        $checks = [
            ...$this->server(),
            $this->agentTools(),
            $this->importWorker(),
            $this->apiKey(),
            $this->userAgent(),
            ...$this->simStadt(),
        ];

        $io->table(['', 'Check', 'Result'], array_map(
            static fn (Check $check): array => [$check->status->symbol(), $check->name, $check->detail],
            $checks,
        ));

        $fixes = array_filter($checks, static fn (Check $check): bool => null !== $check->fix);
        if ([] !== $fixes) {
            $io->section('To fix');
            $io->listing(array_map(static fn (Check $check): string => sprintf('<options=bold>%s</>: %s', $check->name, $check->fix), $fixes));
        }

        if ([] !== array_filter($checks, static fn (Check $check): bool => CheckStatus::Failure === $check->status)) {
            $io->error('The app will not run until the failures above are fixed.');

            return Command::FAILURE;
        }
        $io->success([] === $fixes ? 'Everything is set up.' : 'The app runs. The warnings only affect optional features.');

        return Command::SUCCESS;
    }

    /**
     * @return list<Check>
     */
    private function server(): array
    {
        try {
            $status = $this->http->request('GET', $this->appUrl, ['timeout' => self::TIMEOUT_SECONDS, 'max_redirects' => 0])->getStatusCode();
        } catch (HttpException) {
            return [Check::failure('Web server', sprintf('Nothing answers at %s.', $this->appUrl), sprintf('start it with `%s` (with the Symfony CLI, DEFAULT_URI then follows its port).', self::START_SERVER))];
        }
        if (200 !== $status) {
            return [Check::failure('Web server', sprintf('%s answers with HTTP %d.', $this->appUrl, $status), 'open it in a browser and read var/log/dev.log.')];
        }

        return [Check::ok('Web server', sprintf('Serving at %s', $this->appUrl))];
    }

    /**
     * The agent starts this application's own MCP server as a child process (bin/console mcp:server,
     * stdio) and lists its tools: what a chat turn does first.
     */
    private function agentTools(): Check
    {
        $count = count($this->planningTools->getTools());
        if ($count > 0) {
            return Check::ok('Agent tools', sprintf('The agent gets %d tools from bin/console mcp:server', $count));
        }

        return Check::failure('Agent tools', 'The agent gets no tools from its MCP server (bin/console mcp:server, over stdio), so the chat cannot plan.', 'run `php bin/console mcp:server` and read what it prints; the reason is also logged as "Failed to list the tools of MCP server".');
    }

    private function importWorker(): Check
    {
        $waiting = $this->importQueue->getMessageCount();
        if (0 === $waiting) {
            return Check::ok('Import worker', 'No site imports waiting');
        }

        return Check::failure('Import worker', sprintf('%d site import(s) waiting: new plans stay "pending".', $waiting), sprintf('`%s` starts the workers from .symfony.local.yaml; without the Symfony CLI run `bin/console messenger:consume async`.', self::START_SERVER));
    }

    private function apiKey(): Check
    {
        if ('' !== trim($this->anthropicApiKey)) {
            return Check::ok('Anthropic API key', 'Set');
        }

        return Check::warning('Anthropic API key', 'Not set: the planning chat will not answer. Everything else works.', 'add ANTHROPIC_API_KEY=… to .env.local, or `bin/console secrets:set ANTHROPIC_API_KEY`.');
    }

    private function userAgent(): Check
    {
        if (self::DEFAULT_USER_AGENT !== $this->userAgent) {
            return Check::ok('OSM user agent', $this->userAgent);
        }

        return Check::warning('OSM user agent', 'Still the shared default: public Overpass and Nominatim servers may reject or throttle it.', 'add OSM_USER_AGENT="YourName-dev (you@example.com)" to .env.local.');
    }

    /**
     * @return list<Check>
     */
    private function simStadt(): array
    {
        if (!$this->succeeds([$this->docker, 'info'])) {
            return [Check::warning('SimStadt (optional)', 'Docker is not running: the agent cannot simulate the energy of buildings. Everything else works.', 'only if you need building simulations: start Docker Desktop, then run this check again.')];
        }
        if (!$this->succeeds([$this->docker, 'image', 'inspect', $this->simStadtImage])) {
            $platform = '' === $this->simStadtPlatform ? '' : sprintf(' --platform %s', $this->simStadtPlatform);

            return [Check::warning('SimStadt (optional)', sprintf('Docker runs, but the image %s is not pulled.', $this->simStadtImage), sprintf('only if you need building simulations: `docker pull%s %s` (about 1.3 GB).', $platform, $this->simStadtImage))];
        }

        return [Check::ok('SimStadt (optional)', sprintf('Docker runs and %s is there', $this->simStadtImage))];
    }

    /**
     * @param list<string> $command
     */
    private function succeeds(array $command): bool
    {
        try {
            $process = new Process($command, timeout: 20);
            $process->run();

            return $process->isSuccessful();
        } catch (ProcessException) {
            return false;
        }
    }
}
