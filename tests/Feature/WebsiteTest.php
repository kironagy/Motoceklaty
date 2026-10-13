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
            '/blog', '/blog/' . rawurlencode($post->slug), '/team', '/contact', '/installment', '/apply-installment'] as $url) {
            $this->get($url)->assertOk();
        }

        $this->get('/machines/' . $this->machine->id)->assertSee('F250')->assertSee('55,000');
    }

    public function test_calculator_uses_the_domain_formula(): void
    {
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
        $this->get(route('blog.show', $post))->assertSee('مقال مفيد');
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
        $this->get('/')->assertSee('احسب قسط دراجتك النارية بكل سهولة');
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
            ->assertSessionHas('success');

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

    public function test_showroom_paginates_by_twelve_and_searches(): void
    {
        $brand = Brand::create(['name' => 'بينيلي', 'image' => 'x.png']);
        foreach (range(1, 14) as $i) {
            Machine::create(['name' => "TNT {$i}", 'brand_id' => $brand->id, 'cash_price' => 50000, 'installment_price' => 55000, 'is_active' => true]);
        }
        Machine::create(['name' => 'مخفية', 'brand_id' => $brand->id, 'cash_price' => 1, 'installment_price' => 1, 'is_active' => false]);

        // 14 + F250 from setUp = 15 active machines.
        $page1 = $this->get('/machines')->assertOk()->assertSee('عرض 1 - 12 من 15 موديل');
        $this->assertSame(12, substr_count($page1->getContent(), 'class="mg-card"'));
        $this->assertSame(3, substr_count($this->get('/machines?page=2')->getContent(), 'class="mg-card"'));

        $this->get('/machines?q=F250')->assertSee('عرض 1 - 1 من 1 موديل')->assertDontSee('TNT 3');
        $this->get('/machines?q=' . urlencode('بينيلي'))->assertSee('من 14 موديل');           // by brand name
        $this->get('/machines?brand=' . $brand->id . '&q=TNT')->assertSee('من 14 موديل');
        $this->get('/machines?brand_id=' . $brand->id)->assertSee('من 14 موديل');               // old site links
        $this->get('/machines?q=' . urlencode('مخفية'))->assertSee('عرض 0 - 0 من 0 موديل');    // inactive never shows
    }

    protected function admin(): Staff
    {
        return Staff::create([
            'name' => 'Admin', 'email' => uniqid() . '@x.com', 'password' => 'secret',
            'is_admin' => true, 'is_super_admin' => true, 'is_hitler' => true,
        ]);
    }
}
