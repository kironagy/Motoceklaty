<?php

namespace App\Observers;

use App\Agent\Tracing\Redactor;
use App\Models\RequirementField;

class RequirementFieldObserver
{
    public function saved(RequirementField $field): void
    {
        Redactor::invalidateSensitiveFieldKeysCache();
    }

    public function deleted(RequirementField $field): void
    {
        Redactor::invalidateSensitiveFieldKeysCache();
    }
}
