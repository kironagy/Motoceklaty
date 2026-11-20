<?php

namespace App\Http\Controllers\Api;

use App\Domain\Aman\IdCardReader;
use App\Domain\Aman\SmartTransport;
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

    private const CARD_FIELDS = ['applicant_id_image', 'applicant_id_back_image', 'guarantor_id_image', 'guarantor_id_back_image'];

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
        return AmanRecipe::where('type', '!=', self::INQUIRY_RECIPE)->get()->keyBy('type')->map(fn (AmanRecipe $r) => [
            'type' => $r->type, 'version' => $r->version, 'steps' => $r->steps, 'savedAt' => $r->updated_at?->toIso8601String(),
        ]);
    }

    public function saveRecipe(Request $request, string $type)
    {
        abort_unless($this->staff($request)->isAdmin(), 403, 'التعليم للمسؤول بس');
        abort_unless(array_key_exists($type, config('aman_extension.types')), 422, 'نوع طلب مش معروف');

        $request->validate(['steps' => 'required|array|min:1', 'steps.*.kind' => 'required|string']);
        // Not validate()'s result: with a "steps.*.kind" rule it keeps only `kind` of each step and
        // drops target, source and gate, so every recipe was refused (2026-11-16).
        $steps = array_values($request->input('steps'));
        abort_unless(collect($steps)->contains(fn ($s) => ! empty($s['gate'])), 422, 'الوصفة لازم فيها وقفة موافقة');

        $recipe = AmanRecipe::firstOrNew(['type' => $type]);
        $recipe->version = ($recipe->exists ? $recipe->version : 0) + 1;
        $recipe->steps = $steps;
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

        if (! $staff->aman_extension_enabled) {
            return \App\Http\Middleware\AmanExtensionToken::locked();
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
    public function claim(Request $request, int $id, IdCardReader $cards, SmartTransport $transport)
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
            // ID card photos go to Aman as they are: cropping cut cards wrong (owner 2026-11-17).
            $order[$field] = $paths ? array_map(fn ($i) => route('aman.file', ['upload' => $upload->id, 'field' => $field, 'i' => $i]), array_keys($paths)) : null;
        }

        // Smart-transport drivers: income proof photos merged into the two guarantor slots, work address
        // zeros. Anyone else keeps the card photos there, as before.
        $smart = $transport->is($req);
        $proofs = $smart ? count($transport->proofs($req)) : 0;
        $order['smart_transport'] = $smart;
        $order['aman_work_address'] = $smart ? SmartTransport::ZERO_ADDRESS : $req->work_address;
        // A driver has no work place: Aman's work governorate / area are the customer's own (owner 2026-11-16).
        $order['aman_work_governorate'] = $smart ? $req->applicant_governorate : $req->work_governorate;
        $order['aman_work_area'] = $smart ? $req->applicant_area : $req->work_area;
        $order['proof_merged_1'] = $proofs >= 1 ? [route('aman.file', ['upload' => $upload->id, 'field' => 'proof_merged_1'])] : $order['applicant_id_image'];
        $order['proof_merged_2'] = $proofs >= 2 ? [route('aman.file', ['upload' => $upload->id, 'field' => 'proof_merged_2'])] : $order['applicant_id_back_image'];

        // The recipe picks the number's source: "الرقم القومي" is motobot's, "البطاقة: الرقم القومي" is the
        // card's OCR read (owner 2026-11-17: OCR misreads a digit, so the recipe uses motobot's).
        $card = $cards->read($req);

        return [
            'upload' => ['id' => $upload->id, 'type' => $upload->type, 'type_label' => config('aman_extension.types')[$upload->type] ?? $upload->type],
            'request_id' => $req->id,
            'order' => $order,
            'card' => $card,
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

    public function file(Request $request, AmanUpload $upload, string $field, SmartTransport $transport)
    {
        abort_unless($upload->staff_id === $this->staff($request)->id, 403);

        if (preg_match('/^proof_merged_([12])$/', $field, $m)) {
            $path = $transport->merged($upload->request, (int) $m[1]);
            abort_if($path === null, 404);

            return Storage::disk('public')->response($path);
        }
        abort_unless(in_array($field, self::FILE_FIELDS, true), 404);

        $value = $upload->request->{$field};
        $paths = is_array($value) ? array_values($value) : [$value];
        $path = $paths[(int) $request->query('i', 0)] ?? null;
        abort_if(blank($path) || ! Storage::disk('public')->exists($path), 404);

        return Storage::disk('public')->response($path);
    }

    /** The extension still has this request open (stopped for the employee): keep the claim. */
    public function alive(Request $request, AmanUpload $upload)
    {
        abort_unless($upload->staff_id === $this->staff($request)->id, 403);
        if ($upload->status === 'claimed') {
            $upload->update(['claimed_at' => now()]);
        }

        return ['ok' => true];
    }

    public function result(Request $request, AmanUpload $upload)
    {
        abort_unless($upload->staff_id === $this->staff($request)->id, 403);

        $data = $request->validate([
            'status' => ['required', Rule::in(['uploaded', 'rejected_before', 'error', 'released', 'held', 'canceled'])],
            // "إلغاء الطلب" from the extension: the status the request takes, and Aman's reply.
            'request_status' => ['nullable', Rule::in(['rejected', 'canceled', 'paused'])],
            'reply' => 'nullable|string|max:2000',
            'aman_ref' => 'nullable|string|max:100',
            'message' => 'nullable|string|max:2000',
            'recipe_version' => 'nullable|integer',
            'log' => 'nullable|array',
        ]);

        // Uploaded means Aman gave its number (older extensions sent "uploaded" without one).
        if ($data['status'] === 'uploaded' && blank($data['aman_ref'] ?? null)) {
            $data['status'] = 'error';
            $data['message'] = 'اتبعت إنه اترفع من غير رقم طلب من أمان'.(filled($data['message'] ?? null) ? ' — '.$data['message'] : '');
        }

        $upload->update(\Illuminate\Support\Arr::only($data, ['status', 'aman_ref', 'message', 'recipe_version', 'log']) + ['finished_at' => now()]);

        // Like staff doing it by hand (owner 2026-11-16), and only on a request that is still new:
        // - refused before at Aman: the request is rejected;
        // - uploaded with Aman's number: the request goes under inquiry, the number and Aman's message
        //   become its reason.
        $req = InstallmentRequest::find($upload->installment_request_id);
        if ($req && in_array($req->status, config('aman_extension.new_statuses'), true)) {
            if ($data['status'] === 'rejected_before') {
                // Aman's own words when it gave them ("عفواً هذا العميل لدية طلب كارت ...").
                $req->checks_report = array_merge((array) $req->checks_report, ['aman' => filled($data['message'] ?? null) ? $data['message'] : 'العميل عنده طلب مرفوض قبل كده عند أمان']);
                $req->status = 'rejected';
                $req->save();
            } elseif (in_array($data['status'], ['held', 'canceled'], true)) {
                // Owner 2026-11-16: "خليه متوقف" pauses the request with the employee's reason;
                // "إلغاء الطلب" sets the status the employee picked, with the reason and Aman's reply.
                $reason = trim(implode("\n", array_filter([$data['message'] ?? null, filled($data['reply'] ?? null) ? 'رد أمان: '.$data['reply'] : null])));
                $req->checks_report = array_merge((array) $req->checks_report, ['aman' => $reason ?: 'من رافع أمان']);
                $req->status = $data['status'] === 'held' ? 'paused' : ($data['request_status'] ?? 'canceled');
                // Owner 2026-11-19: "متوقف" from the extension is "متوقف امان" (parked on hold-aman@ after 48 h).
                if ($req->status === 'paused') {
                    $req->status = 'paused_aman';
                }
                $req->save();
            } elseif ($data['status'] === 'uploaded' && filled($data['aman_ref'] ?? null)) {
                $note = 'رقم طلب أمان: '.$data['aman_ref'].(filled($data['message'] ?? null) ? ' — '.$data['message'] : '');
                $req->checks_report = array_merge((array) $req->checks_report, ['aman' => $note]);
                $req->status = 'pending';
                $req->save();
            }
        }

        return ['ok' => true];
    }

    // ---------- متابعة الاستعلام (owner 2026-11-20) ----------
    // A tab of its own beside the uploader: every request under inquiry ("تحت الاستعلام") is looked up
    // on Aman by the customer's national id, and the status Aman shows comes back here.

    private const INQUIRY_RECIPE = '_inquiry';

    /** Statuses the follow-up may set; "pending" = still under inquiry, nothing changes. */
    public const INQUIRY_STATUSES = ['pending', 'approved', 'rejected', 'paused_aman', 'work_check', 'canceled'];

    public function inquiryRecipe()
    {
        $r = AmanRecipe::where('type', self::INQUIRY_RECIPE)->first();

        return ['recipe' => $r ? $r->steps + ['version' => $r->version] : null, 'statuses' => array_intersect_key(
            \App\Filament\Resources\DeliveryResource::STATUS_LABELS, array_flip(self::INQUIRY_STATUSES))];
    }

    public function saveInquiryRecipe(Request $request)
    {
        abort_unless($this->staff($request)->isAdmin(), 403, 'التعليم للمسؤول بس');
        $request->validate([
            'url' => 'required|string|max:1000',
            // the steps in order (owner 2026-11-20): pick the search type, type the national id, search,
            // copy the request number from the row of this employee's Aman user, search by it, read the status
            'steps' => 'required|array|min:1',
            'steps.*.kind' => ['required', Rule::in(['select', 'fill', 'nid', 'click', 'copy'])],
            'steps.*.source' => ['nullable', Rule::in(['national_id', 'ref', 'aman_ref'])],
            'result' => 'required|array',
            'user' => 'nullable|array',
            'map' => 'array', 'map.*.text' => 'required|string|max:200',
            'map.*.status' => ['required', Rule::in(self::INQUIRY_STATUSES)],
        ]);
        $steps = collect($request->input('steps'));
        abort_unless($steps->contains(fn ($s) => in_array($s['kind'], ['fill', 'nid'], true)), 422, 'علّم خانة الرقم القومي');
        abort_if($steps->contains('kind', 'copy') && ! $request->input('user'), 422, 'علّم اسم يوزر أمان عشان النسخ من الجدول');

        $recipe = AmanRecipe::firstOrNew(['type' => self::INQUIRY_RECIPE]);
        $recipe->version = ($recipe->exists ? $recipe->version : 0) + 1;
        // the whole input, not validate()'s result (it drops the targets' keys - see saveRecipe)
        $recipe->steps = $request->only(['url', 'steps', 'result', 'user', 'map']);
        $recipe->updated_by = $this->staff($request)->id;
        $recipe->save();

        return ['version' => $recipe->version];
    }

    private function inquiryRequests(Staff $staff)
    {
        return InstallmentRequest::query()->visibleTo($staff)
            ->where('status', 'pending')
            ->where('installment_type', 'like', config('aman_extension.installment_type_like'))
            ->whereIn('request_type', config('aman_extension.request_types'))
            ->whereNotNull('applicant_national_id')->where('applicant_national_id', '!=', '');
    }

    /** Requests under inquiry, oldest first. */
    public function inquiryQueue(Request $request)
    {
        $rows = $this->inquiryRequests($this->staff($request))
            // owner 2026-11-20: from the oldest request to the newest
            ->orderBy('created_at')->orderBy('id')
            ->limit(1000)
            ->get(['id', 'applicant_name', 'applicant_national_id', 'installment_type', 'created_at', 'aman_checked_at', 'aman_check_text']);
        $refs = AmanUpload::whereIn('installment_request_id', $rows->pluck('id'))->whereNotNull('aman_ref')
            ->orderBy('id')->pluck('aman_ref', 'installment_request_id');

        return ['requests' => $rows->map(fn (InstallmentRequest $r) => [
            'id' => $r->id, 'name' => $r->applicant_name, 'national_id' => $r->applicant_national_id,
            'company' => $r->installment_type, 'aman_ref' => $refs[$r->id] ?? null,
            'created_at' => $r->created_at?->toDateString(),
            'checked_at' => $r->aman_checked_at, 'last_text' => $r->aman_check_text,
        ])->values()];
    }

    /** What Aman showed for one request; a mapped status other than "pending" moves the request. */
    public function inquiryResult(Request $request, int $id)
    {
        $staff = $this->staff($request);
        $data = $request->validate([
            'text' => 'required|string|max:500',
            'status' => ['nullable', Rule::in(self::INQUIRY_STATUSES)],
        ]);
        $req = $this->inquiryRequests($staff)->whereKey($id)->first();
        if (! $req) {
            return ['ok' => true, 'changed' => false, 'skipped' => 'الطلب مبقاش تحت الاستعلام'];
        }

        $req->aman_checked_at = now();
        $req->aman_check_text = mb_substr($data['text'], 0, 500);
        $changed = filled($data['status'] ?? null) && $data['status'] !== 'pending';
        if ($changed) {
            $req->checks_report = array_merge((array) $req->checks_report, ['aman' => 'متابعة الاستعلام على أمان: '.$data['text']]);
            $req->status = $data['status'];
            $req->status_updated_by = $staff->id;
            \Spatie\Activitylog\Facades\CauserResolver::setCauser($staff);
        }
        $req->save();
        DB::table('aman_inquiry_checks')->insert([
            'installment_request_id' => $req->id, 'staff_id' => $staff->id, 'text' => $req->aman_check_text,
            'status' => $data['status'] ?? null, 'changed' => $changed, 'created_at' => now(),
        ]);

        return ['ok' => true, 'changed' => $changed, 'status' => $req->status];
    }
}
