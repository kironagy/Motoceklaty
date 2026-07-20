<?php

namespace App\Http\Controllers;

use App\Models\Machine;
use App\Models\InstallmentRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Carbon;
use Google\Cloud\Vision\V1\ImageAnnotatorClient;
use Illuminate\Validation\Rule;

class InstallmentRequestController extends Controller
{
    public function show(Machine $machine)
    {
        $plans = DB::table('installment_plans')
            ->select('id', 'name', 'months_json', 'meta')
            ->get()
            ->mapWithKeys(fn($p) => [$p->name => json_decode($p->months_json, true) ?: []])
            ->toArray();
  
    $allMachines = Machine::all();

        return view('machines.show', compact('machine', 'plans' , 'allMachines '));
    }

public function store(Request $request)
{
    // ✅ (اختياري لكن مفيد) Normalize لقيمة work_status
    if ($request->has('work_status')) {
        $w = trim(mb_strtolower($request->input('work_status')));
        $w = str_replace('-', '_', $w);
        $request->merge(['work_status' => $w]);
    }

    // ✅ 0) منع التكرار
    $exists = InstallmentRequest::where('machine_id', $request->machine_id)
        ->where('applicant_phone', $request->applicant_phone)
        ->where('installment_type', $request->installment_type)
        ->where('status', 'pending')
        ->exists();

    if ($exists) {
        return back()
            ->withErrors(['لا يمكن تقديم نفس الطلب مرتين قبل مراجعة الطلب السابق.'])
            ->withInput();
    }

    // ✅ 1) التحقق الأساسي
    $data = $request->validate([
        'machine_id'        => ['required', 'exists:machines,id'],
        'installment_type'  => ['required', 'string', 'max:255'],
        'months'            => ['required', 'integer', 'min:1'],

        // العميل
        'applicant_name'        => ['required', 'string', 'max:255'],
        'applicant_phone'       => ['required', 'string', 'max:30'],
        'applicant_address'     => ['required', 'string', 'max:500'],
        'applicant_id_front'    => ['required', 'file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:10240'],
        'applicant_id_back'     => ['required', 'file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:10240'],

        // عنوان العمل (مش مطلوب لكل الحالات)
        'work_address'          => ['nullable', 'string', 'max:500'],

        // دخل حر
        'free_work_name'        => ['nullable', 'string', 'max:255'],
        'free_work_address'     => ['nullable', 'string', 'max:500'],

        // الضامن (اختياري)
        'guarantor_name'        => ['nullable', 'string', 'max:255'],
        'guarantor_phone'       => ['nullable', 'string', 'max:30'],
        'guarantor_id_front'    => ['nullable', 'file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:10240'],
        'guarantor_id_back'     => ['nullable', 'file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:10240'],

        // الحالة
        'work_status' => ['required', Rule::in(['employee', 'pension', 'self_employed', 'no_income_proof'])],

        // ملفات حسب الحالة (nullable هنا — وهنجبرها required في switch)
        'salary_slip_file'       => ['nullable', 'file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:15360'],
        'pension_statement_file' => ['nullable', 'file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:15360'],
        'commercial_reg_file'    => ['nullable', 'file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:15360'],
        'tax_card_file'          => ['nullable', 'file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:15360'],
        'place_video'            => ['nullable', 'file', 'mimetypes:video/mp4,video/quicktime', 'max:51200'],
    ]);

    // ✅ 1.1) اشتراط إضافي حسب الحالة (مهم)
    switch ($data['work_status']) {
        case 'employee':
            $request->validate([
                'salary_slip_file' => ['required', 'file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:15360'],
            ]);
            break;

        case 'pension':
            $request->validate([
                'pension_statement_file' => ['required', 'file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:15360'],
            ]);
            break;

        case 'self_employed':
            $request->validate([
                'commercial_reg_file' => ['required', 'file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:15360'],
                'tax_card_file'       => ['required', 'file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:15360'],
                'place_video'         => ['required', 'file', 'mimetypes:video/mp4,video/quicktime', 'max:51200'],
            ]);
            break;

        case 'no_income_proof':
            $request->validate([
                'free_work_name'    => ['required', 'string', 'max:255'],
                'free_work_address' => ['nullable', 'string', 'max:500'],
            ]);
            break;
    }

    // ✅ 2) حفظ الملفات
    $paths = [];
    $fileFields = [
        'applicant_id_front',
        'applicant_id_back',
        'guarantor_id_front',
        'guarantor_id_back',
        'salary_slip_file',
        'pension_statement_file',
        'commercial_reg_file',
        'tax_card_file',
        'place_video',
    ];

    foreach ($fileFields as $field) {
        if ($request->hasFile($field)) {
            $paths[$field] = $request->file($field)->store('installments', 'public');
        }
    }

    // ✅ 3) OCR Vision
    $vision = $this->makeVisionClient();

    $ocr = [
        'app_front' => $this->extractText($vision, $request->file('applicant_id_front')->getRealPath()),
        'app_back'  => $this->extractText($vision, $request->file('applicant_id_back')->getRealPath()),
        'gua_front' => $request->hasFile('guarantor_id_front')
            ? $this->extractText($vision, $request->file('guarantor_id_front')->getRealPath())
            : '',
        'gua_back'  => $request->hasFile('guarantor_id_back')
            ? $this->extractText($vision, $request->file('guarantor_id_back')->getRealPath())
            : '',
    ];

    $checks = [];
    $hardFails = false;

    // ✅ مطابقة وش وضهر البطاقة (عميل)
    $appFrontId = $this->extractNationalId($ocr['app_front']);
    $appBackId  = $this->extractNationalId($ocr['app_back']);
    if ($appFrontId && $appBackId && $appFrontId !== $appBackId) {
        $hardFails = true;
        $checks[] = ['type' => 'error', 'msg' => 'الرقم القومي في وجه البطاقة لا يطابق الرقم في الظهر (العميل).'];
    }

    // ✅ مطابقة وش وضهر البطاقة (ضامن)
    $guaFrontId = $this->extractNationalId($ocr['gua_front']);
    $guaBackId  = $this->extractNationalId($ocr['gua_back']);
    if ($guaFrontId && $guaBackId && $guaFrontId !== $guaBackId) {
        $hardFails = true;
        $checks[] = ['type' => 'error', 'msg' => 'الرقم القومي في وجه البطاقة لا يطابق الرقم في الظهر (الضامن).'];
    }

    // ✅ 4) استخراج وتحليل البطاقة
    $appIDText = $ocr['app_front'] . "\n" . $ocr['app_back'];
    $guaIDText = $ocr['gua_front'] . "\n" . $ocr['gua_back'];

    $applicantNationalId = $this->extractNationalId($appIDText);
    [$appBirthdate, $appAge] = $this->birthFromNationalIdOrText($applicantNationalId, $appIDText);
    $applicantNameFromId = $this->guessArabicNameFromId($appIDText);

    $guarantorNationalId = $this->extractNationalId($guaIDText);
    [$guaBirthdate, $guaAge] = $this->birthFromNationalIdOrText($guarantorNationalId, $guaIDText);
    $guarantorNameFromId = $this->guessArabicNameFromId($guaIDText);

    // ✅ 5) تحقق من السن
    if ($appAge !== null && ($appAge < 21 || $appAge > 62)) {
        $hardFails = true;
        $checks[] = ['type' => 'error', 'msg' => "عمر المتقدم = {$appAge} — الشرط من 21 إلى 62 سنة."];
    }

    if ($guarantorNationalId || $request->hasFile('guarantor_id_front') || $request->hasFile('guarantor_id_back')) {
        if ($guaAge === null) {
            $checks[] = ['type' => 'warn', 'msg' => 'لم يمكن تحديد عمر الضامن من البطاقة.'];
        } elseif ($guaAge < 21 || $guaAge > 62) {
            $hardFails = true;
            $checks[] = ['type' => 'error', 'msg' => "عمر الضامن = {$guaAge} — الشرط من 21 إلى 62 سنة."];
        }
    }

    // ✅ 6) تحقق مفردات المرتب فقط لو موظف
    if ($data['work_status'] === 'employee' && $request->hasFile('salary_slip_file')) {
        $salText = $this->extractText($vision, $request->file('salary_slip_file')->getRealPath());
        $salaryName = $this->guessArabicNameFromId($salText);
        $salaryNid  = $this->extractNationalId($salText);

        $checks[] = ['type' => 'info', 'msg' => "الاسم المستخرج من مفردات المرتب: <b>{$salaryName}</b>"];
        $checks[] = ['type' => 'info', 'msg' => "الرقم القومي المستخرج من مفردات المرتب: <b>{$salaryNid}</b>"];

        if ($salaryNid && $applicantNationalId && $salaryNid !== $applicantNationalId) {
            $hardFails = true;
            $checks[] = ['type' => 'error', 'msg' => 'الرقم القومي في مفردات المرتب لا يطابق البطاقة.'];
        }

        if ($salaryName && $applicantNameFromId) {
            $similarity = levenshtein($this->normalizeArabic($salaryName), $this->normalizeArabic($applicantNameFromId));
            $checks[] = ['type' => 'info', 'msg' => "الفارق بين الاسم في البطاقة والمرتب (Levenshtein distance): {$similarity}"];
            if ($similarity > 5) {
                $hardFails = true;
                $checks[] = ['type' => 'error', 'msg' => 'اسم مفردات المرتب لا يطابق اسم البطاقة.'];
            }
        }
    }

    // ❌ لو فيه أخطاء ممنوعة → رجّع الفورم مع التقرير فقط
    if ($hardFails) {
        return back()
            ->withErrors(collect($checks)->where('type', 'error')->pluck('msg')->all())
            ->withInput()
            ->with('ocr_report', view('partials.ocr_report', [
                'ocr' => $ocr,
                'checks' => $checks,
                'extra' => compact(
                    'applicantNameFromId',
                    'guarantorNameFromId',
                    'applicantNationalId',
                    'guarantorNationalId',
                    'appBirthdate',
                    'guaBirthdate',
                    'appAge',
                    'guaAge'
                )
            ])->render());
    }

    // ✅ 7) تحديد الموظف (إحالة أو مستخدم داخل النظام)
    $staffId = session('referred_staff_id')
        ?? $request->input('staff_id')
        ?? auth('staff')->id()
        ?? auth('filament')->id()
        ?? null;

    // ✅ 8) خزّن الطلب فعلاً
    $requestModel = InstallmentRequest::create([
        'machine_id'              => $data['machine_id'],
        'installment_type'        => $data['installment_type'],
        'months'                  => $data['months'],

        'applicant_name'          => $data['applicant_name'],
        'applicant_phone'         => $data['applicant_phone'],
        'applicant_address'       => $data['applicant_address'],
        'applicant_national_id'   => $applicantNationalId,
        'applicant_id_image'      => $paths['applicant_id_front'] ?? null,
        'applicant_id_back_image' => $paths['applicant_id_back'] ?? null,
        'applicant_birthdate'     => $appBirthdate,
        'applicant_age_ok'        => ($appAge !== null && $appAge >= 21 && $appAge <= 62),

        // دخل حر
        'free_work_name'          => $data['free_work_name'] ?? null,
        'free_work_address'       => $data['free_work_address'] ?? null,

        // الضامن
        'guarantor_name'          => $data['guarantor_name'] ?? null,
        'guarantor_phone'         => $data['guarantor_phone'] ?? null,
        'guarantor_national_id'   => $guarantorNationalId,
        'guarantor_id_image'      => $paths['guarantor_id_front'] ?? null,
        'guarantor_id_back_image' => $paths['guarantor_id_back'] ?? null,
        'guarantor_birthdate'     => $guaBirthdate,
        'guarantor_age_ok'        => ($guaAge !== null && $guaAge >= 21 && $guaAge <= 62),

        // الحالة الوظيفية
        'work_status'             => $data['work_status'],
        'work_address'            => $data['work_address'] ?? null,

        // ملفات حسب الحالة
        'salary_slip_file'        => $paths['salary_slip_file'] ?? null,
        'pension_statement_file'  => $paths['pension_statement_file'] ?? null, // ✅ مهم
        'commercial_reg_file'     => $paths['commercial_reg_file'] ?? null,
        'tax_card_file'           => $paths['tax_card_file'] ?? null,
        'place_video'             => $paths['place_video'] ?? null,

        // نظام
        'staff_id'                => $staffId,
        'status'                  => 'new',
        'checks_report'           => json_encode($checks, JSON_UNESCAPED_UNICODE),
    ]);

    // ✅ نحذف الإحالة بعد التقديم عشان ما تتكرر
    session()->forget('referred_staff_id');

    // ✅ 9) رسالة نجاح + عرض التقرير
    return back()
        ->with('success', 'تم إرسال طلبك بنجاح وجاري المراجعة.')
        ->with('ocr_report', view('partials.ocr_report', [
            'ocr' => $ocr,
            'checks' => $checks,
            'extra' => compact(
                'applicantNameFromId',
                'guarantorNameFromId',
                'applicantNationalId',
                'guarantorNationalId',
                'appBirthdate',
                'guaBirthdate',
                'appAge',
                'guaAge'
            )
        ])->render());
}



    /* ===================== Helpers ===================== */

    private function makeVisionClient(): ImageAnnotatorClient
    {
        // تأكد ان GOOGLE_APPLICATION_CREDENTIALS مضبوطة في .env
        return new ImageAnnotatorClient([
            'credentials' => base_path(env('GOOGLE_CLOUD_KEY_PATH'))
        ]);
    }

    private function extractText(ImageAnnotatorClient $vision, string $realPath): string
    {
        // يدعم صور + PDF (لو PDF هيقرأ الصفحة الأولى فقط بشكل افتراضي من Vision)
        $image = file_get_contents($realPath);
        $response = $vision->documentTextDetection($image);
        $annotation = $response->getFullTextAnnotation();
        $text = $annotation ? $annotation->getText() : '';

        $error = $response->getError();
        if ($error && $error->getMessage()) {
            $text .= "\n[OCR_ERROR] " . $error->getMessage();
        }

        return $this->normalizeArabic($text);
    }

    private function normalizeArabic(string $t): string
    {
        // توحيد أرقام عربية/هندية + إزالة تشكيل مبدئي + مسافات مكررة
        $t = strtr($t, [
            '٠' => '0',
            '١' => '1',
            '٢' => '2',
            '٣' => '3',
            '٤' => '4',
            '٥' => '5',
            '٦' => '6',
            '٧' => '7',
            '٨' => '8',
            '٩' => '9',
            '۰' => '0',
            '۱' => '1',
            '۲' => '2',
            '۳' => '3',
            '۴' => '4',
            '۵' => '5',
            '۶' => '6',
            '۷' => '7',
            '۸' => '8',
            '۹' => '9',
            'أ' => 'ا',
            'إ' => 'ا',
            'آ' => 'ا',
            'ى' => 'ي',
            'ة' => 'ه',
        ]);
        $t = preg_replace('/[\x{0617}-\x{061A}\x{064B}-\x{0652}]/u', '', $t);
        $t = preg_replace('/[^\S\r\n]+/', ' ', $t);
        return trim($t);
    }

    private function extractNationalId(string $text): ?string
    {
        // رقم قومي مصري: 14 رقم متتالية
        if (preg_match('/\b(\d{14})\b/u', $text, $m)) {
            return $m[1];
        }
        return null;
    }

    private function birthFromNationalIdOrText(?string $nid, string $text): array
    {
        if ($nid && preg_match('/^(\d)(\d{2})(\d{2})(\d{2})/', $nid, $m)) {
            $cent = $m[1] === '3' ? 2000 : 1900;
            $yy = (int)$m[2];
            $mm = (int)$m[3];
            $dd = (int)$m[4];
            if (checkdate($mm, $dd, $cent + $yy)) {
                $bd = Carbon::create($cent + $yy, $mm, $dd);
                return [$bd->toDateString(), $bd->age];
            }
        }

        // fallback: التقاط تواريخ عامة في النص
        if ($d = $this->extractAnyDate($text)) {
            return [$d->toDateString(), $d->age];
        }
        return [null, null];
    }

    private function extractAnyDate(string $text): ?Carbon
    {
        // أنماط تواريخ شائعة عربي/رقمي
        // 13/04/2007 أو 2007-04-13 أو 13-4-2007
        if (preg_match('/\b(\d{1,2})[\/\-\s](\d{1,2})[\/\-\s](\d{4})\b/u', $text, $m)) {
            $d = (int)$m[1];
            $mth = (int)$m[2];
            $y = (int)$m[3];
            if (checkdate($mth, $d, $y)) return Carbon::create($y, $mth, $d);
        }
        if (preg_match('/\b(\d{4})[\/\-\s](\d{1,2})[\/\-\s](\d{1,2})\b/u', $text, $m)) {
            $y = (int)$m[1];
            $mth = (int)$m[2];
            $d = (int)$m[3];
            if (checkdate($mth, $d, $y)) return Carbon::create($y, $mth, $d);
        }

        // أشهر عربية: "تحررت في تاريخ ١٥ مايو ٢٠٢٥"
        $months = [
            'يناير' => 1,
            'فبراير' => 2,
            'مارس' => 3,
            'ابريل' => 4,
            'أبريل' => 4,
            'ابربل' => 4,
            'مايو' => 5,
            'يونيو' => 6,
            'يوليو' => 7,
            'اغسطس' => 8,
            'أغسطس' => 8,
            'سبتمبر' => 9,
            'اكتوبر' => 10,
            'أكتوبر' => 10,
            'نوفمبر' => 11,
            'ديسمبر' => 12
        ];
        if (preg_match('/\b(\d{1,2})\s+([^\s]+)\s+(\d{4})\b/u', $text, $m)) {
            $d = (int)$m[1];
            $monName = $m[2];
            $y = (int)$m[3];
            $monName = $this->normalizeArabic($monName);
            if (isset($months[$monName]) && checkdate($months[$monName], $d, $y)) {
                return Carbon::create($y, $months[$monName], $d);
            }
        }
        return null;
    }

    private function withinMonths(Carbon $date, int $months): bool
    {
        return $date->greaterThanOrEqualTo(Carbon::now()->subMonths($months)->startOfDay());
    }

    private function guessArabicNameFromId(string $text): string
    {
        // 0️⃣ تجهيز النص وتنظيفه
        $clean = $this->normalizeArabic($text);
        $clean = preg_replace('/[^ء-ي\s]/u', ' ', $clean);
        $clean = preg_replace('/\s+/u', ' ', trim($clean));

        // 🧠 تحديد نوع المستند (بطاقة أو مفردات مرتب)
        $isSalaryDoc = preg_match('/(شركة|مؤسسة|مرتب|الراتب|العمل|توظيف|الموارد|شركه|جهة|معاش|هيئة|تأمينات)/u', $clean);

        if ($isSalaryDoc) {
            // ✅ استخراج الاسم من مفردات المرتب بعد "الأستاذ / السيد / الموظف"
            if (preg_match('/(?:الاستاذ|الأستاذ|الاستاذه|الأستاذة|السيد|السيدة|الانسه|الآنسة|الموظف|المدعو|الاستاذ\/|السيد\/)\s+([ء-ي\s]{5,120})/u', $clean, $m)) {
                $possibleName = trim($m[1]);

                // نوقف عند أول جملة جديدة أو رقم قومي أو كلمة شركة أو ختم
                $possibleName = preg_split('/\s+(?:بطاقه|بطاقة|رقم|قومي|شركة|مؤسسة|إدارة|ادارة|مرتب|راتب|مبلغ|تاريخ|تحرير|تحرر|تحريرا|بأن)\s+/u', $possibleName)[0] ?? $possibleName;

                // تقسيم الاسم لكلمات وتجميعها كلها لحد 6 مقاطع
                $parts = array_slice(preg_split('/\s+/u', trim($possibleName)), 0, 6);
                $parts = array_filter($parts, fn($w) => !preg_match('/^(شركة|مؤسسة|مدرسة|ادارة|إدارة|شركه)$/u', $w));

                $name = trim(implode(' ', $parts));
                if (mb_strlen($name) >= 5) {
                    return $name;
                }
            }
        }

        // --------------------------------------------------------
        // 👇 المنطق الأصلي للبطاقة كما هو
        // --------------------------------------------------------

        $t = $this->normalizeArabic($text);
        $t = preg_replace('/[^ء-ي\s]/u', ' ', $t);
        $t = preg_replace('/\s+/u', ' ', trim($t));

        $stopWords = [
            'جمهوريه',
            'جمهورية',
            'مصر',
            'العربيه',
            'العربية',
            'بطاقه',
            'بطاقة',
            'تحقيق',
            'الشخصيه',
            'الشخصية',
            'الرقم',
            'القومي',
            'وزارة',
            'الوزاره',
            'محافظه',
            'المحافظة',
            'القاهره',
            'القاهرة',
            'الجيزه',
            'الجيزة',
            'الاسكندريه',
            'الإسكندرية',
            'حلوان',
            'المطرية',
            'المطريه',
            'عين',
            'شمس',
            'المنصوره',
            'المنصورة',
            'طنطا',
            'دمنهور',
            'زايد',
            'الشيخ',
            'مدينة',
            'منطقة',
            'قسم',
            'الحي',
            'ميدان',
            'شارع',
            'ش',
            'طريق',
            'عماره',
            'عمارة',
            'برج',
            'بناية',
            'عزبه',
            'رقم'
        ];

        $allowedLinkers = ['بن', 'بنت', 'عبد', 'عبدال', 'عبدالله', 'ابن'];

        $tokens = array_values(array_filter(preg_split('/\s+/u', $t)));
        $maxParts   = 6;
        $minParts   = 2;
        $collecting = false;
        $collected  = [];

        $isStop = fn(string $w) => in_array($w, $stopWords, true);
        $isNameLike = function (string $w) use ($allowedLinkers): bool {
            if (in_array($w, $allowedLinkers, true)) return true;
            if (in_array($w, ['طالب', 'ذكر', 'انثى', 'مسلم', 'اعزب', 'ارمل', 'متزوج', 'مطلق', 'مطلقه', 'مطلقة'], true)) return false;
            if (preg_match('/^(شركة|مؤسسة|مدرسة|ادارة|إدارة)$/u', $w)) return false;
            return mb_strlen($w) >= 2;
        };

        foreach ($tokens as $w) {
            if (!$collecting) {
                if ($isStop($w)) continue;
                if ($isNameLike($w)) {
                    $collecting = true;
                    $collected[] = $w;
                }
                continue;
            }

            if ($isStop($w)) {
                if (count($collected) >= $minParts) break;
                else continue;
            }

            if ($isNameLike($w)) {
                $collected[] = $w;
                if (count($collected) >= $maxParts) break;
            } else {
                if (count($collected) >= $minParts) break;
            }
        }

        if (!empty($collected)) {
            $last = end($collected);
            if (in_array($last, $allowedLinkers, true)) array_pop($collected);
        }

        if (count($collected) < $minParts) return '';

        return trim(implode(' ', $collected));
    }


    private function namesSimilar(string $a, string $b): float
    {
        $na = $this->normalizeArabic(mb_strtolower($a));
        $nb = $this->normalizeArabic(mb_strtolower($b));
        if ($na === '' || $nb === '') return 0.0;
        similar_text($na, $nb, $perc);
        return $perc / 100.0;
    }

    private function extractMoneySmart(string $text): ?int
    {
        $t = $this->normalizeArabic($text);

        // 1) أرقام صريحة (مع فواصل/رموز)
        if (preg_match_all('/\b(\d{3,})(?:\s*جنيه|\s*EGP|\s*ج|$)/u', $t, $all)) {
            // خُد أكبر رقم منطقي
            $nums = array_map(fn($n) => (int)preg_replace('/[^\d]/', '', $n), $all[1]);
            $max = !empty($nums) ? max($nums) : null;
            if ($max) return $max;
        }

        // 2) كلمات عربية: "خمسة الاف وخمسمائة" / "ثلاثة الاف ومئة اثنان وثمانون"
        $words = $this->wordsToNumberAr($t);
        return $words ?: null;
    }

    private function wordsToNumberAr(string $t): ?int
    {
        // نحاول نلقط مقاطع مال بـ (ألف/الاف/تلاف/مائة/مية/مئه/مليون ... إلخ)
        // سنبني Parser مبسط يكفي السيناريوهات الشائعة
        $mapUnits = [
            'صفر' => 0,
            'واحد' => 1,
            'اثنان' => 2,
            'اثنين' => 2,
            'اتنين' => 2,
            'ثلاثه' => 3,
            'ثلاثة' => 3,
            'اربعه' => 4,
            'أربعة' => 4,
            'خمسه' => 5,
            'خمسة' => 5,
            'سته' => 6,
            'ستة' => 6,
            'سبعه' => 7,
            'سبعة' => 7,
            'تمانيه' => 8,
            'ثمانيه' => 8,
            'ثمانية' => 8,
            'ثمانيه' => 8,
            'تسعه' => 9,
            'تسعة' => 9,
        ];
        $mapTens = [
            'عشره' => 10,
            'عشرة' => 10,
            'احدي عشر' => 11,
            'إحدى عشر' => 11,
            'احدى عشر' => 11,
            'اثنى عشر' => 12,
            'اثنا عشر' => 12,
            'عشرون' => 20,
            'عشرين' => 20,
            'ثلاثون' => 30,
            'ثلاثين' => 30,
            'اربعون' => 40,
            'اربعين' => 40,
            'خمسون' => 50,
            'خمسين' => 50,
            'ستون' => 60,
            'ستين' => 60,
            'سبعون' => 70,
            'سبعين' => 70,
            'ثمانون' => 80,
            'تمانين' => 80,
            'ثمانين' => 80,
            'تسعون' => 90,
            'تسعين' => 90,
        ];
        $mapHund = ['مائه' => 100, 'مئة' => 100, 'ميه' => 100, 'مئه' => 100, 'مائه' => 100, 'مائة' => 100];
        $scales = ['الف' => 1000, 'الاف' => 1000, 'تلاف' => 1000, 'الفا' => 1000, 'الفين' => 2000, 'مليون' => 1000000, 'ملايين' => 1000000];

        $clean = mb_strtolower($t);
        $clean = preg_replace('/[^ء-ي0-9\s]/u', ' ', $clean);
        $clean = preg_replace('/\s+/u', ' ', $clean);

        // ابحث عن جملة فيها ألف/الاف/راتب/معاش
        if (!preg_match('/((?:راتب|مرتب|اجمالي|صافى|صافي|معاش)[^\.:\n]*?)?((?:[ء-ي]+\s+){1,12})(الف|الاف|تلاف|مليون|ملايين)(?:\s+[ء-ي]+){0,6}/u', $clean, $m)) {
            // fallback: مقطع كلمات عام
            if (!preg_match('/((?:[ء-ي]+\s+){1,20})/u', $clean, $m)) return null;
        }
        $phrase = trim($m[0]);

        $tokens = preg_split('/\s+/u', $phrase);
        $total = 0;
        $current = 0;

        $numLike = function ($w) use ($mapUnits, $mapTens, $mapHund) {
            if (isset($mapUnits[$w])) return $mapUnits[$w];
            if (isset($mapTens[$w])) return $mapTens[$w];
            if (isset($mapHund[$w])) return $mapHund[$w];
            return null;
        };

        foreach ($tokens as $w) {
            if (isset($scales[$w])) {
                $scale = $scales[$w];
                if ($current === 0) $current = 1;
                $total += $current * $scale;
                $current = 0;
                continue;
            }
            $n = $numLike($w);
            if ($n !== null) {
                if ($n === 100) {
                    if ($current === 0) $current = 100;
                    else $current *= 100;
                } else {
                    $current += $n;
                }
            }
        }
        $total += $current;
        return $total > 0 ? $total : null;
    }

    private function extractIssueDate(string $text): ?Carbon
    {
        $t = $this->normalizeArabic($text);

        // إشارة "تحررت في تاريخ ..."
        if (preg_match('/تحررت\s+في\s+تاريخ\s+(.+)/u', $t, $m)) {
            if ($d = $this->extractAnyDate($m[1])) return $d;
        }
        // التقط أي تاريخ
        return $this->extractAnyDate($t);
    }

    private function combineTwoImagesIfPossible(?string $p1, ?string $p2): ?string
    {
        if (!$p1 || !$p2) return null;
        $full1 = Storage::disk('public')->path($p1);
        $full2 = Storage::disk('public')->path($p2);

        $img1 = @imagecreatefromstring(@file_get_contents($full1));
        $img2 = @imagecreatefromstring(@file_get_contents($full2));
        if (!$img1 || !$img2) return null;

        $w = imagesx($img1) + imagesx($img2);
        $h = max(imagesy($img1), imagesy($img2));
        $canvas = imagecreatetruecolor($w, $h);
        imagecopy($canvas, $img1, 0, 0, 0, 0, imagesx($img1), imagesy($img1));
        imagecopy($canvas, $img2, imagesx($img1), 0, 0, 0, imagesx($img2), imagesy($img2));

        $outRel = 'installments/combined_' . uniqid() . '.jpg';
        $outAbs = Storage::disk('public')->path($outRel);
        imagejpeg($canvas, $outAbs, 85);
        imagedestroy($img1);
        imagedestroy($img2);
        imagedestroy($canvas);
        return $outRel;
    }

    private function renderReportHtml(InstallmentRequest $r, array $extra): string
    {
        $rows = [];
        $rows[] = $this->tr('حالة الطلب', $this->badge($r->status));
        $rows[] = $this->tr('الرقم القومي (عميل)', e($r->applicant_national_id ?? 'غير متوفر'));
        $rows[] = $this->tr('تاريخ ميلاد (عميل)', $r->applicant_birthdate ?: 'غير متوفر');
        $rows[] = $this->tr('الرقم القومي (ضامن)', e($r->guarantor_national_id ?? 'غير متوفر'));
        $rows[] = $this->tr('تاريخ ميلاد (ضامن)', $r->guarantor_birthdate ?: 'غير متوفر');

        if ($r->work_status === 'employee') {
            $rows[] = $this->tr('الراتب المستخرج', $r->salary_amount ? number_format($r->salary_amount) . ' جنيه' : 'غير متوفر');
            $rows[] = $this->tr('تاريخ إصدار المفردات', $r->salary_issue_date ?: 'غير متوفر');
        }
        if ($r->work_status === 'pension') {
            $rows[] = $this->tr('المعاش المستخرج', $r->pension_amount ? number_format($r->pension_amount) . ' جنيه' : 'غير متوفر');
        }

        // نتائج تحقق تفصيلية
        $checks = $r->checks_report ?: [];
        $checksHtml = '';
        foreach ($checks as $c) {
            $cls = match ($c['type']) {
                'ok' => 'text-success',
                'warn' => 'text-warning',
                'error' => 'text-danger',
                default => 'text-muted',
            };
            $checksHtml .= "<li class=\"{$cls}\">" . e($c['msg']) . "</li>";
        }

        $nameExtra = '';

        if (!empty($extra['applicantNameFromId'])) {
            $nameExtra .= "<li>اسم العميل من البطاقة (تخمين): <b>" . e($extra['applicantNameFromId']) . "</b></li>";
        }

        // ✅ عرض اسم الضامن فقط لو المستخدم أدخل اسمه فعلاً
        if (!empty($r->guarantor_name) && !empty($extra['guarantorNameFromId'])) {
            $nameExtra .= "<li>اسم الضامن من البطاقة (تخمين): <b>" . e($extra['guarantorNameFromId']) . "</b></li>";
        }


        return <<<HTML
<table class="table table-sm table-bordered">
    <tbody>
        {$rows[0]}{$rows[1]}{$rows[2]}{$rows[3]}{$rows[4]}
        <!-- المزيد حسب الحالة -->
    </tbody>
</table>

<ul class="mb-2">
    {$nameExtra}
</ul>

<h6 class="fw-bold">نتيجة الفحوصات:</h6>
<ul class="mb-0 ps-3">
    {$checksHtml}
</ul>
HTML;
    }

    private function tr(string $k, string $v): string
    {
        return "<tr><th class=\"w-25\">" . e($k) . "</th><td>" . ($v) . "</td></tr>";
    }

    private function badge(string $status): string
    {
        $map = ['pending' => 'secondary', 'needs_more_info' => 'warning', 'approved' => 'success', 'rejected' => 'danger'];
        $cls = $map[$status] ?? 'secondary';
        return "<span class=\"badge bg-{$cls}\">{$status}</span>";
    }
}

