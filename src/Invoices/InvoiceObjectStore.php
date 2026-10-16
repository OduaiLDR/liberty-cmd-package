<?php

namespace Cmd\Reports\Invoices;

/** The few S3 operations the registry needs, so it can be tested with a fake and never touches AWS in tests. */
interface InvoiceObjectStore
{
    /**
     * Create the object only if the key does not exist yet (S3 conditional write, If-None-Match: *).
     *
     * @return bool true when created, false when the key already exists (HTTP 412)
     *
     * @throws InvoiceStoreTransientException when S3 asks for a retry (409 conflict, 5xx, throttling)
     */
    public function putIfAbsent(string $key, string $body, string $contentType): bool;

    /**
     * Read an object back (used to verify a file left by an earlier, half-finished attempt). null when the key does not exist.
     *
     * @throws InvoiceRegistryException when the object cannot be read (for example access denied): callers treat that as "cannot verify"
     */
    public function get(string $key): ?string;
}
