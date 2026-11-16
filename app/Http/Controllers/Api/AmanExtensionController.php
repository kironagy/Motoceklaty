<?php

namespace App\Http\Controllers\Api;

use App\Domain\Aman\IdCardReader;
use App\Http\Controllers\Controller;
use App\Models\AmanRecipe;
use App\Models\AmanToken;
use App\Models\AmanUpload;
use App\Models\InstallmentRequest;
use App\Models\Staff;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

/**
 * API for the Aman uploader Chrome extension (aman-extension/). Every call is
 * made with an employee's sign-in token (AmanExtensionToken middleware). It lists
 * the new requests the employee may see, hands them out one at a time, serves their files and card reading, stores the
 * taught recipes, and records how each upload ended. It never changes the
 * request itself.
 */
class AmanExtensionController extends Controller
{
    /** Request columns the extension may read. */
    private const HIDDEN = ['staff_id', 'pending_staff_id', 'transfer_requested_by', 'transfer_requested_at', 'checks_report', 'customer_action', 'whatsapp_conversation_id', 'form_link_id', 'application_id', 'employee_editable'];

    public const FILE_FIELDS = [
        'applicant_id_image', 'applicant_id_back_image', 'guarantor_id_image', 'guarantor_id_back_image',
        'selfie_image', 'medical_card_image', 'price_offer_image', 'free_income_proof_images',
        'salary_slip_file', 'pension_statement_file', 'commercial_reg_file', 'tax_card_file',
    ];

    private function staff(Request $request): Staff
    {
        return $request->attributes->get('aman_staff');
    }

    public function me(Request $request)
    {
        return $this->meData($this->staff($request));
    }

    private function meData(Staff $s): array
    {

        return ['id' => $s->id, 'name' => $s->name, 'can_teach' => $s->isAdmin(), 'types' => config('aman_extension.types')];
    }

    public function recipes()
    {
        return AmanRecipe::all()->keyBy('type')->map(fn (AmanRecipe $r) => [
            'type' => $r->type, 'version' => $r->version, 'steps' => $r->steps, 'savedAt' => $r->updated_at?->toIso8601String(),
        ]);
    }

    public function saveRecipe(Request $request, string $type)
    {
        abort_unless($this->staff($request)->isAdmin(), 403, 'التعليم للمسؤول بس');
        abort_unless(array_key_exists($type, config('aman_extension.types')), 422, 'نوع طلب مش معروف');

        $data = $request->validate(['steps' => 'required|array|min:1', 'steps.*.kind' => 'required|string']);
        abort_unless(collect($data['steps'])->contains(fn ($s) => ! empty($s['gate'])), 422, 'الوصفة لازم فيها وقفة موافقة');

        $recipe = AmanRecipe::firstOrNew(['type' => $type]);
        $recipe->version = ($recipe->exists ? $recipe->version : 0) + 1;
        $recipe->steps = $data['steps'];
        $recipe->updated_by = $this->staff($request)->id;
        $recipe->save();

        return ['type' => $type, 'version' => $recipe->version];
    }

    /** Signs an employee in with their motobot email and password; the extension keeps only the token. */
    public function login(Request $request)
    {
        $data = $request->validate(['email' => 'required|string', 'password' => 'required|string', 'device' => 'nullable|string|max:200']);
        $staff = Staff::where('email', $data['email'])->first();

        if (! $staff || ! Hash::check($data['password'], (string) $staff->password)) {
            return response()->json(['message' => 'الإيميل أو الباسورد غلط'], 422);
        }

        return ['token' => AmanToken::issue($staff, $data['device'] ?? null), 'me' => $this->meData($staff)];
    }

    public function logout(Request $request)
    {
        $request->attributes->get('aman_token')?->delete();

        return ['ok' => true];
    }

    /** New requests this employee may see on motobot that still need uploading to Aman. */
    private function pending(Staff $staff)
    {
        $staleBefore = now()->subMinutes((int) config('aman_extension.claim_minutes', 15));

        // A company employee uploads only for the companies on their account, even when they are
        // also a super admin who sees every company on the requests table (owner 2026-11-16).
        $companies = $staff->is_company_employee ? $staff->installmentSystems()->pluck('installment_systems.name')->all() : null;

        return InstallmentRequest::query()
            ->visibleTo($staff)
            ->when($companies !== null, fn ($q) => $q->whereIn('installment_type', $companies ?: ['__none__']))
            ->whereIn('status', config('aman_extension.new_statuses'))
            ->whereIn('request_type', config('aman_extension.request_types', ['normal', 'bot']))
            ->where('installment_type', 'like', config('aman_extension.installment_type_like', 'امان%'))
            ->whereNotExists(fn ($q) => $q->from('aman_uploads')->whereColumn('aman_uploads.installment_request_id', 'installment_requests.id')
                ->where(fn ($w) => $w->whereIn('status', AmanUpload::DONE)
                    ->orWhere(fn ($c) => $c->where('status', 'claimed')->where('claimed_at', '>', $staleBefore)->where('staff_id', '!=', $staff->id))));
    }

    public function queue(Request $request)
    {
        $types = config('aman_extension.types');
        $recipes = AmanRecipe::pluck('type')->all();

        $items = $this->pending($this->staff($request))->orderBy('id')->limit(300)
            ->get(['id', 'applicant_name', 'applicant_phone', 'applicant_national_id', 'work_status', 'installment_type'])
            ->map(fn (InstallmentRequest $r) => [
                'id' => $r->id, 'name' => $r->applicant_name, 'phone' => $r->applicant_phone, 'national_id' => $r->applicant_national_id,
                'type' => $r->work_status, 'type_label' => $types[$r->work_status] ?? ($r->work_status ?: 'مش محدد'),
                'company' => $r->installment_type, 'has_recipe' => in_array($r->work_status, $recipes, true),
            ]);

        return ['items' => $items];
    }

    /** Claims one request for this employee and returns its data, files and card reading. */
    public function claim(Request $request, int $id, IdCardReader $cards)
    {
        $staff = $this->staff($request);

        $upload = DB::transaction(function () use ($staff, $id) {
            $req = $this->pending($staff)->whereKey($id)->lockForUpdate()->first();
            if (! $req) {
                return null;
            }

            $mine = AmanUpload::where('installment_request_id', $id)->where('staff_id', $staff->id)->where('status', 'claimed')->first();
            if ($mine) {
                $mine->update(['claimed_at' => now()]);

                return $mine;
            }

            return AmanUpload::create([
                'installment_request_id' => $req->id, 'staff_id' => $staff->id, 'type' => $req->work_status,
                'status' => 'claimed', 'claimed_at' => now(),
            ]);
        });

        if (! $upload) {
            return response()->json(['message' => 'الطلب ده مبقاش جديد أو حد تاني شغال عليه'], 409);
        }

        $req = $upload->request;
        $order = collect($req->only($req->getFillable()))->except(self::HIDDEN)->all();

        foreach (self::FILE_FIELDS as $field) {
            $value = $req->{$field};
            $paths = is_array($value) ? array_values($value) : (blank($value) ? [] : [$value]);
            $order[$field] = $paths ? array_map(fn ($i) => route('aman.file', ['upload' => $upload->id, 'field' => $field, 'i' => $i]), array_keys($paths)) : null;
        }

        return [
            'upload' => ['id' => $upload->id, 'type' => $upload->type, 'type_label' => config('aman_extension.types')[$upload->type] ?? $upload->type],
            'request_id' => $req->id,
            'order' => $order,
            'card' => $cards->read($req),
        ];
    }

    /** One day's uploads by this employee, for the extension's "today's report" sheet. */
    public function report(Request $request)
    {
        $day = $request->validate(['date' => 'nullable|date'])['date'] ?? now()->toDateString();
        $types = config('aman_extension.types');

        return ['date' => $day, 'rows' => AmanUpload::with('request:id,applicant_name,applicant_national_id')
            ->where('staff_id', $this->staff($request)->id)
            ->whereDate('claimed_at', $day)
            ->whereIn('status', ['uploaded', 'rejected_before', 'error', 'released'])
            ->orderBy('finished_at')
            ->get()
            ->map(fn (AmanUpload $u) => [
                'id' => $u->installment_request_id, 'name' => $u->request?->applicant_name, 'national_id' => $u->request?->applicant_national_id,
                'type_label' => $types[$u->type] ?? $u->type, 'status' => $u->status, 'aman_ref' => $u->aman_ref,
                'message' => $u->message, 'at' => $u->finished_at?->toIso8601String(),
            ])];
    }

    public function file(Request $request, AmanUpload $upload, string $field)
    {
        abort_unless($upload->staff_id === $this->staff($request)->id, 403);
        abort_unless(in_array($field, self::FILE_FIELDS, true), 404);

        $value = $upload->request->{$field};
        $paths = is_array($value) ? array_values($value) : [$value];
        $path = $paths[(int) $request->query('i', 0)] ?? null;
        abort_if(blank($path) || ! Storage::disk('public')->exists($path), 404);

        return Storage::disk('public')->response($path);
    }

    public function result(Request $request, AmanUpload $upload)
    {
        abort_unless($upload->staff_id === $this->staff($request)->id, 403);

        $data = $request->validate([
            'status' => ['required', Rule::in(['uploaded', 'rejected_before', 'error', 'released'])],
            'aman_ref' => 'nullable|string|max:100',
            'message' => 'nullable|string|max:2000',
            'recipe_version' => 'nullable|integer',
            'log' => 'nullable|array',
        ]);

        $upload->update($data + ['finished_at' => now()]);

        return ['ok' => true];
    }
}
