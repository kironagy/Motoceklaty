<?php

namespace Tests\Unit;

use App\Domain\Aman\SmartTransport;
use App\Domain\Documents\OcrProvider;
use App\Models\InstallmentRequest;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AmanSmartTransportTest extends TestCase
{
    private function transport(): SmartTransport
    {
        return new SmartTransport($this->createMock(OcrProvider::class));
    }

    private function jpeg(int $w, int $h): string
    {
        $img = imagecreatetruecolor($w, $h);
        ob_start();
        imagejpeg($img);

        return ob_get_clean();
    }

    public function test_work_name_tells_a_smart_transport_driver(): void
    {
        Storage::fake('public');
        $t = $this->transport();
        foreach (['شغال في نقل الزكي', 'سائق اوبر', ' شغال اوبر واندريفر ودي دي', 'طلبات', 'InDrive'] as $name) {
            $r = new InstallmentRequest(['work_status' => 'no_income_proof', 'free_work_name' => $name]);
            $this->assertTrue($t->is($r), $name);
        }
        $r = new InstallmentRequest(['work_status' => 'no_income_proof', 'free_work_name' => 'نقاش', 'free_income_proof_images' => []]);
        $this->assertFalse($t->is($r));
        $r = new InstallmentRequest(['work_status' => 'employee', 'free_work_name' => 'اوبر']);
        $this->assertFalse($t->is($r), 'only the free income type');
    }

    public function test_photos_are_merged_side_by_side_at_one_height(): void
    {
        $two = imagecreatefromstring($this->transport()->merge([$this->jpeg(720, 1280), $this->jpeg(1280, 720)]));
        $this->assertGreaterThan(imagesy($two), imagesx($two), 'one row');
        $this->assertLessThanOrEqual(2400, imagesx($two));

        $four = imagecreatefromstring($this->transport()->merge(array_fill(0, 4, $this->jpeg(720, 1280))));
        $this->assertGreaterThan(imagesx($four), imagesy($four), 'two rows of two phone screenshots');
        $this->assertLessThanOrEqual(2400, imagesx($four));
    }
}
