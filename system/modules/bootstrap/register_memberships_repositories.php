<?php

declare(strict_types=1);

$container->singleton(\Modules\Memberships\Repositories\MembershipDefinitionRepository::class, fn ($c) => new \Modules\Memberships\Repositories\MembershipDefinitionRepository($c->get(\Core\App\Database::class), $c->get(\Core\Organization\OrganizationRepositoryScope::class)));
$container->singleton(\Modules\Memberships\Repositories\ClientMembershipRepository::class, fn ($c) => new \Modules\Memberships\Repositories\ClientMembershipRepository($c->get(\Core\App\Database::class), $c->get(\Core\Organization\OrganizationRepositoryScope::class)));
$container->singleton(\Modules\Memberships\Repositories\MembershipBenefitUsageRepository::class, fn ($c) => new \Modules\Memberships\Repositories\MembershipBenefitUsageRepository($c->get(\Core\App\Database::class)));

