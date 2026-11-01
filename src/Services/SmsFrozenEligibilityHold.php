<?php

declare(strict_types=1);

namespace Cmd\Reports\Services;

/** A safe local hold before any new recipient-data handoff. */
final class SmsFrozenEligibilityHold extends \RuntimeException {}
