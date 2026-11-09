<?php

namespace Tests\Feature\Agent;

use App\Models\Branch;
use App\Support\BranchListReply;
use App\Support\InstallmentLayout;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Owner 2026-11-09: cut branch links and run-on installment paragraphs. */
class ReplyLayoutTest extends TestCase
{
    use RefreshDatabase;

    private function branches(): void
    {
        foreach ([
            ['فرع العمرانية', 'giza', 'العمرانية الغربية', '٢٨ ش ترعة الزمر، بجوار مستشفى الصدر، العمرانية الغربية', 'https://maps.app.goo.gl/5b2YAaAbzdf8mam48'],
            ['فرع جسر السويس', 'cairo', 'جسر السويس', 'ش متحف المطرية، متفرع من منشية التحرير، أمام بنك مصر', 'https://maps.app.goo.gl/kfC22wfnDA9Esec76'],
            ['فرع عين شمس', 'cairo', 'عين شمس', 'عين شمس، ش الزهراء مع تقاطع شارع العشرين، أمام مدرسة الخنساء', 'https://maps.app.goo.gl/hyyoCboo2ccQfDBz7'],
            ['فرع الخصوص', 'qalyubia', 'الخصوص', 'ش الخصوص العمومي، ميدان الديب، أسفل مول أبو سعده', 'https://maps.app.goo.gl/C2mnEtZGKQSvPt376'],
        ] as $i => [$name, $gov, $city, $address, $url]) {
            Branch::create(['name' => $name, 'governorate' => $gov, 'city' => $city, 'address' => $address, 'map_url' => $url, 'is_active' => true, 'sort' => $i]);
        }
    }

    public function test_a_branch_list_with_cut_links_is_rebuilt_in_full(): void
    {
        $this->branches();
        $reply = 'يا باشا فروعنا العمرانية: ٢٨ ش ترعة الزمر، بجوار مستشفى الصدر، العمرانية الغربية، https://maps. جسر السويس: ش متحف المطرية، https://maps. عين شمس: ش الزهراء، https://maps. الخصوص: ش الخصوص العمومي، https://maps. تحب تيجي أنهي فرع؟';

        $out = BranchListReply::repair($reply);

        foreach (Branch::all() as $branch) {
            $this->assertStringContainsString($branch->map_url, $out);
            $this->assertStringContainsString($branch->address, $out);
        }
        $this->assertStringContainsString('عندنا 4 فروع:', $out);
        $this->assertStringContainsString('تحب تيجي أنهي فرع؟', $out);
        $this->assertDoesNotMatchRegularExpression('#https://maps\.(?:\s|$)#', $out);
    }

    public function test_a_branch_named_without_a_lookup_is_left_alone(): void
    {
        $this->branches();
        $reply = 'المكنة متاحة في فرع عين شمس وجسر السويس.';

        $this->assertSame($reply, BranchListReply::repair($reply, false));
        $this->assertSame('تمام يا باشا.', BranchListReply::repair('تمام يا باشا.'));
    }

    public function test_installment_numbers_are_laid_out_line_by_line(): void
    {
        $reply = "تمام يا باشا\nF250: سنة مقدم 18,000 وقسط 6,050، سنة ونص 4,383، سنتين 3,550، و٣ سنين 2,717. المصاريف الإدارية 4,200 وقت الاستلام. تختار أنهي مدة؟";

        $out = InstallmentLayout::format($reply);

        $this->assertStringContainsString("*F250*\n• سنة", $out);
        $this->assertStringContainsString("\n• سنة ونص 4,383\n• سنتين 3,550\n• ٣ سنين 2,717\nالمصاريف الإدارية 4,200", $out);
        $this->assertStringEndsWith("\nتختار أنهي مدة؟", $out);
    }

    public function test_text_without_an_installment_list_is_unchanged(): void
    {
        foreach (['سعرها كاش 85,000 جنيه. على سنة القسط 6,050.', "• سنة: 6,050\n• سنتين: 3,550"] as $text) {
            $this->assertSame($text, InstallmentLayout::format($text));
        }
    }
}
