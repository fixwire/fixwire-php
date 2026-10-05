<?php

declare(strict_types=1);

namespace Fixwire;

/**
 * Where the SDK sends, and with which key: https://<key>@<host>.
 */
final class Dsn
{
    private function __construct(
        public readonly string $key,
        /** The DSN without the key; the endpoints are relative to it. */
        public readonly string $baseUrl,
    ) {}

    /**
     * @throws \InvalidArgumentException when it has no scheme, host or key
     */
    public static function parse(string $dsn): self
    {
        $u = parse_url(trim($dsn));
        if ($u === false || !\in_array($u['scheme'] ?? '', ['http', 'https'], true) || ($u['host'] ?? '') === '' || ($u['user'] ?? '') === '') {
            throw new \InvalidArgumentException('fixwire: the DSN must look like https://<key>@<host>');
        }
        $port = isset($u['port']) ? ':' . $u['port'] : '';
        $path = rtrim($u['path'] ?? '', '/');

        return new self($u['user'], $u['scheme'] . '://' . $u['host'] . $port . $path);
    }

    /** The address of an endpoint, such as /v1/logs. */
    public function url(string $path): string
    {
        return $this->baseUrl . $path;
    }
}
