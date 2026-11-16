// Shared between the side panel, the background worker and the content script.
// Field keys match motobot's installment_requests columns (app/Models/InstallmentRequest.php).
var AMAN = (typeof AMAN !== 'undefined') ? AMAN : {};

// Keys are installment_requests.work_status values (config/aman_extension.php). The server's list wins.
AMAN.REQUEST_TYPES = [
  { key: 'no_income_proof', label: 'دخل حر' },
  { key: 'employee', label: 'موظف / صاحب عمل' },
  { key: 'pension', label: 'صاحب معاش' },
  { key: 'self_employed', label: 'صاحب نشاط' },
];

AMAN.FIELD_GROUPS = [
  { label: 'بيانات العميل (السيستم)', fields: [
    ['applicant_name', 'اسم العميل كامل'],
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

// Read off the national ID card on the server (app/Domain/Aman/IdCardReader.php).
AMAN.CARD_FIELDS = [
  ['name_1', 'الاسم الأول'],
  ['name_2', 'اسم الأب'],
  ['name_3', 'اسم الجد'],
  ['name_4', 'اسم العائلة (الباقي)'],
  ['full_name', 'الاسم كامل'],
  ['national_id', 'الرقم القومي'],
  ['birthdate', 'تاريخ الميلاد'],
  ['gender', 'النوع (ذكر / أنثى)'],
  ['governorate', 'محافظة الميلاد'],
  ['address', 'العنوان المكتوب في البطاقة'],
  ['religion', 'الديانة'],
  ['marital_status', 'الحالة الاجتماعية'],
  ['issue_date', 'تاريخ إصدار البطاقة'],
  ['expiry_date', 'البطاقة سارية حتى'],
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

AMAN.DATE_FORMATS = [
  ['iso', 'سنة-شهر-يوم (2000-01-31)'],
  ['dmy', 'يوم/شهر/سنة (31/01/2000)'],
  ['mdy', 'شهر/يوم/سنة (01/31/2000)'],
];

const findLabel = (list, key) => (list.find(([k]) => k === key) || [])[1];

AMAN.fieldLabel = function (key) {
  for (const g of AMAN.FIELD_GROUPS) { const l = findLabel(g.fields, key); if (l) return l; }
  return findLabel(AMAN.FILE_FIELDS, key) || key;
};
AMAN.cardLabel = (key) => findLabel(AMAN.CARD_FIELDS, key) || key;
AMAN.isDateKey = (key) => /date|birth|expiry/.test(key || '');

AMAN.typeLabel = function (key) {
  const t = (AMAN.types || AMAN.REQUEST_TYPES).find((x) => x.key === key);
  return t ? t.label : key;
};

AMAN.describeSource = function (s) {
  if (!s) return '—';
  const v = s.verify ? ' (تأكيد بس)' : '';
  if (s.type === 'field') return 'السيستم: ' + AMAN.fieldLabel(s.key) + v;
  if (s.type === 'card') return 'البطاقة: ' + AMAN.cardLabel(s.key) + v;
  if (s.type === 'file') return 'ملف: ' + AMAN.fieldLabel(s.key);
  if (s.type === 'const') return 'ثابت: ' + s.value;
  if (s.type === 'ask') return 'الموظف يكتبها';
  if (s.type === 'check') {
    if (s.mode === 'always') return 'دايمًا متعلّم';
    if (s.mode === 'never') return 'دايمًا مش متعلّم';
    const src = s.from === 'card' ? AMAN.cardLabel(s.key) : AMAN.fieldLabel(s.key);
    return 'متعلّم لو ' + src + ' = ' + s.equals;
  }
  return s.type;
};

// Arabic-tolerant comparison for matching dropdown options, labels and messages.
AMAN.norm = function (v) {
  return String(v ?? '')
    .replace(/[ً-ْـ]/g, '')
    .replace(/[أإآ]/g, 'ا').replace(/ة/g, 'ه').replace(/ى/g, 'ي')
    .replace(/[٠-٩]/g, (d) => '٠١٢٣٤٥٦٧٨٩'.indexOf(d))
    .replace(/\s+/g, ' ').trim().toLowerCase();
};

AMAN.formatDate = function (v, fmt) {
  const m = String(v ?? '').match(/^(\d{4})-(\d{2})-(\d{2})/);
  if (!m || !fmt || fmt === 'iso') return v;
  return fmt === 'dmy' ? `${m[3]}/${m[2]}/${m[1]}` : `${m[2]}/${m[3]}/${m[1]}`;
};

AMAN.store = {
  get: (keys) => new Promise((r) => chrome.storage.local.get(keys, r)),
  set: (obj) => new Promise((r) => chrome.storage.local.set(obj, r)),
};

// Calls motobot through the background worker (it holds the key and is not bound by page CORS).
AMAN.api = async function (method, path, body) {
  const r = await chrome.runtime.sendMessage({ type: 'api', method, path, body });
  if (!r?.ok) throw new Error(r?.error || 'السيرفر مش بيرد');
  return r.data;
};
