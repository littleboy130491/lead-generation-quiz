<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class AdminRoleSeeder extends Seeder
{
    /** Create idempotent, least-privilege Filament roles. */
    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $subjects = ['Media', 'Quiz', 'Submission', 'User'];
        $actions = ['Create', 'Delete', 'DeleteAny', 'ForceDelete', 'ForceDeleteAny', 'Reorder', 'Replicate', 'Restore', 'RestoreAny', 'Update', 'View', 'ViewAny'];
        foreach ($subjects as $subject) {
            foreach ($actions as $action) {
                Permission::findOrCreate("{$action}:{$subject}");
            }
        }
        foreach (['ManageBrandingSettings', 'ManageReportEmailSettings', 'OperationalSettings'] as $page) {
            Permission::findOrCreate("View:{$page}");
        }

        $admin = Role::findOrCreate('admin');
        $admin->syncPermissions(Permission::query()->pluck('name')->all());

        $quizManager = Role::findOrCreate('quiz_manager');
        $quizManager->syncPermissions(Permission::query()->where('name', 'like', '%:Quiz')->pluck('name')->all());

        $submissionManager = Role::findOrCreate('submission_manager');
        $submissionManager->syncPermissions(Permission::query()->where('name', 'like', '%:Submission')->pluck('name')->all());
    }
}
