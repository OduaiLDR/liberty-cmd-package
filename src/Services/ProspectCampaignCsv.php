<?php

declare(strict_types=1);

namespace Cmd\Reports\Services;

final class ProspectCampaignCsv
{
    public const COLUMNS = [
        'campaign_id' => 'number',
        'first_name' => 'text',
        'last_name' => 'text',
        'country_code' => 'text',
        'state_code' => 'text',
        'mobile_number' => 'text',
        'external_system_id' => 'text',
        'source' => 'text',
        'personal_loan_amount' => 'number',
    ];

    public const PROSPECT_COLUMNS = ['first_name', 'last_name', 'state', 'phone'];

    public static function rules(): array
    {
        return [
            'campaign' => ['sometimes', 'required', 'array:mailer_id,source,personal_loan_amount'],
            'campaign.mailer_id' => ['required_with:campaign', 'string', 'max:100', 'regex:/\A[A-Za-z0-9][A-Za-z0-9_. -]*\z/'],
            'campaign.source' => ['required_with:campaign', 'string', 'max:100', 'regex:/\A[A-Za-z0-9][A-Za-z0-9_. -]*\z/'],
            'campaign.personal_loan_amount' => ['sometimes', 'nullable', 'string', 'regex:/\A[0-9]{1,9}(?:\.[0-9]{1,2})?\z/'],
        ];
    }

    public static function catalog(): array
    {
        $fields = [];
        foreach (self::COLUMNS as $id => $type) {
            $fields[] = ['id' => $id, 'label' => $id, 'type' => $type];
        }

        return $fields;
    }

    /** @return list<string>|null */
    public static function cells(object $prospect, array $campaign): ?array
    {
        $phone = ProspectListFields::usPhoneDigits($prospect->phone);
        if ($phone === null) {
            return null;
        }

        $state = strtoupper(trim((string) $prospect->state));

        return [
            '', // The file writer fills the campaign ID after choosing the file part.
            (string) ProspectListFields::csvCell($prospect, 'first_name', 'text'),
            (string) ProspectListFields::csvCell($prospect, 'last_name', 'text'),
            'US',
            preg_match('/\A[A-Z]{2}\z/', $state) === 1 ? $state : '',
            sprintf('(%s) %s-%s', substr($phone, 0, 3), substr($phone, 3, 3), substr($phone, 6)),
            $campaign['mailer_id'],
            $campaign['source'],
            $campaign['personal_loan_amount'] ?? '',
        ];
    }
}
