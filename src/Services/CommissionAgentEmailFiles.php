<?php

declare(strict_types=1);

namespace Cmd\Reports\Services;

/**
 * Split All vs per-agent commission workbooks for email routing (Jacob: All → distro, agents → Rama).
 */
final class CommissionAgentEmailFiles
{
    public static function isAllFilename(string $filename): bool
    {
        return str_ends_with($filename, ' - All.xlsx') || str_contains($filename, ' - All.');
    }

    public static function agentNameFromFilename(string $filename): string
    {
        $base = (string) preg_replace('/\.xlsx$/i', '', $filename);
        $pos = strrpos($base, ' - ');
        if ($pos === false) {
            return $base;
        }

        return substr($base, $pos + 3);
    }

    /**
     * @param  array<int,array{filename?:string,path?:string}> $files
     * @return array{
     *   all: array<int,array{filename?:string,path?:string}>,
     *   agents: array<int,array{filename?:string,path?:string}>,
     *   missing: list<string>
     * }
     */
    public static function partition(array $files): array
    {
        $all = [];
        $agents = [];
        $missing = [];

        foreach ($files as $f) {
            $filename = (string) ($f['filename'] ?? '');
            $path = (string) ($f['path'] ?? '');
            if ($path === '' || !is_file($path)) {
                $missing[] = $filename !== '' ? $filename : '(unnamed)';
                continue;
            }
            if (self::isAllFilename($filename)) {
                $all[] = $f;
            } else {
                $agents[] = $f;
            }
        }

        return ['all' => $all, 'agents' => $agents, 'missing' => $missing];
    }

    /**
     * @param  array<int,array{filename:string,path:string}> $files
     * @return array<int,array{name:string,contentType:string,contentBytes:string}>
     */
    public static function toAttachments(array $files): array
    {
        $attachments = [];
        foreach ($files as $f) {
            $attachments[] = [
                'name'         => $f['filename'],
                'contentType'  => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'contentBytes' => base64_encode((string) file_get_contents($f['path'])),
            ];
        }

        return $attachments;
    }
}
