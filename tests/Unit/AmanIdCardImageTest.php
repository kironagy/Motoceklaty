<?php

namespace Tests\Unit;

use App\Domain\Aman\IdCardImage;
use App\Domain\Aman\IdCardReader;
use App\Domain\Documents\OcrProvider;
use App\Support\EgyptianNationalId;
use PHPUnit\Framework\TestCase;

class AmanIdCardImageTest extends TestCase
{
    private function images(): IdCardImage
    {
        return new IdCardImage($this->createMock(OcrProvider::class));
    }

    private function word(array $v): array
    {
        return ['text' => 'كلمة', 'v' => $v];
    }

    public function test_sideways_card_turns_upright_and_is_cropped_to_card_shape(): void
    {
        // A 400 x 1000 portrait photo; the words read top-to-bottom (card turned clockwise).
        $img = imagecreatetruecolor(400, 1000);
        ob_start();
        imagejpeg($img);
        $bytes = ob_get_clean();

        $words = [];
        foreach ([100, 160, 220, 280] as $x) {
            // vertex 0 top-left as read, vertex 1 top-right as read: reading direction points down (+y).
            $words[] = $this->word([[$x + 40, 300], [$x + 40, 600], [$x, 600], [$x, 300]]);
        }

        $images = $this->images();
        $this->assertSame(90, $images->turn($words));

        $jpeg = $images->straighten($bytes, $words);
        $out = imagecreatefromstring($jpeg);
        $this->assertGreaterThan(imagesy($out), imagesx($out), 'card comes out landscape');
    }

    public function test_upright_words_need_no_turn(): void
    {
        $words = [$this->word([[10, 10], [90, 10], [90, 30], [10, 30]])];
        $this->assertSame(0, $this->images()->turn($words));
    }

    public function test_dropped_letters_in_card_name_are_taken_from_the_system(): void
    {
        $reader = new IdCardReader($this->createMock(OcrProvider::class), new EgyptianNationalId());
        $errors = [];
        $out = $reader->fixNameFromSystem(['name_1' => 'مد', 'name_2' => 'حسن', 'name_3' => 'محمد'], 'محمد حسين محمد احمد', $errors);

        $this->assertSame('محمد', $out['name_1']);
        $this->assertSame('حسن', $out['name_2'], 'one-letter difference keeps the card spelling');
        $this->assertCount(2, $errors);
    }
}
