<?php

declare(strict_types=1);

namespace Cmd\Reports\Services;

use RuntimeException;

final class ProspectMailerOffersUnavailable extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Unable to verify the recorded mailer check amounts.');
    }
}
