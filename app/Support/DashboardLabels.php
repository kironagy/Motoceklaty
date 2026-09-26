<?php

namespace App\Support;

/**
 * Arabic wording for the codes and statuses the agent stores in English
 * ("collecting", "customer_stated", "UNVERIFIED_NUMBER"). The dashboard
 * showed them raw; anything not listed here is still shown readably.
 */
class DashboardLabels
{
    public const APPLICATION_STATUS = [
        'collecting' => 'بيجمع البيانات',
        'submitted' => 'اتقدم',
        'under_review' => 'تحت المراجعة',
        'needs_more_info' => 'محتاج بيانات زيادة',
        'approved' => 'اتوافق عليه',
        'rejected' => 'اترفض',
        'withdrawn' => 'اتلغى',
        'expired' => 'انتهى',
    ];

    public const APPLICATION_STATUS_COLOR = [
        'collecting' => 'warning',
        'submitted' => 'info',
        'under_review' => 'info',
        'needs_more_info' => 'warning',
        'approved' => 'success',
        'rejected' => 'danger',
        'withdrawn' => 'gray',
        'expired' => 'gray',
    ];

    public const DOCUMENT_STATUS = [
        'accepted' => 'مقبول',
        'rejected' => 'مرفوض',
        'failed' => 'ما اتقراش',
        'superseded' => 'اتبدل بنسخة أحدث',
        'pending' => 'تحت المعالجة',
        'processing' => 'تحت المعالجة',
    ];

    public const DOCUMENT_STATUS_COLOR = [
        'accepted' => 'success',
        'rejected' => 'danger',
        'failed' => 'danger',
        'superseded' => 'gray',
        'pending' => 'warning',
        'processing' => 'warning',
    ];

    public const DOCUMENT_ISSUE = [
        'NAME_MISMATCH' => 'الاسم مش مطابق',
        'ID_MISMATCH' => 'الرقم القومي مش مطابق',
        'BUSINESS_NAME_MISMATCH' => 'اسم النشاط مش مطابق للبطاقة الضريبية',
        'MISSING_DATA' => 'بيانات ناقصة في المستند',
        'BLURRY_DOCUMENT' => 'الصورة مش واضحة',
        'UNREADABLE' => 'مش مقروء',
        'WRONG_DOCUMENT' => 'مستند غلط',
        'DOCUMENT_NOT_SUPPORTED' => 'نوع مستند مش مطلوب',
        'UNSUPPORTED_FILE' => 'نوع ملف مش مدعوم',
        'INVALID_FORMAT' => 'شكل البيانات غلط',
        'EXPIRED_DOCUMENT' => 'المستند قديم / منتهي',
        'LICENSE_EXPIRED' => 'الرخصة منتهية',
        'EMPLOYMENT_TOO_RECENT' => 'مدة الخدمة أقل من المطلوب',
        'PERIOD_IN_FUTURE' => 'الفترة في المستقبل',
        'PERIOD_UNREADABLE' => 'الفترة مش مقروءة',
        'PERIOD_TOO_SHORT' => 'الفترة أقل من المطلوب',
        'OCR_UNAVAILABLE' => 'قراءة الصور كانت واقفة',
    ];

    public const DATA_SOURCE = [
        'customer_stated' => 'العميل قالها',
        'document' => 'من مستند',
        'staff' => 'موظف',
    ];

    public const DATA_STATUS = [
        'valid' => 'سليمة',
        'invalid' => 'فيها مشكلة',
        'conflict' => 'متعارضة',
    ];

    public const PARTY = [
        'applicant' => 'العميل',
        'guarantor' => 'الضامن',
    ];

    public const EVENT_TYPE = [
        'started' => 'اتفتح الطلب',
        'selection_updated' => 'اتغيّر الاختيار (موتوسيكل / مدة / مقدم)',
        'submitted' => 'اتقدم الطلب',
        'withdrawn' => 'اتلغى الطلب',
        'status_changed' => 'اتغيرت الحالة',
    ];

    public const ACTOR = [
        'ai' => 'البوت',
        'staff' => 'موظف',
        'system' => 'السيستم',
        'customer' => 'العميل',
    ];

    public const TRACE_STATUS = [
        'done' => 'تم',
        'running' => 'شغال',
        'error' => 'خطأ',
        'fallback' => 'رد احتياطي',
        'superseded' => 'اتلغى برسالة أحدث',
    ];

    public const TRACE_STATUS_COLOR = [
        'done' => 'success',
        'running' => 'warning',
        'error' => 'danger',
        'fallback' => 'danger',
        'superseded' => 'gray',
    ];

    /** Why a reply was held back by ReplyGuard, in the owner's words. */
    public const GUARD_CODE = [
        'EMPTY_REPLY' => 'رد فاضي',
        'GARBLED_TEXT' => 'كلام متلخبط (حروف إنجليزي جوه كلمة عربي)',
        'HANDOFF_CLAIMED_NOT_DONE' => 'قال هيحوّل لزميل من غير ما يحوّل فعلًا',
        'DATA_CLAIMED_NOT_SAVED' => 'قال إنه سجّل بيانات وهو ما سجّلش',
        'DATA_OVERCLAIMED' => 'قال إنه سجّل كل البيانات وهو سجّل حاجة واحدة',
        'SUBMISSION_CLAIMED_NOT_DONE' => 'قال إن الطلب اتقدم وهو ما اتقدمش',
        'WORK_TYPE_NOT_RECORDED' => 'عدّد مستندات الشغل قبل ما يسجّل نوع الشغل',
        'UNSOURCED_FINANCE_COMPANY' => 'ذكر شركة تمويل مش شغالين معاها',
        'REPEATED_QUESTION' => 'كرر نفس السؤال اللي في آخر رسالة',
        'DUPLICATE_REPLY' => 'نفس الرد اللي اتبعت قبل كده',
        'INTERNAL_KEY_IN_REPLY' => 'فيه كلمة داخلية بالإنجليزي في الرد',
        'PLACEHOLDER_IN_REPLY' => 'فيه علامة داخلية زي [media] في الرد',
        'IMAGES_CLAIMED_NOT_SENT' => 'قال إنه بعت صور وهو ما بعتش',
        'UNVERIFIED_NUMBER' => 'فيه رقم مش موجود في بيانات السيستم',
        'TOTAL_NOT_SOURCED' => 'قال إجمالي مش من حسبة السيستم',
        'UNSOURCED_REASON' => 'ألّف سبب للمصاريف أو لفرق السعر',
        'BRANCH_NOT_SOURCED' => 'ذكر فرع أو عنوان أو مواعيد مش من جدول الفروع',
    ];

    public const BLOCKER_TYPE = [
        'field' => 'بيان ناقص',
        'document' => 'مستند ناقص',
        'selection' => 'اختيار ناقص',
        'eligibility' => 'الأهلية',
        'identity' => 'الهوية',
    ];

    public const SELECTION = [
        'motorcycle' => 'الموتوسيكل',
        'plan' => 'نظام / مدة التقسيط',
        'duration' => 'مدة التقسيط',
        'down_payment' => 'المقدم',
    ];

    public const ENUM_VALUE = [
        'delivery_app' => 'دليفري على تطبيق (موتوسيكل)',
        'delivery_app_bicycle' => 'دليفري على تطبيق (عجلة)',
        'delivery_company' => 'مندوب شركة توصيل',
        'craftsman' => 'صنايعي / حرفي',
        'business_owner' => 'صاحب نشاط',
        'other' => 'شغل تاني',
        'owned' => 'تمليك',
        'rented' => 'إيجار',
        'family' => 'ساكن مع أهله',
    ];

    public static function get(array $map, ?string $key): string
    {
        if ($key === null || $key === '') {
            return '-';
        }

        return $map[$key] ?? str_replace('_', ' ', $key);
    }

    public static function color(array $map, ?string $key): string
    {
        return $map[$key] ?? 'gray';
    }
}
