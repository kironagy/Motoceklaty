<?php

namespace Database\Seeders;

use App\Models\Brand;
use App\Models\ClientReview;
use App\Models\Post;
use App\Models\TeamMember;
use App\Support\SiteContent;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

/**
 * The MotoGate design's sample articles, team and testimonials, so the new
 * pages aren't empty on day one. Each table is only filled when empty - safe
 * to re-run, never overwrites what was edited in the dashboard. The sample
 * team and reviews are invented people, so they go in hidden: the owner
 * replaces them with real ones and switches them on from the dashboard.
 *
 *   php artisan db:seed --class=WebsiteContentSeeder
 */
class WebsiteContentSeeder extends Seeder
{
    public function run(): void
    {
        SiteContent::publishDefaults();

        if (TeamMember::count() === 0) {
            $this->seedTeam();
        }

        if (ClientReview::count() === 0) {
            $this->seedReviews();
        }

        if (Post::count() === 0) {
            $this->seedPosts();
        }

        $this->seedBrandIcons();
    }

    /** The design gave each brand chip its own icon; only fills brands without one. */
    protected function seedBrandIcons(): void
    {
        $icons = [
            'هوجان' => 'fa-solid fa-motorcycle',
            'باجاج' => 'fa-solid fa-bolt',
            'دايو' => 'fa-solid fa-gauge-high',
            'بينيلي' => 'fa-solid fa-shield-halved',
            'كيواي' => 'fa-solid fa-compass',
            'وينج' => 'fa-solid fa-feather',
            'فيجوري' => 'fa-solid fa-fire',
            'تروسيك' => 'fa-solid fa-truck-pickup',
            'TVS' => 'fa-solid fa-star',
            'Electric' => 'fa-solid fa-charging-station',
            'Scooter' => 'fa-solid fa-wind',
        ];

        Brand::whereNull('web_icon')->get()->each(function (Brand $brand) use ($icons) {
            foreach ($icons as $needle => $icon) {
                if (mb_stripos($brand->name, $needle) !== false) {
                    $brand->update(['web_icon' => $icon]);

                    return;
                }
            }
        });
    }

    protected function seedTeam(): void
    {
        $members = [
            ['م. محمود الشريف', 'مدير المعرض والمبيعات'],
            ['كابتن طارق فؤاد', 'كبير مستشاري تجارب القيادة'],
            ['م. عمر مصطفى', 'رئيس قسم الصيانة والفحص'],
            ['أ. يوسف إبراهيم', 'أخصائي تمويل وتقسيط'],
            ['كابتن حسام حسن', 'استشاري تجهيزات الأمان'],
            ['م. سامح نبيل', 'كبير فنيي المحركات'],
            ['أ. سارة مجدي', 'خدمة العملاء والدعم'],
            ['أ. حازم عبد الله', 'مسؤول شؤون المرور والتراخيص'],
        ];

        foreach ($members as $i => [$name, $role]) {
            TeamMember::create([
                'name' => $name,
                'role' => $role,
                'image' => null, // the design's team photos are grey placeholders
                'show_on_about' => $i < 4,
                'is_active' => false,
                'sort' => $i + 1,
            ]);
        }
    }

    protected function seedReviews(): void
    {
        $reviews = [
            ['أحمد سمير', 'مالك دوكاتي V4', null, 'تجربة الشراء من موتوجيت كانت استثنائية. تعامل راقٍ، وضوح تام في الإجراءات، واستلمت الموتوسيكل في نفس اليوم مع كل الأوراق والضمان. أنصح بالتعامل معهم جداً.'],
            ['كريم ممدوح', 'مالك سكوتر فورزا', null, 'نظام التقسيط كان ميسر جداً وبدون تعقيدات، وموظف المبيعات ساعدني في اختيار السكوتر الأنسب لمشواري اليومي. الصيانة الدورية عندهم ممتازة وسريعة.'],
            ['محمد عادل', 'مالك بي إم دبليو GS', null, 'أفضل معرض موتوسيكلات في مصر بلا منازع! تنوع الموديلات وتوفير كل قطع الغيار الأصلية والإكسسوارات يعطيك راحة واطمئنان كاملين بعد الشراء.'],
            ['أحمد سليم', 'عميل معتمد • القاهرة', null, 'تجربتي مع موتوجيت كانت فوق الممتازة! اشتريت سكوتر بالتقسيط وإجراءات الموافقة تمت في أقل من نصف ساعة، واستلمت المكنة جاهزة بالترخيص والضمان. الصيانة عندهم محترفة جداً وفريق العمل أمين وخدوم لأقصى درجة.'],
        ];

        foreach ($reviews as $i => [$name, $position, $image, $review]) {
            ClientReview::create([
                'name' => $name,
                'position' => $position,
                'image' => null, // the design's testimonial photos are grey placeholders
                'review' => $review,
                'rating' => 5,
                'is_active' => false,
                'sort' => $i + 1,
            ]);
        }
    }

    protected function seedPosts(): void
    {
        $year = now()->year;

        $posts = [
            [
                'title' => 'دليلك الشامل لاختيار أول دراجة نارية رياضية (سوبر بايك)',
                'author' => 'فريق موتوجيت',
                'image' => 'motorcycles/bike-superbike.jpg',
                'date' => "{$year}-09-15",
                'tags' => ['دراجات نارية', 'سوبر بايك', 'نصائح القيادة', 'موتوجيت مصر'],
                'body' => '<p>يعد دخول عالم الدراجات النارية الرياضية (Superbikes) حلماً يراود الكثيرين، ولكن اتخاذ القرار الصحيح في البداية هو الفارق بين الاستمتاع بتجربة القيادة الآمنة وبين مواجهة تحديات غير متوقعة. في هذا المقال، يشاركك خبراء <strong>معرض موتوجيت (MotoGate)</strong> أهم المعايير لاختيار دراجتك الأولى.</p>'
                    . '<h3>1. تحديد سعة المحرك المناسبة لخبرتك (Engine CC)</h3>'
                    . '<p>إذا كنت في بداية مشوارك، ينصح الخبراء بالبدء بفئات المحركات الصغيرة أو المتوسطة (250cc إلى 400cc) مثل <strong>KTX 250</strong> أو <strong>ياماها R3</strong> أو <strong>كاواساكي نينجا 400</strong>. هذه الفئة تمنحك استجابة سريعة مع وزن خفيف يسهل التحكم فيه والمناورة في المنعطفات، قبل الترقية إلى وحوش الـ 600cc والـ 1000cc.</p>'
                    . '<h3>2. أنظمة الأمان الإلكترونية (Electronic Rider Aids)</h3>'
                    . '<p>تأكد من أن الدراجة النارية مزودة بنظام مانع انغلاق المكابح <strong>(ABS)</strong> الثنائي، ونظام التحكم في الجر <strong>(Traction Control)</strong>، وأوضاع القيادة المختلفة (Rain / Road / Sport). هذه الأنظمة تتدخل في أجزاء من الثانية لحمايتك من الانزلاق عند الضغط المفاجئ على الفرامل أو عند القيادة في ظروف الطقس الرطبة.</p>'
                    . '<blockquote>"السرعة والشغف متعة لا تضاهى، ولكن الأمان والتحكم هما الأساس الذي يضمن استمرارك في الاستمتاع بكل رحلة على الطريق." - كابتن طارق فؤاد، خبير تجارب القيادة في موتوجيت</blockquote>'
                    . '<h3>3. وضعية الجلوس والراحة الإيروديناميكية</h3>'
                    . '<p>تتميز دراجات السوبر بايك بوضعية جلوس مائلة للأمام لتقليل مقاومة الهواء، ولكن يجب تجربة الجلوس على الدراجة قبل الشراء للتأكد من راحة المعصمين والظهر ومدى وصول قدميك للأرض بثبات عند التوقف. نوفر في صالة عرض موتوجيت إمكانية تجربة كل موديل عملياً.</p>'
                    . '<h3>نصائح ذهبية قبل استلام الدراجة:</h3>'
                    . '<ul><li>شراء خوذة أمان معتمدة (ECE 22.06 أو DOT)</li><li>سترة حماية مبطنة بدرع للأكتاف والكوع والظهر</li><li>قفازات جلدية مصفحة لحماية المفاصل</li><li>فحص ضغط الإطارات ومستوى الزيت بانتظام</li><li>الالتزام بجدول الصيانة الدورية وتغيير الفلاتر</li><li>الاستفادة من برامج الضمان المعتمد لدى موتوجيت</li></ul>',
            ],
            [
                'title' => 'أهم 7 نصائح للحفاظ على محرك الدراجة النارية لأطول فترة',
                'author' => 'م. الصيانة',
                'image' => 'motorcycles/moto-service.jpg',
                'date' => "{$year}-09-10",
                'tags' => ['صيانة', 'محرك'],
                'body' => '<p>المحرك هو قلب دراجتك النارية، والعناية به بانتظام هي ما يحافظ على أدائه وقيمته لسنوات. في هذا المقال نجمع لك أهم النصائح التي يطبقها فريق الصيانة في موتوجيت يومياً.</p>',
            ],
            [
                'title' => 'مقارنة شاملة: هل السكوتر أفضل أم الموتوسيكل للمشاوير اليومية؟',
                'author' => 'خبير موتوجيت',
                'image' => 'motorcycles/bike-scooter.jpg',
                'date' => "{$year}-09-02",
                'tags' => ['سكوتر', 'مقارنة'],
                'body' => '<p>سؤال بيتكرر كتير في المعرض: أشتري سكوتر ولا موتوسيكل للمشاوير اليومية؟ الإجابة بتعتمد على طبيعة طريقك، المسافة، وميزانيتك. نستعرض هنا الفروق الأساسية بينهم.</p>',
            ],
            [
                'title' => 'معدات السلامة والخوذ: كيف تختار خوذة الأمان المعتمدة DOT؟',
                'author' => 'كابتن موتوجيت',
                'image' => 'motorcycles/bike-adventure.jpg',
                'date' => "{$year}-08-25",
                'tags' => ['سلامة', 'خوذة'],
                'body' => '<p>الخوذة هي أهم قطعة في معدات أي سائق دراجة نارية. نشرح لك معايير الاعتماد المختلفة وكيف تختار المقاس الصحيح.</p>',
            ],
            [
                'title' => 'ما هو نظام الكويك شفتر Quick Shifter وكيف يحسن أداء دراجتك؟',
                'author' => 'فريق التقنية',
                'image' => 'motorcycles/bike-streetfighter.jpg',
                'date' => "{$year}-08-18",
                'tags' => ['تقنية', 'أداء'],
                'body' => '<p>نظام الكويك شفتر بيسمح لك تغيّر السرعات من غير ما تضغط الدبرياج، وده بيفرق جداً في التسارع والراحة. نشرح لك إزاي بيشتغل ومين محتاجه.</p>',
            ],
            [
                'title' => 'مستقبل الدراجات النارية والسكوتر الكهربائي في مصر: أداء وتوفير',
                'author' => 'مستشار البيئة',
                'image' => 'motorcycles/bike-electric.jpg',
                'date' => "{$year}-08-12",
                'tags' => ['كهربائي', 'توفير'],
                'body' => '<p>السكوتر الكهربائي بقى اختيار حقيقي في مصر: مصاريف تشغيل أقل، صيانة أبسط، وصفر انبعاثات. نستعرض المميزات والتحديات وإيه اللي تسأل عنه قبل الشراء.</p>',
            ],
        ];

        foreach ($posts as $post) {
            $date = Carbon::parse($post['date']);

            Post::create([
                'title' => $post['title'],
                'author_name' => $post['author'],
                'cover_image' => 'site/defaults/' . $post['image'],
                'body' => $post['body'],
                'tags' => $post['tags'],
                'is_published' => true,
                'allow_comments' => true,
                'published_at' => $date->isFuture() ? $date->subYear() : $date,
            ]);
        }
    }
}
