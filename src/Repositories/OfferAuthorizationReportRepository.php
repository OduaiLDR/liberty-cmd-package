<?php

namespace Cmd\Reports\Repositories;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class OfferAuthorizationReportRepository extends SqlSrvRepository
{
    /**
     * @return array<string, string>
     */
    public function columns(): array
    {
        return [
            'offer_id' => 'Offer ID',
            'llg_id' => 'LLG ID',
            'title' => 'Title',
            'firstname' => 'First Name',
            'lastname' => 'Last Name',
            'address' => 'Address',
            'address2' => 'Address 2',
            'city' => 'City',
            'state' => 'State',
            'zip' => 'ZIP',
            'return_address' => 'Return Address',
        ];
    }

    /**
     * Gather select options for the offer authorization report filters.
     *
     * @return array<string, Collection<int, string>>
     */
    public function options(): array
    {
        $query = $this->table('TblEnrollment');

        $pluckDistinct = fn(string $column, int $limit = 500): Collection => $this->distinctValues($query, $column, $limit);

        return [
            'states' => $pluckDistinct('State'),
            'agents' => $pluckDistinct('Agent'),
            'negotiators' => $pluckDistinct('Negotiator'),
            'enrollment_status' => $pluckDistinct('Enrollment_Status'),
            'clients' => $pluckDistinct('Client', 1000),
            'enrollment_plans' => $pluckDistinct('Enrollment_Plan'),
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    public function all(array $filters = []): Collection
    {
        return $this->baseQuery($filters)->get();
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    public function paginate(int $perPage = 25, array $filters = []): LengthAwarePaginator
    {
        return $this->paginateBuilder($this->baseQuery($filters), $perPage);
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    protected function baseQuery(array $filters = []): Builder
    {
        // Note: This is a simplified version since we don't have access to SETTLEMENT_OFFERS table
        // The original SQL uses SETTLEMENT_OFFERS table which isn't available in our structure
        // We're using TblEnrollment as a proxy with mock offer data
        $query = $this->table('TblEnrollment')
            ->select([
                DB::raw("CONCAT('OFFER-', LLG_ID) AS offer_id"),
                DB::raw("CONCAT('LLG-', LLG_ID) AS llg_id"),
                'Enrollment_Plan AS title',
                DB::raw("LEFT(Client, CHARINDEX(' ', Client) - 1) AS firstname"),
                DB::raw("RIGHT(Client, LEN(Client) - CHARINDEX(' ', Client)) AS lastname"),
                DB::raw("'' AS address"), // Address not available in TblEnrollment
                DB::raw("'' AS address2"), // Address2 not available in TblEnrollment
                DB::raw("'' AS city"), // City not available in TblEnrollment
                'State AS state',
                DB::raw("'' AS zip"), // Zip not available in TblEnrollment
                DB::raw("CASE 
                    WHEN UPPER(Enrollment_Plan) LIKE 'LDR%' THEN '333 City Blvd W 17 FL, Orange CA, 92868'
                    WHEN UPPER(Enrollment_Plan) LIKE 'PLAW%' THEN '350 10th Avenue, Suite 1000, San Diego CA, 92101-7496'
                    ELSE '8383 Wilshire Blvd Suite 800, Beverly Hills, CA 90211'
                END AS return_address"),
            ])
            ->whereNotNull('LLG_ID')
            ->whereRaw("Client NOT LIKE '%TEST%'");

        // Apply filters
        if (!empty($filters['offer_id'])) {
            $query->where(DB::raw("CONCAT('OFFER-', LLG_ID)"), 'like', '%' . $filters['offer_id'] . '%');
        }

        if (!empty($filters['llg_id'])) {
            $query->where(DB::raw("CONCAT('LLG-', LLG_ID)"), 'like', '%' . $filters['llg_id'] . '%');
        }

        if (!empty($filters['title'])) {
            $query->where('Enrollment_Plan', 'like', '%' . $filters['title'] . '%');
        }

        if (!empty($filters['firstname'])) {
            $query->where(DB::raw("LEFT(Client, CHARINDEX(' ', Client) - 1)"), 'like', '%' . $filters['firstname'] . '%');
        }

        if (!empty($filters['lastname'])) {
            $query->where(DB::raw("RIGHT(Client, LEN(Client) - CHARINDEX(' ', Client))"), 'like', '%' . $filters['lastname'] . '%');
        }

        if (!empty($filters['address'])) {
            // Address filter not available as Address column doesn't exist
        }

        if (!empty($filters['address2'])) {
            // Address2 filter not available as Address2 column doesn't exist
        }

        if (!empty($filters['city'])) {
            // City filter not available as City column doesn't exist
        }

        if (!empty($filters['state'])) {
            $query->where('State', '=', $filters['state']);
        }

        if (!empty($filters['zip'])) {
            // Zip filter not available as Zip column doesn't exist
        }

        return $query->orderBy('Client', 'asc');
    }
}
