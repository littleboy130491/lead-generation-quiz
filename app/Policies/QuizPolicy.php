<?php

declare(strict_types=1);

namespace App\Policies;

use Illuminate\Foundation\Auth\User as AuthUser;
use App\Models\Quiz;
use Illuminate\Auth\Access\HandlesAuthorization;

class QuizPolicy
{
    use HandlesAuthorization;
    
    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:Quiz');
    }

    public function view(AuthUser $authUser, Quiz $quiz): bool
    {
        return $authUser->can('View:Quiz');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:Quiz');
    }

    public function update(AuthUser $authUser, Quiz $quiz): bool
    {
        return $authUser->can('Update:Quiz');
    }

    public function delete(AuthUser $authUser, Quiz $quiz): bool
    {
        return $authUser->can('Delete:Quiz');
    }

    public function deleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('DeleteAny:Quiz');
    }

    public function restore(AuthUser $authUser, Quiz $quiz): bool
    {
        return $authUser->can('Restore:Quiz');
    }

    public function forceDelete(AuthUser $authUser, Quiz $quiz): bool
    {
        return $authUser->can('ForceDelete:Quiz');
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('ForceDeleteAny:Quiz');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('RestoreAny:Quiz');
    }

    public function replicate(AuthUser $authUser, Quiz $quiz): bool
    {
        return $authUser->can('Replicate:Quiz');
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('Reorder:Quiz');
    }

}