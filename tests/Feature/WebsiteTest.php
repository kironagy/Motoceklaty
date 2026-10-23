<?php

namespace Tests\Feature;

use App\Filament\Clusters\WebsiteSettings\Pages\AboutSettings;
use App\Filament\Clusters\WebsiteSettings\Pages\BlogSettings;
use App\Filament\Clusters\WebsiteSettings\Pages\ContactSettings;
use App\Filament\Clusters\WebsiteSettings\Pages\GeneralSettings;
use App\Filament\Clusters\WebsiteSettings\Pages\HomeSettings;
use App\Filament\Clusters\WebsiteSettings\Pages\InstallmentSettings;
use App\Filament\Clusters\WebsiteSettings\Pages\ShowroomSettings;
use App\Filament\Clusters\WebsiteSettings\Pages\TeamSettings;
use App\Filament\Clusters\WebsiteSettings\Resources\ContactMessageResource\Pages\ManageContactMessages;
use App\Filament\Clusters\WebsiteSettings\Resources\NewsletterSubscriberResource\Pages\ManageNewsletterSubscribers;
use App\Filament\Clusters\WebsiteSettings\Resources\PostCommentResource\Pages\ManagePostComments;
use App\Filament\Clusters\WebsiteSettings\Resources\PostResource\Pages\CreatePost;
use App\Filament\Clusters\WebsiteSettings\Resources\PostResource\Pages\ListPosts;
use App\Filament\Clusters\WebsiteSettings\Resources\TeamMemberResource\Pages\ManageTeamMembers;
use App\Models\Brand;
use App\Models\ContactMessage;
use App\Models\InstallmentPlan;
use App\Models\InstallmentSystem;
use App\Models\Machine;
use App\Models\NewsletterSubscriber;
use App\Models\Post;
use App\Models\PostComment;
use App\Models\Staff;
use App\Support\SiteContent;
use Database\Seeders\WebsiteContentSeeder;
use App\Domain\Documents\OcrException;
use App\Domain\Documents\OcrProvider;
use App\Domain\Documents\OcrResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The MotoGate website (resources/views/site) and its "Website Settings"
 * cluster in the dashboard.
 */
class WebsiteTest extends TestCase
{
    use RefreshDatabase;

    protected Machine $machine;

    protected InstallmentSystem $system;

    protected function setUp(): void
    {
        parent::setUp();

        SiteContent::flush();
        $this->seed(WebsiteContentSeeder::class);

        $brand = Brand::create(['name' => 'هوجان', 'image' => 'x.png']);
        $this->machine = Machine::create([
            'name' => 'F250', 'brand_id' => $brand->id, 'category' => 'sport', 'cc' => 250,
            'cash_price' => 55000, 'installment_price' => 60000, 'is_active' => true,
            'features' => [['title' => '24 حصان']], 'specifications' => ['التبريد' => 'مياه'],
        ]);
        $this->system = InstallmentSystem::create([
            'name' => 'امان', 'plans' => [['months' => 12, 'interest' => 20]],
            'administrative_fees' => 7, 'is_active' => true,
        ]);
        InstallmentPlan::updateOrCreate(
            ['installment_system_id' => $this->system->id, 'months' => 12],
            ['interest_percent' => 20, 'is_active' => true],
        );
        $this->machine->installmentSystems()->syncWithoutDetaching([$this->system->id]);
    }

    public function test_every_public_page_renders(): void
    {
        $post = Post::published()->first();

        foreach (['/', '/about', '/machines', '/machines?category=sport', '/machines/' . $this->machine->id,
            '/blog', '/blog/' . rawurlencode($post->slug), '/contact', '/installment', '/apply-installment'] as $url) {
            $this->get($url)->assertOk();
        }
        // The approved design has no team page; its team lives on the about page.
        $this->get('/team')->assertRedirect(route('about.index') . '#team');

        $this->get('/machines/' . $this->machine->id)->assertSee('F250')->assertSee('55,000');
    }

    public function test_calculator_uses_the_domain_formula(): void
    {
        $this->postJson(route('installment.calculate'), [
            'machine_id' => $this->machine->id,
            'installment_system_id' => $this->system->id,
            'months' => 12,
        ])->assertOk()->assertJson(['ok' => true, 'result' => ['down_payment' => 0]]);

        $this->postJson(route('installment.calculate'), [
            'machine_id' => $this->machine->id,
            'installment_system_id' => $this->system->id,
            'months' => 12,
            'down_payment' => 5000,
        ])->assertOk()->assertJson(['ok' => true, 'result' => [
            'monthly_installment' => 5500,
            'total_with_interest' => 66000,
            'administrative_fees' => 3850,
        ]]);

        $this->postJson(route('installment.calculate'), [
            'machine_id' => $this->machine->id,
            'installment_system_id' => $this->system->id,
            'months' => 12,
            'down_payment' => 60000,
        ])->assertJson(['ok' => false]);
    }

    public function test_visitor_forms_land_in_the_dashboard_tables(): void
    {
        $this->post(route('contact.store'), ['name' => 'أحمد', 'phone' => '01000000000', 'message' => 'عايز أعرف السعر'])
            ->assertRedirect();
        $this->post(route('newsletter.store'), ['email' => 'A@B.com'])->assertRedirect();
        $post = Post::published()->first();
        $this->post(route('blog.comment', $post), ['name' => 'كريم', 'email' => 'k@x.com', 'body' => 'مقال مفيد'])
            ->assertRedirect();

        $this->assertDatabaseHas('contact_messages', ['name' => 'أحمد', 'is_read' => false]);
        $this->assertDatabaseHas('newsletter_subscribers', ['email' => 'a@b.com']);
        $this->assertDatabaseHas('post_comments', ['name' => 'كريم', 'is_approved' => false]);

        // Unapproved comments stay off the article.
        $this->get(route('blog.show', $post))->assertDontSee('مقال مفيد');
        PostComment::query()->update(['is_approved' => true]);
        // The approved article design has no comments section; approval remains available to the view/backend.
        $this->get(route('blog.show', $post))->assertViewHas('comments', fn ($comments) => $comments->contains('body', 'مقال مفيد'));
    }

    public function test_every_settings_page_loads_saves_and_drives_the_site(): void
    {
        $this->actingAs($this->admin(), 'filament');

        foreach ([GeneralSettings::class, HomeSettings::class, AboutSettings::class, ShowroomSettings::class,
            InstallmentSettings::class, BlogSettings::class, TeamSettings::class, ContactSettings::class] as $page) {
            Livewire::test($page)->assertOk()->call('save')->assertHasNoFormErrors();
        }

        // Saving untouched forms must not change what the site shows -
        // images, repeaters and nested lists come back exactly as designed.
        $this->get('/')->assertSee(site('home.hero.calc_title'));
        $defaults = \App\Support\SiteDefaults::all();
        foreach (['general', 'home', 'about', 'showroom', 'installment', 'blog', 'team', 'contact'] as $group) {
            $this->assertEquals($defaults[$group], SiteContent::group($group), "{$group} drifted after an untouched save");
        }

        Livewire::test(HomeSettings::class)
            ->set('data.hero.calc_title', 'عنوان جديد للحاسبة')
            ->set('data.explore.enabled', false)
            ->call('save')
            ->assertHasNoFormErrors();

        $this->get('/')
            ->assertSee('عنوان جديد للحاسبة')
            ->assertDontSee('twm-explore-section-wrap', false);

        Livewire::test(GeneralSettings::class)
            ->set('data.phone', '0111 222 3333')
            ->call('save');

        $this->get('/contact')->assertSee('0111 222 3333')->assertSee('tel:01112223333', false);
    }

    public function test_content_resources_render(): void
    {
        $this->actingAs($this->admin(), 'filament');
        ContactMessage::create(['name' => 'x', 'phone' => '1', 'message' => 'y']);
        NewsletterSubscriber::create(['email' => 'x@y.com']);

        foreach ([ListPosts::class, CreatePost::class, ManageTeamMembers::class, ManagePostComments::class,
            ManageContactMessages::class, ManageNewsletterSubscribers::class] as $page) {
            Livewire::test($page)->assertOk();
        }

        // Opening a message in the dashboard marks it read.
        $message = ContactMessage::first();
        Livewire::test(ManageContactMessages::class)->mountTableAction('view', $message)->assertHasNoErrors();
        $this->assertTrue($message->fresh()->is_read);

        // Posts are edited by id in the dashboard even though the site uses slugs.
        $post = Post::first();
        $this->assertStringEndsWith('/posts/' . $post->id . '/edit',
            \App\Filament\Clusters\WebsiteSettings\Resources\PostResource::getUrl('edit', ['record' => $post]));
        Livewire::test(\App\Filament\Clusters\WebsiteSettings\Resources\PostResource\Pages\EditPost::class, ['record' => $post->id])
            ->assertOk()
            ->set('data.title', 'عنوان معدّل')
            ->call('save')
            ->assertHasNoFormErrors();
        $this->get(route('blog.show', $post->fresh()))->assertSee('عنوان معدّل');
    }

    public function test_installment_application_is_saved_even_when_ocr_is_unavailable(): void
    {
        Storage::fake('public');
        $this->app->bind(OcrProvider::class, fn () => new class implements OcrProvider {
            public function extractText(string $base64Image, string $mime): OcrResult
            {
                throw new OcrException('OCR_UNAVAILABLE: no key');
            }
        });

        $this->post(route('installments.store'), $this->application())
            ->assertRedirect()
            ->assertSessionHas('success')->assertSessionHas('tracking_code');

        $request = \App\Models\InstallmentRequest::latest('id')->first();
        $this->assertSame('أحمد محمد علي', $request->applicant_name);
        $this->assertStringContainsString('مراجعتها يدوياً', $request->checks_report);
    }

    public function test_installment_application_reads_the_national_id_when_ocr_works(): void
    {
        Storage::fake('public');
        $this->app->bind(OcrProvider::class, fn () => new class implements OcrProvider {
            public function extractText(string $base64Image, string $mime): OcrResult
            {
                return new OcrResult("جمهورية مصر العربية\nأحمد محمد علي\n29501011234567");
            }
        });

        $this->post(route('installments.store'), $this->application())->assertSessionHas('success');

        $this->assertSame('29501011234567', \App\Models\InstallmentRequest::latest('id')->first()->applicant_national_id);
    }

    protected function application(): array
    {
        return [
            'machine_id' => $this->machine->id,
            'installment_type' => 'امان',
            'months' => 12,
            'applicant_name' => 'أحمد محمد علي',
            'applicant_phone' => '0100' . random_int(1000000, 9999999),
            'applicant_address' => 'القاهرة',
            'applicant_id_front' => UploadedFile::fake()->image('front.jpg'),
            'applicant_id_back' => UploadedFile::fake()->image('back.jpg'),
            'work_status' => 'no_income_proof',
            'free_work_name' => 'كهربائي',
        ];
    }

    public function test_showroom_groups_models_into_slides_and_searches(): void
    {
        $brand = Brand::create(['name' => 'بينيلي', 'image' => 'x.png']);
        foreach (range(1, 14) as $i) {
            Machine::create(['name' => "TNT {$i}", 'brand_id' => $brand->id, 'cash_price' => 50000, 'installment_price' => 55000, 'is_active' => true]);
        }
        Machine::create(['name' => 'مخفية', 'brand_id' => $brand->id, 'cash_price' => 1, 'installment_price' => 1, 'is_active' => false]);

        // 14 + F250 from setUp = 15 active machines.
        $page1 = $this->get('/machines')->assertOk()->assertViewHas('machines', fn ($machines) => $machines->count() === 15);
        $this->assertSame(15, substr_count($page1->getContent(), 'data-live-machine='));
        $this->assertSame(2, substr_count($page1->getContent(), 'models-grid models-slide'));
        $filtered = $this->get('/machines?q=F250')->assertViewHas('machines', fn ($machines) => $machines->count() === 1);
        $this->assertSame(1, substr_count($filtered->getContent(), 'data-live-machine='));
        foreach (['/machines?q=' . urlencode('بينيلي'), '/machines?brand=' . $brand->id . '&q=TNT', '/machines?brand_id=' . $brand->id] as $url) {
            $this->get($url)->assertViewHas('machines', fn ($machines) => $machines->count() === 14);
        }
        $this->get('/machines?q=' . urlencode('مخفية'))->assertViewHas('machines', fn ($machines) => $machines->isEmpty());
    }

    public function test_article_page_renders_the_post_body(): void
    {
        $post = Post::create(['title' => 'دليل الاختيار', 'slug' => 'choose-guide', 'author_name' => 'موتوجيت', 'excerpt' => 'مقدمة', 'body' => '<h2>سعة المحرك</h2><p>اختار السعة المناسبة.</p>', 'is_published' => true]);

        $this->get(route('blog.show', $post))->assertOk()
            ->assertSee('<section id="part-0"><h2>سعة المحرك</h2><p>اختار السعة المناسبة.</p></section>', false)
            ->assertSee('<a href="#part-0">سعة المحرك</a>', false)
            ->assertDontSee('$post->body', false);
    }

    public function test_header_search_lists_only_active_models(): void
    {
        $brand = Brand::create(['name' => 'بينيلي', 'image' => 'x.png']);
        $visible = Machine::create(['name' => 'TNT 25', 'brand_id' => $brand->id, 'cash_price' => 1, 'installment_price' => 1, 'is_active' => true]);
        Machine::create(['name' => 'مخفية', 'brand_id' => $brand->id, 'cash_price' => 1, 'installment_price' => 1, 'is_active' => false]);

        $html = $this->get('/')->assertOk()->assertSee('id="searchModal"', false)->getContent();
        preg_match('/<script type="application\/json" id="searchMachines">(.*?)<\/script>/s', $html, $match);
        $items = collect(json_decode($match[1] ?? '[]', true));

        $this->assertTrue($items->contains(fn ($item) => $item['label'] === 'بينيلي TNT 25' && $item['url'] === route('machines.show', $visible)));
        $this->assertFalse($items->contains(fn ($item) => str_contains($item['label'], 'مخفية')));
    }

    public function test_new_design_uses_real_content_and_has_no_demo_runtime(): void
    {
        $this->get('/')->assertOk()->assertSee('motogate-v2/assets/css/style.css', false)
            ->assertSee('data-installment-calculator', false)
            ->assertDontSee('motogate-v2/assets/js/main.js', false)
            ->assertDontSee('machines.html', false);
        $this->get('/machines')->assertOk()->assertSee('F250')
            ->assertDontSee('MG-2026-001')->assertDontSee('89,000');
        $this->get('/contact')->assertSee('name="message"', false)
            ->assertSee('action="' . route('contact.store') . '"', false);
        $this->get('/about')->assertDontSee('عميل تجريبي')->assertDontSee('بيانات تجريبية');
    }

    public function test_tracking_accepts_order_numbers_and_encrypted_codes_without_personal_details(): void
    {
        $application = \App\Models\InstallmentRequest::create([
            'machine_id' => $this->machine->id, 'installment_type' => 'امان', 'months' => 12,
            'applicant_name' => 'Private Customer', 'applicant_phone' => '01012345678',
            'applicant_address' => 'Private address', 'status' => 'new_request',
        ]);
        $code = \Illuminate\Support\Facades\Crypt::encryptString((string) $application->id);
        $this->get('/track')->assertOk()->assertSee('رقم الطلب أو كود المتابعة');
        $this->post('/track', ['code' => 'MG-2026-001'])->assertOk()->assertSee(site('tracking.not_found'));
        $this->post('/track', ['code' => (string) $application->id])->assertSee(site('tracking.statuses.new_request'))->assertDontSee('Private Customer')->assertDontSee('01012345678');
        $arabicNumber = strtr((string) $application->id, ['0'=>'٠','1'=>'١','2'=>'٢','3'=>'٣','4'=>'٤','5'=>'٥','6'=>'٦','7'=>'٧','8'=>'٨','9'=>'٩']);
        $this->post('/track', ['code' => $arabicNumber])->assertSee(site('tracking.statuses.new_request'));
        $this->get(route('tracking.index', ['code' => $code]))->assertOk()
            ->assertSee(site('tracking.statuses.new_request'))->assertDontSee('Private Customer')
            ->assertDontSee('01012345678')->assertDontSee('Private address')
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow')
            ->assertDontSee('embed.tawk.to');
        $application->update(['status' => 'approved']);
        $this->post('/track', ['code' => $code])->assertSee(site('tracking.statuses.approved'));
        $application->delete();
        $this->post('/track', ['code' => $code])->assertSee(site('tracking.not_found'));
    }

    public function test_new_dashboard_fields_drive_the_new_design(): void
    {
        $this->actingAs($this->admin(), 'filament');
        Livewire::test(HomeSettings::class)->set('data.design.hero.text_03', 'وصف جديد للتصميم')
            ->set('data.fleet.enabled', false)->call('save')->assertHasNoFormErrors();
        $this->get('/')->assertSee('وصف جديد للتصميم')->assertDontSee('stock-track', false);
        Livewire::test(AboutSettings::class)->set('data.faq.title', 'أسئلة محدثة')
            ->call('save')->assertHasNoFormErrors();
        $this->get('/about')->assertSee('أسئلة محدثة');
        Livewire::test(\App\Filament\Clusters\WebsiteSettings\Pages\TrackingSettings::class)
            ->set('data.title', 'متابعة طلبك من الداشبورد')->call('save')->assertHasNoFormErrors();
        $this->get('/track')->assertSee('متابعة طلبك من الداشبورد');
    }

    public function test_design_adoption_keeps_custom_content_and_can_be_repeated(): void
    {
        SiteContent::save('home', ['hero' => ['small_title' => 'عنوان مخصص', 'bg_image' => 'site/defaults/slider1.jpg']]);
        $this->artisan('site:adopt-motogate-v2')->assertSuccessful();
        $this->assertSame('عنوان مخصص', site('home.hero.small_title'));
        $this->assertSame('site/v2-defaults/الباكجراوند.png', site('home.hero.bg_image'));
        $before = SiteContent::all();
        $this->artisan('site:adopt-motogate-v2')->assertSuccessful();
        $this->assertSame($before, SiteContent::all());
    }

    public function test_home_keeps_only_the_approved_source_sections_and_styles(): void
    {
        $html = $this->get('/')->assertOk()->getContent();
        $dom = new \DOMDocument();
        @$dom->loadHTML('<?xml encoding="UTF-8">' . $html);
        $xpath = new \DOMXPath($dom);
        $classes = [];
        foreach ($xpath->query('//main[@class="home-content"]/section') as $section) {
            $classes[] = $section->getAttribute('class');
        }
        $this->assertSame(['installment framed', 'story-section', 'available-section', 'journal', 'why-choose', 'categories'], $classes);
        foreach (json_decode(file_get_contents(resource_path('site/motogate-v2-css-hashes.json')), true) as $file => $hash) {
            $this->assertSame($hash, hash_file('sha256', public_path("motogate-v2/assets/css/{$file}.css")));
        }
    }

    public function test_calculator_configuration_survives_html_attribute_encoding(): void
    {
        foreach (['/', '/installment', '/machines/' . $this->machine->id] as $url) {
            $dom = new \DOMDocument();
            @$dom->loadHTML('<?xml encoding="UTF-8">' . $this->get($url)->assertOk()->getContent());
            $xpath = new \DOMXPath($dom);
            $forms = $xpath->query('//form[@data-installment-calculator]');
            $this->assertGreaterThan(0, $forms->length, $url);
            foreach ($forms as $form) {
                $config = json_decode($form->getAttribute('data-config'), true, 512, JSON_THROW_ON_ERROR);
                $this->assertSame(route('installment.calculate'), $config['endpoint']);
                $this->assertNotEmpty($config['labels']);
            }
        }
    }

    public function test_application_rejects_unavailable_terms_models_and_invalid_deposits_before_uploading(): void
    {
        Storage::fake('public');
        $data = $this->application();
        $this->post(route('installments.store'), array_replace($data, ['months' => 999]))
            ->assertSessionHasErrors('installment_type');
        $this->post(route('installments.store'), array_replace($data, ['deposit' => 55000]))
            ->assertSessionHasErrors('deposit');
        $this->machine->update(['is_active' => false]);
        $this->post(route('installments.store'), $data)->assertSessionHasErrors('installment_type');
        $this->assertDatabaseCount('installment_requests', 0);
        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    protected function admin(): Staff
    {
        return Staff::create([
            'name' => 'Admin', 'email' => uniqid() . '@x.com', 'password' => 'secret',
            'is_admin' => true, 'is_super_admin' => true, 'is_hitler' => true,
        ]);
    }
}
