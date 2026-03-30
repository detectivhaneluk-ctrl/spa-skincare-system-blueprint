<?php

declare(strict_types=1);

/**
 * SESSION-BOOTSTRAP-SECURITY-UNIFICATION-01 — static guard: no raw session_start() outside canonical bootstrap.
 *
 * Canonical session open + cookie configuration: {@see \Core\Auth\SessionAuth}.
 *
 * From repo root:
 *   php system/scripts/read-only/verify_session_bootstrap_no_raw_session_start_readonly_01.php
 *
 * Exit: 0 = pass, 1 = fail.
 */

/**
 * @return list<int> 1-based line numbers of unqualified session_start( calls
 */
function session_bootstrap_violation_lines(string $phpSource): array
{
    $tokens = token_get_all($phpSource);
    $lines = [];
    $n = count($tokens);
    for ($i = 0; $i < $n; $i++) {
        if (!is_array($tokens[$i])) {
            continue;
        }
        if ($tokens[$i][0] !== T_STRING || $tokens[$i][1] !== 'session_start') {
            continue;
        }
        $j = $i + 1;
        while ($j < $n && is_array($tokens[$j]) && $tokens[$j][0] === T_WHITESPACE) {
            ++$j;
        }
        if ($j < $n && $tokens[$j] === '(') {
            $lines[] = (int) $tokens[$i][2];
        }
    }

    return $lines;
}

$repoRoot = realpath(dirname(__DIR__, 3));
if ($repoRoot === false) {
    fwrite(STDERR, "FAILED: could not resolve repo root\n");
    exit(1);
}

$allowedReal = realpath($repoRoot . '/system/core/auth/SessionAuth.php');
if ($allowedReal === false) {
    fwrite(STDERR, "FAILED: canonical SessionAuth.php not found\n");
    exit(1);
}

$excludeDirNames = ['vendor', 'node_modules', '.git', '.cursor'];

$rii = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($repoRoot, FilesystemIterator::SKIP_DOTS)
);

$violations = [];

/** @var SplFileInfo $file */
foreach ($rii as $file) {
    if (!$file->isFile() || strtolower($file->getExtension()) !== 'php') {
        continue;
    }
    $path = $file->getPathname();
    $parts = explode(DIRECTORY_SEPARATOR, $path);
    foreach ($excludeDirNames as $ex) {
        if (in_array($ex, $parts, true)) {
            continue 2;
        }
    }
    $real = realpath($path);
    if ($real === false || $real === $allowedReal) {
        continue;
    }
    $src = (string) file_get_contents($path);
    $hitLines = session_bootstrap_violation_lines($src);
    if ($hitLines !== []) {
        $violations[] = $real . ' (lines: ' . implode(', ', $hitLines) . ')';
    }
}

if ($violations !== []) {
    fwrite(STDERR, "FAILED: raw session_start() outside system/core/auth/SessionAuth.php:\n");
    foreach ($violations as $v) {
        fwrite(STDERR, '  - ' . $v . PHP_EOL);
    }
    exit(1);
}

echo 'Session bootstrap check passed: session_start() only in canonical SessionAuth.' . PHP_EOL;
exit(0);
