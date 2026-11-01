<?php

declare(strict_types=1);

namespace Cmd\Reports\Services;

use InvalidArgumentException;

/** Pure, versioned original-event seals. No current debt/identity/source lookups. */
final class SmsExternalEventSeal
{
    public const EVENT = 'jude-original-sms-2026-10-21';
    public const OCCURRED = '2026-10-21 18:11:15';
    public const DAY = '2026-10-21';
    public const MAX_CHUNK = 1000;
    public const FILE_HASHES = [
        'SMS_Data_Part1.csv' => '1895a2795c56b97063814c1a68397c05dd676d034adc3c62f0d4fd7efb75882c',
        'SMS_Data_Part2.csv' => 'e191ee550e940c40ecf457b1e9bfd5e1cef5b90fd27441ccb2c8f331f7bd1bfa',
        'SMS_Data_Part3.csv' => 'dbecb280df85d807f3ee0e56694158a0cae0b2d97711ea0667bace0c04e3f58d',
        'selected_TblProspects_PKs.csv' => 'f3a4ab87e17aad87c03e7c80c0ddf05ebb5dc49b336042155752d51c137f736f',
    ];

    public static function json(array $value): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    public static function hash(string $value): string
    {
        if (! preg_match('/\A[a-f0-9]{64}\z/', $value)) throw new InvalidArgumentException('A canonical SHA256 seal is required.');
        return $value;
    }

    /** No currency signs, grouping, exponent notation, floating point or rounding. */
    public static function debt(string $raw): ?string
    {
        if (! preg_match('/\A(0|[1-9][0-9]{0,9})(?:\.([0-9]{1,2}))?\z/', $raw, $m)) return null;
        return $m[1].'.'.str_pad($m[2] ?? '', 2, '0');
    }

    public static function storedDebt(mixed $value): ?string
    {
        if ($value===null) return null;
        // SQL Server returns decimal text. SQLite test affinity may return a
        // numeric scalar; never round it or use it to infer an original amount.
        $normalized=self::debt((string)$value);
        if ($normalized===null) throw new InvalidArgumentException('Stored original decimal is not losslessly canonical.');
        return $normalized;
    }

    public static function date(?string $value): ?string
    {
        if ($value === null) return null;
        if (! preg_match('/\A[0-9]{4}-[0-9]{2}-[0-9]{2}\z/', $value)
            || ! checkdate((int) substr($value,5,2),(int) substr($value,8,2),(int) substr($value,0,4))) {
            throw new InvalidArgumentException('An exact calendar date is required.');
        }
        return $value;
    }

    public static function later(?string $existing, string $event): string
    {
        self::date($existing); self::date($event);
        return $existing !== null && $existing > $event ? $existing : $event;
    }

    /** Strict scalar row shape also bounds SQL text sizes before staging. */
    public static function member(array $input): array
    {
        $keys=['Prospect_PK','Original_Phone','Original_First_Name','Original_Address','Original_Debt_Text',
            'Original_Send_Date','Part_Number','Part_Row','Expected_Attempts','Expected_Last_SMS_Date','Expected_Latest_Export_Date'];
        if (array_diff(array_keys($input), $keys) !== [] || array_diff($keys,array_keys($input)) !== []) {
            throw new InvalidArgumentException('Unexpected external member shape.');
        }
        foreach (['Prospect_PK'=>2147483647,'Part_Number'=>3,'Part_Row'=>1000000] as $key=>$max) {
            if (! is_int($input[$key]) || $input[$key]<1 || $input[$key]>$max) throw new InvalidArgumentException('Invalid external member identity.');
        }
        if (! is_int($input['Expected_Attempts']) || $input['Expected_Attempts']<0 || $input['Expected_Attempts']>=2147483647) {
            throw new InvalidArgumentException('Invalid or overflowing preflight attempts.');
        }
        if (! is_string($input['Original_Phone']) || ! preg_match('/\A[0-9]{10}\z/', $input['Original_Phone'])) {
            throw new InvalidArgumentException('Original phone must retain its exact ten digits.');
        }
        foreach (['Original_First_Name'=>150,'Original_Address'=>200,'Original_Debt_Text'=>100] as $key=>$max) {
            $value=$input[$key];
            if (! is_string($value) || $value==='' || ! preg_match('//u',$value) || str_contains($value,"\0")
                || mb_strlen($value,'UTF-8')>$max) throw new InvalidArgumentException('Original field exceeds the supported lossless contract.');
        }
        if ($input['Original_Send_Date']!==self::DAY) throw new InvalidArgumentException('Original send date differs from the sealed event.');
        self::date($input['Expected_Last_SMS_Date']); self::date($input['Expected_Latest_Export_Date']);
        $hash=hash('sha256',self::json([$input['Prospect_PK'],$input['Part_Number'],$input['Part_Row'],
            $input['Original_Phone'],$input['Original_First_Name'],$input['Original_Address'],$input['Original_Debt_Text'],$input['Original_Send_Date']]));
        $before=hash('sha256',self::json([$input['Prospect_PK'],$input['Expected_Attempts'],
            $input['Expected_Last_SMS_Date'],$input['Expected_Latest_Export_Date']]));
        return $input+['Row_SHA256'=>$hash,'Before_SHA256'=>$before,'Original_Debt_Amount'=>self::debt($input['Original_Debt_Text'])];
    }

    /** Ordered, length-fixed binary seals avoid concatenation ambiguity. */
    public static function membership(iterable $members,string $field='Row_SHA256'): string
    {
        if (! in_array($field,['Row_SHA256','Before_SHA256'],true)) throw new InvalidArgumentException('Unknown historical seal contract.');
        $context=hash_init('sha256'); $previous=0;
        foreach ($members as $row) {
            $pk=(int) (is_object($row)?$row->Prospect_PK:$row['Prospect_PK']);
            $seal=is_object($row)?$row->$field:$row[$field];
            if ($pk<=$previous) throw new InvalidArgumentException('External membership must be distinct and ordered by exact PK.');
            hash_update($context,hex2bin(self::hash($seal))); $previous=$pk;
        }
        return hash_final($context);
    }

    public static function storedMember(object $member): array
    {
        $original=array_intersect_key((array)$member,array_flip(['Prospect_PK','Original_Phone','Original_First_Name','Original_Address',
            'Original_Debt_Text','Original_Send_Date','Part_Number','Part_Row','Expected_Attempts','Expected_Last_SMS_Date','Expected_Latest_Export_Date']));
        foreach (['Prospect_PK','Part_Number','Part_Row','Expected_Attempts'] as $key) $original[$key]=(int)$original[$key];
        $sealed=self::member($original);
        if ($sealed['Row_SHA256']!==$member->Row_SHA256 || $sealed['Before_SHA256']!==$member->Before_SHA256
            || $sealed['Original_Debt_Amount']!==self::storedDebt($member->Original_Debt_Amount)) {
            throw new InvalidArgumentException('Stored original fields, derived debt or before-state differ from their seals.');
        }
        return $sealed;
    }

    public static function assertUnapplied(object $member): void
    {
        foreach (['Applied_Chunk','Applied_At_UTC','Previous_Attempts','Recorded_Attempts','Previous_Last_SMS_Date','Recorded_Last_SMS_Date',
            'Previous_Latest_Export_Date','Recorded_Latest_Export_Date'] as $key) {
            if (! property_exists($member,$key) || $member->$key!==null) throw new InvalidArgumentException('Unapplied historical member already contains audit evidence.');
        }
    }
}
