<?php

declare(strict_types=1);

namespace App\Command\Doctor;

enum CheckStatus: string
{
    case Ok = 'ok';
    case Warning = 'warning';
    case Failure = 'failure';

    public function symbol(): string
    {
        return match ($this) {
            self::Ok => '<info>✔</info>',
            self::Warning => '<comment>!</comment>',
            self::Failure => '<error>✘</error>',
        };
    }
}
