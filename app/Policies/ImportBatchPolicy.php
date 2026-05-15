<?php

namespace App\Policies;

use App\Models\ImportBatch;
use App\Models\User;

class ImportBatchPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->canPerform('import-data') || $user->canPerform('manage-settings');
    }

    public function view(User $user, ImportBatch $importBatch): bool
    {
        return $this->viewAny($user) && $this->sameOrganization($user, $importBatch) && $this->sameBranch($user, $importBatch);
    }

    public function create(User $user): bool
    {
        return $user->canPerform('import-data') || $user->canPerform('manage-settings');
    }

    public function update(User $user, ImportBatch $importBatch): bool
    {
        return $this->view($user, $importBatch) && $importBatch->isEditable();
    }

    public function delete(User $user, ImportBatch $importBatch): bool
    {
        return false;
    }

    public function commit(User $user, ImportBatch $importBatch): bool
    {
        return $this->view($user, $importBatch) && $importBatch->isEditable();
    }

    public function rollback(User $user, ImportBatch $importBatch): bool
    {
        return $this->view($user, $importBatch) && $importBatch->status === 'imported';
    }

    private function sameOrganization(User $user, ImportBatch $importBatch): bool
    {
        return $user->organization_id === null
            || $importBatch->organization_id === null
            || (int) $user->organization_id === (int) $importBatch->organization_id;
    }

    private function sameBranch(User $user, ImportBatch $importBatch): bool
    {
        return $user->branch_id === null
            || $importBatch->branch_id === null
            || (int) $user->branch_id === (int) $importBatch->branch_id;
    }
}
