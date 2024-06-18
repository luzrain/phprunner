<?php

declare(strict_types=1);

namespace Luzrain\PHPStreamServer\Command;

use Luzrain\PHPStreamServer\Console\Command;
use Luzrain\PHPStreamServer\Console\Table;
use Luzrain\PHPStreamServer\Internal\Functions;
use Luzrain\PHPStreamServer\Internal\MasterProcess;
use Luzrain\PHPStreamServer\Server;

final class StatusCommand implements Command
{
    public function __construct(
        private MasterProcess $masterProcess,
    ) {
    }

    public function getCommand(): string
    {
        return 'status';
    }

    public function getHelp(): string
    {
        return 'Show server status';
    }

    public function run(array $arguments): int
    {
        $status = $this->masterProcess->getServerStatus();
        $processesCount = $status->getProcessesCount();
        $totalMemory = $status->getTotalMemory();

        echo ($status->isRunning ? '<color;fg=green>●</> ' : '● ') . Server::TITLE . "\n";

        echo (new Table(indent: 1))
            ->addRows([
                [Server::NAME . ' version:', $status->version],
                ['PHP version:', $status->phpVersion],
                ['Event loop driver:', $status->eventLoop],
                ['Start file:', $status->startFile],
                ['Status:', $status->isRunning
                    ? '<color;fg=green>active</> since ' . ($status->startedAt?->format(\DateTimeInterface::RFC7231) ?? '?')
                    : 'inactive',
                ],
                ['Workers count:', $status->getWorkersCount()],
                ['Processes count:', $processesCount > 0 || $status->isRunning ? $processesCount : '<color;fg=gray>0</>'],
                ['Memory usage:', $totalMemory > 0 || $status->isRunning ? Functions::humanFileSize($totalMemory) : '<color;fg=gray>0</>'],
            ]);

        return 0;
    }
}
