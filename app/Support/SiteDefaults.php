<?php

namespace App\Support;

use App\Models\Brand;

/**
 * The design's own copy and imagery (motogate/*.html), one array per page
 * group. SiteContent merges whatever was saved from the dashboard over
 * these, so a fresh install renders the design untouched and a key added
 * here later shows up without a migration.
 *
 * Images point at 'site/defaults/...', served from public/motogate/images
 * (see SiteContent::url) and mirrored to the public disk so the dashboard's
 * upload fields can preview them.
 */
class SiteDefaults
{
    public static function all(): array
    {
        return SiteContent::merge([
            'tracking' => [
                'title' => 'تتبع حالة طلبك',
                'text' => 'تابع آخر تحديث لطلب التقسيط من خلال كود المتابعة الخاص بك.',
                'form_title' => 'طلبك وصل لفين؟', 'code_label' => 'كود المتابعة الآمن',
                'submit' => 'اعرض حالة الطلب', 'not_found' => 'لم نعثر على طلب بهذا الكود. راجع كود المتابعة أو تواصل معنا.',
                'result_title' => 'حالة طلبك الآن', 'result_note' => 'الحالة محدثة من فريق موتوجيت. تواصل معنا لأي تفاصيل إضافية.',
                'empty' => 'أدخل كود المتابعة الذي ظهر بعد إرسال الطلب.', 'contact_label' => 'تواصل مع الفريق',
                'statuses' => ['new' => 'في انتظار المراجعة', 'new_request' => 'تم استلام الطلب', 'pending' => 'قيد المراجعة',
                    'work_check' => 'جارٍ استكمال الاستعلام', 'approved' => 'تمت الموافقة', 'rejected' => 'لم تتم الموافقة',
                    'paused' => 'الطلب متوقف مؤقتاً — تواصل معنا', 'transferred' => 'قيد مراجعة الإدارة',
                    'delivered' => 'تم الاستلام', 'canceled' => 'الطلب ملغي'],
            ],
            'general' => static::general(),
            'home' => static::home(),
            'about' => static::about(),
            'showroom' => static::showroom(),
            'installment' => static::installment(),
            'blog' => static::blog(),
            'team' => static::team(),
            'contact' => static::contact(),
        ], json_decode(file_get_contents(resource_path('site/motogate-v2-content.json')), true));
    }

    protected static function img(string $path): string
    {
        return 'site/defaults/' . $path;
    }

    protected static function general(): array
    {
        return [
            'site_name' => 'MotoGate',
            'logo' => 'site/v2-defaults/logo.png',
            'footer_logo' => 'site/v2-defaults/logo.png',
            'favicon' => static::img('favicon.png'),
            'phone' => '01028887119',
            'whatsapp' => '01028887119',
            'email' => 'info@motogate-eg.com',
            'address' => 'القاهرة، جمهورية مصر العربية',
            'show_header_email' => true,
            'working_hours' => [
                ['day' => 'السبت - الخميس', 'time' => '10:00 ص - 10:00 م'],
                ['day' => 'الجمعة', 'time' => '02:00 م - 10:00 م'],
            ],
            'social' => [
                ['icon' => 'fa-brands fa-facebook-f', 'url' => 'https://www.facebook.com'],
                ['icon' => 'feather feather-instagram', 'url' => 'https://www.instagram.com'],
                ['icon' => 'fa-brands fa-youtube', 'url' => 'https://www.youtube.com'],
            ],
            'menu' => [
                ['label' => 'الرئيسية', 'url' => '/'], ['label' => 'المعرض', 'url' => '/machines'],
                ['label' => 'اعرف قسطك', 'url' => '/installment'], ['label' => 'نبذة عنا', 'url' => '/about'],
                ['label' => 'تواصل معنا', 'url' => '/contact'],
            ],
            'loader' => [
                'enabled' => true,
                'video' => static::img('Intro.mp4'),
                'video_mobile' => static::img('Intro-mobile.mp4'),
            ],
            'footer' => [
                'bg_image' => static::img('slider1.jpg'), // the design's footer-bg.jpg is a grey placeholder
                'about_text' => 'معرض موتوجيت (MotoGate) الوجهة الأولى لعشاق الدراجات النارية والسكوتر في مصر. نوفر أحدث الموديلات العالمية مع خيارات تقسيط ميسرة وخدمات صيانة وقطع غيار أصلية.',
                'hours_title' => 'مواعيد العمل',
                'links_title' => 'روابط سريعة',
                'links' => [
                    ['label' => 'الرئيسية', 'url' => '/'],
                    ['label' => 'من نحن', 'url' => '/about'],
                    ['label' => 'المعرض', 'url' => '/machines'],
                    ['label' => 'احسب قسطك', 'url' => '/installment'],
                    ['label' => 'قدّم على التقسيط', 'url' => '/apply-installment'],
                    ['label' => 'المدونة', 'url' => '/blog'],
                    ['label' => 'فريق العمل', 'url' => '/team'],
                    ['label' => 'تواصل معنا', 'url' => '/contact'],
                ['label' => 'تتبع طلبك', 'url' => '/track'],
                ],
                'categories_title' => 'فئات الدراجات',
                'newsletter_enabled' => true,
                'newsletter_text' => 'اشترك في نشرتنا البريدية ليصلك جديد العروض والموديلات!',
                'newsletter_placeholder' => 'أدخل بريدك الإلكتروني...',
                'newsletter_success' => 'شكراً لاشتراكك في نشرة موتوجيت!',
                'copyright_brand' => 'MotoGate',
                'copyright_text' => 'جميع الحقوق محفوظة لـ موتوجيت.',
            ],
            'seo' => [
                'title_suffix' => 'موتوجيت MotoGate',
                'keywords' => 'موتوجيت, دراجات نارية, موتوسيكلات مصر, سكوتر, شراء موتوسيكل, صيانة موتوسيكلات, تقسيط موتوسيكلات, MotoGate',
                'description' => 'معرض موتوجيت MotoGate - أكبر معرض للدراجات النارية والسكوتر في مصر، أفضل الماركات العالمية، خطط تقسيط مريحة وخدمات صيانة وقطع غيار أصلية.',
                'og_image' => 'site/v2-defaults/logo.png',
            ],
            'integrations' => [
                'tawk_enabled' => true,
                'tawk_src' => 'https://embed.tawk.to/690b6fb3409a40195ef86c2f/1j9aaols0',
                'protect_images' => true,
                'head_code' => null,
                'footer_code' => null,
            ],
        ];
    }

    protected static function home(): array
    {
        return [
            'seo' => [
                'title' => 'MotoGate | معرض موتوجيت للدراجات النارية والسكوتر',
                'description' => null,
            ],
            'hero' => [
                'bg_image' => 'site/v2-defaults/الباكجراوند.png',
                'small_title' => 'عالمك، حركتك، اختيارك',
                'description' => 'نساعدك تختار أنسب موتوسيكل وأفضل نظام للتقسيط وأقرب فرع معتمد تقدر تستلم منه',
                'calculator_image' => 'site/v2-defaults/motorcycle-rkz200.webp',
                'typed_words' => ['تقسيط مريح', 'بدون مقدم', 'ضمان معتمد'],
                'brand_first' => 'Moto',
                'brand_second' => 'Gate',
                'calc_title' => 'احسب قسط دراجتك النارية بكل سهولة',
                'tabs' => static::heroTabs(),
                'slides' => [
                    ['title' => 'Keeway RKZ200', 'image' => 'site/v2-defaults/motorcycle-rkz200.webp'],
                    ['title' => 'Keeway K-Light 202', 'image' => 'site/v2-defaults/bike2-isolated.webp'],
                    ['title' => 'Benelli TNT 150i', 'image' => 'site/v2-defaults/bike3-isolated.webp'],
                    ['title' => 'Keeway RKZ200', 'image' => 'site/v2-defaults/motorcycle-rkz200.webp'],
                ],
            ],
            'explore' => [
                'enabled' => true,
                'small_title' => 'الماركات المميزة',
                'title' => 'استكشف أحدث الدراجات النارية في موتوجيت',
                'image' => static::img('Trio Bikes.png'),
                'button_text' => 'عرض جميع الموديلات المتاحة',
                'button_url' => '/machines',
            ],
            'about' => [
                'enabled' => true,
                'image' => 'site/v2-defaults/about-scene.png',
                'video_url' => null,
                'year_label' => 'خبرة منذ',
                'year' => '2019',
                'image2' => 'site/v2-defaults/bike2-isolated.webp',
                'small_title' => 'عن موتوجيت (MotoGate)',
                'title' => 'رواد عالم الدراجات النارية والسكوتر في مصر',
                'text' => 'منذ انطلاقنا في عام 2019، كرّسنا جهودنا لتقديم أفضل تجربة شراء وامتلاك للدراجات النارية والسكوتر في مصر. نوفر تشكيلة واسعة من أشهر الماركات العالمية، مع حلول تقسيط مرنة بدون تعقيدات، ومركز صيانة معتمد مجهز بأحدث أجهزة الفحص وقطع الغيار الأصلية.',
                'bullets' => [
                    'جميع الفئات متاحة (ريس، كروزر، أدفنتشر، سكوتر)',
                    'ضمان معتمد وخدمات صيانة دورية',
                    'أنظمة تقسيط متعددة تناسب جميع الفئات',
                ],
                'button_text' => 'تعرف علينا أكثر',
                'button_url' => '/about',
            ],
            'adventure' => [
                'enabled' => true,
                'bg_image' => static::img('half-bg-pic.jpg'),
                'small_title' => 'خطوة واحدة نحو انطلاقتك',
                'title' => 'ابدأ مغامرتك على الطريق مع موتوجيت',
                'items' => [
                    ['icon' => static::img('icons/label.png'), 'title' => 'عروض تناسب كل الميزانيات', 'text' => 'أفضل أسعار الدراجات النارية والسكوتر كاش وتقسيط بأقل فائدة في مصر مع برامج دفع مرنة.'],
                    ['icon' => static::img('icons/customer-support.png'), 'title' => 'دعم فني وصيانة معتمدة', 'text' => 'فريق من أمهر مهندسي وفنيي الدراجات النارية لخدمتك وصيانة دراجتك بأعلى معايير الأمان.'],
                    ['icon' => static::img('icons/parking-area.png'), 'title' => 'تجربة قيادة وتسليم فوري', 'text' => 'جرب الموديل الذي تريده قبل الشراء واستلم دراجتك النارية مرخصة وجاهزة للانطلاق فوراً.'],
                ],
            ],
            'fleet' => [
                'enabled' => true,
                'small_title' => 'اختر دراجتك المفضلة',
                'title' => 'أسطول الدراجات النارية والسكوتر',
                'source' => 'featured',
                'limit' => 8,
            ],
            'why' => [
                'enabled' => true,
                'small_title' => 'مميزاتنا الحصرية',
                'title' => 'لماذا تختار معرض موتوجيت؟',
                'text' => 'نقدم لك تجربة متكاملة لشراء وصيانة دراجتك النارية بكل ثقة وراحة بال، مع حلول تمويلية وضمان معتمد.',
                'items' => [
                    ['icon' => static::img('w-choose-icon/icon-1.png'), 'title' => 'أنظمة تقسيط مريحة', 'text' => 'برامج تقسيط متعددة تبدأ من 0% مقدم وبفترات سداد تصل إلى 48 شهر بموافقة سريعة.'],
                    ['icon' => static::img('w-choose-icon/icon-2.png'), 'title' => 'ضمان معتمد وصيانة رسمية', 'text' => 'جميع الدراجات تخضع للفحص الدقيق وتأتي مع ضمان معتمد ومركز صيانة متخصص.'],
                    ['icon' => static::img('w-choose-icon/icon-3.png'), 'title' => 'قطع غيار أصلية 100%', 'text' => 'نوفر قطع الغيار الأصلية والإكسسوارات وخوذ الأمان من كبرى الشركات العالمية.'],
                    ['icon' => static::img('w-choose-icon/icon-4.png'), 'title' => 'استشارة متخصصة وتجربة قيادة', 'text' => 'فريقنا من خبراء الدراجات النارية يساعدك في اختيار الموديل الأنسب لاحتياجاتك وميزانيتك.'],
                ],
                'image' => static::img('motorcycles/qj-srk250-showroom.jpg'),
                'help_text' => 'هل تحتاج مساعدة أو استفسار؟',
                'help_phone' => '01028887119',
                'help_icon' => static::img('24-clock.png'),
            ],
            'categories' => [
                'enabled' => true,
                'small_title' => 'فئات الدراجات النارية',
                'title' => 'نظرة على جميع الفئات المتاحة في المعرض',
                'items' => [
                    ['label' => 'سوبر بايك (ريس)', 'category' => 'sport', 'image' => static::img('motorcycles/bike-superbike.jpg')],
                    ['label' => 'كروزر كاستم', 'category' => 'cruiser', 'image' => static::img('motorcycles/bike-cruiser.jpg')],
                    ['label' => 'أدفنتشر تورينج', 'category' => 'touring', 'image' => static::img('motorcycles/bike-adventure.jpg')],
                    ['label' => 'سكوتر وماكسي', 'category' => 'scooter', 'image' => static::img('motorcycles/bike-scooter.jpg')],
                    ['label' => 'ستريت فايتر', 'category' => 'naked', 'image' => static::img('motorcycles/bike-streetfighter.jpg')],
                    ['label' => 'موتوسيكلات اقتصادية', 'category' => 'commuter', 'image' => static::img('motorcycles/haojiang-4.jpg')],
                ],
            ],
            'steps' => [
                'enabled' => true,
                'bg_image' => static::img('step-bg.jpg'),
                'small_title' => 'خطوات سهلة ومباشرة',
                'title' => 'كيف تمتلك دراجتك النارية من موتوجيت؟',
                'items' => [
                    ['icon' => 'fa-solid fa-motorcycle', 'title' => 'اختر الدراجة المناسبة', 'text' => 'تصفح كتالوج الموديلات المتاح في المعرض وحدد الفئة المناسبة لذوقك واحتياجك.'],
                    ['icon' => 'fa-solid fa-route', 'title' => 'احجز تجربة القيادة', 'text' => 'قم بزيارة المعرض أو احجز موعداً لتجربة قيادة الدراجة والتأكد من ملاءمتها لك.'],
                    ['icon' => 'fa-solid fa-wallet', 'title' => 'اختر نظام السداد', 'text' => 'اختر ما يناسبك سواء بالدفع النقدي المباشر أو من خلال خطط التقسيط الميسرة بأقل فائدة.'],
                    ['icon' => 'fa-solid fa-key', 'title' => 'استلم وانطلق', 'text' => 'استلم دراجتك النارية بضمان معتمد وجاهزة للترخيص مع باقة هدايا وإكسسوارات الأمان.'],
                ],
                'image' => static::img('motorcycles/bike-superbike.jpg'),
            ],
            'testimonials' => [
                'enabled' => true,
                'small_title' => 'آراء عملائنا',
                'title' => 'ماذا يقول عملاء معرض موتوجيت؟',
            ],
            'blog' => [
                'enabled' => true,
                'small_title' => 'من مدونة موتوجيت',
                'title' => 'نصائح ومقالات لعشاق الدراجات النارية',
                'limit' => 4,
            ],
        ];
    }

    /**
     * The hero calculator tabs filter the machine list. Electric has no
     * category of its own in the catalogue, so its default tab points at the
     * brand(s) whose name says "electric".
     */
    protected static function heroTabs(): array
    {
        try {
            $electricBrandIds = Brand::query()
                ->where('name', 'like', '%lectric%')
                ->orWhere('name', 'like', '%كهرب%')
                ->pluck('id')
                ->map(fn ($id) => (string) $id)
                ->all();
        } catch (\Throwable) {
            $electricBrandIds = [];
        }

        return [
            [
                'label' => 'موتوسيكل',
                'image' => static::img('motocykl.png'),
                'categories' => ['commuter', 'naked', 'sport', 'cruiser', 'off_road', 'touring'],
                'brands' => [],
                'browse_text' => 'استعرض المعرض',
            ],
            [
                'label' => 'اسكوتر',
                'image' => static::img('scoter.png'),
                'categories' => ['scooter'],
                'brands' => [],
                'browse_text' => 'استعرض السكوترات',
            ],
            [
                'label' => 'كهربائي',
                'image' => static::img('scooter el.png'),
                'categories' => [],
                'brands' => $electricBrandIds,
                'browse_text' => 'استعرض الموديلات',
            ],
        ];
    }

    protected static function about(): array
    {
        return [
            'seo' => [
                'title' => 'من نحن | معرض موتوجيت MotoGate للدراجات النارية',
                'description' => null,
            ],
            'banner' => [
                'image' => static::img('banner/banner-6.jpg'),
                'title' => 'قصتنا | منصتنا رائدة في خدمات الدراجات النارية',
                'breadcrumb' => 'من نحن',
            ],
            'story' => [
                'card_icon' => 'feather-compass',
                'card_title' => 'MotoGate',
                'card_subtitle' => 'موتوجيت | وجهة راكبي الدراجات في مصر',
                'card_note' => 'تأسست 2019 • الوكيل الأفضل لاختيار وتجهيز دراجتك',
                'small_title' => '| قصتنا',
                'title' => 'موتوجيت | رحلتنا في بناء مجتمع الدراجات النارية في مصر',
                'body' => '<p>تعتبر منصة <strong>موتوجيت (MotoGate)</strong> إحدى الشركات الرائدة والسبّاقة في قطاع توزيع وتجارة الدراجات النارية والسكوتر في جمهورية مصر العربية.</p>'
                    . '<p>نقدم منظومة متكاملة من الخدمات التي تشمل توفير أحدث الموديلات العالمية (ريس، كروزر، أدفنتشر، سكوتر، ومكن اقتصادي) بأسعار تنافسية، إلى جانب أنظمة تقسيط فورية وميسرة بدون إجراءات معقدة تناسب جميع الفئات.</p>'
                    . '<p>هدفنا الأسمى هو تقديم تجربة شراء راقية مبنية على الأمانة والشفافية التامة، مع توفير الدعم الفني والاستشارات المتخصصة وفحص الكمبيوتر الشامل لضمان راحة بالك واستمتاعك بكل رحلة على الطريق.</p>',
            ],
            'features' => [
                'enabled' => true,
                'small_title' => '| مميزات موتوجيت',
                'title' => 'خدمات متكاملة تضمن لك أفضل تجربة',
                'items' => [
                    ['icon' => 'feather-shield', 'color' => '#00d2b4', 'title' => 'ضمان وجودة', 'text' => 'نوفر أفضل الموديلات المعتمدة مع ضمان شامل وتاريخ فحص فني دقيق لكل دراجة قبل التسليم.'],
                    ['icon' => 'feather-credit-card', 'color' => '#ff6100', 'title' => 'حلول دفع مرنة', 'text' => 'خيارات تمويل وتقسيط مريح بصورة البطاقة فقط وبدون مصاريف إدارية لتناسب كافة الميزانيات.'],
                    ['icon' => 'feather-headphones', 'color' => '#00bcd4', 'title' => 'دعم ومتابعة', 'text' => 'فريق خدمة العملاء ومهندسو الصيانة متاحون دائماً لتوفير قطع الغيار والمتابعة الدورية لدراجتك.'],
                ],
            ],
            'stats' => [
                'enabled' => true,
                'items' => [
                    ['number' => '15', 'suffix' => '+', 'label' => 'سنة خبرة مجتمعة'],
                    ['number' => '2000', 'suffix' => '+', 'label' => 'عميل سعيد وموثوق'],
                    ['number' => '150', 'suffix' => '+', 'label' => 'موديل متاح فوراً'],
                    ['number' => '2', 'suffix' => null, 'label' => 'فروع وصالات عرض'],
                ],
            ],
            'why' => [
                'enabled' => true,
                'small_title' => '| ليه الناس بتختار موتوجيت؟',
                'title' => 'أسباب تجعلنا اختيارك الأول والآمن',
                'items' => [
                    ['icon' => 'feather-heart', 'title' => 'شفافية وأمان', 'text' => 'وضوح كامل في المواصفات والأسعار وأوراق المرور دون أي رسوم خفية.'],
                    ['icon' => 'feather-package', 'title' => 'اختيارات متنوعة', 'text' => 'تشكيلة واسعة من الدراجات النارية والسكوترات بمختلف السعات والأنواع.'],
                    ['icon' => 'feather-users', 'title' => 'دعم سريع', 'text' => 'استشارات فورية وفريق متخصص في خدمتك للرد على كافة أسئلتك على مدار اليوم.'],
                    ['icon' => 'feather-map-pin', 'title' => 'سهولة الوصول', 'text' => 'موقع مميز في قلب القاهرة مع توفير خدمة الشحن والتوصيل لجميع المحافظات.'],
                ],
            ],
            'official' => [
                'enabled' => true,
                'small_title' => '| البيانات الرسمية',
                'title' => 'معلومات وترخيص المنشأة',
                'items' => [
                    ['label' => 'اسم المنشأة', 'value' => 'شركة موتوجيت لتجارة وتوزيع الدراجات النارية'],
                    ['label' => 'الكيان القانوني', 'value' => 'شركة مساهمة مصرية خاضعة لأحكام قانون الاستثمار'],
                    ['label' => 'التراخيص المعتمدة', 'value' => 'سجل تجاري وبطاقة ضريبية معتمدة من وزارة التجارة والصناعة'],
                    ['label' => 'مقر المعرض التجاري', 'value' => 'القاهرة - جمهورية مصر العربية'],
                    ['label' => 'الرقم الضريبي', 'value' => 'مسجل ومعتمد لدى مصلحة الضرائب المصرية'],
                ],
            ],
            'team' => [
                'enabled' => true,
                'small_title' => '| فريق العمل',
                'title' => 'نخبة من خبراء ومحترفي الدراجات النارية',
                'limit' => 4,
            ],
            'faq' => [
                'enabled' => true,
                'title' => 'كل سؤال له طريق واضح',
                'items' => [
                    ['question' => 'إزاي أختار الموديل المناسب؟', 'answer' => 'تصفح المعرض وقارن المواصفات أو تواصل مع فريقنا لمساعدتك.'],
                    ['question' => 'أتابع حالة طلبي إزاي؟', 'answer' => 'احتفظ برابط المتابعة الذي يظهر بعد إرسال طلبك، وافتحه لمعرفة آخر حالة للطلب.'],
                    ['question' => 'إيه الأوراق المطلوبة للتقسيط؟', 'answer' => 'افتح نموذج طلب التقسيط واختر حالتك الوظيفية لعرض المستندات المطلوبة.'],
                ],
            ],
            'testimonial' => [
                'enabled' => true,
                'review_id' => null,
                'small_title' => '| آراء عملائنا',
                'title' => 'ماذا يقول عملاؤنا عن موتوجيت؟',
            ],
            'cta' => [
                'enabled' => true,
                'title' => 'جاهز تبدأ رحلتك؟ 🚀',
                'text' => 'اكتشف مجموعتنا المميزة من الدراجات النارية والسكوتر وابدأ مشوارك اليوم بكل ثقة وأمان',
                'button_text' => 'تواصل معنا الآن',
                'button_url' => '/contact',
            ],
        ];
    }

    protected static function showroom(): array
    {
        return [
            'seo' => [
                'title' => 'شبكة الموديلات | موتوجيت MotoGate',
                'description' => 'استعرض شبكة جميع موديلات الدراجات النارية والسكوتر المتاحة لدى موتوجيت MotoGate بأفضل الأسعار.',
            ],
            'hero' => [
                'badge' => 'منصة متخصصة في بيع وشراء وتقسيط الدراجات النارية في مصر',
                'title_before' => 'معرض وموديلات',
                'title_highlight' => 'موتوجيت',
                'subtitle' => 'الحرية تبدأ من هنا — تجربة شراء أو تقسيط موتوسيكل وسكوتر بشكل أوضح، أسرع، وبثقة وأعلى جودة.',
                'breadcrumb' => 'شبكة الموديلات والماركات',
            ],
            'filter' => [
                'title' => 'فلترة سريعة حسب الماركة والفئة',
                'search_placeholder' => 'ابحث باسم الموديل أو الماركة...',
                'search_button' => 'بحث',
                'search_label' => 'نتائج البحث عن:',
                'clear_search' => 'مسح البحث',
                'results' => 'عرض :from - :to من :total موديل',
                'per_page' => 12,
                'all_label' => 'الكل',
                'visible_chips' => 6,
                'more_label' => 'عرض باقي الماركات',
                'less_label' => 'عرض أقل',
                'count_all' => 'عرض الكل (:count موديل متاح)',
                'count_brand' => 'عرض :count موديل متوفر من (:brand)',
                'category_label' => 'الفئة:',
                'clear_category' => 'إلغاء فلتر الفئة',
            ],
            'empty' => [
                'title' => 'لا توجد دراجات حالياً في هذا التصنيف',
                'text' => 'جاري تحديث وإضافة موديلات جديدة قريباً لهذا القسم. يمكنك تصفح باقي الموديلات المتاحة.',
                'button' => 'عرض كل الموديلات',
            ],
            'card' => [
                'details_label' => 'التفاصيل',
                'show_price' => true,
                'price_prefix' => 'كاش',
                'currency' => 'ج.م',
            ],
            'detail' => [
                'color_label' => 'اختر لون الدراجة النارية:',
                'cash_label' => 'سعر الكاش',
                'installment_label' => 'سعر التقسيط',
                'show_installment_price' => false,
                'offer_label' => 'عرض خاص',
                'calc_button' => 'احسب قسطك',
                'apply_button' => 'قدّم على التقسيط',
                'whatsapp_button' => 'استفسر على واتساب',
                'whatsapp_message' => 'أهلاً، عايز أستفسر عن :name',
                'gallery_zoom' => 'اضغط على الصورة لعرضها بالحجم الكامل',
                'specs_title' => 'المواصفات الفنية والتجهيزات',
                'features_title' => 'مميزات الموديل',
                'video_small_title' => '| فيديو المراجعة وتجربة القيادة',
                'video_title' => 'شاهد تجربة أداء :name وصوت المحرك والتسارع',
                'video_text' => 'مراجعة تفصيلية شاملة لكل التجهيزات والمواصفات الفنية ونظام الفرامل والتحكم الإلكتروني على الطبيعة.',
                'calc_title' => 'احسب قسط :name',
                'faq_title' => 'الأسئلة الشائعة حول الشراء والتقسيط',
                'faqs' => [
                    ['question' => 'كيف يمكنني حجز تجربة قيادة للموتوسيكل؟', 'answer' => 'يمكنك التواصل معنا هاتفياً أو زيارة صالة العرض مباشرة. سنقوم بتأكيد الموعد واستقبالك في المعرض مع تجهيزات الأمان لتجربة قيادة متكاملة.'],
                    ['question' => 'ما هي الأوراق المطلوبة للتقسيط في موتوجيت؟', 'answer' => 'الأوراق بسيطة جداً: صورة بطاقة الرقم القومي سارية، وإثبات دخل أو كشف حساب بنكي لـ 6 أشهر، أو من خلال برامج التقسيط المباشر للبطاقات الائتمانية بدون فوائد.'],
                    ['question' => 'هل يتوفر ضمان وخدمة صيانة دورية؟', 'answer' => 'نعم، جميع دراجاتنا تأتي بضمان معتمد يشمل المحرك وناقل الحركة، بالإضافة إلى الصيانة الدورية في مركز خدمة موتوجيت المتخصص وقطع الغيار الأصلية.'],
                    ['question' => 'هل تساعدون في إجراءات الترخيص والمرور؟', 'answer' => 'بالتأكيد، نوفر ملفاً مرورياً كاملاً مع خطاب المرور والمطابقة الفنية لتسهيل استخراج الرخصة ولوحات الدراجة في أسرع وقت.'],
                ],
                'related_title' => 'موديلات مشابهة',
            ],
        ];
    }

    protected static function installment(): array
    {
        return [
            'calculator' => [
                'labels' => [
                    'brand' => 'الماركة',
                    'brand_placeholder' => 'اختر الماركة',
                    'machine' => 'الموديل',
                    'machine_placeholder' => 'اختر الموديل',
                    'system' => 'نظام التقسيط',
                    'system_placeholder' => 'اختر النظام',
                    'months' => 'مدة التقسيط',
                    'months_placeholder' => 'اختر عدد الشهور',
                    'months_option' => ':months شهر (:interest% فايدة)',
                    'down_payment' => 'قيمة المقدم المتوقع (جنيه)',
                    'down_payment_placeholder' => 'مثال: 15,000',
                    'submit' => 'احسب القسط',
                ],
                'result' => [
                    'title' => 'تفاصيل القسط',
                    'monthly' => 'القسط الشهري',
                    'per_month' => 'شهرياً',
                    'duration' => 'لمدة :months شهر',
                    'down_payment' => 'المقدم',
                    'financed' => 'المبلغ المتبقي',
                    'interest' => 'قيمة الفايدة',
                    'total' => 'الإجمالي بعد الفايدة',
                    'fees' => 'المصاريف الإدارية',
                    'note' => '*المصاريف الإدارية بتدفع مرة واحدة وانت بتستلم المكنة',
                    'currency' => 'جنيه',
                    'apply' => 'قدّم على التقسيط الآن',
                    'missing_fields' => 'من فضلك اختر جميع الحقول المطلوبة',
                ],
            ],
            'page' => [
                'seo_title' => 'احسب قسطك | موتوجيت MotoGate',
                'banner_image' => static::img('banner/banner-6.jpg'),
                'banner_title' => 'احسب قسط موتوسيكلك',
                'breadcrumb' => 'احسب قسطك',
                'small_title' => 'حاسبة التقسيط',
                'title' => 'اعرف قسطك الشهري في ثواني',
                'text' => 'اختار الماركة والموديل ونظام التقسيط والمدة، واكتب المقدم اللي تقدر عليه وهنحسبلك القسط الشهري فوراً.',
            ],
            'apply' => [
                'seo_title' => 'قدّم على التقسيط | موتوجيت MotoGate',
                'banner_image' => static::img('banner/banner-6.jpg'),
                'banner_title' => 'قدّم طلب التقسيط أونلاين',
                'breadcrumb' => 'طلب التقسيط',
                'small_title' => 'طلب تقسيط',
                'title' => 'املأ بياناتك وارفع المستندات',
                'text' => 'هنراجع طلبك ونتواصل معاك في أسرع وقت.',
            ],
        ];
    }

    protected static function blog(): array
    {
        return [
            'seo' => [
                'title' => 'مدونة موتوجيت | نصائح ومقالات الدراجات النارية',
                'description' => null,
            ],
            'banner' => [
                'image' => static::img('banner/banner-6.jpg'),
                'title' => 'مدونة موتوجيت للدراجات النارية',
                'breadcrumb' => 'المدونة',
            ],
            'small_title' => 'أحدث المقالات',
            'title' => 'كل ما يهم عشاق ومحبي الدراجات النارية والسكوتر',
            'card_style' => 'classic',
            'per_page' => 6,
            'empty_text' => 'لا توجد مقالات منشورة حالياً.',
            'detail' => [
                'author_prefix' => 'بواسطة',
                'tags_label' => 'الوسوم:',
                'share_label' => 'مشاركة:',
                'comments_title' => 'التعليقات',
                'form_title' => 'أضف تعليقاً أو استفساراً',
                'name_placeholder' => 'الاسم',
                'email_placeholder' => 'البريد الإلكتروني',
                'body_placeholder' => 'اكتب تعليقك أو سؤالك هنا...',
                'submit' => 'نشر التعليق',
                'success' => 'شكراً لمشاركتك! سيتم مراجعة تعليقك ونشره.',
            ],
        ];
    }

    protected static function team(): array
    {
        return [
            'seo' => [
                'title' => 'فريق عمل موتوجيت | خبراء الدراجات النارية',
                'description' => null,
            ],
            'banner' => [
                'image' => static::img('banner/banner-6.jpg'),
                'title' => 'فريق عمل موتوجيت',
                'breadcrumb' => 'فريق العمل',
            ],
            'small_title' => 'كوادر محترفة',
            'title' => 'فريق من عشاق وخبراء الدراجات النارية في خدمتك',
        ];
    }

    protected static function contact(): array
    {
        return [
            'seo' => [
                'title' => 'تواصل معنا | معرض موتوجيت MotoGate',
                'description' => null,
            ],
            'banner' => [
                'image' => static::img('banner/banner-6.jpg'),
                'title' => 'تواصل مع معرض موتوجيت',
                'breadcrumb' => 'تواصل معنا',
            ],
            'small_title' => 'نحن هنا لمساعدتك',
            'title' => 'تفضل بزيارة صالة العرض أو اتصل بنا',
            'text' => 'يسعد فريق موتوجيت الإجابة على كافة استفساراتكم حول الموديلات المتاحة، عروض التقسيط، وخدمات الصيانة.',
            'phone_label' => 'الهاتف المباشر',
            'email_label' => 'البريد الإلكتروني',
            'address_label' => 'عنوان المعرض',
            'hours_label' => 'مواعيد العمل',
            'social_title' => 'تابعونا على مواقع التواصل',
            'show_branches' => true,
            'branches_title' => 'فروعنا',
            'branch_map_label' => 'افتح على الخريطة',
            'form' => [
                'title' => 'أرسل استفسارك',
                'text' => 'املأ النموذج وسيقوم مسؤول المبيعات بالتواصل معك فوراً.',
                'name_label' => 'الاسم الكريم',
                'name_placeholder' => 'الاسم بالكامل',
                'phone_label' => 'رقم الهاتف / الواتساب',
                'phone_placeholder' => '01xxxxxxxxx',
                'email_label' => 'البريد الإلكتروني',
                'email_placeholder' => 'example@email.com',
                'subject_label' => 'نوع الاستفسار',
                'subjects' => [
                    ['label' => 'استفسار عن أسعار الكاش والتقسيط'],
                    ['label' => 'حجز موعد تجربة قيادة (Test Ride)'],
                    ['label' => 'حجز موعد صيانة دورية أو فحص'],
                    ['label' => 'طلب قطع غيار أو إكسسوارات'],
                    ['label' => 'أخرى'],
                ],
                'message_label' => 'نص الرسالة',
                'message_placeholder' => 'اكتب تفاصيل طلبك أو استفسارك هنا...',
                'submit' => 'إرسال الرسالة الآن',
                'success' => 'تم إرسال رسالتك بنجاح إلى معرض موتوجيت! سيتواصل معك أحد ممثلينا خلال وقت قصير.',
            ],
        ];
    }
}
