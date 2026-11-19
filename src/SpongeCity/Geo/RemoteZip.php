<?php

declare(strict_types=1);

namespace App\SpongeCity\Geo;

use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * A large ZIP on a web server, read file by file with HTTP range requests: the end of
 * the archive for its central directory, then a file's local header and its (deflated)
 * bytes. Hamburg publishes its terrain and building models only as city-wide ZIPs of
 * hundreds of megabytes; a site needs one to four of their tiles. Directory and files
 * are cached.
 */
final readonly class RemoteZip
{
    private const int END_OF_DIRECTORY_SEARCH = 65_557;
    private const string END_OF_DIRECTORY = "PK\x05\x06";
    private const string DIRECTORY_ENTRY = "PK\x01\x02";
    private const int LOCAL_HEADER = 30;
    private const int METHOD_DEFLATE = 8;
    private const int METHOD_STORED = 0;
    private const int CACHE_VERSION = 1;
    private const int TIMEOUT_SECONDS = 30;
    private const int MAX_DURATION_SECONDS = 120;

    public function __construct(
        private HttpClientInterface $httpClient,
        private CacheInterface $cache,
        private string $url,
        private int $cacheSeconds,
    ) {
    }

    /**
     * @return array<string, array{int, int, int}> file name → [local header offset, compressed size, method]
     */
    public function entries(): array
    {
        return $this->cache->get($this->key('directory'), function (ItemInterface $item): array {
            $item->expiresAfter($this->cacheSeconds);
            $size = (int) ($this->httpClient->request('HEAD', $this->url, $this->options())->getHeaders()['content-length'][0] ?? 0);
            $tail = $this->range(max(0, $size - self::END_OF_DIRECTORY_SEARCH), min($size, self::END_OF_DIRECTORY_SEARCH));
            $end = strrpos($tail, self::END_OF_DIRECTORY);
            if (false === $end) {
                throw new \UnexpectedValueException(sprintf('"%s" has no ZIP central directory.', $this->url));
            }
            [$length, $start] = [unpack('V', $tail, $end + 12)[1], unpack('V', $tail, $end + 16)[1]];
            $directory = $this->range($start, $length);
            $entries = [];
            for ($p = 0; self::DIRECTORY_ENTRY === substr($directory, $p, 4);) {
                $method = unpack('v', $directory, $p + 10)[1];
                $compressed = unpack('V', $directory, $p + 20)[1];
                [$nameLength, $extra, $comment] = array_values(unpack('v3', $directory, $p + 28));
                $offset = unpack('V', $directory, $p + 42)[1];
                $entries[substr($directory, $p + 46, $nameLength)] = [$offset, $compressed, $method];
                $p += 46 + $nameLength + $extra + $comment;
            }

            return $entries;
        });
    }

    /** The first file whose name matches a pattern, null if there is none. */
    public function find(string $pattern): ?string
    {
        foreach (array_keys($this->entries()) as $name) {
            if (1 === preg_match($pattern, $name)) {
                return $name;
            }
        }

        return null;
    }

    /**
     * A file's contents, unpacked; cached unless $cache is false (files of tens of megabytes,
     * whose cache entry would double the memory: the caller caches what it makes of them).
     */
    public function read(string $name, bool $cache = true): string
    {
        $entry = $this->entries()[$name] ?? throw new \OutOfBoundsException(sprintf('No "%s" in "%s".', $name, $this->url));
        if (!$cache) {
            return $this->unpack($entry);
        }

        return $this->cache->get($this->key('file_'.$name), function (ItemInterface $item) use ($entry): string {
            $item->expiresAfter($this->cacheSeconds);

            return $this->unpack($entry);
        });
    }

    /**
     * A file's contents in pieces as they arrive, unpacked on the way: for files of tens of
     * megabytes, which need never be in memory at once. Not cached.
     *
     * @param callable(string): void $consume called with each unpacked piece, in order
     */
    public function stream(string $name, callable $consume): void
    {
        [$offset, $compressed, $method] = $this->entries()[$name] ?? throw new \OutOfBoundsException(sprintf('No "%s" in "%s".', $name, $this->url));
        $header = $this->range($offset, self::LOCAL_HEADER);
        $start = $offset + self::LOCAL_HEADER + unpack('v', $header, 26)[1] + unpack('v', $header, 28)[1];
        $options = $this->options();
        $options['headers']['Range'] = sprintf('bytes=%d-%d', $start, $start + $compressed - 1);
        // not buffered: neither the client nor a profiler keeps the body
        $options['buffer'] = false;
        $inflate = self::METHOD_DEFLATE === $method ? inflate_init(\ZLIB_ENCODING_RAW) : null;
        if (false === $inflate) {
            throw new \UnexpectedValueException('A file in the ZIP could not be unpacked.');
        }
        $response = $this->httpClient->request('GET', $this->url, $options);
        foreach ($this->httpClient->stream($response) as $chunk) {
            $bytes = $chunk->getContent();
            if ('' === $bytes) {
                continue;
            }
            $piece = null === $inflate ? $bytes : inflate_add($inflate, $bytes, \ZLIB_SYNC_FLUSH);
            if (false === $piece) {
                throw new \UnexpectedValueException('A file in the ZIP could not be unpacked.');
            }
            if ('' !== $piece) {
                $consume($piece);
            }
        }
    }

    /**
     * @param array{int, int, int} $entry
     */
    private function unpack(array $entry): string
    {
        [$offset, $compressed, $method] = $entry;
        $header = $this->range($offset, self::LOCAL_HEADER);
        $skip = unpack('v', $header, 26)[1] + unpack('v', $header, 28)[1];
        $data = $this->range($offset + self::LOCAL_HEADER + $skip, $compressed);
        $contents = match ($method) {
            self::METHOD_DEFLATE => gzinflate($data),
            self::METHOD_STORED => $data,
            default => false,
        };
        if (false === $contents) {
            throw new \UnexpectedValueException('A file in the ZIP could not be unpacked.');
        }

        return $contents;
    }

    private function key(string $what): string
    {
        return sprintf('remote_zip_v%d_%s', self::CACHE_VERSION, hash('xxh128', $this->url.'|'.$what));
    }

    private function range(int $offset, int $length): string
    {
        $options = $this->options();
        $options['headers']['Range'] = sprintf('bytes=%d-%d', $offset, $offset + $length - 1);
        $body = $this->httpClient->request('GET', $this->url, $options)->getContent();
        if (strlen($body) !== $length) {
            throw new \UnexpectedValueException(sprintf('"%s" did not answer the byte range.', $this->url));
        }

        return $body;
    }

    /**
     * @return array<string, mixed>
     */
    private function options(): array
    {
        return ['timeout' => self::TIMEOUT_SECONDS, 'max_duration' => self::MAX_DURATION_SECONDS];
    }
}
