<?php

namespace App\Agent\Runtime;

use App\Models\Machine;
use App\Models\WhatsappMessage;
use App\Support\ArabicTextNormalizer;

/** Customer choices, including references to models we actually showed. */
class MotorcycleSelection
{
    public function confirms(int $conversationId, int $machineId): bool
    {
        return $this->lastNamed($conversationId) === $machineId;
    }

    public function lastDuration(int $conversationId): ?int
    {
        $months = $this->lastDurations($conversationId);
        return count($months) === 1 ? $months[0] : null;
    }

    public function lastDurations(int $conversationId): array
    {
        $messages = $this->history($conversationId);
        foreach ($messages as $i => $message) {
            if ($message->direction !== 'incoming') continue;
            $text = ArabicTextNormalizer::normalize(trim(($message->text ?? '').' '.($message->transcript ?? '')));
            $previous = ArabicTextNormalizer::normalize($this->previousReply($messages, $i, false));
            // A bare number is a duration only after a duration question, never an age or catalog id.
            if (preg_match('/^(?:علي\s*|على\s*)?(1|2|3|12|18|24|36)[.!؟?\s]*$/u', $text, $m)
                && preg_match('/كام سنه|تحب.*(?:القسط|التقسيط).*(?:على|علي)|سنه.*سنتين/u', $previous)) {
                return [(int) $m[1] <= 3 ? (int) $m[1] * 12 : (int) $m[1]];
            }
            if (! preg_match('/قسط|تقسيط|علي|على|(?:سنه|سنتين).*(?:كام|هيكون|بكام)|^(?:طب\s*)?(?:سنه(?: ونص)?|سنتين|3 سنين)[؟?\s]*$/u', $text)) continue;
            $months = app(RequestedDurations::class)->months($text);
            if ($months !== []) return $months;
        }
        return [];
    }

    public function lastNamed(int $conversationId): ?int
    {
        return $this->resolveModel($this->history($conversationId));
    }

    /** Messages in newest-first order; also used for read-only incident replay. */
    public function resolveModel(array $messages): ?int
    {
        $catalog = app(CatalogMentions::class);
        foreach ($messages as $i => $message) {
            if ($message->direction !== 'incoming') continue;
            $text = trim(($message->text ?? '').' '.($message->transcript ?? ''));
            $ids = $catalog->fullNames($text);
            if ($ids === []) {
                $normalized = $catalog->normalize($text);
                $previous = $this->previousReply($messages, $i);
                $discussed = $catalog->fullNames($previous);
                if (preg_match('/(?<!\p{L})(?:ال)?لي(?!\p{L})|دي|ده|عادي|اصلي|فرز/u', $normalized)) {
                    if (preg_match('/(\d[\d,٬]*)\s*(الف|جنيه|ج)?/u', $normalized, $price)
                        && (preg_match('/(?<!\p{L})(?:ال)?لي(?!\p{L})/u', $normalized) || isset($price[2]))) {
                        $amount = (float) str_replace([',', '٬'], '', $price[1]);
                        if (($price[2] ?? '') === 'الف') $amount *= 1000;
                        $ids = Machine::whereIn('id', $discussed)->get()->filter(fn ($m) =>
                            (float) ($m->type === 'offer' && $m->new_price ? $m->new_price : $m->cash_price) === $amount)->pluck('id')->all();
                    } elseif (preg_match('/عادي|محلي|فرز تاني|فرز ثاني|اصلي/u', $normalized)) {
                        $ids = Machine::whereIn('id', $discussed)->get()->filter(function ($m) use ($normalized, $catalog) {
                            $name = $catalog->normalize($m->name);
                            if (str_contains($normalized, 'عادي') || str_contains($normalized, 'محلي')) return ! preg_match('/استيراد|فرز|اصلي/u', $name);
                            return str_contains($normalized, 'اصلي') ? str_contains($name, 'اصلي') : str_contains($name, 'فرز');
                        })->pluck('id')->all();
                    } elseif (count($discussed) === 1 && preg_match('/عايز|عاوز|هاخد|احسب|قسط|تقسيط/u', $normalized)) {
                        $ids = $discussed;
                    }
                }
            }
            if ($ids === []) {
                if (preg_match('/استيراد|فرز|اصلي|أصلي/u', $text) && $catalog->words($text) !== []) return null;
                continue;
            }
            if (preg_match('/قارن|الفرق بين/u', $text)) return null;
            if (preg_match('/مش عايز|مش عاوز/u', $text) && ! app(ConversationIntent::class)->refusesApplication($text)) return null;
            if (preg_match('/استيراد|فرز|اصلي|أصلي/u', $text)) {
                $normalized = $catalog->normalize($text);
                $ids = Machine::whereIn('id', $ids)->get()->filter(function ($m) use ($normalized, $catalog) {
                    $name = $catalog->normalize($m->name);
                    foreach (['استيراد', 'فرز', 'اصلي'] as $variant) if (str_contains($normalized, $variant) && ! str_contains($name, $variant)) return false;
                    return true;
                })->pluck('id')->all();
            }
            return count($ids) === 1 ? (int) $ids[0] : null;
        }
        return null;
    }

    private function history(int $conversationId): array
    {
        return WhatsappMessage::where('whatsapp_conversation_id', $conversationId)->latest('id')->limit(120)->get(['direction', 'text', 'transcript'])->all();
    }

    private function previousReply(array $messages, int $offset, bool $withModels = true): string
    {
        $replies = [];
        for ($i = $offset + 1; $i < count($messages) && count($replies) < 8; $i++) {
            if ($messages[$i]->direction === 'outgoing') $replies[] = (string) $messages[$i]->text;
        }
        if ($withModels) foreach ($replies as $reply) if (app(CatalogMentions::class)->fullNames($reply) !== []) return $reply;
        return $replies[0] ?? '';
    }
}
