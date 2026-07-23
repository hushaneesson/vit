<?php

declare(strict_types=1);

namespace App\Policies;

use Illuminate\Foundation\Auth\User as AuthUser;
use App\Models\CatalogSubmission;
use Illuminate\Auth\Access\HandlesAuthorization;

class CatalogSubmissionPolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:CatalogSubmission');
    }

    public function view(AuthUser $authUser, CatalogSubmission $catalogSubmission): bool
    {
        return $authUser->can('View:CatalogSubmission');
    }

    public function approve(AuthUser $authUser, CatalogSubmission $catalogSubmission): bool
    {
        return $authUser->can('Approve:CatalogSubmission');
    }

    public function reject(AuthUser $authUser, CatalogSubmission $catalogSubmission): bool
    {
        return $authUser->can('Reject:CatalogSubmission');
    }

    public function confirmUpload(AuthUser $authUser, CatalogSubmission $catalogSubmission): bool
    {
        return $authUser->can('Upload:CatalogSubmission');
    }
}
