<?php

declare(strict_types=1);

namespace Luzrain\PHPStreamServer;

use Luzrain\PHPStreamServer\Exception\UserChangeException;
use Luzrain\PHPStreamServer\Internal\ErrorHandler;
use Luzrain\PHPStreamServer\Internal\Functions;
use Luzrain\PHPStreamServer\Internal\ReloadStrategyTrigger;
use Luzrain\PHPStreamServer\Internal\ServerStatus\Message\Detach;
use Luzrain\PHPStreamServer\Internal\ServerStatus\Message\Heartbeat;
use Luzrain\PHPStreamServer\Internal\ServerStatus\Message\Spawn;
use Luzrain\PHPStreamServer\Internal\ServerStatus\TrafficStatus;
use Luzrain\PHPStreamServer\ReloadStrategy\ReloadStrategyInterface;
use Psr\Log\LoggerInterface;
use Revolt\EventLoop;
use Revolt\EventLoop\Driver;
use Revolt\EventLoop\DriverFactory;

class WorkerProcess
{
    final public const STOP_EXIT_CODE = 0;
    final public const RELOAD_EXIT_CODE = 100;
    private const GC_PERIOD = 180;
    public const HEARTBEAT_PERIOD = 3;

    private LoggerInterface $logger;
    private Driver $eventLoop;
    private int $exitCode = 0;
    private TrafficStatus $trafficStatisticStore;
    private ReloadStrategyTrigger $reloadStrategyTrigger;
    private \Closure $masterPublisher;

    /**
     * @param null|\Closure(self):void $onStart
     * @param null|\Closure(self):void $onStop
     * @param null|\Closure(self):void $onReload
     */
    public function __construct(
        private string $name = 'none',
        private int $count = 1,
        private bool $reloadable = true,
        private string|null $user = null,
        private string|null $group = null,
        private \Closure|null $onStart = null,
        private \Closure|null $onStop = null,
        private \Closure|null $onReload = null,
    ) {
    }

    /**
     * @internal
     */
    final public function run(LoggerInterface $logger, \Closure $masterPublisher): int
    {
        $this->logger = $logger;
        $this->masterPublisher = $masterPublisher;
        $this->setUserAndGroup();
        $this->initWorker();
        $this->eventLoop->run();

        return $this->exitCode;
    }

    final public function startHttpServer(HttpServer $server): void
    {
        $server->start($this->logger, $this->trafficStatisticStore, $this->reloadStrategyTrigger);
    }

    final public function addReloadStrategies(ReloadStrategyInterface ...$reloadStrategies): void
    {
        $this->reloadStrategyTrigger->addReloadStrategies(...$reloadStrategies);
    }

    final public function setLogger(LoggerInterface $logger): void
    {
        $this->logger = $logger;
    }

    final public function getLogger(): LoggerInterface
    {
        return $this->logger;
    }

    final public function getEventLoop(): Driver
    {
        return $this->eventLoop;
    }

    final public function getName(): string
    {
        return $this->name;
    }

    final public function getCount(): int
    {
        return $this->count;
    }

    final public function getUser(): string
    {
        return $this->user ?? Functions::getCurrentUser();
    }

    final public function getGroup(): string
    {
        return $this->group ?? Functions::getCurrentGroup();
    }

    /**
     * @param \Closure(\Throwable):void $errorHandler
     */
    final public function setErrorHandler(\Closure $errorHandler): void
    {
        $this->eventLoop->setErrorHandler(function (\Throwable $exception) use ($errorHandler) {
            $errorHandler($exception);
            $this->reloadStrategyTrigger->emitException($exception);
        });
    }

    private function setUserAndGroup(): void
    {
        try {
            Functions::setUserAndGroup($this->user, $this->group);
        } catch (UserChangeException $e) {
            $this->getLogger()->warning($e->getMessage(), ['worker' => $this->getName()]);
            $this->user = Functions::getCurrentUser();
        }
    }

    private function initWorker(): void
    {
        // some command line SAPIs (e.g. phpdbg) don't have that function
        if (\function_exists('cli_set_process_title')) {
            \cli_set_process_title(\sprintf('%s: worker process  %s', Server::NAME, $this->getName()));
        }

        /** @psalm-suppress InaccessibleProperty */
        $this->eventLoop = (new DriverFactory())->create();
        EventLoop::setDriver($this->eventLoop);
        $this->setErrorHandler(ErrorHandler::handleException(...));

        // onStart callback
        $this->eventLoop->defer(function (): void {
            $this->onStart !== null && ($this->onStart)($this);
        });

        $this->eventLoop->onSignal(SIGTERM, fn () => $this->stop());
        $this->eventLoop->onSignal(SIGUSR2, fn () => $this->reload());

        // Force run garbage collection periodically
        $this->eventLoop->repeat(self::GC_PERIOD, static function (): void {
            \gc_collect_cycles();
            \gc_mem_caches();
        });

        $this->trafficStatisticStore = new TrafficStatus($this->masterPublisher);
        $this->reloadStrategyTrigger = new ReloadStrategyTrigger($this->eventLoop, $this->reload(...));

        ($this->masterPublisher)(new Spawn(
            pid: \posix_getpid(),
            user: $this->getUser(),
            name: $this->getName(),
            startedAt: new \DateTimeImmutable('now'),
        ));

        ($this->masterPublisher)(new Heartbeat(\posix_getpid(), \memory_get_usage(), \hrtime(true)));
        $this->eventLoop->repeat(self::HEARTBEAT_PERIOD, fn() => ($this->masterPublisher)(new Heartbeat(\posix_getpid(), \memory_get_usage(), \hrtime(true))));
    }

    final public function stop(int $code = self::STOP_EXIT_CODE): void
    {
        $this->exitCode = $code;
        try {
            $this->onStop !== null && ($this->onStop)($this);
        } finally {
            $this->eventLoop->stop();
        }
    }

    final public function reload(): void
    {
        if (!$this->reloadable) {
            return;
        }

        $this->exitCode = self::RELOAD_EXIT_CODE;
        try {
            $this->onReload !== null && ($this->onReload)($this);
        } finally {
            $this->eventLoop->stop();
        }
    }

    private function detach(): void
    {
        $identifiers = $this->getEventLoop()->getIdentifiers();
        \array_walk($identifiers, $this->getEventLoop()->cancel(...));
        $this->eventLoop->stop();

        ($this->masterPublisher)(new Detach(\posix_getpid()));

        unset(
            $this->eventLoop,
            $this->logger,
            $this->trafficStatisticStore,
            $this->reloadStrategyTrigger,
            $this->masterPublisher,
            $this->onStart,
            $this->onStop,
            $this->onReload,
        );

        \gc_collect_cycles();
        \gc_mem_caches();
    }

    /**
     * Give control to an external program and have it monitored by the master process.
     *
     * @param string $path path to a binary executable or a script
     * @param array $args array of argument strings passed to the program
     * @see https://www.php.net/manual/en/function.pcntl-exec.php
     */
    public function exec(string $path, array $args = []): never
    {
        $this->detach();
        $envVars = [...\getenv(), ...$_ENV];
        \pcntl_exec($path, $args, $envVars);
        exit(0);
    }
}
