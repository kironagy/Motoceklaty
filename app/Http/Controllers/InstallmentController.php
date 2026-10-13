<?php

namespace App\Http\Controllers;

use App\Domain\Installments\InstallmentCalculationException;
use App\Domain\Installments\InstallmentCalculator;
use App\Models\InstallmentPlan;
use App\Models\InstallmentSystem;
use App\Models\Machine;
use Illuminate\Http\Request;

class InstallmentController extends Controller
{
    public function index()
    {
        return view('site.installments.calculator');
    }

    /** JSON for the website calculator widget - same math as the WhatsApp agent. */
    public function calculate(Request $request, InstallmentCalculator $calculator)
    {
        $data = $request->validate([
            'machine_id' => ['required', 'integer'],
            'installment_system_id' => ['required', 'integer'],
            'months' => ['required', 'integer', 'min:1'],
            'down_payment' => ['nullable', 'numeric', 'min:0'],
        ]);

        $machine = Machine::where('is_active', true)->find($data['machine_id']);
        $system = $machine?->installmentSystems()->where('installment_systems.id', $data['installment_system_id'])->first();
        $plan = $system ? InstallmentPlan::query()
            ->where('installment_system_id', $system->id)
            ->where('months', $data['months'])
            ->where('is_active', true)
            ->first() : null;

        if (! $machine || ! $system || $system->is_active === false || ! $plan) {
            return response()->json(['ok' => false, 'message' => site('installment.calculator.result.missing_fields')]);
        }

        try {
            $result = $calculator->calculate($machine, $system, $plan, (float) ($data['down_payment'] ?? 0));
        } catch (InstallmentCalculationException $e) {
            return response()->json(['ok' => false, 'message' => $this->messageFor($e, $machine, $system)]);
        }

        return response()->json(['ok' => true, 'result' => [
            'months' => $result->months,
            'interest_percent' => $result->interestPercent,
            'down_payment' => $result->downPayment,
            'financed_amount' => $result->financedAmount,
            'total_with_interest' => $result->totalWithInterest,
            'administrative_fees' => $result->administrativeFees,
            'monthly_installment' => $result->monthlyInstallment,
        ]]);
    }

    protected function messageFor(InstallmentCalculationException $e, Machine $machine, InstallmentSystem $system): string
    {
        return match ($e->errorCode) {
            'DOWN_PAYMENT_TOO_HIGH' => 'الدفعة الأولى لا يمكن أن تكون أكبر من أو تساوي سعر المكنة الكاش (' . number_format((float) $machine->cash_price) . ' جنيه).',
            'DOWN_PAYMENT_BELOW_MINIMUM' => 'أقل مقدم لنظام ' . trim($system->name) . ' هو ' . number_format((float) $system->minimum_down_payment) . ' جنيه.',
            default => 'من فضلك اكتب قيمة مقدم صحيحة.',
        };
    }
}
