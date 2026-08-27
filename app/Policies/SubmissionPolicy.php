<?php

declare(strict_types=1);

namespace App\Policies;

use Illuminate\Foundation\Auth\User as AuthUser;
use App\Models\Submission;
use Illuminate\Auth\Access\HandlesAuthorization;

class SubmissionPolicy
{
    use HandlesAuthorization;
    
    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:Submission');
    }

    public function view(AuthUser $authUser, Submission $submission): bool
    {
        return $authUser->can('View:Submission');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:Submission');
    }

    public function update(AuthUser $authUser, Submission $submission): bool
    {
        return $authUser->can('Update:Submission');
    }

    public function delete(AuthUser $authUser, Submission $submission): bool
    {
        return $authUser->can('Delete:Submission');
    }

    public function deleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('DeleteAny:Submission');
    }

    public function restore(AuthUser $authUser, Submission $submission): bool
    {
        return $authUser->can('Restore:Submission');
    }

    public function forceDelete(AuthUser $authUser, Submission $submission): bool
    {
        return $authUser->can('ForceDelete:Submission');
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('ForceDeleteAny:Submission');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('RestoreAny:Submission');
    }

    public function replicate(AuthUser $authUser, Submission $submission): bool
    {
        return $authUser->can('Replicate:Submission');
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('Reorder:Submission');
    }

}