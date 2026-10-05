<?php

declare(strict_types=1);

namespace Fixwire\Monolog;

use Fixwire\Breadcrumb;
use Fixwire\Event;
use Fixwire\Hub;
use Fixwire\Level;
use Monolog\Handler\AbstractProcessingHandler;
use Monolog\Level as MonologLevel;
use Monolog\LogRecord;

/**
 * Sends Monolog records to Fixwire: from INFO they become breadcrumbs, from ERROR events (as the
 * exception in the record's context when it has one, once if the app captured it already). Log
 * lines themselves are not shipped.
 *
 *     $logger->pushHandler(new Fixwire\Monolog\Handler());
 */
final class Handler extends AbstractProcessingHandler
{
    public function __construct(
        private MonologLevel $breadcrumbLevel = MonologLevel::Info,
        private MonologLevel $eventLevel = MonologLevel::Error,
        bool $bubble = true,
    ) {
        parent::__construct(min($breadcrumbLevel->value, $eventLevel->value) === $breadcrumbLevel->value ? $breadcrumbLevel : $eventLevel, $bubble);
    }

    protected function write(LogRecord $record): void
    {
        $hub = Hub::current();
        $client = $hub->getClient();
        if ($client === null || !$client->isEnabled() || str_starts_with($record->channel, 'fixwire')) {
            return;
        }
        $level = self::levelOf($record->level);
        $context = $record->context;
        $exception = $context['exception'] ?? null;
        unset($context['exception']);
        if ($record->level->value >= $this->eventLevel->value) {
            if ($exception instanceof \Throwable && $client->isCaptured($exception)) {
                return; // sent already, where it was caught
            }
            $hub->withScope(static function () use ($hub, $record, $level, $context, $exception): void {
                $scope = $hub->getScope();
                $scope->setExtra('logger', $record->channel);
                foreach ($context as $key => $value) {
                    $scope->setExtra((string) $key, $value);
                }
                if ($exception instanceof \Throwable) {
                    $scope->setExtra('log.message', $record->message);
                    $hub->captureException($exception, 'logging', true, $level);
                } else {
                    $e = new Event();
                    $e->message = $record->message;
                    $e->level = $level;
                    $hub->captureEvent($e);
                }
            });
        } elseif ($record->level->value >= $this->breadcrumbLevel->value) {
            $hub->addBreadcrumb(new Breadcrumb(
                category: $record->channel,
                message: $record->message,
                level: $level,
                type: 'log',
                data: $context,
                timestamp: (float) $record->datetime->format('U.u'),
            ));
        }
    }

    private static function levelOf(MonologLevel $level): Level
    {
        return match (true) {
            $level->value >= MonologLevel::Critical->value => Level::Fatal,
            $level->value >= MonologLevel::Error->value => Level::Error,
            $level->value >= MonologLevel::Warning->value => Level::Warning,
            $level->value >= MonologLevel::Info->value => Level::Info,
            default => Level::Debug,
        };
    }
}
