<?php

declare(strict_types=1);

namespace Fixwire;

/**
 * One call in a stack trace.
 */
final class Frame
{
    /**
     * @param list<string> $preContext
     * @param list<string> $postContext
     */
    public function __construct(
        public ?string $function = null,
        /** The class, such as Shop\Cart. */
        public ?string $module = null,
        public ?string $file = null,
        public int $line = 0,
        public bool $inApp = false,
        public ?string $contextLine = null,
        public array $preContext = [],
        public array $postContext = [],
    ) {}
}
