<?php

namespace App\Actions;

use App\Models\Shift;
use App\Models\ShiftSettlement;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class SettleShift
{
    /**
     * Record the cash management received for a closed shift and lock it.
     */
    public function handle(User $manager, Shift $shift, float $receivedAmount, ?string $notes = null): ShiftSettlement
    {
        $this->authorize($manager);

        if ($receivedAmount < 0) {
            throw new InvalidArgumentException(__('Received amount cannot be negative.'));
        }

        return DB::transaction(function () use ($manager, $shift, $receivedAmount, $notes): ShiftSettlement {
            $locked = Shift::query()
                ->whereKey($shift->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($locked->isBeforeFinanceTracking()) {
                throw new InvalidArgumentException(__('This shift is from before finance tracking started.'));
            }

            if ($locked->status !== 'closed') {
                throw new InvalidArgumentException(__('The shift must be closed before its cash can be received.'));
            }

            if ($locked->settlement()->exists()) {
                throw new InvalidArgumentException(__('This shift has already been settled.'));
            }

            if ($locked->pendingApprovalsCount() > 0) {
                throw new InvalidArgumentException(__('Approve or decline all expenses and returns first.'));
            }

            $expectedAmount = round($locked->expectedCash(), 2);

            return $locked->settlement()->create([
                'settled_by' => $manager->id,
                'business_date' => $locked->businessDate()->toDateString(),
                'period' => $locked->period(),
                'opening_balance' => $locked->opening_balance,
                'cash_sales' => $locked->totalCashSales(),
                'online_sales' => $locked->totalOnlineSales(),
                'doctor_payouts' => $locked->totalDailyPayouts(),
                'expenses' => $locked->totalExpenses(),
                'declared_closing_balance' => $locked->closing_balance,
                'expected_amount' => $expectedAmount,
                'received_amount' => round($receivedAmount, 2),
                'difference' => round($receivedAmount - $expectedAmount, 2),
                'notes' => filled($notes) ? $notes : null,
                'settled_at' => now(),
            ]);
        });
    }

    /**
     * Ensure the user may receive shift cash.
     */
    private function authorize(User $manager): void
    {
        if (! $manager->isAdmin() && ! $manager->isManagement()) {
            abort(403);
        }
    }
}
