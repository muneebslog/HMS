<?php

namespace App\Services;

use App\Models\Doctor;
use App\Models\DoctorPayout;
use App\Models\Expense;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\LabInvoice;
use App\Models\MonthlyExpense;
use App\Models\ProcedurePayment;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

class MonthlyReportService
{
    /**
     * Build the financial summary and detail collections for a calendar month.
     *
     * @return array{
     *     start: Carbon,
     *     end: Carbon,
     *     receipts_total: float,
     *     lab_total: float,
     *     procedure_total: float,
     *     total_income: float,
     *     shift_expenses_total: float,
     *     monthly_expenses_total: float,
     *     doctor_payouts_total: float,
     *     doctor_shares_accrued_total: float,
     *     total_outflow: float,
     *     hospital_net: float,
     *     hospital_share_of_receipts: float,
     *     receipts: \Illuminate\Database\Eloquent\Collection<int, Invoice>,
     *     labs: \Illuminate\Database\Eloquent\Collection<int, LabInvoice>,
     *     procedure_payments: \Illuminate\Database\Eloquent\Collection<int, ProcedurePayment>,
     *     shift_expenses: \Illuminate\Database\Eloquent\Collection<int, Expense>,
     *     monthly_expenses: \Illuminate\Database\Eloquent\Collection<int, MonthlyExpense>,
     *     doctor_payouts: \Illuminate\Database\Eloquent\Collection<int, DoctorPayout>,
     *     doctor_shares: Collection<int, array{doctor: Doctor, total_amount: float, share_amount: float}>
     * }
     */
    public function forMonth(CarbonInterface $month): array
    {
        $start = $month->copy()->startOfMonth();
        $end = $month->copy()->endOfMonth();

        $receipts = Invoice::query()
            ->with('patient')
            ->where('status', '!=', 'cancelled')
            ->whereBetween('created_at', [$start, $end])
            ->latest()
            ->get();

        $labs = LabInvoice::query()
            ->with('patient')
            ->whereBetween('created_at', [$start, $end])
            ->latest()
            ->get();

        $procedurePayments = ProcedurePayment::query()
            ->with(['procedure.patient', 'procedure.doctor'])
            ->active()
            ->whereBetween('created_at', [$start, $end])
            ->latest()
            ->get();

        $shiftExpenses = Expense::query()
            ->with(['user', 'shift'])
            ->whereBetween('created_at', [$start, $end])
            ->latest()
            ->get();

        $monthlyExpenses = MonthlyExpense::query()
            ->with('user')
            ->whereBetween('expense_date', [$start->toDateString(), $end->toDateString()])
            ->latest('expense_date')
            ->latest('id')
            ->get();

        $doctorPayouts = DoctorPayout::query()
            ->with('doctor')
            ->whereBetween('paid_at', [$start, $end])
            ->latest('paid_at')
            ->get();

        $doctorShares = $this->doctorSharesForPeriod($start, $end);

        $receiptsTotal = (float) $receipts->sum('total');
        $labTotal = (float) $labs->sum('total');
        $procedureTotal = (float) $procedurePayments->sum('amount');
        $shiftExpensesTotal = (float) $shiftExpenses->sum('amount');
        $monthlyExpensesTotal = (float) $monthlyExpenses->sum('amount');
        $doctorPayoutsTotal = (float) $doctorPayouts->sum('share_amount');
        $doctorSharesAccruedTotal = (float) $doctorShares->sum('share_amount');

        $totalIncome = $receiptsTotal + $labTotal + $procedureTotal;
        $totalOutflow = $shiftExpensesTotal + $monthlyExpensesTotal + $doctorPayoutsTotal;

        return [
            'start' => $start,
            'end' => $end,
            'receipts_total' => $receiptsTotal,
            'lab_total' => $labTotal,
            'procedure_total' => $procedureTotal,
            'total_income' => $totalIncome,
            'shift_expenses_total' => $shiftExpensesTotal,
            'monthly_expenses_total' => $monthlyExpensesTotal,
            'doctor_payouts_total' => $doctorPayoutsTotal,
            'doctor_shares_accrued_total' => $doctorSharesAccruedTotal,
            'total_outflow' => $totalOutflow,
            'hospital_net' => $totalIncome - $totalOutflow,
            'hospital_share_of_receipts' => $receiptsTotal - $doctorSharesAccruedTotal,
            'receipts' => $receipts,
            'labs' => $labs,
            'procedure_payments' => $procedurePayments,
            'shift_expenses' => $shiftExpenses,
            'monthly_expenses' => $monthlyExpenses,
            'doctor_payouts' => $doctorPayouts,
            'doctor_shares' => $doctorShares,
        ];
    }

    /**
     * Calculate accrued doctor shares from walk-in invoice items in the period.
     *
     * @return Collection<int, array{doctor: Doctor, total_amount: float, share_amount: float}>
     */
    public function doctorSharesForPeriod(CarbonInterface $start, CarbonInterface $end): Collection
    {
        $items = InvoiceItem::query()
            ->with(['doctor', 'invoice'])
            ->whereNotNull('doctor_id')
            ->whereBetween('created_at', [$start, $end])
            ->whereHas('invoice', fn ($query) => $query->where('status', '!=', 'cancelled'))
            ->orderBy('created_at')
            ->get();

        return $items
            ->groupBy('doctor_id')
            ->map(function (Collection $doctorItems) {
                /** @var Doctor $doctor */
                $doctor = $doctorItems->first()->doctor;

                return [
                    'doctor' => $doctor,
                    'total_amount' => (float) $doctorItems->sum('price'),
                    'share_amount' => $doctor->calculateShareAmount($doctorItems, perDay: true),
                ];
            })
            ->sortBy(fn (array $row) => $row['doctor']->name)
            ->values();
    }
}
