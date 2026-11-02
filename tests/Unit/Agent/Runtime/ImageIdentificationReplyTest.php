<?php

namespace Tests\Unit\Agent\Runtime;

use App\Agent\Runtime\ImageIdentificationReply;
use PHPUnit\Framework\TestCase;

class ImageIdentificationReplyTest extends TestCase
{
    public function test_similar_image_is_not_claimed_as_an_exact_match(): void
    {
        $reply = (new ImageIdentificationReply)->for(['data' => [
            'band' => 'similar',
            'candidates' => [['name' => 'VLR200', 'confidence' => .82]],
        ]]);

        $this->assertSame(['messages' => ['الصورة أقرب لـ VLR200، بس الاسم مش ظاهر بوضوح عشان أأكد الموديل. ابعتلي صورة للاسم أو اللوجو.']], $reply);
    }

    public function test_readable_logo_with_high_confidence_is_named_directly(): void
    {
        $reply = (new ImageIdentificationReply)->for(['data' => [
            'band' => 'similar',
            'identified_by' => 'visible_name_or_logo',
            'candidates' => [['name' => 'Tx 250', 'confidence' => .86]],
            'vehicle_count' => 3,
        ]]);

        $this->assertSame(['messages' => ['دي Tx 250، والموديل موجود في الكتالوج. التوفر الفعلي بيأكدّه قسم الكاش.']], $reply);
    }
}
