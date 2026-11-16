// Shared between the side panel and the content script.
// Field keys match motobot's installment_requests columns (app/Models/InstallmentRequest.php).
var AMAN = (typeof AMAN !== 'undefined') ? AMAN : {};

AMAN.REQUEST_TYPES = [
  { key: 'free_income', label: 'دخل حر' },
  { key: 'employer', label: 'صاحب عمل' },
  { key: 'pension', label: 'صاحب معاش' },
  { key: 'business_no_docs', label: 'صاحب نشاط بدون أوراق' },
];

AMAN.FIELD_GROUPS = [
  { label: 'بيانات العميل', fields: [
    ['applicant_name', 'اسم العميل'],
    ['applicant_national_id', 'الرقم القومي'],
    ['applicant_phone', 'الموبايل'],
    ['applicant_phone_2', 'موبايل تاني'],
    ['applicant_birthdate', 'تاريخ الميلاد'],
    ['applicant_address', 'العنوان'],
    ['applicant_governorate', 'المحافظة'],
    ['applicant_area', 'المنطقة'],
    ['applicant_street', 'الشارع'],
    ['applicant_branch_street', 'الشارع المتفرع'],
    ['applicant_building_number', 'رقم العمارة'],
    ['applicant_floor', 'الدور'],
    ['applicant_apartment', 'الشقة'],
    ['applicant_landmark', 'علامة مميزة'],
  ]},
  { label: 'القسط', fields: [
    ['machine_installment_price', 'سعر المكنة بالقسط'],
    ['deposit', 'المقدم'],
    ['months', 'عدد الشهور'],
  ]},
  { label: 'الضامن', fields: [
    ['guarantor_name', 'اسم الضامن'],
    ['guarantor_national_id', 'رقم قومي الضامن'],
    ['guarantor_phone', 'موبايل الضامن'],
    ['guarantor_birthdate', 'تاريخ ميلاد الضامن'],
  ]},
  { label: 'العمل', fields: [
    ['work_address', 'عنوان العمل'],
    ['work_governorate', 'محافظة العمل'],
    ['work_area', 'منطقة العمل'],
    ['work_street', 'شارع العمل'],
    ['work_building_number', 'رقم عمارة العمل'],
    ['free_work_name', 'اسم الشغل الحر'],
    ['free_work_address', 'عنوان الشغل الحر'],
    ['salary_amount', 'المرتب'],
    ['salary_issue_date', 'تاريخ المرتب'],
    ['pension_amount', 'المعاش'],
    ['commercial_reg_expiry', 'انتهاء السجل التجاري'],
    ['tax_card_expiry', 'انتهاء البطاقة الضريبية'],
  ]},
];

AMAN.FILE_FIELDS = [
  ['applicant_id_image', 'وش بطاقة العميل'],
  ['applicant_id_back_image', 'ضهر بطاقة العميل'],
  ['guarantor_id_image', 'وش بطاقة الضامن'],
  ['guarantor_id_back_image', 'ضهر بطاقة الضامن'],
  ['selfie_image', 'صورة شخصية'],
  ['medical_card_image', 'الكارت الطبي'],
  ['price_offer_image', 'عرض السعر'],
  ['free_income_proof_images', 'إثبات الدخل الحر'],
  ['salary_slip_file', 'مفردات المرتب'],
  ['pension_statement_file', 'بيان المعاش'],
  ['commercial_reg_file', 'السجل التجاري'],
  ['tax_card_file', 'البطاقة الضريبية'],
];

AMAN.fieldLabel = function (key) {
  for (const g of AMAN.FIELD_GROUPS) for (const [k, l] of g.fields) if (k === key) return l;
  for (const [k, l] of AMAN.FILE_FIELDS) if (k === key) return l;
  return key;
};

AMAN.typeLabel = function (key) {
  const t = AMAN.REQUEST_TYPES.find((x) => x.key === key);
  return t ? t.label : key;
};

AMAN.describeSource = function (s) {
  if (!s) return '—';
  if (s.type === 'field') return 'من الطلب: ' + AMAN.fieldLabel(s.key);
  if (s.type === 'file') return 'ملف: ' + AMAN.fieldLabel(s.key);
  if (s.type === 'const') return 'ثابت: ' + s.value;
  if (s.type === 'ask') return 'الموظف يكتبها';
  return s.type;
};

// Arabic-tolerant comparison for matching dropdown options and labels.
AMAN.norm = function (v) {
  return String(v ?? '')
    .replace(/[ً-ْـ]/g, '')
    .replace(/[أإآ]/g, 'ا').replace(/ة/g, 'ه').replace(/ى/g, 'ي')
    .replace(/[٠-٩]/g, (d) => '٠١٢٣٤٥٦٧٨٩'.indexOf(d))
    .replace(/\s+/g, ' ').trim().toLowerCase();
};

AMAN.store = {
  get: (keys) => new Promise((r) => chrome.storage.local.get(keys, r)),
  set: (obj) => new Promise((r) => chrome.storage.local.set(obj, r)),
};
