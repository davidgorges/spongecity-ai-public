<?php

namespace App;

use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\HttpKernel\Kernel as BaseKernel;

class Kernel extends BaseKernel
{
    use MicroKernelTrait;

    /**
     * Symfony creates the cache and build dirs, but not the share dir. The SQLite
     * database lives there (see DATABASE_URL), and SQLite cannot create a missing
     * directory, so make sure it exists. On Forge, var/share is a symlink to the
     * shared folder that survives zero-downtime deployments.
     */
    public function boot(): void
    {
        $shareDir = $this->getShareDir();
        if (null !== $shareDir && !is_dir($shareDir) && !@mkdir($shareDir, 0o777, true) && !is_dir($shareDir)) {
            throw new \RuntimeException(\sprintf('Unable to create the share directory (%s).', $shareDir));
        }

        parent::boot();
    }

    /**
     * @return list<string> An array of allowed values for APP_ENV
     */
    private function getAllowedEnvs(): array
    {
        return ['prod', 'dev', 'test'];
    }
}
