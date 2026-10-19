<?php

namespace App\Domain\Forms;

use App\Models\DynamicForm;
use App\Models\DynamicFormVersion;
use App\Models\FormLink;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Publishing and previewing a form from the builder. */
class FormPublisher
{
    /** @return string[] what stops it; [] when published */
    public function publish(DynamicForm $form, ?int $staffId): array
    {
        $schema = new FormSchema($form->schema ?? []);
        $problems = $schema->publishProblems();

        if ($problems !== []) {
            return $problems;
        }

        DB::transaction(function () use ($form, $staffId) {
            $version = DynamicFormVersion::create([
                'dynamic_form_id' => $form->id,
                'version' => (int) $form->versions()->max('version') + 1,
                'schema' => $form->schema,
                'published_by' => $staffId,
            ]);

            $form->update(['published_version_id' => $version->id]);
        });

        return [];
    }

    /** The working copy as the customer will see it, on a throwaway link (version 0) that cannot be sent. */
    public function preview(DynamicForm $form, ?int $staffId): FormLink
    {
        $version = DynamicFormVersion::updateOrCreate(
            ['dynamic_form_id' => $form->id, 'version' => 0],
            ['schema' => $form->schema ?? [], 'published_by' => $staffId],
        );

        FormLink::where('dynamic_form_version_id', $version->id)->delete();

        return FormLink::create([
            'token' => Str::random(40),
            'dynamic_form_version_id' => $version->id,
            'created_by_type' => 'staff',
            'created_by_staff_id' => $staffId,
            'status' => 'sent',
            'purpose' => 'preview',
            'expires_at' => now()->addDay(),
        ]);
    }

    /** Only one form is the bot's. */
    public function keepOneBotDefault(DynamicForm $form): void
    {
        if ($form->is_bot_default) {
            DynamicForm::whereKeyNot($form->id)->where('is_bot_default', true)->update(['is_bot_default' => false]);
        }
    }
}
