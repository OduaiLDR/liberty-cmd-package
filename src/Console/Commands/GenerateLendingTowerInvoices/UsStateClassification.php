<?php

namespace Cmd\Reports\Console\Commands\GenerateLendingTowerInvoices;

use Illuminate\Support\Facades\DB;
use RuntimeException;

final class UsStateClassification
{
    public const TABLE = 'us_state_classifications';

    public const STATES = [
        'AL' => 'Alabama', 'AK' => 'Alaska', 'AZ' => 'Arizona', 'AR' => 'Arkansas',
        'CA' => 'California', 'CO' => 'Colorado', 'CT' => 'Connecticut', 'DE' => 'Delaware',
        'FL' => 'Florida', 'GA' => 'Georgia', 'HI' => 'Hawaii', 'ID' => 'Idaho',
        'IL' => 'Illinois', 'IN' => 'Indiana', 'IA' => 'Iowa', 'KS' => 'Kansas',
        'KY' => 'Kentucky', 'LA' => 'Louisiana', 'ME' => 'Maine', 'MD' => 'Maryland',
        'MA' => 'Massachusetts', 'MI' => 'Michigan', 'MN' => 'Minnesota', 'MS' => 'Mississippi',
        'MO' => 'Missouri', 'MT' => 'Montana', 'NE' => 'Nebraska', 'NV' => 'Nevada',
        'NH' => 'New Hampshire', 'NJ' => 'New Jersey', 'NM' => 'New Mexico', 'NY' => 'New York',
        'NC' => 'North Carolina', 'ND' => 'North Dakota', 'OH' => 'Ohio', 'OK' => 'Oklahoma',
        'OR' => 'Oregon', 'PA' => 'Pennsylvania', 'RI' => 'Rhode Island', 'SC' => 'South Carolina',
        'SD' => 'South Dakota', 'TN' => 'Tennessee', 'TX' => 'Texas', 'UT' => 'Utah',
        'VT' => 'Vermont', 'VA' => 'Virginia', 'WA' => 'Washington', 'WV' => 'West Virginia',
        'WI' => 'Wisconsin', 'WY' => 'Wyoming',
    ];

    /** Read the maintained application table on every invoice run; no hardcoded fallback. */
    public static function progressLawStates(): array
    {
        $states = DB::table(self::TABLE)->where('classification', 'PLAW')
            ->orderBy('state_code')->pluck('state_code')->all();

        if ($states === []) {
            throw new RuntimeException('No PLAW states configured in ' . self::TABLE . '. Apply the Lending Tower migration and review classifications.');
        }

        foreach ($states as $state) {
            if (! isset(self::STATES[$state])) {
                throw new RuntimeException('Invalid PLAW state code in ' . self::TABLE . '.');
            }
        }

        return $states;
    }
}
