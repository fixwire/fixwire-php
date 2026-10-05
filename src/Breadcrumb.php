<?php

declare(strict_types=1);

namespace Fixwire;

/**
 * Something that happened before an error: a log line, a request, a query.
 */
final class Breadcrumb
{
    /**
     * @param array<string, mixed> $data
     */
    public function __construct(
        public ?string $category = null,
        public ?string $message = null,
        public ?Level $level = null,
        public ?string $type = null,
        public array $data = [],
        /** Unix seconds; set when it is added. */
        public ?float $timestamp = null,
    ) {}
}
