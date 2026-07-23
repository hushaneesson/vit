<?php

declare(strict_types=1);

namespace App\Providers;

use App\Models\CatalogSubmission;
use App\Policies\CatalogSubmissionPolicy;
use Illuminate\Foundation\Support\Providers\AuthServiceProvider as ServiceProvider;

class AuthServiceProvider extends ServiceProvider
{
    protected $policies = [
        CatalogSubmission::class => CatalogSubmissionPolicy::class,
    ];

    public function boot(): void
    {
        $this->registerPolicies();
    }
}
