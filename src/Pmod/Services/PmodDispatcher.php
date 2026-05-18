<?php

declare(strict_types=1);

namespace Cmd\Reports\Pmod\Services;

use Cmd\Reports\Pmod\Contracts\PmodActionHandler;
use Cmd\Reports\Pmod\Data\PmodResult;
use Cmd\Reports\Pmod\Data\PmodWorkItem;
use Cmd\Reports\Pmod\Enums\PmodActionType;
use Cmd\Reports\Pmod\Exceptions\UnsupportedPmodActionException;

final class PmodDispatcher
{
    /**
     * @var array<string, PmodActionHandler>
     */
    private array $handlers = [];

    /**
     * @param iterable<PmodActionHandler> $handlers
     */
    public function __construct(iterable $handlers = [])
    {
        foreach ($handlers as $handler) {
            $this->handlers[$handler->actionType()->value] = $handler;
        }
    }

    public function supports(PmodActionType $actionType): bool
    {
        return isset($this->handlers[$actionType->value]);
    }

    /**
     * @return list<string>
     */
    public function supportedActionTypes(): array
    {
        return array_keys($this->handlers);
    }

    public function dispatch(PmodWorkItem $workItem): PmodResult
    {
        $handler = $this->handlers[$workItem->actionType->value] ?? null;

        if (!$handler instanceof PmodActionHandler) {
            throw new UnsupportedPmodActionException(sprintf(
                'PMOD action [%s] is not implemented yet.',
                $workItem->actionType->value,
            ));
        }

        return $handler->handle($workItem);
    }
}
