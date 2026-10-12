<?php

namespace Cmd\Reports\Services;

/** A record needs review; database and execution failures must not use this type. */
final class ContactSyncConflict extends \RuntimeException {}
