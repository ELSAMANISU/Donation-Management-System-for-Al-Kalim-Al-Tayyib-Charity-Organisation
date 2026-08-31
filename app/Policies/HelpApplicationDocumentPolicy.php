<?php

namespace App\Policies;

use App\Models\HelpApplicationDocument;
use App\Models\User;

class HelpApplicationDocumentPolicy
{
    public function delete(User $actor, HelpApplicationDocument $document): bool
    {
        return $document->removed_at === null
            && $document->application !== null
            && $document->application->applicant_id === $actor->getKey()
            && $actor->can('update', $document->application);
    }
}
