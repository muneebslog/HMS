<?php

namespace App\Services;

use App\Enums\MedicationOrderStatus;
use App\Models\MedicationOrder;
use App\Models\MedicationOrderDrip;
use App\Models\MedicationOrderInjection;
use App\Models\MedicationOrderMedicine;
use App\Models\Shift;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use InvalidArgumentException;

class ShiftOrdersExportService
{
    public const TYPE_ALL = 'all';

    public const TYPE_MEDICINE = 'medicine';

    public const TYPE_INJECTION = 'injection';

    public const TYPE_DRIP = 'drip';

    /**
     * @return list<string>
     */
    public static function types(): array
    {
        return [
            self::TYPE_ALL,
            self::TYPE_MEDICINE,
            self::TYPE_INJECTION,
            self::TYPE_DRIP,
        ];
    }

    public function typeLabel(string $type): string
    {
        return match ($type) {
            self::TYPE_MEDICINE => __('Medicines'),
            self::TYPE_INJECTION => __('Injections'),
            self::TYPE_DRIP => __('Drips'),
            default => __('All'),
        };
    }

    /**
     * Build one export row per patient for the selected shift and item type.
     *
     * @return Collection<int, object{
     *     patient_id: int,
     *     mrn: string|null,
     *     patient_name: string,
     *     phone_linked: bool,
     *     items: string
     * }>
     */
    public function rowsForShift(Shift $shift, string $type = self::TYPE_ALL): Collection
    {
        if (! in_array($type, self::types(), true)) {
            throw new InvalidArgumentException("Invalid export type [{$type}].");
        }

        $orders = MedicationOrder::query()
            ->with([
                'patient.family',
                'medicines',
                'injections',
                'drips.additives',
            ])
            ->where('status', '!=', MedicationOrderStatus::Draft)
            ->whereHas('queueToken.serviceQueue', function ($query) use ($shift): void {
                $query->forShift($shift);
            })
            ->where(function ($query) use ($type): void {
                match ($type) {
                    self::TYPE_MEDICINE => $query->whereHas('medicines'),
                    self::TYPE_INJECTION => $query->whereHas('injections'),
                    self::TYPE_DRIP => $query->whereHas('drips'),
                    default => $query->whereHas('medicines')
                        ->orWhereHas('injections')
                        ->orWhereHas('drips'),
                };
            })
            ->get();

        return $orders
            ->groupBy('patient_id')
            ->map(function (Collection $patientOrders) use ($type): ?object {
                /** @var MedicationOrder $first */
                $first = $patientOrders->first();
                $patient = $first->patient;

                if ($patient === null) {
                    return null;
                }

                $itemParts = $patientOrders
                    ->flatMap(fn (MedicationOrder $order): Collection => $this->itemPartsForOrder($order, $type))
                    ->filter()
                    ->values();

                if ($itemParts->isEmpty()) {
                    return null;
                }

                return (object) [
                    'patient_id' => $patient->id,
                    'mrn' => $patient->mrn,
                    'patient_name' => $patient->name,
                    'phone_linked' => filled($patient->contactPhone()),
                    'items' => $itemParts->implode('; '),
                ];
            })
            ->filter()
            ->sortBy(fn (object $row): string => mb_strtolower($row->patient_name))
            ->values();
    }

    /**
     * @return Collection<int, string>
     */
    private function itemPartsForOrder(MedicationOrder $order, string $type): Collection
    {
        $parts = collect();

        if ($type === self::TYPE_ALL || $type === self::TYPE_MEDICINE) {
            foreach ($order->medicines as $medicine) {
                $parts->push($this->formatMedicine($medicine));
            }
        }

        if ($type === self::TYPE_ALL || $type === self::TYPE_INJECTION) {
            foreach ($order->injections as $injection) {
                $parts->push($this->formatInjection($injection));
            }
        }

        if ($type === self::TYPE_ALL || $type === self::TYPE_DRIP) {
            foreach ($order->drips as $drip) {
                $parts->push($this->formatDrip($drip));
            }
        }

        return $parts;
    }

    private function formatMedicine(MedicationOrderMedicine $medicine): string
    {
        $detail = $medicine->dose->label();

        if (filled($medicine->comment)) {
            $detail .= ' · '.$medicine->comment;
        }

        return $this->withAdministeredAt(
            $medicine->name.' — '.$detail,
            $medicine->delivered_at,
        );
    }

    private function formatInjection(MedicationOrderInjection $injection): string
    {
        $detail = $injection->administration_type->label();

        if (filled($injection->comment)) {
            $detail .= ' · '.$injection->comment;
        }

        return $this->withAdministeredAt(
            $injection->name.' — '.$detail,
            $injection->delivered_at,
        );
    }

    private function formatDrip(MedicationOrderDrip $drip): string
    {
        $label = $drip->name;

        if ($drip->additives->isNotEmpty()) {
            $additives = $drip->additives->pluck('name')->implode(', ');
            $label .= ' (+'.$additives.')';
        }

        $start = $this->formatDateTime($drip->started_at) ?? '—';
        $end = $this->formatDateTime($drip->done_at) ?? '—';

        return $label.' '.$start.'–'.$end;
    }

    private function withAdministeredAt(string $label, ?CarbonInterface $administeredAt): string
    {
        $time = $this->formatDateTime($administeredAt);

        if ($time === null) {
            return $label;
        }

        return $label.' @ '.$time;
    }

    private function formatDateTime(?CarbonInterface $value): ?string
    {
        return $value?->format('Y-m-d H:i');
    }
}
