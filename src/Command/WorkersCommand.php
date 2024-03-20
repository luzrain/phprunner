<?php

declare(strict_types=1);

namespace Luzrain\PHPStreamServer\Command;

use Luzrain\PHPStreamServer\Console\Command;
use Luzrain\PHPStreamServer\Console\Table;
use Luzrain\PHPStreamServer\Internal\MasterProcess;
use Luzrain\PHPStreamServer\Internal\Status\WorkerStatus;

final class WorkersCommand implements Command
{
    public function __construct(
        private MasterProcess $masterProcess,
    ) {
    }

    public function getCommand(): string
    {
        return 'workers';
    }

    public function getHelp(): string
    {
        return 'Show workers status';
    }

    public function run(array $arguments): int
    {
        $status = $this->masterProcess->getStatus();

        echo "❯ Workers\n";

        echo (new Table(indent: 1))
            ->setHeaderRow([
                'User',
                'Worker',
                'Count',
            ])
            ->addRows(\array_map(array: $status->workers, callback: fn(WorkerStatus $w) => [
                $w->user,
                $w->name,
                $w->count,
            ]));

        return 0;
    }
}
