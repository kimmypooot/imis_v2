<?php

namespace App\Policies;

use App\Enums\PermissionName;
use App\Models\EmployeeDocument;
use App\Models\User;

/**
 * Opening or downloading one 201-file document.
 */
class EmployeeDocumentPolicy
{
    /**
     * The employee sees their own documents unless archived (an archived
     * one was wrong or outdated); HR (`employee_documents.view`) sees all.
     */
    public function view(User $user, EmployeeDocument $document): bool
    {
        if ($user->hasPermission(PermissionName::EmployeeDocumentsView->value)) {
            return true;
        }

        return ! $document->isArchived() && $document->employee()->value('user_id') === $user->id;
    }
}
