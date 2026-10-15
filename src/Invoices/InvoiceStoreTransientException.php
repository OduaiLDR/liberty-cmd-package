<?php

namespace Cmd\Reports\Invoices;

use RuntimeException;

/** A conditional put that may succeed if repeated (S3 409 ConditionalRequestConflict, 5xx, throttling). */
class InvoiceStoreTransientException extends RuntimeException
{
}
