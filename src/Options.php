<?php

declare(strict_types=1);

namespace Fixwire;

use Fixwire\Transport\Transport;

/**
 * The SDK's options. Only the DSN is needed; without one (and without FIXWIRE_DSN) the SDK does
 * nothing.
 *
 *     \Fixwire\init(['dsn' => 'https://fw_pk_live_…@ingest.eu.fixwire.io', 'release' => 'shop@1.4.0']);
 */
final class Options
{
    /** The project's DSN; FIXWIRE_DSN when not set. */
    public ?string $dsn = null;

    /** The app's version, such as shop@1.4.0; FIXWIRE_RELEASE when not set. */
    public ?string $release = null;

    /** Where the app runs; FIXWIRE_ENVIRONMENT, else production. */
    public ?string $environment = null;

    /** The machine's name; its host name when not set. */
    public ?string $serverName = null;

    /** The service's name: OTEL_SERVICE_NAME when not set, else the name in a name@version release. */
    public ?string $serviceName = null;

    /** The share of errors and messages sent (default 1). */
    public float $sampleRate = 1.0;

    /** The share of new traces kept (default 0: no tracing); continued traces follow the caller. */
    public float $tracesSampleRate = 0.0;

    /** @var list<string> URLs outgoing requests carry trace headers to (those holding one of these). */
    public array $tracePropagationTargets = [];

    /** @var (callable(Event): ?Event)|null changes an event before it is sent, or drops it by returning null */
    public $beforeSend = null;

    /** @var (callable(Breadcrumb): ?Breadcrumb)|null changes a breadcrumb, or drops it by returning null */
    public $beforeBreadcrumb = null;

    /** The breadcrumbs kept (default 100). */
    public int $maxBreadcrumbs = 100;

    /** Send the user's IP address and identifying request headers (off by default). */
    public bool $sendDefaultPii = false;

    /** Mask secrets and personal data on the device, as the Fixwire server does (on). */
    public bool $redact = true;

    /** @var list<string>|null the key fragments whose values are filtered whole; null for the server's */
    public ?array $sensitiveKeys = null;

    /** @var array{per_issue_burst?: int, per_issue_per_minute?: float, per_minute?: float, enabled?: bool} */
    public array $errorBudget = [];

    /** @var list<string> class prefixes of your code (frames under the project root and outside vendor/ are yours anyway) */
    public array $inAppInclude = [];

    /** @var list<string> class prefixes that are not your code */
    public array $inAppExclude = [];

    /** Where your code lives: frames are named relative to it. The Composer project's root when not set. */
    public ?string $projectRoot = null;

    /** Source lines kept around each of your frames (default 5; 0 for none). */
    public int $contextLines = 5;

    /** The events and spans kept until they are sent (default 100). */
    public int $maxQueue = 100;

    /** The timeout of a request to Fixwire, in seconds (default 2: it runs at the end of a request). */
    public float $timeout = 2.0;

    /** Log what the SDK does, and what it drops, to PHP's error log; FIXWIRE_DEBUG=1 turns it on too. */
    public bool $debug = false;

    /**
     * Count requests for release health. Off by default in PHP: each request would cost one more
     * request to Fixwire. Long-running workers may turn it on.
     */
    public bool $autoSessionTracking = false;

    /** Report uncaught exceptions and fatal errors (on). */
    public bool $captureUncaught = true;

    /**
     * When PHP serves a web request, track it from $_SERVER (on): a server span continuing the
     * caller's trace, the request's details on events, its session. Framework integrations that
     * track requests themselves turn it off.
     */
    public bool $trackRequest = true;

    /** The PHP errors (warnings, notices, …) that become breadcrumbs (default E_ALL); fatal ones are events. */
    public int $errorTypes = \E_ALL;

    /** Sends to Fixwire; for tests (default: curl, else PHP streams). */
    public ?Transport $transport = null;

    /**
     * @param array<string, mixed> $options snake_case keys, as in the docs
     *
     * @throws \InvalidArgumentException for an option that doesn't exist
     */
    public static function fromArray(array $options): self
    {
        $o = new self();
        foreach ($options as $key => $value) {
            $property = lcfirst(str_replace('_', '', ucwords((string) $key, '_')));
            if (!property_exists($o, $property)) {
                throw new \InvalidArgumentException("fixwire: no option '{$key}'");
            }
            $o->{$property} = match ($property) {
                'sampleRate', 'tracesSampleRate', 'timeout' => (float) $value,
                default => $value,
            };
        }

        return $o;
    }

    /** @internal fills in what is not set, from the environment */
    public function applyDefaults(): void
    {
        $this->dsn = self::orEnv($this->dsn, 'FIXWIRE_DSN');
        $this->release = self::orEnv($this->release, 'FIXWIRE_RELEASE');
        $this->environment = self::orEnv($this->environment, 'FIXWIRE_ENVIRONMENT') ?? 'production';
        $this->debug = $this->debug || \in_array(strtolower((string) self::orEnv(null, 'FIXWIRE_DEBUG')), ['1', 'true', 'yes', 'on'], true);
        $this->serverName = self::empty($this->serverName) ? (gethostname() ?: null) : $this->serverName;
        $this->serviceName = self::orEnv($this->serviceName, 'OTEL_SERVICE_NAME');
        if (self::empty($this->serviceName) && $this->release !== null && strpos($this->release, '@') > 0) {
            $this->serviceName = substr($this->release, 0, (int) strpos($this->release, '@')); // "api" of "api@1.4.0"
        }
        if (!($this->sampleRate > 0 && $this->sampleRate <= 1)) {
            $this->sampleRate = 1.0;
        }
        $this->tracesSampleRate = max(0.0, min(1.0, $this->tracesSampleRate));
        $this->maxBreadcrumbs = max(0, $this->maxBreadcrumbs);
        $this->maxQueue = $this->maxQueue > 0 ? $this->maxQueue : 100;
        $this->timeout = $this->timeout > 0 ? $this->timeout : 2.0;
        $root = $this->projectRoot ?? self::composerRoot() ?? (getcwd() ?: null);
        $this->projectRoot = $root === null ? null : rtrim($root, '/\\');
    }

    /** The root of the Composer project the app is (under PHP-FPM, the working directory is public/). */
    private static function composerRoot(): ?string
    {
        if (!class_exists(\Composer\InstalledVersions::class)) {
            return null;
        }
        $root = realpath(\Composer\InstalledVersions::getRootPackage()['install_path']);

        return $root === false ? null : $root;
    }

    /** @internal */
    public function sessionsOn(): bool
    {
        return $this->autoSessionTracking && !self::empty($this->release);
    }

    /** @internal */
    public static function empty(?string $s): bool
    {
        return $s === null || trim($s) === '';
    }

    private static function orEnv(?string $value, string $variable): ?string
    {
        if (!self::empty($value)) {
            return $value;
        }
        // Dotenv loaders (Symfony's by default) fill $_SERVER and $_ENV, not getenv().
        foreach ([getenv($variable), $_SERVER[$variable] ?? null, $_ENV[$variable] ?? null] as $env) {
            if (\is_string($env) && trim($env) !== '') {
                return $env;
            }
        }

        return null;
    }
}
