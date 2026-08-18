<?php

namespace App\Services;

use App\Enums\DripLineStatus;
use App\Enums\InjectionAdministrationType;
use App\Models\Doctor;
use App\Models\HealthAide;
use App\Models\MedicationOrder;
use App\Models\MedicationOrderDrip;
use App\Models\MedicationOrderDripAdditive;
use App\Models\MedicationOrderInjection;
use App\Models\MedicationOrderMedicine;
use App\Models\Patient;
use App\Models\QueueToken;
use App\Models\User;
use Illuminate\Database\Query\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class MedicationDeliveryLogService
{
    /**
     * Paginate delivered medicines, injections, and started drips for a date range.
     *
     * @return LengthAwarePaginator<int, object{
     *     type: string,
     *     line_id: int,
     *     item_name: string,
     *     detail: string|null,
     *     patient_name: string,
     *     mrn: string|null,
     *     token_number: int|null,
     *     occurred_at: Carbon,
     *     started_at: Carbon|null,
     *     done_at: Carbon|null,
     *     delivered_by: string|null,
     *     started_by: string|null,
     *     done_by: string|null,
     *     doctor_name: string|null
     * }>
     */
    public function paginate(
        Carbon $dateFrom,
        Carbon $dateTo,
        string $type = 'all',
        string $keyword = '',
        int $perPage = 20,
    ): LengthAwarePaginator {
        $query = $this->baseQuery($type);

        $query->whereBetween('occurred_at', [
            $dateFrom->copy()->startOfDay(),
            $dateTo->copy()->endOfDay(),
        ]);

        if (filled($keyword)) {
            $term = '%'.$keyword.'%';
            $query->where(function (Builder $inner) use ($term): void {
                $inner->where('item_name', 'like', $term)
                    ->orWhere('patient_name', 'like', $term)
                    ->orWhere('mrn', 'like', $term);
            });
        }

        $paginator = $query
            ->orderByDesc('occurred_at')
            ->paginate($perPage);

        $paginator->getCollection()->transform(fn (object $row): object => $this->present($row));

        return $paginator;
    }

    private function baseQuery(string $type): Builder
    {
        $queries = match ($type) {
            'medicine' => [$this->medicinesQuery()],
            'injection' => [$this->injectionsQuery()],
            'drip' => [$this->dripsQuery()],
            default => [$this->medicinesQuery(), $this->injectionsQuery(), $this->dripsQuery()],
        };

        $union = array_shift($queries);

        foreach ($queries as $query) {
            $union->unionAll($query);
        }

        return DB::query()->fromSub($union, 'deliveries');
    }

    private function medicinesQuery(): Builder
    {
        $lines = $this->table(MedicationOrderMedicine::class);
        $orders = $this->table(MedicationOrder::class);
        $patients = $this->table(Patient::class);
        $tokens = $this->table(QueueToken::class);
        $doctors = $this->table(Doctor::class);
        $aides = $this->table(HealthAide::class);

        return DB::table($lines)
            ->join($orders, "{$orders}.id", '=', "{$lines}.medication_order_id")
            ->join($patients, "{$patients}.id", '=', "{$orders}.patient_id")
            ->join($tokens, "{$tokens}.id", '=', "{$orders}.queue_token_id")
            ->leftJoin($doctors, "{$doctors}.id", '=', "{$orders}.doctor_id")
            ->leftJoin($aides, "{$aides}.id", '=', "{$lines}.delivered_by_health_aide_id")
            ->whereNotNull("{$lines}.delivered_at")
            ->select($this->selectColumns(
                type: 'medicine',
                lineId: "{$lines}.id",
                itemName: "{$lines}.name",
                extra1: "{$lines}.dose",
                extra2: "{$lines}.comment",
                patientName: "{$patients}.name",
                mrn: "{$patients}.mrn",
                tokenNumber: "{$tokens}.token_number",
                occurredAt: "{$lines}.delivered_at",
                startedAt: "{$lines}.delivered_at",
                doneAt: null,
                deliveredBy: "{$aides}.name",
                startedBy: "{$aides}.name",
                doneBy: null,
                doctorName: "{$doctors}.name",
            ));
    }

    private function injectionsQuery(): Builder
    {
        $lines = $this->table(MedicationOrderInjection::class);
        $orders = $this->table(MedicationOrder::class);
        $patients = $this->table(Patient::class);
        $tokens = $this->table(QueueToken::class);
        $doctors = $this->table(Doctor::class);
        $aides = $this->table(HealthAide::class);

        return DB::table($lines)
            ->join($orders, "{$orders}.id", '=', "{$lines}.medication_order_id")
            ->join($patients, "{$patients}.id", '=', "{$orders}.patient_id")
            ->join($tokens, "{$tokens}.id", '=', "{$orders}.queue_token_id")
            ->leftJoin($doctors, "{$doctors}.id", '=', "{$orders}.doctor_id")
            ->leftJoin($aides, "{$aides}.id", '=', "{$lines}.delivered_by_health_aide_id")
            ->whereNotNull("{$lines}.delivered_at")
            ->select($this->selectColumns(
                type: 'injection',
                lineId: "{$lines}.id",
                itemName: "{$lines}.name",
                extra1: "{$lines}.administration_type",
                extra2: "{$lines}.comment",
                patientName: "{$patients}.name",
                mrn: "{$patients}.mrn",
                tokenNumber: "{$tokens}.token_number",
                occurredAt: "{$lines}.delivered_at",
                startedAt: "{$lines}.delivered_at",
                doneAt: null,
                deliveredBy: "{$aides}.name",
                startedBy: "{$aides}.name",
                doneBy: null,
                doctorName: "{$doctors}.name",
            ));
    }

    private function dripsQuery(): Builder
    {
        $drips = $this->table(MedicationOrderDrip::class);
        $additives = $this->table(MedicationOrderDripAdditive::class);
        $orders = $this->table(MedicationOrder::class);
        $patients = $this->table(Patient::class);
        $tokens = $this->table(QueueToken::class);
        $doctors = $this->table(Doctor::class);
        $aides = $this->table(HealthAide::class);
        $users = $this->table(User::class);

        $additivesSub = DB::table($additives)
            ->select('medication_order_drip_id')
            ->selectRaw('GROUP_CONCAT(name) as additives')
            ->groupBy('medication_order_drip_id');

        return DB::table($drips)
            ->join($orders, "{$orders}.id", '=', "{$drips}.medication_order_id")
            ->join($patients, "{$patients}.id", '=', "{$orders}.patient_id")
            ->join($tokens, "{$tokens}.id", '=', "{$orders}.queue_token_id")
            ->leftJoin($doctors, "{$doctors}.id", '=', "{$orders}.doctor_id")
            ->leftJoin("{$aides} as started_aides", 'started_aides.id', '=', "{$drips}.started_by_health_aide_id")
            ->leftJoin("{$aides} as done_aides", 'done_aides.id', '=', "{$drips}.done_by_health_aide_id")
            ->leftJoin("{$users} as done_users", 'done_users.id', '=', "{$drips}.done_by_user_id")
            ->leftJoinSub($additivesSub, 'drip_additives', 'drip_additives.medication_order_drip_id', '=', "{$drips}.id")
            ->whereNotNull("{$drips}.started_at")
            ->select($this->selectColumns(
                type: 'drip',
                lineId: "{$drips}.id",
                itemName: "{$drips}.name",
                extra1: "{$drips}.status",
                extra2: 'drip_additives.additives',
                patientName: "{$patients}.name",
                mrn: "{$patients}.mrn",
                tokenNumber: "{$tokens}.token_number",
                occurredAt: "COALESCE({$drips}.done_at, {$drips}.started_at)",
                startedAt: "{$drips}.started_at",
                doneAt: "{$drips}.done_at",
                deliveredBy: 'COALESCE(done_aides.name, done_users.name, started_aides.name)',
                startedBy: 'started_aides.name',
                doneBy: 'COALESCE(done_aides.name, done_users.name)',
                doctorName: "{$doctors}.name",
            ));
    }

    /**
     * @return list<mixed>
     */
    private function selectColumns(
        string $type,
        string $lineId,
        string $itemName,
        string $extra1,
        string $extra2,
        string $patientName,
        string $mrn,
        string $tokenNumber,
        string $occurredAt,
        string $startedAt,
        ?string $doneAt,
        string $deliveredBy,
        string $startedBy,
        ?string $doneBy,
        string $doctorName,
    ): array {
        return [
            DB::raw("'{$type}' as type"),
            DB::raw("{$lineId} as line_id"),
            DB::raw("{$itemName} as item_name"),
            DB::raw("{$extra1} as extra_1"),
            DB::raw("{$extra2} as extra_2"),
            DB::raw("{$patientName} as patient_name"),
            DB::raw("{$mrn} as mrn"),
            DB::raw("{$tokenNumber} as token_number"),
            DB::raw("{$occurredAt} as occurred_at"),
            DB::raw("{$startedAt} as started_at"),
            DB::raw(($doneAt ?? 'null').' as done_at'),
            DB::raw("{$deliveredBy} as delivered_by"),
            DB::raw("{$startedBy} as started_by"),
            DB::raw(($doneBy ?? 'null').' as done_by'),
            DB::raw("{$doctorName} as doctor_name"),
        ];
    }

    /**
     * @param  object{
     *     type: string,
     *     line_id: int|string,
     *     item_name: string,
     *     extra_1: string|null,
     *     extra_2: string|null,
     *     patient_name: string,
     *     mrn: string|null,
     *     token_number: int|string|null,
     *     occurred_at: mixed,
     *     started_at: mixed,
     *     done_at: mixed,
     *     delivered_by: string|null,
     *     started_by: string|null,
     *     done_by: string|null,
     *     doctor_name: string|null
     * }  $row
     */
    private function present(object $row): object
    {
        return (object) [
            'type' => $row->type,
            'line_id' => (int) $row->line_id,
            'item_name' => $row->item_name,
            'detail' => $this->detailFor($row->type, $row->extra_1, $row->extra_2),
            'patient_name' => $row->patient_name,
            'mrn' => $row->mrn,
            'token_number' => $row->token_number === null ? null : (int) $row->token_number,
            'occurred_at' => Carbon::parse($row->occurred_at),
            'started_at' => $row->started_at === null ? null : Carbon::parse($row->started_at),
            'done_at' => $row->done_at === null ? null : Carbon::parse($row->done_at),
            'delivered_by' => $row->delivered_by,
            'started_by' => $row->started_by,
            'done_by' => $row->done_by,
            'doctor_name' => $row->doctor_name,
        ];
    }

    private function detailFor(string $type, ?string $extra1, ?string $extra2): ?string
    {
        $primary = match ($type) {
            'injection' => InjectionAdministrationType::tryFrom((string) $extra1)?->label() ?? $extra1,
            'drip' => DripLineStatus::tryFrom((string) $extra1)?->label() ?? $extra1,
            default => $extra1,
        };

        $secondary = $type === 'drip' && filled($extra2)
            ? str_replace(',', ', ', $extra2)
            : $extra2;

        return $this->joinDetail($primary, $secondary);
    }

    private function joinDetail(?string $primary, ?string $secondary): ?string
    {
        $parts = array_values(array_filter([
            filled($primary) ? $primary : null,
            filled($secondary) ? $secondary : null,
        ]));

        if ($parts === []) {
            return null;
        }

        return implode(' — ', $parts);
    }

    /**
     * @param  class-string  $model
     */
    private function table(string $model): string
    {
        return (new $model)->getTable();
    }
}
