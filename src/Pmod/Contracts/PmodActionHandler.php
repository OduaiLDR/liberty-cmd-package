<?php

declare(strict_types=1);

namespace Cmd\Reports\Pmod\Contracts;

use Cmd\Reports\Pmod\Data\PmodResult;
use Cmd\Reports\Pmod\Data\PmodWorkItem;
use Cmd\Reports\Pmod\Enums\PmodActionType;

interface PmodActionHandler
{
    public function actionType(): PmodActionType;

    public function handle(PmodWorkItem $workItem): PmodResult;
}
