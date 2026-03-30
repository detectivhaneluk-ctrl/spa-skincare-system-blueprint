<?php

declare(strict_types=1);

namespace Core\Auth;

/**
 * Validates and lazily backfills {@see SessionAuth::SESSION_EPOCH_KEY} against {@see UserSessionEpochRepository}.
 */
final class SessionEpochCoordinator
{
    public function __construct(
        private UserSessionEpochRepository $epochs,
        private SessionAuth $sessionAuth,
    ) {
    }

    /**
     * @return true when the session remains valid for the authenticated user id
     */
    public function assertAuthenticatedSessionEpochValid(): bool
    {
        $uid = $this->sessionAuth->id();
        if ($uid === null || $uid <= 0) {
            return true;
        }
        $dbEpoch = $this->epochs->getSessionVersion($uid);
        if (!isset($_SESSION[SessionAuth::SESSION_EPOCH_KEY])) {
            $_SESSION[SessionAuth::SESSION_EPOCH_KEY] = $dbEpoch;

            return true;
        }
        $sess = $_SESSION[SessionAuth::SESSION_EPOCH_KEY];
        $invalid = (!is_int($sess) && !is_string($sess)) || (is_string($sess) && ($sess === '' || !ctype_digit($sess)));
        if ($invalid) {
            $_SESSION[SessionAuth::SESSION_EPOCH_KEY] = $dbEpoch;

            return true;
        }
        $sessInt = (int) $sess;

        return $sessInt >= $dbEpoch;
    }
}
