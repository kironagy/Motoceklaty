<?php

namespace Tests\Feature\Agent\Rebuild;

use App\Agent\Context\ContextBuilder;
use App\Models\MessageMedia;
use App\Models\WhatsappConversation;
use App\Models\WhatsappMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** Rebuild CTX-005 (current turn = his messages only) and CTX-008 (every message type visible). */
class ContextMessagesTest extends TestCase
{
    use BuildsTurns;
    use RefreshDatabase;

    private function message(WhatsappConversation $conversation, array $attributes): WhatsappMessage
    {
        return WhatsappMessage::create(array_merge([
            'whatsapp_bot_id' => $conversation->whatsapp_bot_id,
            'whatsapp_conversation_id' => $conversation->id,
            'direction' => 'incoming', 'sender_type' => 'customer', 'type' => 'text',
            'wa_message_id' => uniqid('wa'),
        ], $attributes));
    }

    /** @return list<string> every text part the model sees, in order */
    private function texts(array $contents): array
    {
        // internal notes to the agent ("[ملاحظة داخلية ...]") are not customer text
        return collect($contents)->flatMap(fn ($c) => collect($c['parts'])->where('type', 'text')->pluck('text'))
            ->reject(fn ($t) => str_starts_with((string) $t, '[ملاحظة داخلية'))->values()->all();
    }

    public function test_a_replayed_finished_turn_does_not_show_the_bots_reply_as_customer_text(): void
    {
        $conversation = $this->conversation();
        $turn = $this->turnFor($conversation, 'بكام الهوجن 3؟');

        // The turn finished: the reply was sent with the same turn_id.
        $this->message($conversation, [
            'direction' => 'outgoing', 'sender_type' => 'bot', 'text' => 'الهوجن 3 كاش بـ 95,000', 'turn_id' => $turn->id,
        ]);

        // Retried (e.g. reclaimed after a crash): the context is rebuilt.
        $request = app(ContextBuilder::class)->build($turn);

        $this->assertCount(1, $request->contents);
        $this->assertSame('user', $request->contents[0]['role']);
        $this->assertSame(['بكام الهوجن 3؟'], $this->texts($request->contents));
    }

    public function test_a_location_in_the_current_turn_is_a_clear_line(): void
    {
        $conversation = $this->conversation();
        $turn = $this->turnFor($conversation);
        $this->message($conversation, [
            'type' => 'location', 'text' => null, 'turn_id' => $turn->id,
            'metadata' => ['location' => ['latitude' => 30.0444, 'longitude' => 31.2357, 'name' => 'ميدان التحرير', 'address' => 'وسط البلد']],
        ]);

        $texts = $this->texts(app(ContextBuilder::class)->build($turn)->contents);

        $this->assertSame(['[العميل بعت لوكيشن: 30.0444, 31.2357 - ميدان التحرير - وسط البلد]'], $texts);
    }

    public function test_a_location_in_history_is_not_media(): void
    {
        $conversation = $this->conversation();
        $this->message($conversation, [
            'type' => 'location', 'text' => null,
            'metadata' => ['location' => ['latitude' => 30.1, 'longitude' => 31.2, 'name' => null, 'address' => null]],
        ]);
        $turn = $this->turnFor($conversation, 'ده عنواني');

        $texts = $this->texts(app(ContextBuilder::class)->build($turn)->contents);

        $this->assertSame(['[العميل بعت لوكيشن: 30.1, 31.2]', 'ده عنواني'], $texts);
    }

    public function test_a_pdf_in_the_current_turn_has_its_media_id(): void
    {
        Storage::fake('local');
        $conversation = $this->conversation();
        $turn = $this->turnFor($conversation);
        $message = $this->message($conversation, ['type' => 'document', 'text' => 'مفردات المرتب', 'turn_id' => $turn->id]);
        $media = MessageMedia::create(['message_id' => $message->id, 'media_type' => 'document', 'mime' => 'application/pdf', 'disk' => 'local', 'path' => 'm/a.pdf', 'original_filename' => 'salary.pdf']);

        $request = app(ContextBuilder::class)->build($turn);
        $texts = $this->texts($request->contents);

        $this->assertStringContainsString("media_id: {$media->id}", $texts[0]);
        $this->assertStringContainsString('PDF salary.pdf', $texts[0]);
        $this->assertStringContainsString('process_document', $texts[0]);
        $this->assertSame('مفردات المرتب', $texts[1]);
        // A PDF is never sent as an image part.
        $this->assertSame([], collect($request->contents[0]['parts'])->where('type', 'inline_media')->all());
    }

    public function test_a_photo_sent_as_a_file_is_shown_like_a_photo(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('m/id.jpg', 'jpeg-bytes');
        $conversation = $this->conversation();
        $turn = $this->turnFor($conversation);
        $message = $this->message($conversation, ['type' => 'document', 'text' => null, 'turn_id' => $turn->id]);
        $media = MessageMedia::create(['message_id' => $message->id, 'media_type' => 'document', 'mime' => 'image/jpeg', 'disk' => 'local', 'path' => 'm/id.jpg']);

        $parts = app(ContextBuilder::class)->build($turn)->contents[0]['parts'];

        $this->assertSame("[صورة مرفقة - media_id: {$media->id}]", $parts[0]['text']);
        $this->assertSame('inline_media', $parts[1]['type']);
    }

    public function test_a_pdf_in_history_keeps_its_media_id(): void
    {
        $conversation = $this->conversation();
        $message = $this->message($conversation, ['type' => 'document', 'text' => null]);
        $media = MessageMedia::create(['message_id' => $message->id, 'media_type' => 'document', 'mime' => 'application/pdf', 'disk' => 'local', 'path' => 'm/a.pdf']);
        $turn = $this->turnFor($conversation, 'وصل؟');

        $texts = $this->texts(app(ContextBuilder::class)->build($turn)->contents);

        $this->assertSame("[ملف سابق - media_id: {$media->id}]", $texts[0]);
    }

    public function test_a_video_is_noted_in_the_current_turn_and_in_history(): void
    {
        $conversation = $this->conversation();
        $this->message($conversation, ['type' => 'video', 'text' => null]);
        $turn = $this->turnFor($conversation);
        $this->message($conversation, ['type' => 'video', 'text' => 'شوف الصوت', 'turn_id' => $turn->id]);

        $texts = $this->texts(app(ContextBuilder::class)->build($turn)->contents);

        $this->assertStringStartsWith('[العميل بعت فيديو', $texts[0]);
        $this->assertStringNotContainsString('[media]', $texts[0]);
        $this->assertStringStartsWith('[العميل بعت فيديو', $texts[1]);
        $this->assertSame('شوف الصوت', $texts[2]);
    }
}
