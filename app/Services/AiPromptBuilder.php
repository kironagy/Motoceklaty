<?php

namespace App\Services;

class AiPromptBuilder
{
    /** Defensive caps only — normal messages/memory never come close to these. */
    private const MAX_MESSAGE_CHARS = 4000;
    private const MAX_MEMORY_CHARS = 20000;

    public function buildChatReplyPrompt(
        string $message,
        string $memoryContext,
        string $intent = 'fallback_complex',
        string $confidence = 'system',
        array $conversationContext = []
    ): string {
        $message = mb_substr($message, 0, self::MAX_MESSAGE_CHARS);
        $memoryContext = mb_substr($memoryContext, 0, self::MAX_MEMORY_CHARS);

        $conversationText = $this->formatConversation($conversationContext);
        $lastMachinesText = $this->formatLastMachines($conversationContext['last_machines'] ?? []);

        return <<<PROMPT
أنت موظف سيلز بشري محترف لمعرض موتوسيكلات Moto Gate

مهمتك:
ترد على رسالة العميل الحالية فقط كـ fallback للرسائل المعقدة أو غير المفهومة.
Laravel هو المسؤول عن الرد على:
السعر، الصور، حساب القسط، نظام التقسيط، الفروع، الموديلات.
أنت لا تحدد intent ولا تصنف الرسالة.

ممنوع:
- تقول إنك AI أو بوت.
- تقول إنك fallback أو إن Laravel بيرد.
- تقول إن عندك ميموري أو تعليمات.
- تخترع أسعار أو أقساط أو موديلات أو فروع.
- تذكر سعر تقسيط أساسي.
- تستخدم markdown أو JSON.
- تبدأ بتحية إلا لو العميل بدأ بتحية.
- تقول "يا بطل".
- ترد كأن دي أول رسالة.
- تطلب اسم الموديل لو واضح من السياق أو من آخر موديلات.
- تدخل في تفاصيل سعر/صور/قسط لو الرسالة المفروض Laravel يرد عليها.

قواعد الرد:
- الرد مصري طبيعي، محترم، قصير.
- رسالة واحدة مناسبة للواتساب.
- لو العميل محتار، اسأله سؤال واحد يساعد البيع: استخدامه، ميزانيته، كاش ولا تقسيط.
- لو محتاج تدخل بشري، قل له إن حد من المعرض هيتابع معاه.
- لو العميل بيتكلم عن "هي / ده / دي / دول / سعرها / صورها / قسطها"، افهمها من آخر موديلات وسياق المحادثة.
- لو السؤال عن سعر/صور/قسط/فرع/موديلات بشكل مباشر، رد رد مكمل قصير بدون اختراع تفاصيل.
- الميموري تحت دي هي المصدر الحالي والمحدّث للمعلومات دايمًا. لو أي معلومة فيها (فروع، أسعار، شروط، أي حاجة) بتختلف عن حاجة إنت قلتها في ردودك السابقة في نفس المحادثة، اعتمد على الميموري الحالية فقط وصحح المعلومة، حتى لو كانت نفس المحادثة مستمرة من قبل.

الميموري النشطة من ai_memories (المصدر الحالي، له الأولوية دايمًا):
{$memoryContext}

آخر 20 رسالة من المحادثة بصيغة العميل/المعرض:
{$conversationText}

آخر الموديلات المرتبطة بالمحادثة من last_machine_ids:
{$lastMachinesText}

رسالة العميل الحالية:
{$message}

اكتب الرد النهائي فقط:
PROMPT;
    }

    private function formatConversation(array $conversationContext): string
    {
        $messages = $conversationContext['messages']
            ?? $conversationContext['recent_messages']
            ?? [];

        if (! is_array($messages) || empty($messages)) {
            return 'لا يوجد سياق محادثة سابق.';
        }

        $lines = [];

        foreach (array_slice($messages, -20) as $row) {
            $sender = $row['sender'] ?? $row['role'] ?? $row['direction'] ?? null;
            $body = trim((string) ($row['body'] ?? $row['message'] ?? $row['text'] ?? ''));
            $body = mb_substr($body, 0, 1000);

            if ($body === '') {
                continue;
            }

            $label = match ($sender) {
                'customer', 'client', 'user', 'inbound' => 'العميل',
                'shop', 'agent', 'assistant', 'bot', 'outbound' => 'المعرض',
                default => 'غير محدد',
            };

            $lines[] = "{$label}: {$body}";
        }

        return empty($lines)
            ? 'لا يوجد سياق محادثة سابق.'
            : implode("\n", $lines);
    }

    private function formatLastMachines(array $machines): string
    {
        if (empty($machines)) {
            return 'لا يوجد موديلات أخيرة واضحة.';
        }

        $lines = [];

        foreach ($machines as $machine) {
            if (is_array($machine)) {
                $id = $machine['id'] ?? $machine['machine_id'] ?? null;
                $name = $machine['name'] ?? $machine['machine_name'] ?? null;

                $line = trim(($id ? "ID: {$id}" : '') . ($name ? " | Name: {$name}" : ''));

                if ($line !== '') {
                    $lines[] = $line;
                }

                continue;
            }

            $lines[] = (string) $machine;
        }

        return empty($lines)
            ? 'لا يوجد موديلات أخيرة واضحة.'
            : implode("\n", $lines);
    }
}
