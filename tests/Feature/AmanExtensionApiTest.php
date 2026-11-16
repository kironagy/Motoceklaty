<?php

namespace Tests\Feature;

use App\Domain\Documents\OcrProvider;
use App\Domain\Documents\OcrResult;
use App\Models\AmanRecipe;
use App\Models\AmanUpload;
use App\Models\Brand;
use App\Models\InstallmentRequest;
use App\Models\Machine;
use App\Models\Staff;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AmanExtensionApiTest extends TestCase
{
    use RefreshDatabase;

    private Staff $staff;
    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        $this->app->instance(OcrProvider::class, new class implements OcrProvider {
            public function extractText(string $b, string $m): OcrResult
            {
                return new OcrResult(base64_decode($b));
            }
        });

        $this->staff = Staff::create(['name' => 'شادي', 'email' => 's@x.com', 'password' => 'secret', 'is_admin' => true, 'is_super_admin' => false]);
        $this->token = \App\Models\AmanToken::issue($this->staff, 'test');
    }

    private function api(): self
    {
        return $this->withHeader('Authorization', 'Bearer '.$this->token);
    }

    private function request(array $over = []): InstallmentRequest
    {
        $brand = Brand::create(['name' => 'هوجان', 'image' => 'x.png']);
        $machine = Machine::create(['name' => 'L250', 'brand_id' => $brand->id, 'cash_price' => 55000, 'installment_price' => 59000, 'is_active' => true]);
        Storage::disk('public')->put('installments/f.jpg', "جمهورية مصر العربية\nبطاقة تحقيق الشخصية\nمحمد\nأحمد علي حسن\nش زكي\n29001011234567");
        Storage::disk('public')->put('installments/b.jpg', "ذكر مسلم متزوج");

        $r = InstallmentRequest::create(array_merge([
            'status' => 'new_request', 'installment_type' => InstallmentRequest::AMAAN_CAIRO, 'months' => 12,
            'machine_id' => $machine->id, 'machine_installment_price' => 59000, 'deposit' => 0,
            'applicant_name' => 'محمد أحمد علي حسن', 'applicant_phone' => '01060104481', 'applicant_national_id' => '29001011234567',
            'applicant_id_image' => 'installments/f.jpg', 'applicant_id_back_image' => 'installments/b.jpg',
            'work_status' => 'no_income_proof',
        ], $over));
        $r->forceFill(['staff_id' => $this->staff->id])->saveQuietly();

        return $r;
    }

    public function test_login_with_email_and_password_gives_a_working_token(): void
    {
        $this->postJson('/api/aman/login', ['email' => 's@x.com', 'password' => 'wrong'])->assertStatus(422);
        $token = $this->postJson('/api/aman/login', ['email' => 's@x.com', 'password' => 'secret'])->assertOk()->json('token');
        $this->withHeader('Authorization', 'Bearer '.$token)->getJson('/api/aman/me')->assertJsonPath('name', 'شادي');
    }

    public function test_wrong_key_is_refused(): void
    {
        $this->withHeader('Authorization', 'Bearer nope')->getJson('/api/aman/me')->assertStatus(401);
    }

    public function test_recipe_needs_a_save_gate(): void
    {
        $this->api()->putJson('/api/aman/recipes/no_income_proof', ['steps' => [['kind' => 'fill']]])->assertStatus(422);
        $this->api()->putJson('/api/aman/recipes/no_income_proof', ['steps' => [['kind' => 'click', 'gate' => true]]])->assertOk()->assertJson(['version' => 1]);
        $this->api()->putJson('/api/aman/recipes/no_income_proof', ['steps' => [['kind' => 'click', 'gate' => true]]])->assertJson(['version' => 2]);

        // The whole step is kept, not only `kind`.
        $step = ['kind' => 'fill', 'target' => ['id' => 'ctl00_n1'], 'source' => ['type' => 'card', 'key' => 'name_1']];
        $this->api()->putJson('/api/aman/recipes/no_income_proof', ['steps' => [$step, ['kind' => 'click', 'gate' => true]]])->assertOk();
        $this->assertSame($step, AmanRecipe::where('type', 'no_income_proof')->first()->steps[0]);
    }

    public function test_next_claims_once_reads_card_and_skips_finished(): void
    {
        AmanRecipe::create(['type' => 'no_income_proof', 'steps' => [['kind' => 'click', 'gate' => true]]]);
        $req = $this->request();

        $this->api()->getJson('/api/aman/queue')->assertJsonPath('items.0.id', $req->id)->assertJsonPath('items.0.has_recipe', true);
        $res = $this->api()->postJson('/api/aman/requests/'.$req->id.'/claim')->assertOk();
        $res->assertJsonPath('request_id', $req->id)
            ->assertJsonPath('card.name_1', 'محمد')
            ->assertJsonPath('card.name_4', 'حسن')
            ->assertJsonPath('card.gender', 'ذكر')
            ->assertJsonPath('card.governorate', 'القاهرة');
        $this->assertArrayNotHasKey('staff_id', $res->json('order'));

        // Same employee claiming again gets the same claim, not a second one.
        $this->api()->postJson('/api/aman/requests/'.$req->id.'/claim')->assertJsonPath('upload.id', $res->json('upload.id'));

        $this->get($res->json('order.applicant_id_image.0'), ['Authorization' => 'Bearer '.$this->token])->assertOk();

        // A run stopped for the employee keeps its claim.
        AmanUpload::first()->update(['claimed_at' => now()->subHour()]);
        $this->api()->postJson('/api/aman/uploads/'.$res->json('upload.id').'/alive')->assertOk();
        $this->assertTrue(AmanUpload::first()->claimed_at->gt(now()->subMinute()));

        $this->api()->postJson('/api/aman/uploads/'.$res->json('upload.id').'/result', ['status' => 'rejected_before', 'message' => 'مرفوض'])->assertOk();
        $this->assertSame('rejected_before', AmanUpload::first()->status);
        // Refused before at Aman: the request is rejected in motobot too (owner 2026-11-16).
        $this->assertSame('rejected', $req->fresh()->status);
        $this->assertSame('العميل عنده طلب مرفوض قبل كده عند أمان', $req->fresh()->checks_report['aman']);

        $this->api()->getJson('/api/aman/queue')->assertJsonCount(0, 'items');
        $this->api()->postJson('/api/aman/requests/'.$req->id.'/claim')->assertStatus(409);
    }

    public function test_uploaded_with_aman_number_goes_under_inquiry(): void
    {
        AmanRecipe::create(['type' => 'no_income_proof', 'steps' => [['kind' => 'click', 'gate' => true]]]);
        $req = $this->request();
        $id = $this->api()->postJson('/api/aman/requests/'.$req->id.'/claim')->json('upload.id');

        $this->api()->postJson('/api/aman/uploads/'.$id.'/result', [
            'status' => 'uploaded', 'aman_ref' => '26116832162', 'message' => 'تم تقديم الحد الائتمانى بنجاح و رقم العملية : 26116832162',
        ])->assertOk();

        $this->assertSame('pending', $req->fresh()->status);
        $this->assertSame('رقم طلب أمان: 26116832162 — تم تقديم الحد الائتمانى بنجاح و رقم العملية : 26116832162', $req->fresh()->checks_report['aman']);
    }

    public function test_queue_skips_other_statuses_and_non_aman_companies(): void
    {
        $this->request(['status' => 'pending']);
        $this->request(['installment_type' => 'ڤاليو']);
        $this->request(['request_type' => 'fake']);
        $this->api()->getJson('/api/aman/queue')->assertJsonCount(0, 'items');
    }
}
