<?php

namespace App\Services;

use App\Enums\ClinicStation;
use App\Enums\DripChargeStatus;
use App\Enums\DripLineStatus;
use App\Enums\MedicationOrderStatus;
use App\Enums\StationType;
use App\Models\DripCharge;
use App\Models\QueueToken;
use App\Models\Shift;
use App\Models\StationSession;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class PatientFlowBoardService
{
    public function __construct(
        private StationSessionService $stationSessions,
    ) {}

    /**
     * Build the patient flow board for the current open shift.
     *
     * @return array{
     *     stations: array<string, list<array{token_id: int, patient_name: string, mrn: ?string, token_number: int, service_name: string, stage_started_at: string, minutes_in_stage: int}>>,
     *     aide_sessions: array<string, array{aide_name: ?string, expired: bool, minutes_remaining: ?int, status: string}>
     * }
     */
    public function board(?Shift $shift = null): array
    {
        $shift ??= Shift::current();

        $stations = [];
        foreach (ClinicStation::cases() as $station) {
            if ($station === ClinicStation::Done) {
                continue;
            }
            $stations[$station->value] = [];
        }

        if ($shift === null) {
            return [
                'stations' => $stations,
                'aide_sessions' => $this->aideSessions(),
            ];
        }

        $tokens = QueueToken::query()
            ->with([
                'patient',
                'serviceQueue.service',
                'vital',
                'medicationOrder.medicines',
                'medicationOrder.injections',
                'medicationOrder.drips',
            ])
            ->whereHas('serviceQueue', function ($query) use ($shift): void {
                $query->where('shift_id', $shift->id)
                    ->where('status', 'open');
            })
            ->whereIn('status', ['waiting', 'serving', 'served'])
            ->orderBy('token_number')
            ->get();

        $pendingDripChargeTokenIds = $this->pendingDripChargeTokenIds($tokens);

        foreach ($tokens as $token) {
            $resolved = $this->resolveStation($token, $pendingDripChargeTokenIds);

            if ($resolved['station'] === ClinicStation::Done) {
                continue;
            }

            $stations[$resolved['station']->value][] = [
                'token_id' => $token->id,
                'patient_name' => $token->patient?->name ?? __('Unknown'),
                'mrn' => $token->patient?->mrn,
                'token_number' => $token->token_number,
                'service_name' => $token->serviceQueue?->service?->name ?? __('Unknown'),
                'stage_started_at' => $resolved['started_at']->toIso8601String(),
                'minutes_in_stage' => max(0, (int) $resolved['started_at']->diffInMinutes(now())),
            ];
        }

        return [
            'stations' => $stations,
            'aide_sessions' => $this->aideSessions(),
        ];
    }

    /**
     * Resolve the current clinic station for a queue token.
     *
     * @param  Collection<int, int>  $pendingDripChargeTokenIds
     * @return array{station: ClinicStation, started_at: Carbon}
     */
    public function resolveStation(QueueToken $token, Collection $pendingDripChargeTokenIds): array
    {
        $service = $token->serviceQueue?->service;
        $order = $token->medicationOrder;
        $arrivedAt = $token->arrived_at ?? $token->created_at ?? now();

        if (($service?->needs_vitals || $service?->ends_at_vitals) && $token->vital === null) {
            return [
                'station' => ClinicStation::Vitals,
                'started_at' => $arrivedAt,
            ];
        }

        if ($service?->ends_at_vitals) {
            return [
                'station' => ClinicStation::Done,
                'started_at' => $token->vital?->created_at ?? $token->updated_at ?? $arrivedAt,
            ];
        }

        if ($this->needsDoctorStep($token)) {
            return [
                'station' => ClinicStation::Doctor,
                'started_at' => $token->vital?->created_at ?? $arrivedAt,
            ];
        }

        if ($pendingDripChargeTokenIds->contains($token->id)) {
            return [
                'station' => ClinicStation::Reception,
                'started_at' => $order?->created_at ?? $arrivedAt,
            ];
        }

        $activeDrip = $order?->drips
            ?->first(fn ($drip): bool => in_array($drip->status, [DripLineStatus::Pending, DripLineStatus::Started], true));

        if ($activeDrip !== null) {
            return [
                'station' => ClinicStation::Drip,
                'started_at' => $activeDrip->started_at
                    ?? $activeDrip->created_at
                    ?? $order?->created_at
                    ?? $arrivedAt,
            ];
        }

        if ($this->needsErStep($token)) {
            return [
                'station' => ClinicStation::Er,
                'started_at' => $order?->created_at
                    ?? $token->vital?->created_at
                    ?? $arrivedAt,
            ];
        }

        return [
            'station' => ClinicStation::Done,
            'started_at' => $token->updated_at ?? $arrivedAt,
        ];
    }

    /**
     * Whether the patient still needs to see the doctor.
     */
    public function needsDoctorStep(QueueToken $token): bool
    {
        $service = $token->serviceQueue?->service;

        if ($service === null) {
            return false;
        }

        if ($service->needs_medication) {
            return $token->medicationOrder === null;
        }

        if ($service->appear_on_er) {
            return false;
        }

        return in_array($token->status, ['waiting', 'serving'], true);
    }

    /**
     * Whether the patient still needs ER station work.
     */
    public function needsErStep(QueueToken $token): bool
    {
        $service = $token->serviceQueue?->service;
        $order = $token->medicationOrder;

        if ($order !== null && $order->status === MedicationOrderStatus::Pending) {
            $hasUndelivered = $order->medicines->contains(fn ($line): bool => $line->delivered_at === null)
                || $order->injections->contains(fn ($line): bool => $line->delivered_at === null);

            if ($hasUndelivered) {
                return true;
            }
        }

        if ($service?->appear_on_er && $token->status !== 'served') {
            return true;
        }

        return false;
    }

    /**
     * @param  Collection<int, QueueToken>  $tokens
     * @return Collection<int, int>
     */
    private function pendingDripChargeTokenIds(Collection $tokens): Collection
    {
        $tokenIds = $tokens->pluck('id');
        $orderIds = $tokens->map(fn (QueueToken $token): ?int => $token->medicationOrder?->id)->filter();

        if ($tokenIds->isEmpty()) {
            return collect();
        }

        $charges = DripCharge::query()
            ->where('status', DripChargeStatus::Pending)
            ->where(function ($query) use ($tokenIds, $orderIds): void {
                $query->whereIn('queue_token_id', $tokenIds);

                if ($orderIds->isNotEmpty()) {
                    $query->orWhereIn('medication_order_id', $orderIds);
                }
            })
            ->get(['queue_token_id', 'medication_order_id']);

        $matched = collect();

        foreach ($charges as $charge) {
            if ($charge->queue_token_id !== null) {
                $matched->push($charge->queue_token_id);

                continue;
            }

            if ($charge->medication_order_id !== null) {
                $tokenId = $tokens->first(
                    fn (QueueToken $token): bool => $token->medicationOrder?->id === $charge->medication_order_id
                )?->id;

                if ($tokenId !== null) {
                    $matched->push($tokenId);
                }
            }
        }

        return $matched->unique()->values();
    }

    /**
     * @return array<string, array{aide_name: ?string, expired: bool, minutes_remaining: ?int, status: string}>
     */
    private function aideSessions(): array
    {
        $result = [];

        foreach (StationType::cases() as $type) {
            $session = $this->stationSessions->forStation($type);
            $result[$type->value] = $this->formatAideSession($session);
        }

        return $result;
    }

    /**
     * @return array{aide_name: ?string, expired: bool, minutes_remaining: ?int, status: string}
     */
    private function formatAideSession(?StationSession $session): array
    {
        if ($session === null || $session->health_aide_id === null) {
            return [
                'aide_name' => null,
                'expired' => false,
                'minutes_remaining' => null,
                'status' => 'none',
            ];
        }

        if ($session->isExpired()) {
            return [
                'aide_name' => $session->healthAide?->name,
                'expired' => true,
                'minutes_remaining' => null,
                'status' => 'expired',
            ];
        }

        return [
            'aide_name' => $session->healthAide?->name,
            'expired' => false,
            'minutes_remaining' => $session->minutesRemaining(),
            'status' => 'active',
        ];
    }
}
