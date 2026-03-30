<?php

declare(strict_types=1);

namespace Core\Repository;

final class RepositoryContractGuard
{
    /**
     * @param list<string> $allowedAlternatives
     */
    public static function denyMixedSemanticsApi(string $method, array $allowedAlternatives = []): never
    {
        $msg = $method . '() is locked. Mixed-semantics generic repository verbs are not allowed on protected families.';
        if ($allowedAlternatives !== []) {
            $msg .= ' Use ' . implode(', ', $allowedAlternatives) . '.';
        }

        throw new \LogicException($msg);
    }
}
