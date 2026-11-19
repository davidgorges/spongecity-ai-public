<?php

declare(strict_types=1);

namespace App\Command\Doctor;

/**
 * One finding of app:doctor: what was checked, how it went, and how to fix it.
 */
final readonly class Check
{
    private function __construct(
        public string $name,
        public CheckStatus $status,
        public string $detail,
        public ?string $fix = null,
    ) {
    }

    public static function ok(string $name, string $detail): self
    {
        return new self($name, CheckStatus::Ok, $detail);
    }

    /** a feature that is optional (the chat, SimStadt) will not work */
    public static function warning(string $name, string $detail, string $fix): self
    {
        return new self($name, CheckStatus::Warning, $detail, $fix);
    }

    /** the app itself will not work */
    public static function failure(string $name, string $detail, string $fix): self
    {
        return new self($name, CheckStatus::Failure, $detail, $fix);
    }
}
