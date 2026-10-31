<?php

namespace App\Http\Controllers;

use App\Domain\Forms\FormLinkService;
use App\Domain\Forms\FormSubmissionService;
use App\Domain\Installments\BestOfferService;
use App\Jobs\CheckFormDocument;
use App\Models\FormLink;
use App\Models\FormLinkAnswer;
use App\Models\FormLinkFile;
use App\Models\Machine;
use App\Models\MessageMedia;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * The customer's form page (spec §4.4): /f/{token}. No login - the long random token is the key.
 * Every answer is saved as he types; uploads are checked in the background.
 */
class FormLinkController extends Controller
{
    private const MAX_IMAGE_BYTES = 10 * 1024 * 1024;

    private const MAX_VIDEO_BYTES = 50 * 1024 * 1024;

    public function __construct(private readonly FormLinkService $links)
    {
    }

    public function show(string $token)
    {
        $link = $this->find($token);

        if (! $link) {
            return response()->view('forms.closed', ['reason' => 'missing'], 404);
        }

        if ($link->status === 'submitted') {
            return view('forms.closed', ['reason' => 'submitted', 'link' => $link]);
        }

        if (! $link->isOpen()) {
            return view('forms.closed', ['reason' => 'expired', 'link' => $link]);
        }

        $schema = $link->version->formSchema();

        return view('forms.show', [
            'link' => $link,
            'form' => $link->version->form,
            'schema' => $schema,
            'state' => $this->state($link),
            'machines' => $this->machines(),
            'governorates' => array_values(config('agent.governorates', [])),
        ]);
    }

    /** The page's heartbeat: the first one marks the link opened (a preview fetch runs no JS). */
    public function ping(string $token): JsonResponse
    {
        $link = $this->openLink($token);
        $this->links->touch($link);

        return response()->json(['ok' => true, 'progress' => $this->links->progress($link)]);
    }

    public function answer(Request $request, string $token): JsonResponse
    {
        $link = $this->openLink($token);
        $schema = $link->version->formSchema();
        $field = $schema->field((string) $request->input('key'));

        abort_if(! $field || in_array($field['type'], \App\Domain\Forms\FormSchema::FILE_TYPES, true), 422);

        $value = $request->input('value');
        $value = is_string($value) ? mb_substr($value, 0, 2000) : $value;

        FormLinkAnswer::updateOrCreate(['form_link_id' => $link->id, 'field_key' => $field['key']], ['value' => $value]);
        $this->links->touch($link, true);

        if ($field['type'] === 'national_id') {
            app(\App\Domain\Forms\FormDocumentChecker::class)->recheckNationalId($link);
        }

        return response()->json([
            'ok' => true,
            'error' => $schema->validateValue($field, $value),
            'progress' => $this->links->progress($link->fresh()),
        ]);
    }

    public function upload(Request $request, string $token): JsonResponse
    {
        $link = $this->openLink($token);
        $field = $link->version->formSchema()->field((string) $request->input('key'));

        abort_if(! $field || ! in_array($field['type'], \App\Domain\Forms\FormSchema::FILE_TYPES, true), 422);

        $file = $request->file('file');
        abort_if(! $file || ! $file->isValid(), 422, 'الملف موصلش، جرّب تاني.');

        $mime = (string) $file->getMimeType();
        $accept = $field['type'] === 'images' ? 'images' : ($field['accept'] ?? 'images_pdf');
        $allowed = match ($accept) {
            'images' => str_starts_with($mime, 'image/'),
            'video' => str_starts_with($mime, 'image/') || str_starts_with($mime, 'video/'),
            default => str_starts_with($mime, 'image/') || $mime === 'application/pdf',
        };

        if (! $allowed) {
            return response()->json(['ok' => false, 'error' => 'نوع الملف ده مش مقبول هنا.'], 422);
        }

        if ($file->getSize() > (str_starts_with($mime, 'video/') ? self::MAX_VIDEO_BYTES : self::MAX_IMAGE_BYTES)) {
            return response()->json(['ok' => false, 'error' => 'الملف كبير أوي. صوّره تاني أو ارفع نسخة أصغر.'], 422);
        }

        $max = max(1, (int) ($field['max_files'] ?? ($field['type'] === 'images' ? 10 : 1)));
        $count = FormLinkFile::where('form_link_id', $link->id)->where('field_key', $field['key'])->count();

        // one file allowed: the new one replaces the old
        if ($max === 1 && $count >= 1) {
            FormLinkFile::where('form_link_id', $link->id)->where('field_key', $field['key'])->delete();
        } elseif ($count >= $max) {
            return response()->json(['ok' => false, 'error' => "أقصى عدد {$max} ملفات هنا. امسح واحد الأول."], 422);
        }

        $path = $file->storeAs('form_uploads/'.$link->id, Str::random(24).'.'.($file->guessExtension() ?: 'bin'), 'local');

        $media = MessageMedia::create([
            'message_id' => null,
            'media_type' => str_starts_with($mime, 'image/') ? 'image' : (str_starts_with($mime, 'video/') ? 'video' : 'document'),
            'mime' => $mime,
            'disk' => 'local',
            'path' => $path,
            'size' => $file->getSize(),
            'sha256' => hash_file('sha256', $file->getRealPath()),
            'original_filename' => mb_substr((string) $file->getClientOriginalName(), 0, 200),
            'analysis' => ['source' => 'form', 'form_link_id' => $link->id],
        ]);

        $row = FormLinkFile::create([
            'form_link_id' => $link->id,
            'field_key' => $field['key'],
            'media_id' => $media->id,
            'check_status' => 'checking',
        ]);

        $this->links->touch($link, true);
        CheckFormDocument::dispatchAfterResponse($row->id);

        return response()->json(['ok' => true, 'file' => $this->fileJson($row, $link), 'progress' => $this->links->progress($link->fresh())]);
    }

    public function deleteFile(string $token, int $file): JsonResponse
    {
        $link = $this->openLink($token);
        FormLinkFile::where('form_link_id', $link->id)->whereKey($file)->delete();
        $this->links->touch($link, true);

        return response()->json(['ok' => true, 'progress' => $this->links->progress($link->fresh())]);
    }

    /** Check results the page waits for. */
    public function files(string $token): JsonResponse
    {
        $link = $this->find($token);
        abort_if(! $link, 404);

        return response()->json([
            'files' => FormLinkFile::where('form_link_id', $link->id)->orderBy('id')->get()
                ->map(fn (FormLinkFile $f) => $this->fileJson($f, $link))->all(),
            // answers the card reading filled in (name, national id) reach the open page
            'answers' => (object) $link->answerValues(),
            'progress' => $this->links->progress($link),
        ]);
    }

    /** The upload itself, for the thumbnail - only through its own link. */
    public function fileContent(string $token, int $file)
    {
        $link = $this->find($token);
        $row = $link ? FormLinkFile::where('form_link_id', $link->id)->with('media')->find($file) : null;
        abort_if(! $row || ! $row->media || ! Storage::disk($row->media->disk)->exists($row->media->path), 404);

        return Storage::disk($row->media->disk)->response($row->media->path, null, [
            'Content-Type' => $row->media->mime,
            'Cache-Control' => 'private, max-age=600',
        ]);
    }

    /**
     * One offer per duration (owner: the customer never picks a finance company) - the cheapest
     * system his answers qualify for, as the bot quotes it (BestOfferService).
     */
    public function plans(Request $request, string $token): JsonResponse
    {
        $link = $this->openLink($token);
        $machine = Machine::where('is_active', true)->find((int) $request->query('machine'));

        if (! $machine) {
            return response()->json(['plans' => []]);
        }

        $customerTypeId = $link->version->formSchema()->customerTypeId($link->answerValues());

        // above what finance companies fund for his job, the difference is paid cash up front
        // (owner 2026-10-19: shown on the form) - the cap figure and his type stay internal
        $plans = array_map(fn (array $offer) => [
            'id' => (int) $offer['plan_id'],
            'months' => (int) $offer['months'],
            'monthly' => round($offer['monthly_payment']),
            'down_payment' => round($offer['down_payment']),
            'upfront' => round($offer['cash_due_upfront']),
            'capped' => $offer['cap'] !== null,
        ], app(BestOfferService::class)->offers($machine, $customerTypeId));

        return response()->json([
            'plans' => $plans,
            'price' => $machine->installment_price,
            'job_known' => $customerTypeId !== null,
            'capped' => collect($plans)->contains('capped', true),
        ]);
    }

    public function submit(Request $request, string $token): JsonResponse
    {
        $link = $this->find($token);
        abort_if(! $link, 404);

        try {
            $result = app(FormSubmissionService::class)->submit($link, (array)$request->input('cap_confirmation', []));
        } catch (\Illuminate\Validation\ValidationException $e) {
            if (!isset($e->errors()['applicant_national_id'])) throw $e;
            return response()->json(['ok'=>false,'code'=>'DUPLICATE','message'=>'أنت مقدم عندنا قبل كده بالفعل. راجع الطلب الموجود بدل إنشاء طلب جديد.'],422);
        }

        return response()->json($result, ($result['ok'] ?? false) ? 200 : 422);
    }

    private function find(string $token): ?FormLink
    {
        return strlen($token) >= 32 ? FormLink::with('version.form')->where('token', $token)->first() : null;
    }

    private function openLink(string $token): FormLink
    {
        $link = $this->find($token);
        abort_if(! $link, 404);
        abort_if(! $link->isOpen(), 410, 'الرابط ده انتهى.');

        return $link;
    }

    /** What the page starts with: answers, files and progress. */
    private function state(FormLink $link): array
    {
        return [
            'answers' => (object) $link->answerValues(),
            'files' => FormLinkFile::where('form_link_id', $link->id)->orderBy('id')->get()->map(fn ($f) => $this->fileJson($f, $link))->all(),
            'progress' => $this->links->progress($link),
        ];
    }

    private function fileJson(FormLinkFile $file, FormLink $link): array
    {
        $media = $file->media;

        return [
            'id' => $file->id,
            'key' => $file->field_key,
            'status' => $file->check_status,
            'message' => $file->message,
            'name' => $media?->original_filename,
            'is_image' => str_starts_with((string) $media?->mime, 'image/'),
            'url' => url('/f/'.$link->token.'/files/'.$file->id),
        ];
    }

    private function machines(): array
    {
        return Machine::where('is_active', true)
            ->with('brand')->orderBy('name')->get()
            ->map(fn (Machine $m) => [
                'id' => $m->id,
                'name' => trim(($m->brand?->name ? $m->brand->name.' ' : '').$m->name),
                'price' => $m->installment_price,
                'image' => $m->displayImageUrl(),
                'brand_id' => $m->brand_id,
                'brand' => $m->brand?->name ?? 'أخرى',
            ])->values()->all();
    }
}
