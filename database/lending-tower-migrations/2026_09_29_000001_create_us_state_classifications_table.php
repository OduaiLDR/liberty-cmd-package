<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('us_state_classifications', function (Blueprint $table): void {
            $table->char('state_code', 2)->primary();
            $table->string('state_name', 32);
            // NULL means classification still needs confirmation, not Open or Excluded.
            $table->enum('classification', ['LDR', 'PLAW', 'Open', 'Excluded'])->nullable()->index();
        });

        $states = [
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
        $plaw = ['CO', 'CT', 'DE', 'GA', 'HI', 'ID', 'IL', 'IA', 'KS', 'LA', 'ME', 'MI', 'MN',
            'MT', 'NE', 'NV', 'NH', 'NJ', 'ND', 'OH', 'SC', 'VT', 'VA', 'WA', 'WI', 'WY'];
        $rows = [];
        foreach ($states as $code => $name) {
            $rows[] = ['state_code' => $code, 'state_name' => $name,
                'classification' => in_array($code, $plaw, true) ? 'PLAW' : null];
        }
        DB::table('us_state_classifications')->insert($rows);
    }

    public function down(): void
    {
        Schema::dropIfExists('us_state_classifications');
    }
};
