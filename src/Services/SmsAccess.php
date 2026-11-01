<?php

declare(strict_types=1);

namespace Cmd\Reports\Services;

/** SMS action grants; display role names never confer authority. */
final class SmsAccess
{
    public const VIEW = 'cmd_report_mail_drop_export';
    public const MANAGE = 'cmd_sms_manage';
    public const DOWNLOAD = 'cmd_sms_download';

    public static function authenticated(mixed $actor): bool
    {
        return is_array($actor) && empty($actor['anonymous'])
            && is_string($actor['email'] ?? null) && trim($actor['email']) !== '';
    }

    public static function canView(mixed $actor): bool
    {
        return self::allows($actor, [self::VIEW, self::MANAGE, self::DOWNLOAD]);
    }

    public static function canManage(mixed $actor): bool
    {
        return self::allows($actor, [self::MANAGE]);
    }

    public static function canDownload(mixed $actor): bool
    {
        return self::allows($actor, [self::MANAGE, self::DOWNLOAD]);
    }

    private static function allows(mixed $actor, array $wanted): bool
    {
        if (!self::authenticated($actor)) return false;
        if (($actor['siteAdmin'] ?? false) === true) return true;
        $grants = is_array($actor['permissionIds'] ?? null) ? $actor['permissionIds'] : [];
        foreach (is_array($actor['brandAccess'] ?? null) ? $actor['brandAccess'] : [] as $entry) {
            if (is_array($entry) && is_array($entry['permissionIds'] ?? null)) $grants = [...$grants, ...$entry['permissionIds']];
        }
        return in_array('*', $grants, true) || array_intersect($wanted, array_filter($grants, 'is_string')) !== [];
    }
}
