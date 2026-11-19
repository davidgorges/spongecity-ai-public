<?php

declare(strict_types=1);

namespace App\SpongeCity\SimStadt;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

/**
 * Runs a SimStadt workflow on a CityGML file in SimStadt's own Docker image
 * (simstadt/simstadt:cli: Java, INSEL and simstadtpy in one; nothing installed on the
 * host). var/simstadt is mounted as /data and is SimStadt's repository: each run puts
 * its file, project and result table there under its own name and removes them after,
 * while the repository's .cache (weather downloaded from PVGIS, irradiance, shadows)
 * stays, so later runs need not download again.
 *
 * Docker must be available to the PHP process that runs it (the Messenger worker).
 */
final readonly class SimStadtRunner
{
    private const string MOUNT = '/data';
    /** a decimal point in the CSV, whatever the container's locale */
    private const string LOCALE = 'en_GB';
    private const int TAIL_BYTES = 1500;
    /** the weather service SimStadt downloads from drops connections now and then: such a run is tried again */
    private const string TRANSIENT = 'PVGIS';
    private const int ATTEMPTS = 2;

    public function __construct(
        #[Autowire(env: 'SIMSTADT_IMAGE')]
        private string $image,
        #[Autowire(env: 'SIMSTADT_PLATFORM')]
        private string $platform,
        #[Autowire(env: 'SIMSTADT_DOCKER')]
        private string $docker,
        #[Autowire(env: 'int:SIMSTADT_TIMEOUT_SECONDS')]
        private int $timeoutSeconds,
        #[Autowire('%kernel.project_dir%/var/simstadt')]
        private string $repository,
        private Filesystem $filesystem = new Filesystem(),
    ) {
    }

    /**
     * @return array{output: string, csv: string, seconds: float}
     */
    public function run(SimStadtTemplate $template, string $cityGml): array
    {
        $name = 'run_'.bin2hex(random_bytes(6));
        [$input, $result, $project] = [$name.'.gml', $name.'.csv', $name];
        $this->filesystem->dumpFile($this->repository.'/'.$input, $cityGml);
        try {
            $command = [$this->docker, 'run', '--rm'];
            if ('' !== $this->platform) {
                $command = [...$command, '--platform', $this->platform];
            }
            $command = [
                ...$command,
                '-e', 'LOCALE='.self::LOCALE,
                '-v', $this->repository.':'.self::MOUNT,
                $this->image,
                'simstadt', $template->name(), self::MOUNT.'/'.$input,
                '--project-path', self::MOUNT.'/'.$project,
                '--save', self::MOUNT.'/'.$result,
            ];
            $start = microtime(true);
            for ($attempt = 1;; ++$attempt) {
                $process = new Process($command, timeout: $this->timeoutSeconds);
                $process->run();
                $csv = is_file($this->repository.'/'.$result) ? (string) file_get_contents($this->repository.'/'.$result) : '';
                $log = trim($process->getOutput()."\n".$process->getErrorOutput());
                if ($process->isSuccessful() && '' !== $csv) {
                    return ['output' => $process->getOutput(), 'csv' => $csv, 'seconds' => microtime(true) - $start];
                }
                if ($attempt >= self::ATTEMPTS || !str_contains($log, self::TRANSIENT)) {
                    throw new \RuntimeException(sprintf('SimStadt %s failed (exit %s): %s', $template->name(), $process->getExitCode() ?? '?', substr($log, -self::TAIL_BYTES)));
                }
            }
        } finally {
            $this->filesystem->remove([$this->repository.'/'.$input, $this->repository.'/'.$result, $this->repository.'/'.$project.'.proj']);
        }
    }
}
