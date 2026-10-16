<?php

namespace Cmd\Reports\Invoices;

use RuntimeException;

/** Anything the invoice registry could not do (no free number, S3 refused, bad input). Generators catch this and fall back. */
class InvoiceRegistryException extends RuntimeException
{
}
