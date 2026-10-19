<?php

namespace App\Models;

use App\Domain\Forms\FormSchema;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A published, frozen copy of a form: a link keeps the version it was sent with. */
class DynamicFormVersion extends Model
{
    protected $fillable = ['dynamic_form_id', 'version', 'schema', 'published_by'];

    protected $casts = ['schema' => 'array'];

    public function form(): BelongsTo
    {
        return $this->belongsTo(DynamicForm::class, 'dynamic_form_id');
    }

    public function formSchema(): FormSchema
    {
        return new FormSchema($this->schema ?? []);
    }
}
