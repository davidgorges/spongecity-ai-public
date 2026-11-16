<?php

declare(strict_types=1);

namespace App\SpongeCity\Geo;

/**
 * Where a layer of a site came from: who publishes it, as of when, under which licence,
 * and what it cannot tell. Shown as the attribution in the view and given to the agent,
 * so it can tell measured, modelled and assumed information apart.
 */
final readonly class DataSource
{
    public const string KEY = 'key';
    public const string TITLE = 'title';
    public const string PUBLISHER = 'publisher';
    public const string AS_OF = 'asOf';
    public const string LICENCE = 'licence';
    public const string URL = 'url';
    public const string LIMITATIONS = 'limitations';

    public function __construct(
        public string $key,
        public string $title,
        public string $publisher,
        public string $asOf,
        public string $licence,
        public string $url,
        public string $limitations = '',
    ) {
    }

    /**
     * @return array<string, string>
     */
    public function toArray(): array
    {
        return [
            self::KEY => $this->key,
            self::TITLE => $this->title,
            self::PUBLISHER => $this->publisher,
            self::AS_OF => $this->asOf,
            self::LICENCE => $this->licence,
            self::URL => $this->url,
            self::LIMITATIONS => $this->limitations,
        ];
    }
}
