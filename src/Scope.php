<?php

declare(strict_types=1);

namespace Fixwire;

/**
 * What is known about the work under way (the user, tags, contexts, breadcrumbs, the request),
 * added to every event captured with it.
 */
final class Scope
{
    private ?User $user = null;

    /** @var array<string, string> */
    private array $tags = [];

    /** @var array<string, array<string, mixed>> */
    private array $contexts = [];

    /** @var array<string, mixed> */
    private array $extra = [];

    /** @var list<Breadcrumb> the newest last; up to twice the most kept, as the oldest go in bulk */
    private array $breadcrumbs = [];

    /** The breadcrumbs kept: the last $max given to addBreadcrumb(). */
    private int $maxBreadcrumbs = 100;

    private ?Level $level = null;

    /** @var list<string> */
    private array $fingerprint = [];

    private ?string $transaction = null;

    private ?Request $request = null;

    /** @internal the request's session, for release health */
    public ?RequestSession $session = null;

    /**
     * @internal for integrations: the user when none was set, read when it is needed (frameworks
     * know the signed-in user only once their authentication ran)
     *
     * @var (\Closure(): ?User)|null
     */
    public ?\Closure $userProvider = null;

    public function setUser(?User $user): self
    {
        $this->user = $user === null ? null : clone $user;

        return $this;
    }

    public function getUser(): ?User
    {
        if ($this->user !== null) {
            return clone $this->user;
        }
        if ($this->userProvider === null) {
            return null;
        }
        try {
            return ($this->userProvider)();
        } catch (\Throwable) {
            return null;
        }
    }

    /** Sets a searchable tag; null removes it. */
    public function setTag(string $key, ?string $value): self
    {
        if ($value === null) {
            unset($this->tags[$key]);
        } else {
            $this->tags[$key] = $value;
        }

        return $this;
    }

    /**
     * Sets a named group of details, such as an order's id and items; null removes it.
     *
     * @param array<string, mixed>|null $values
     */
    public function setContext(string $name, ?array $values): self
    {
        if ($values === null) {
            unset($this->contexts[$name]);
        } else {
            $this->contexts[$name] = $values;
        }

        return $this;
    }

    /** Sets a detail sent with events; null removes it. */
    public function setExtra(string $key, mixed $value): self
    {
        if ($value === null) {
            unset($this->extra[$key]);
        } else {
            $this->extra[$key] = $value;
        }

        return $this;
    }

    /** Sets the level of the events captured with the scope; null for the default. */
    public function setLevel(?Level $level): self
    {
        $this->level = $level;

        return $this;
    }

    /** Groups the events captured with the scope; {{ default }} stands for Fixwire's own grouping. */
    public function setFingerprint(string ...$fingerprint): self
    {
        $this->fingerprint = array_values($fingerprint);

        return $this;
    }

    /** Names the route or task the work is for, such as GET /items/{id}. */
    public function setTransaction(?string $transaction): self
    {
        $this->transaction = $transaction;

        return $this;
    }

    public function getTransaction(): ?string
    {
        return $this->transaction;
    }

    public function setRequest(?Request $request): self
    {
        $this->request = $request;

        return $this;
    }

    public function getRequest(): ?Request
    {
        return $this->request;
    }

    /** Records something that happened; past $max, the oldest go. */
    public function addBreadcrumb(Breadcrumb $breadcrumb, int $max = 100): self
    {
        if ($max <= 0) {
            return $this;
        }
        $breadcrumb->timestamp ??= microtime(true);
        $this->breadcrumbs[] = $breadcrumb;
        $this->maxBreadcrumbs = $max;
        // Trimmed once there are twice as many, so that adding one doesn't copy them all.
        if (\count($this->breadcrumbs) >= 2 * $max) {
            $this->breadcrumbs = \array_slice($this->breadcrumbs, -$max);
        }

        return $this;
    }

    public function clearBreadcrumbs(): self
    {
        $this->breadcrumbs = [];

        return $this;
    }

    /** Forgets everything (for workers, between jobs). */
    public function clear(): self
    {
        $this->user = null;
        $this->tags = $this->contexts = $this->extra = $this->breadcrumbs = $this->fingerprint = [];
        $this->level = $this->transaction = $this->request = null;
        $this->session = null;
        $this->userProvider = null;

        return $this;
    }

    /** @internal adds what the scope knows to an event; the event's own details win */
    public function applyTo(Event $e, ?Span $span): void
    {
        $e->user ??= $this->getUser();
        $e->tags += $this->tags;
        $e->contexts += $this->contexts;
        $e->extra += $this->extra;
        if ($e->breadcrumbs === []) {
            $e->breadcrumbs = \count($this->breadcrumbs) > $this->maxBreadcrumbs ? \array_slice($this->breadcrumbs, -$this->maxBreadcrumbs) : $this->breadcrumbs;
        }
        $e->level ??= $this->level;
        if ($e->fingerprint === []) {
            $e->fingerprint = $this->fingerprint;
        }
        $e->request ??= $this->request;
        $e->transaction ??= $this->transaction;
        $route = $e->request?->currentRoute();
        if ($e->transaction === null && $route !== null) {
            $e->transaction = ($e->request->method === null ? '' : $e->request->method . ' ') . $route;
        }
        $e->transaction ??= $span?->segmentName();
        if ($e->traceId === null && $span !== null) {
            $e->traceId = $span->traceId;
            $e->spanId = $span->spanId;
        }
    }
}
