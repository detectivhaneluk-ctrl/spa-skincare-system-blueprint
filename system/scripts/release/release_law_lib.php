<?php

declare(strict_types=1);

final class ReleaseLawException extends RuntimeException
{
}

final class ReleaseLawCommandResult
{
    public function __construct(
        public readonly array $command,
        public readonly int $exitCode,
        public readonly string $stdout,
        public readonly string $stderr,
        public readonly float $durationSeconds
    ) {
    }

    public function combinedOutput(): string
    {
        $parts = [];
        if ($this->stdout !== '') {
            $parts[] = trim($this->stdout);
        }
        if ($this->stderr !== '') {
            $parts[] = trim($this->stderr);
        }

        return trim(implode("\n", $parts));
    }

    public function toCheck(string $id, string $label): array
    {
        return [
            'id' => $id,
            'label' => $label,
            'status' => $this->exitCode === 0 ? 'passed' : 'failed',
            'command' => implode(' ', $this->command),
            'exit_code' => $this->exitCode,
            'duration_seconds' => round($this->durationSeconds, 3),
            'stdout' => trim($this->stdout),
            'stderr' => trim($this->stderr),
        ];
    }
}

final class ReleaseLawShell
{
    /**
     * @param list<string> $command
     * @param array<string, string> $extraEnv
     */
    public static function run(array $command, string $cwd, array $extraEnv = []): ReleaseLawCommandResult
    {
        $descriptorSpec = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $escaped = implode(' ', array_map('escapeshellarg', $command));
        $start = microtime(true);

        // Inherit the real process environment (null). A merged env map can drop variables that
        // downstream tools rely on (observed: PDO MySQL to GitHub Actions service containers).
        $saved = [];
        foreach ($extraEnv as $key => $value) {
            $saved[$key] = getenv($key);
            putenv($key . '=' . $value);
        }

        try {
            $process = proc_open($escaped, $descriptorSpec, $pipes, $cwd, null);

            if (!is_resource($process)) {
                throw new ReleaseLawException('Unable to start command: ' . $escaped);
            }

            fclose($pipes[0]);
            $stdout = stream_get_contents($pipes[1]);
            $stderr = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $exitCode = proc_close($process);

            return new ReleaseLawCommandResult(
                $command,
                $exitCode,
                $stdout === false ? '' : $stdout,
                $stderr === false ? '' : $stderr,
                microtime(true) - $start
            );
        } finally {
            foreach ($extraEnv as $key => $_) {
                $prior = $saved[$key];
                if ($prior === false) {
                    putenv($key);
                } else {
                    putenv($key . '=' . $prior);
                }
            }
        }
    }
}

final class ReleaseLawPaths
{
    public static function normalizeRelative(string $path): string
    {
        $path = str_replace('\\', '/', $path);
        $path = preg_replace('~^\./+~', '', $path) ?? $path;
        $path = trim($path, '/');

        return $path;
    }

    public static function normalizeForCompare(string $path): string
    {
        return strtolower(self::normalizeRelative($path));
    }

    public static function join(string ...$parts): string
    {
        $clean = [];
        foreach ($parts as $part) {
            if ($part === '') {
                continue;
            }
            $clean[] = trim(str_replace('\\', '/', $part), '/');
        }

        return implode('/', $clean);
    }

    public static function resolveFilesystemCase(string $repoRoot, string $relativePath): ?string
    {
        $relativePath = self::normalizeRelative($relativePath);
        if ($relativePath === '') {
            return '';
        }

        $segments = explode('/', $relativePath);
        $current = rtrim(str_replace('\\', '/', $repoRoot), '/');
        $resolved = [];

        foreach ($segments as $segment) {
            if (!is_dir($current)) {
                return null;
            }

            $entries = scandir($current);
            if ($entries === false) {
                return null;
            }

            $matched = null;
            foreach ($entries as $entry) {
                if (strcasecmp($entry, $segment) === 0) {
                    $matched = $entry;
                    break;
                }
            }

            if ($matched === null) {
                return null;
            }

            $resolved[] = $matched;
            $current .= '/' . $matched;
        }

        return implode('/', $resolved);
    }
}

final class ReleaseLawGit
{
    /**
     * @param list<string> $arguments
     * @return list<string>
     */
    public static function paths(string $repoRoot, array $arguments): array
    {
        $result = ReleaseLawShell::run(array_merge(['git'], $arguments), $repoRoot);
        if ($result->exitCode !== 0) {
            throw new ReleaseLawException("Git command failed:\n" . $result->combinedOutput());
        }

        $raw = $result->stdout;
        $items = $raw === '' ? [] : explode("\0", rtrim($raw, "\0"));

        return array_values(array_filter(array_map(
            static fn (string $path): string => ReleaseLawPaths::normalizeRelative($path),
            $items
        )));
    }
}

final class ReleaseLawComposer
{
    /**
     * @return array{raw:array<string, mixed>, psr4:array<string, string>}
     */
    public static function load(string $repoRoot): array
    {
        $composerPath = $repoRoot . '/composer.json';
        if (!is_file($composerPath)) {
            throw new ReleaseLawException('Missing composer.json at repo root.');
        }

        $decoded = json_decode((string) file_get_contents($composerPath), true);
        if (!is_array($decoded)) {
            throw new ReleaseLawException('composer.json is not valid JSON.');
        }

        $autoload = $decoded['autoload'] ?? null;
        $psr4 = [];
        if (is_array($autoload) && isset($autoload['psr-4']) && is_array($autoload['psr-4'])) {
            foreach ($autoload['psr-4'] as $prefix => $dir) {
                if (!is_string($prefix) || !is_string($dir)) {
                    continue;
                }
                $psr4[$prefix] = ReleaseLawPaths::normalizeRelative($dir);
            }
        }

        return [
            'raw' => $decoded,
            'psr4' => $psr4,
        ];
    }

    /**
     * @return array{ok:bool, contradictions:list<array<string, mixed>>, required_prefixes:list<string>}
     */
    public static function validateSettings(string $repoRoot): array
    {
        $composer = self::load($repoRoot);
        $psr4 = $composer['psr4'];
        $requiredPrefixes = [
            'Core\\App\\',
            'Core\\Tenant\\',
            'Modules\\Organizations\\Services\\',
            'Modules\\Sales\\Services\\',
        ];

        $contradictions = [];
        foreach ($requiredPrefixes as $prefix) {
            if (!isset($psr4[$prefix])) {
                $contradictions[] = [
                    'type' => 'composer_missing_prefix',
                    'namespace_prefix' => $prefix,
                    'expected_path' => null,
                    'real_path' => null,
                    'offending_path' => 'composer.json',
                    'message' => "composer.json autoload.psr-4 is missing required prefix {$prefix}.",
                ];
                continue;
            }

            $resolved = ReleaseLawPaths::resolveFilesystemCase($repoRoot, $psr4[$prefix]);
            if ($resolved === null) {
                $contradictions[] = [
                    'type' => 'composer_missing_directory',
                    'namespace_prefix' => $prefix,
                    'expected_path' => $psr4[$prefix],
                    'real_path' => null,
                    'offending_path' => $psr4[$prefix],
                    'message' => "Autoload base directory for {$prefix} does not exist.",
                ];
                continue;
            }

            if ($resolved !== $psr4[$prefix]) {
                $contradictions[] = [
                    'type' => 'composer_base_dir_case_mismatch',
                    'namespace_prefix' => $prefix,
                    'expected_path' => $psr4[$prefix],
                    'real_path' => $resolved,
                    'offending_path' => $psr4[$prefix],
                    'message' => "Autoload base directory case mismatch for {$prefix}.",
                ];
            }
        }

        return [
            'ok' => $contradictions === [],
            'contradictions' => $contradictions,
            'required_prefixes' => $requiredPrefixes,
        ];
    }

    /**
     * @param array<string, string> $psr4
     * @return array{prefix:string, expected_relative_path:string}|null
     */
    public static function resolveExpectedPath(string $fqcn, array $psr4): ?array
    {
        $matchedPrefix = null;
        $matchedBaseDir = null;
        foreach ($psr4 as $prefix => $baseDir) {
            if (!str_starts_with($fqcn, $prefix)) {
                continue;
            }
            if ($matchedPrefix === null || strlen($prefix) > strlen($matchedPrefix)) {
                $matchedPrefix = $prefix;
                $matchedBaseDir = $baseDir;
            }
        }

        if ($matchedPrefix === null || $matchedBaseDir === null) {
            return null;
        }

        $relativeClass = substr($fqcn, strlen($matchedPrefix));
        $expectedPath = ReleaseLawPaths::join(
            $matchedBaseDir,
            str_replace('\\', '/', $relativeClass) . '.php'
        );

        return [
            'prefix' => $matchedPrefix,
            'expected_relative_path' => $expectedPath,
        ];
    }
}

final class ReleaseLawPhpScanner
{
    /**
     * @return list<array{fqcn:string, namespace:string, short_name:string}>
     */
    public static function extractClasses(string $filePath): array
    {
        $source = (string) file_get_contents($filePath);
        $tokens = token_get_all($source);
        $namespace = '';
        $classes = [];
        $count = count($tokens);

        for ($i = 0; $i < $count; $i++) {
            $token = $tokens[$i];
            if (!is_array($token)) {
                continue;
            }

            if ($token[0] === T_NAMESPACE) {
                $namespace = self::readNamespace($tokens, $i + 1);
                continue;
            }

            if (!in_array($token[0], [T_CLASS, T_INTERFACE, T_TRAIT, T_ENUM], true)) {
                continue;
            }

            $previous = self::previousSignificantToken($tokens, $i);
            if (is_array($previous) && $previous[0] === T_NEW) {
                continue;
            }

            $shortName = self::readIdentifier($tokens, $i + 1);
            if ($shortName === null) {
                continue;
            }

            $fqcn = $namespace !== '' ? $namespace . '\\' . $shortName : $shortName;
            $classes[] = [
                'fqcn' => $fqcn,
                'namespace' => $namespace,
                'short_name' => $shortName,
            ];
        }

        return $classes;
    }

    private static function readNamespace(array $tokens, int $startIndex): string
    {
        $parts = [];
        $count = count($tokens);

        for ($i = $startIndex; $i < $count; $i++) {
            $token = $tokens[$i];
            if (is_string($token)) {
                if ($token === ';' || $token === '{') {
                    break;
                }
                continue;
            }

            if (in_array($token[0], [T_STRING, T_NAME_QUALIFIED, T_NS_SEPARATOR], true)) {
                $parts[] = $token[1];
            }
        }

        return trim(implode('', $parts), '\\');
    }

    private static function readIdentifier(array $tokens, int $startIndex): ?string
    {
        $count = count($tokens);
        for ($i = $startIndex; $i < $count; $i++) {
            $token = $tokens[$i];
            if (is_array($token) && $token[0] === T_STRING) {
                return $token[1];
            }
            if (is_string($token) && trim($token) !== '') {
                return null;
            }
        }

        return null;
    }

    private static function previousSignificantToken(array $tokens, int $index): array|string|null
    {
        for ($i = $index - 1; $i >= 0; $i--) {
            $token = $tokens[$i];
            if (is_string($token)) {
                if (trim($token) === '') {
                    continue;
                }
                return $token;
            }

            if (in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT, T_FINAL, T_ABSTRACT], true)) {
                continue;
            }

            return $token;
        }

        return null;
    }
}

final class ReleaseLawCasePathAuditor
{
    /**
     * @param list<string> $selectedRelativePaths
     * @return array{
     *   ok:bool,
     *   checked_class_count:int,
     *   contradictions:list<array<string, mixed>>,
     *   critical_family_checked:list<string>
     * }
     */
    public static function audit(string $repoRoot, array $selectedRelativePaths = []): array
    {
        $composer = ReleaseLawComposer::load($repoRoot);
        $psr4 = $composer['psr4'];
        $gitAvailable = is_dir($repoRoot . '/.git') || is_file($repoRoot . '/.git');
        $trackedPaths = $gitAvailable ? ReleaseLawGit::paths($repoRoot, ['ls-files', '-z']) : [];
        $candidatePaths = $gitAvailable ? ReleaseLawGit::paths($repoRoot, ['ls-files', '-z', '--cached', '--others', '--exclude-standard']) : [];
        $trackedLookup = [];
        foreach ($trackedPaths as $trackedPath) {
            $trackedLookup[ReleaseLawPaths::normalizeForCompare($trackedPath)][] = $trackedPath;
        }

        $duplicates = $gitAvailable ? self::findCaseDuplicates($candidatePaths) : [];
        $selectedLookup = [];
        foreach ($selectedRelativePaths as $path) {
            $selectedLookup[ReleaseLawPaths::normalizeForCompare($path)] = true;
        }

        $phpFiles = self::collectOwnedPhpFiles($repoRoot);
        $contradictions = [];
        $classCount = 0;
        $criticalPrefixes = ['Core\\Tenant\\'];
        $criticalFound = array_fill_keys($criticalPrefixes, false);

        foreach ($duplicates as $paths) {
            $contradictions[] = [
                'type' => 'duplicate_logical_path_case',
                'namespace_prefix' => null,
                'expected_path' => null,
                'real_path' => null,
                'offending_path' => implode(' | ', $paths),
                'message' => 'Logical duplicate paths differ only by case.',
                'paths' => $paths,
            ];
        }

        foreach ($phpFiles as $relativePath) {
            $normalizedPath = ReleaseLawPaths::normalizeForCompare($relativePath);
            if ($selectedLookup !== [] && !isset($selectedLookup[$normalizedPath])) {
                continue;
            }

            $absolutePath = $repoRoot . '/' . $relativePath;
            $classes = ReleaseLawPhpScanner::extractClasses($absolutePath);
            foreach ($classes as $class) {
                $fqcn = $class['fqcn'];
                if (!str_starts_with($fqcn, 'Core\\') && !str_starts_with($fqcn, 'Modules\\')) {
                    continue;
                }

                $classCount++;
                foreach ($criticalPrefixes as $criticalPrefix) {
                    if (str_starts_with($fqcn, $criticalPrefix)) {
                        $criticalFound[$criticalPrefix] = true;
                    }
                }

                $resolved = ReleaseLawComposer::resolveExpectedPath($fqcn, $psr4);
                if ($resolved === null) {
                    $contradictions[] = [
                        'type' => 'missing_psr4_mapping',
                        'namespace_prefix' => null,
                        'expected_path' => null,
                        'real_path' => $relativePath,
                        'offending_path' => $relativePath,
                        'class' => $fqcn,
                        'message' => 'No composer PSR-4 mapping matched declared class.',
                    ];
                    continue;
                }

                $expectedPath = $resolved['expected_relative_path'];
                $namespacePrefix = $resolved['prefix'];
                $realCaseFromFs = ReleaseLawPaths::resolveFilesystemCase($repoRoot, $relativePath);
                $expectedCaseFromFs = ReleaseLawPaths::resolveFilesystemCase($repoRoot, $expectedPath);
                $trackedMatches = $trackedLookup[ReleaseLawPaths::normalizeForCompare($relativePath)] ?? [];

                if ($relativePath !== $expectedPath) {
                    $contradictions[] = [
                        'type' => 'namespace_to_path_mismatch',
                        'namespace_prefix' => $namespacePrefix,
                        'expected_path' => $expectedPath,
                        'real_path' => $relativePath,
                        'offending_path' => $relativePath,
                        'class' => $fqcn,
                        'message' => 'Declared class path does not exactly match composer PSR-4 expectation.',
                    ];
                }

                if ($realCaseFromFs === null) {
                    $contradictions[] = [
                        'type' => 'filesystem_missing_path',
                        'namespace_prefix' => $namespacePrefix,
                        'expected_path' => $expectedPath,
                        'real_path' => null,
                        'offending_path' => $relativePath,
                        'class' => $fqcn,
                        'message' => 'Class file is missing from the filesystem.',
                    ];
                } elseif ($realCaseFromFs !== $relativePath) {
                    $contradictions[] = [
                        'type' => 'filesystem_case_mismatch',
                        'namespace_prefix' => $namespacePrefix,
                        'expected_path' => $expectedPath,
                        'real_path' => $realCaseFromFs,
                        'offending_path' => $relativePath,
                        'class' => $fqcn,
                        'message' => 'Filesystem case does not match the resolved repo path.',
                    ];
                }

                if ($expectedCaseFromFs !== null && $expectedCaseFromFs !== $expectedPath) {
                    $contradictions[] = [
                        'type' => 'expected_path_case_mismatch',
                        'namespace_prefix' => $namespacePrefix,
                        'expected_path' => $expectedPath,
                        'real_path' => $expectedCaseFromFs,
                        'offending_path' => $expectedPath,
                        'class' => $fqcn,
                        'message' => 'Expected PSR-4 path exists with a different on-disk case.',
                    ];
                }

                if ($gitAvailable) {
                    if ($trackedMatches === []) {
                        $contradictions[] = [
                            'type' => 'untracked_class_file',
                            'namespace_prefix' => $namespacePrefix,
                            'expected_path' => $expectedPath,
                            'real_path' => $relativePath,
                            'offending_path' => $relativePath,
                            'class' => $fqcn,
                            'message' => 'Class file is not tracked by git.',
                        ];
                    } elseif (!in_array($relativePath, $trackedMatches, true)) {
                        $contradictions[] = [
                            'type' => 'git_case_mismatch',
                            'namespace_prefix' => $namespacePrefix,
                            'expected_path' => $expectedPath,
                            'real_path' => $relativePath,
                            'offending_path' => $relativePath,
                            'class' => $fqcn,
                            'git_paths' => $trackedMatches,
                            'message' => 'Git tracked path differs only by case from the filesystem path.',
                        ];
                    }
                }
            }
        }

        foreach ($criticalFound as $criticalPrefix => $found) {
            if (!$found) {
                $contradictions[] = [
                    'type' => 'critical_family_missing',
                    'namespace_prefix' => $criticalPrefix,
                    'expected_path' => null,
                    'real_path' => null,
                    'offending_path' => $criticalPrefix,
                    'message' => 'Critical namespace family was not discovered by the auditor.',
                ];
            }
        }

        return [
            'ok' => $contradictions === [],
            'checked_class_count' => $classCount,
            'contradictions' => array_values($contradictions),
            'critical_family_checked' => array_keys($criticalFound),
        ];
    }

    /**
     * @return list<string>
     */
    private static function collectOwnedPhpFiles(string $repoRoot): array
    {
        $roots = [$repoRoot . '/system/core', $repoRoot . '/system/modules'];
        $files = [];

        foreach ($roots as $root) {
            if (!is_dir($root)) {
                continue;
            }

            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
            );

            foreach ($iterator as $item) {
                if (!$item instanceof SplFileInfo || !$item->isFile()) {
                    continue;
                }
                if (strtolower($item->getExtension()) !== 'php') {
                    continue;
                }

                $relative = substr(str_replace('\\', '/', $item->getPathname()), strlen(str_replace('\\', '/', $repoRoot)) + 1);
                $files[] = ReleaseLawPaths::normalizeRelative($relative);
            }
        }

        sort($files);

        return $files;
    }

    /**
     * @param list<string> $paths
     * @return list<list<string>>
     */
    private static function findCaseDuplicates(array $paths): array
    {
        $groups = [];
        foreach ($paths as $path) {
            $groups[ReleaseLawPaths::normalizeForCompare($path)][] = ReleaseLawPaths::normalizeRelative($path);
        }

        $duplicates = [];
        foreach ($groups as $group) {
            $unique = array_values(array_unique($group));
            if (count($unique) > 1) {
                sort($unique);
                $duplicates[] = $unique;
            }
        }

        return $duplicates;
    }
}

final class ReleaseLawRuntimeProbe
{
    /**
     * @return array{ok:bool, output:string, critical_classes:list<string>}
     */
    public static function probe(string $repoRoot): array
    {
        $criticalClasses = [
            'Core\\App\\Application',
            'Core\\Auth\\SessionAuth',
            'Core\\Tenant\\TenantRuntimeContextEnforcer',
            'Modules\\Organizations\\Services\\UserOrganizationMembershipReadService',
            'Modules\\Sales\\Services\\InvoiceService',
        ];

        $probeFile = tempnam(sys_get_temp_dir(), 'release-law-probe-');
        if ($probeFile === false) {
            throw new ReleaseLawException('Unable to create temporary runtime probe file.');
        }

        $probeCode = <<<'PHP'
<?php
declare(strict_types=1);

$repoRoot = $argv[1] ?? '';
if ($repoRoot === '' || !is_dir($repoRoot)) {
    fwrite(STDERR, "Missing repo root.\n");
    exit(2);
}

require $repoRoot . '/system/bootstrap.php';
require $repoRoot . '/system/modules/bootstrap.php';

$classes = [
    'Core\\App\\Application',
    'Core\\Auth\\SessionAuth',
    'Core\\Tenant\\TenantRuntimeContextEnforcer',
    'Modules\\Organizations\\Services\\UserOrganizationMembershipReadService',
    'Modules\\Sales\\Services\\InvoiceService',
];

foreach ($classes as $class) {
    if (!class_exists($class)) {
        fwrite(STDERR, "Missing class: {$class}\n");
        exit(1);
    }
}

app(\Core\Tenant\TenantRuntimeContextEnforcer::class);
app(\Modules\Organizations\Services\UserOrganizationMembershipReadService::class);
app(\Modules\Sales\Services\InvoiceService::class);

echo "strict_bootstrap_probe=PASS\n";
PHP;

        file_put_contents($probeFile, $probeCode);

        try {
            $result = ReleaseLawShell::run(
                [PHP_BINARY, $probeFile, $repoRoot],
                $repoRoot,
                ['SPA_AUTOLOAD_MODE' => 'composer_only']
            );
        } finally {
            @unlink($probeFile);
        }

        return [
            'ok' => $result->exitCode === 0,
            'output' => $result->combinedOutput(),
            'critical_classes' => $criticalClasses,
        ];
    }
}

final class ReleaseLawPackagingPolicy
{
    public const METADATA_ENTRY_PATH = 'release-law/canonical-release-metadata.json';

    public static function forbiddenReason(string $relativePath): ?string
    {
        $path = ReleaseLawPaths::normalizeForCompare($relativePath);

        if ($path === 'system/.env' || $path === 'system/.env.local') {
            return 'forbidden secret env file';
        }
        if (preg_match('~(^|/)\.env($|\.)~', $path) === 1 && !str_ends_with($path, '.env.example')) {
            return 'forbidden env secret file';
        }
        if ($path === 'vendor' || str_starts_with($path, 'vendor/')) {
            return 'forbidden generated vendor tree';
        }
        if ($path === 'distribution/release-law' || str_starts_with($path, 'distribution/release-law/')) {
            return 'forbidden generated release-law report output';
        }
        if ($path === '.git' || str_starts_with($path, '.git/')) {
            return 'forbidden git metadata';
        }
        if (str_contains($path, '/node_modules/')) {
            return 'forbidden node_modules tree';
        }
        if (str_ends_with($path, '.zip')) {
            return 'forbidden nested zip artifact';
        }
        if (str_ends_with($path, '.log')) {
            return 'forbidden runtime log';
        }
        if ($path === '.ds_store' || str_ends_with($path, '/.ds_store')) {
            return 'forbidden OS junk (.DS_Store)';
        }
        if ($path === 'thumbs.db' || str_ends_with($path, '/thumbs.db')) {
            return 'forbidden OS junk (Thumbs.db)';
        }
        if (str_starts_with($path, 'system/storage/logs/') && $path !== 'system/storage/logs/.gitkeep') {
            return 'forbidden runtime logs directory';
        }
        if (str_starts_with($path, 'system/storage/backups/')) {
            return 'forbidden runtime backups directory';
        }
        if (str_starts_with($path, 'system/storage/sessions/') && $path !== 'system/storage/sessions/.gitkeep') {
            return 'forbidden runtime sessions directory';
        }
        foreach ([
            'system/storage/framework/cache/',
            'system/storage/framework/sessions/',
            'system/storage/framework/views/',
        ] as $frameworkPath) {
            if (str_starts_with($path, $frameworkPath) && !str_ends_with($path, '/.gitkeep')) {
                return 'forbidden framework runtime directory';
            }
        }
        if (str_starts_with($path, 'system/docs/') && str_ends_with($path, '-result.txt')) {
            return 'forbidden pasted proof transcript';
        }

        return null;
    }

    /**
     * @return array{ok:bool, contradictions:list<array<string, mixed>>, packaged_paths:list<string>}
     */
    public static function collectPackagedPaths(string $repoRoot): array
    {
        $paths = ReleaseLawGit::paths($repoRoot, ['ls-files', '-z', '--cached', '--others', '--exclude-standard']);
        $contradictions = [];
        $allowed = [];

        foreach ($paths as $path) {
            $reason = self::forbiddenReason($path);
            if ($reason !== null) {
                $contradictions[] = [
                    'type' => 'forbidden_packaged_path',
                    'namespace_prefix' => null,
                    'expected_path' => null,
                    'real_path' => $path,
                    'offending_path' => $path,
                    'message' => $reason,
                ];
                continue;
            }
            $allowed[] = $path;
        }

        sort($allowed);

        return [
            'ok' => $contradictions === [],
            'contradictions' => $contradictions,
            'packaged_paths' => $allowed,
        ];
    }

    /**
     * @param list<string> $packagedPaths
     * @param array<string, mixed> $metadata
     */
    public static function buildCanonicalZip(string $repoRoot, string $zipPath, array $packagedPaths, array $metadata): void
    {
        $zipDir = dirname($zipPath);
        if (!is_dir($zipDir) && !mkdir($zipDir, 0777, true) && !is_dir($zipDir)) {
            throw new ReleaseLawException('Unable to create zip output directory: ' . $zipDir);
        }

        if (is_file($zipPath) && !unlink($zipPath)) {
            throw new ReleaseLawException('Unable to replace existing zip: ' . $zipPath);
        }

        $zip = new ZipArchive();
        if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new ReleaseLawException('Unable to open zip for writing: ' . $zipPath);
        }

        try {
            foreach ($packagedPaths as $relativePath) {
                $absolutePath = $repoRoot . '/' . $relativePath;
                if (!is_file($absolutePath)) {
                    throw new ReleaseLawException('Packaged path disappeared during zip build: ' . $relativePath);
                }
                if (!$zip->addFile($absolutePath, $relativePath)) {
                    throw new ReleaseLawException('Unable to add file to zip: ' . $relativePath);
                }
            }

            $metadataJson = json_encode($metadata, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
            if (!is_string($metadataJson)) {
                throw new ReleaseLawException('Unable to encode canonical release metadata.');
            }

            if (!$zip->addFromString(self::METADATA_ENTRY_PATH, $metadataJson . "\n")) {
                throw new ReleaseLawException('Unable to write canonical release metadata into zip.');
            }
        } finally {
            $zip->close();
        }
    }

    public static function metadataEntryPath(): string
    {
        return self::METADATA_ENTRY_PATH;
    }

    /**
     * @return array{
     *   ok:bool,
     *   metadata:array<string, mixed>,
     *   zip_entries:list<string>,
     *   contradictions:list<array<string, mixed>>
     * }
     */
    public static function verifyCanonicalZipTruth(string $zipPath): array
    {
        $zip = new ZipArchive();
        if ($zip->open($zipPath) !== true) {
            throw new ReleaseLawException('Unable to open zip for truth verification: ' . $zipPath);
        }

        $entries = [];
        $metadata = [];
        $contradictions = [];

        try {
            $metadataJson = null;

            for ($i = 0; $i < $zip->numFiles; $i++) {
                $stat = $zip->statIndex($i);
                if (!is_array($stat) || !isset($stat['name']) || !is_string($stat['name'])) {
                    continue;
                }

                $entryPath = ReleaseLawPaths::normalizeRelative($stat['name']);
                if ($entryPath === '') {
                    continue;
                }

                $entries[] = $entryPath;

                if ($entryPath === self::METADATA_ENTRY_PATH) {
                    $contents = $zip->getFromIndex($i);
                    if (is_string($contents)) {
                        $metadataJson = $contents;
                    }
                    continue;
                }

                $reason = self::forbiddenReason($entryPath);
                if ($reason !== null) {
                    $contradictions[] = [
                        'type' => 'zip_contains_forbidden_path',
                        'namespace_prefix' => null,
                        'expected_path' => null,
                        'real_path' => $entryPath,
                        'offending_path' => $entryPath,
                        'message' => $reason,
                    ];
                }
            }

            sort($entries);

            if ($metadataJson === null) {
                $contradictions[] = [
                    'type' => 'zip_metadata_missing',
                    'namespace_prefix' => null,
                    'expected_path' => self::METADATA_ENTRY_PATH,
                    'real_path' => null,
                    'offending_path' => $zipPath,
                    'message' => 'Canonical release metadata file is missing from the zip.',
                ];
            } else {
                $decoded = json_decode($metadataJson, true);
                if (!is_array($decoded)) {
                    $contradictions[] = [
                        'type' => 'zip_metadata_invalid',
                        'namespace_prefix' => null,
                        'expected_path' => self::METADATA_ENTRY_PATH,
                        'real_path' => self::METADATA_ENTRY_PATH,
                        'offending_path' => self::METADATA_ENTRY_PATH,
                        'message' => 'Canonical release metadata is not valid JSON.',
                    ];
                } else {
                    $metadata = $decoded;
                }
            }

            $entrySet = array_fill_keys($entries, true);
            $claimedReportPaths = [];
            if ($metadata !== []) {
                $packagedReportPaths = $metadata['packaged_report_paths'] ?? [];
                if (!is_array($packagedReportPaths)) {
                    $contradictions[] = [
                        'type' => 'zip_metadata_invalid_packaged_report_paths',
                        'namespace_prefix' => null,
                        'expected_path' => null,
                        'real_path' => self::METADATA_ENTRY_PATH,
                        'offending_path' => self::METADATA_ENTRY_PATH,
                        'message' => 'packaged_report_paths must be an array when present.',
                    ];
                } else {
                    foreach ($packagedReportPaths as $path) {
                        if (!is_string($path) || trim($path) === '') {
                            $contradictions[] = [
                                'type' => 'zip_metadata_invalid_packaged_report_path',
                                'namespace_prefix' => null,
                                'expected_path' => null,
                                'real_path' => self::METADATA_ENTRY_PATH,
                                'offending_path' => self::METADATA_ENTRY_PATH,
                                'message' => 'packaged_report_paths contains a non-string or empty path.',
                            ];
                            continue;
                        }
                        $claimedReportPaths[] = ReleaseLawPaths::normalizeRelative($path);
                    }
                }

                foreach (['json_report_path', 'text_report_path'] as $legacyField) {
                    if (isset($metadata[$legacyField]) && is_string($metadata[$legacyField]) && trim($metadata[$legacyField]) !== '') {
                        $claimedReportPaths[] = ReleaseLawPaths::normalizeRelative($metadata[$legacyField]);
                    }
                }

                $reportPackaging = $metadata['report_packaging'] ?? null;
                if ($reportPackaging === 'excluded' && $claimedReportPaths !== []) {
                    $contradictions[] = [
                        'type' => 'zip_metadata_report_packaging_contradiction',
                        'namespace_prefix' => null,
                        'expected_path' => null,
                        'real_path' => self::METADATA_ENTRY_PATH,
                        'offending_path' => self::METADATA_ENTRY_PATH,
                        'message' => 'report_packaging=excluded contradicts packaged report path claims.',
                    ];
                }
            }

            $claimedReportPaths = array_values(array_unique($claimedReportPaths));
            foreach ($claimedReportPaths as $claimedPath) {
                if (!isset($entrySet[$claimedPath])) {
                    $contradictions[] = [
                        'type' => 'zip_metadata_report_missing',
                        'namespace_prefix' => null,
                        'expected_path' => $claimedPath,
                        'real_path' => null,
                        'offending_path' => self::METADATA_ENTRY_PATH,
                        'message' => 'Zip metadata claims a packaged report that is not present in the canonical artifact.',
                    ];
                }
            }
        } finally {
            $zip->close();
        }

        return [
            'ok' => $contradictions === [],
            'metadata' => $metadata,
            'zip_entries' => $entries,
            'contradictions' => $contradictions,
        ];
    }

    public static function extractZip(string $zipPath, string $targetDirectory): void
    {
        if (!is_dir($targetDirectory) && !mkdir($targetDirectory, 0777, true) && !is_dir($targetDirectory)) {
            throw new ReleaseLawException('Unable to create extraction directory: ' . $targetDirectory);
        }

        $zip = new ZipArchive();
        if ($zip->open($zipPath) !== true) {
            throw new ReleaseLawException('Unable to open zip for extraction: ' . $zipPath);
        }

        try {
            if (!$zip->extractTo($targetDirectory)) {
                throw new ReleaseLawException('Unable to extract zip to: ' . $targetDirectory);
            }
        } finally {
            $zip->close();
        }
    }
}

final class ReleaseLawReportWriter
{
    /**
     * @param list<array<string, mixed>> $checks
     * @param list<array<string, mixed>> $contradictions
     * @return array{json_path:string,text_path:string,text_sha256:string}
     */
    public static function write(
        string $reportDir,
        string $verdict,
        array $checks,
        array $contradictions,
        ?string $artifactPath,
        array $metadata = []
    ): array {
        if (!is_dir($reportDir) && !mkdir($reportDir, 0777, true) && !is_dir($reportDir)) {
            throw new ReleaseLawException('Unable to create report directory: ' . $reportDir);
        }

        $jsonPath = $reportDir . '/canonical-release-law-report.json';
        $textPath = $reportDir . '/canonical-release-law-report.txt';

        $payload = [
            'verdict' => $verdict,
            'artifact_path' => $artifactPath,
            'checks' => $checks,
            'contradictions' => $contradictions,
            'metadata' => $metadata,
            'generated_at_utc' => gmdate('c'),
        ];

        $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if (!is_string($json)) {
            throw new ReleaseLawException('Unable to encode JSON report.');
        }
        file_put_contents($jsonPath, $json . "\n");

        $lines = [
            'Release Law Verdict: ' . $verdict,
            'Generated At UTC: ' . gmdate('c'),
            'Artifact Path: ' . ($artifactPath ?? 'n/a'),
            '',
            'Checks:',
        ];

        foreach ($checks as $check) {
            $lines[] = sprintf(
                '[%s] %s',
                strtoupper((string) ($check['status'] ?? 'unknown')),
                (string) ($check['label'] ?? $check['id'] ?? 'unknown')
            );
            if (!empty($check['command'])) {
                $lines[] = '  command: ' . (string) $check['command'];
            }
            if (!empty($check['exit_code']) || (isset($check['exit_code']) && (int) $check['exit_code'] === 0)) {
                $lines[] = '  exit_code: ' . (string) $check['exit_code'];
            }
            if (!empty($check['details'])) {
                $lines[] = '  details: ' . (string) $check['details'];
            }
        }

        $lines[] = '';
        $lines[] = 'Contradictions:';
        if ($contradictions === []) {
            $lines[] = '  none';
        } else {
            foreach ($contradictions as $contradiction) {
                $lines[] = '- ' . (string) ($contradiction['type'] ?? 'unknown');
                $lines[] = '  message: ' . (string) ($contradiction['message'] ?? '');
                $lines[] = '  offending_path: ' . (string) ($contradiction['offending_path'] ?? 'n/a');
                $lines[] = '  expected_path: ' . (string) ($contradiction['expected_path'] ?? 'n/a');
                $lines[] = '  real_path: ' . (string) ($contradiction['real_path'] ?? 'n/a');
                $lines[] = '  namespace_prefix: ' . (string) ($contradiction['namespace_prefix'] ?? 'n/a');
            }
        }

        file_put_contents($textPath, implode(PHP_EOL, $lines) . PHP_EOL);

        return [
            'json_path' => $jsonPath,
            'text_path' => $textPath,
            'text_sha256' => hash_file('sha256', $textPath),
        ];
    }
}

final class ReleaseLawGate
{
    /**
     * @return array{
     *   verdict:string,
     *   artifact_path:?string,
     *   checks:list<array<string, mixed>>,
     *   contradictions:list<array<string, mixed>>,
     *   reports:array{json_path:string,text_path:string,text_sha256:string}
     * }
     */
    public static function run(string $repoRoot, string $reportDir, string $zipPath): array
    {
        $checks = [];
        $contradictions = [];
        $artifactPath = null;
        $commit = trim((string) self::safeStdout(['git', 'rev-parse', 'HEAD'], $repoRoot));
        $composerBin = (string) getenv('COMPOSER_BIN');
        if ($composerBin === '') {
            $composerBin = 'composer';
        }

        try {
            $composerValidation = ReleaseLawComposer::validateSettings($repoRoot);
            $checks[] = [
                'id' => 'validate_composer_settings',
                'label' => 'Validate composer.json autoload settings',
                'status' => $composerValidation['ok'] ? 'passed' : 'failed',
                'details' => 'Required prefixes: ' . implode(', ', $composerValidation['required_prefixes']),
            ];
            $contradictions = array_merge($contradictions, $composerValidation['contradictions']);
            if (!$composerValidation['ok']) {
                throw new ReleaseLawException('Composer autoload settings are contradicted.');
            }

            $composerValidateCmd = ReleaseLawShell::run(
                [$composerBin, 'validate', '--strict', '--no-check-publish'],
                $repoRoot
            );
            $checks[] = $composerValidateCmd->toCheck('composer_validate', 'Validate composer.json');
            if ($composerValidateCmd->exitCode !== 0) {
                throw new ReleaseLawException('composer validate failed.');
            }

            $composerDumpCmd = ReleaseLawShell::run(
                [$composerBin, 'dump-autoload', '-o', '-a', '--no-interaction'],
                $repoRoot
            );
            $checks[] = $composerDumpCmd->toCheck('composer_dump_autoload', 'Composer dump-autoload -o -a');
            if ($composerDumpCmd->exitCode !== 0) {
                throw new ReleaseLawException('composer dump-autoload failed.');
            }

            $audit = ReleaseLawCasePathAuditor::audit($repoRoot);
            $checks[] = [
                'id' => 'case_path_audit',
                'label' => 'Exact case and path auditor',
                'status' => $audit['ok'] ? 'passed' : 'failed',
                'details' => 'Checked classes: ' . $audit['checked_class_count'],
            ];
            $contradictions = array_merge($contradictions, $audit['contradictions']);
            if (!$audit['ok']) {
                throw new ReleaseLawException('Case/path auditor detected contradictions.');
            }

            $runtimeProbe = ReleaseLawRuntimeProbe::probe($repoRoot);
            $checks[] = [
                'id' => 'strict_runtime_probe',
                'label' => 'Strict authoritative autoload runtime probe',
                'status' => $runtimeProbe['ok'] ? 'passed' : 'failed',
                'details' => $runtimeProbe['output'],
            ];
            if (!$runtimeProbe['ok']) {
                $contradictions[] = [
                    'type' => 'strict_runtime_probe_failed',
                    'namespace_prefix' => null,
                    'expected_path' => null,
                    'real_path' => null,
                    'offending_path' => 'system/bootstrap.php',
                    'message' => $runtimeProbe['output'],
                ];
                throw new ReleaseLawException('Strict runtime probe failed.');
            }

            $tierA = ReleaseLawShell::run(
                [PHP_BINARY, 'system/scripts/run_mandatory_tenant_isolation_proof_release_gate_01.php'],
                $repoRoot,
                ['SPA_AUTOLOAD_MODE' => 'composer_only']
            );
            $checks[] = $tierA->toCheck('tier_a_gate', 'Existing mandatory Tier A gate');
            if ($tierA->exitCode !== 0) {
                $contradictions[] = [
                    'type' => 'tier_a_failed',
                    'namespace_prefix' => null,
                    'expected_path' => null,
                    'real_path' => null,
                    'offending_path' => 'system/scripts/run_mandatory_tenant_isolation_proof_release_gate_01.php',
                    'message' => $tierA->combinedOutput(),
                ];
                throw new ReleaseLawException('Tier A gate failed.');
            }

            $migrate = ReleaseLawShell::run(
                [PHP_BINARY, 'system/scripts/migrate.php', '--strict', '--verify-baseline'],
                $repoRoot,
                ['SPA_AUTOLOAD_MODE' => 'composer_only']
            );
            $checks[] = $migrate->toCheck('db_migrate', 'Migrate database baseline');
            if ($migrate->exitCode !== 0) {
                $contradictions[] = [
                    'type' => 'db_migrate_failed',
                    'namespace_prefix' => null,
                    'expected_path' => null,
                    'real_path' => null,
                    'offending_path' => 'system/scripts/migrate.php',
                    'message' => $migrate->combinedOutput(),
                ];
                throw new ReleaseLawException('Database migrate step failed.');
            }

            $seed = ReleaseLawShell::run(
                [PHP_BINARY, 'system/scripts/seed.php'],
                $repoRoot,
                ['SPA_AUTOLOAD_MODE' => 'composer_only']
            );
            $checks[] = $seed->toCheck('db_seed', 'Seed baseline data');
            if ($seed->exitCode !== 0) {
                $contradictions[] = [
                    'type' => 'db_seed_failed',
                    'namespace_prefix' => null,
                    'expected_path' => null,
                    'real_path' => null,
                    'offending_path' => 'system/scripts/seed.php',
                    'message' => $seed->combinedOutput(),
                ];
                throw new ReleaseLawException('Database seed step failed.');
            }

            $smokeSeed = ReleaseLawShell::run(
                [PHP_BINARY, 'system/scripts/dev-only/seed_branch_smoke_data.php'],
                $repoRoot,
                ['SPA_AUTOLOAD_MODE' => 'composer_only']
            );
            $checks[] = $smokeSeed->toCheck('db_seed_smoke', 'Seed smoke branch fixtures');
            if ($smokeSeed->exitCode !== 0) {
                $contradictions[] = [
                    'type' => 'db_seed_smoke_failed',
                    'namespace_prefix' => null,
                    'expected_path' => null,
                    'real_path' => null,
                    'offending_path' => 'system/scripts/dev-only/seed_branch_smoke_data.php',
                    'message' => $smokeSeed->combinedOutput(),
                ];
                throw new ReleaseLawException('Smoke branch seed step failed.');
            }

            $tierBSales = ReleaseLawShell::run(
                [PHP_BINARY, 'system/scripts/smoke_sales_tenant_data_plane_hardening_01.php'],
                $repoRoot,
                ['SPA_AUTOLOAD_MODE' => 'composer_only']
            );
            $checks[] = $tierBSales->toCheck('tier_b_sales', 'Tier B smoke sales tenant data plane');
            if ($tierBSales->exitCode !== 0) {
                $contradictions[] = [
                    'type' => 'tier_b_sales_failed',
                    'namespace_prefix' => null,
                    'expected_path' => null,
                    'real_path' => null,
                    'offending_path' => 'system/scripts/smoke_sales_tenant_data_plane_hardening_01.php',
                    'message' => $tierBSales->combinedOutput(),
                ];
                throw new ReleaseLawException('Tier B sales smoke failed.');
            }

            $tierBFoundation = ReleaseLawShell::run(
                [PHP_BINARY, 'system/scripts/smoke_foundation_minimal_regression_wave_01.php'],
                $repoRoot,
                ['SPA_AUTOLOAD_MODE' => 'composer_only']
            );
            $checks[] = $tierBFoundation->toCheck('tier_b_foundation', 'Tier B foundation regression smoke');
            if ($tierBFoundation->exitCode !== 0) {
                $contradictions[] = [
                    'type' => 'tier_b_foundation_failed',
                    'namespace_prefix' => null,
                    'expected_path' => null,
                    'real_path' => null,
                    'offending_path' => 'system/scripts/smoke_foundation_minimal_regression_wave_01.php',
                    'message' => $tierBFoundation->combinedOutput(),
                ];
                throw new ReleaseLawException('Tier B foundation smoke failed.');
            }

            $dbTruth = ReleaseLawShell::run(
                [PHP_BINARY, 'system/scripts/run_db_truth_observability_proof_gate_03.php'],
                $repoRoot,
                ['SPA_AUTOLOAD_MODE' => 'composer_only']
            );
            $checks[] = $dbTruth->toCheck('db_truth_smoke', 'DB-backed smoke gate');
            if ($dbTruth->exitCode !== 0) {
                $contradictions[] = [
                    'type' => 'db_truth_smoke_failed',
                    'namespace_prefix' => null,
                    'expected_path' => null,
                    'real_path' => null,
                    'offending_path' => 'system/scripts/run_db_truth_observability_proof_gate_03.php',
                    'message' => $dbTruth->combinedOutput(),
                ];
                throw new ReleaseLawException('DB-backed smoke gate failed.');
            }

            $packaging = ReleaseLawPackagingPolicy::collectPackagedPaths($repoRoot);
            $checks[] = [
                'id' => 'packaging_policy',
                'label' => 'Packaging policy audit',
                'status' => $packaging['ok'] ? 'passed' : 'failed',
                'details' => 'Packaged paths: ' . count($packaging['packaged_paths']),
            ];
            $contradictions = array_merge($contradictions, $packaging['contradictions']);
            if (!$packaging['ok']) {
                throw new ReleaseLawException('Packaging policy contradictions detected.');
            }

            $metadata = [
                'git_commit' => $commit,
                'built_at_utc' => gmdate('c'),
                'gate_verdict' => 'ACCEPTED',
                'packaged_metadata_path' => ReleaseLawPackagingPolicy::metadataEntryPath(),
                'report_packaging' => 'excluded',
                'packaged_report_paths' => [],
            ];

            ReleaseLawPackagingPolicy::buildCanonicalZip($repoRoot, $zipPath, $packaging['packaged_paths'], $metadata);
            $artifactPath = $zipPath;
            $checks[] = [
                'id' => 'build_canonical_zip',
                'label' => 'Build canonical zip artifact',
                'status' => 'passed',
                'details' => $zipPath,
            ];

            $extractRoot = sys_get_temp_dir() . '/release-law-artifact-' . bin2hex(random_bytes(6));
            ReleaseLawPackagingPolicy::extractZip($zipPath, $extractRoot);
            $checks[] = [
                'id' => 'extract_artifact',
                'label' => 'Extract artifact into fresh directory',
                'status' => 'passed',
                'details' => $extractRoot,
            ];

            $artifactComposerValidate = ReleaseLawShell::run(
                [$composerBin, 'validate', '--strict', '--no-check-publish'],
                $extractRoot
            );
            $checks[] = $artifactComposerValidate->toCheck('artifact_composer_validate', 'Validate extracted composer.json');
            if ($artifactComposerValidate->exitCode !== 0) {
                $contradictions[] = [
                    'type' => 'artifact_composer_validate_failed',
                    'namespace_prefix' => null,
                    'expected_path' => null,
                    'real_path' => null,
                    'offending_path' => $zipPath,
                    'message' => $artifactComposerValidate->combinedOutput(),
                ];
                throw new ReleaseLawException('Extracted artifact composer validation failed.');
            }

            $artifactComposerDump = ReleaseLawShell::run(
                [$composerBin, 'dump-autoload', '-o', '-a', '--no-interaction'],
                $extractRoot
            );
            $checks[] = $artifactComposerDump->toCheck('artifact_composer_dump', 'Composer dump-autoload inside artifact');
            if ($artifactComposerDump->exitCode !== 0) {
                $contradictions[] = [
                    'type' => 'artifact_composer_dump_failed',
                    'namespace_prefix' => null,
                    'expected_path' => null,
                    'real_path' => null,
                    'offending_path' => $zipPath,
                    'message' => $artifactComposerDump->combinedOutput(),
                ];
                throw new ReleaseLawException('Extracted artifact composer dump-autoload failed.');
            }

            $artifactAudit = ReleaseLawCasePathAuditor::audit($extractRoot);
            $checks[] = [
                'id' => 'artifact_case_path_audit',
                'label' => 'Exact case/path auditor against extracted artifact',
                'status' => $artifactAudit['ok'] ? 'passed' : 'failed',
                'details' => 'Checked classes: ' . $artifactAudit['checked_class_count'],
            ];
            $contradictions = array_merge($contradictions, $artifactAudit['contradictions']);
            if (!$artifactAudit['ok']) {
                throw new ReleaseLawException('Extracted artifact case/path audit failed.');
            }

            $artifactRuntime = ReleaseLawRuntimeProbe::probe($extractRoot);
            $checks[] = [
                'id' => 'artifact_runtime_probe',
                'label' => 'Strict runtime probe against extracted artifact',
                'status' => $artifactRuntime['ok'] ? 'passed' : 'failed',
                'details' => $artifactRuntime['output'],
            ];
            if (!$artifactRuntime['ok']) {
                $contradictions[] = [
                    'type' => 'artifact_runtime_probe_failed',
                    'namespace_prefix' => null,
                    'expected_path' => null,
                    'real_path' => null,
                    'offending_path' => $zipPath,
                    'message' => $artifactRuntime['output'],
                ];
                throw new ReleaseLawException('Extracted artifact runtime probe failed.');
            }

            $zipTruth = ReleaseLawPackagingPolicy::verifyCanonicalZipTruth($zipPath);
            $checks[] = [
                'id' => 'zip_internal_truth',
                'label' => 'Canonical zip metadata matches packaged truth',
                'status' => $zipTruth['ok'] ? 'passed' : 'failed',
                'details' => 'Zip entries checked: ' . count($zipTruth['zip_entries']),
            ];
            $contradictions = array_merge($contradictions, $zipTruth['contradictions']);
            if (!$zipTruth['ok']) {
                throw new ReleaseLawException('Canonical zip metadata truth verification failed.');
            }

            $reports = ReleaseLawReportWriter::write(
                $reportDir,
                'ACCEPTED',
                $checks,
                $contradictions,
                $artifactPath,
                ['git_commit' => $commit]
            );

            return [
                'verdict' => 'ACCEPTED',
                'artifact_path' => $artifactPath,
                'checks' => $checks,
                'contradictions' => $contradictions,
                'reports' => $reports,
            ];
        } catch (Throwable $throwable) {
            $contradictions[] = [
                'type' => 'release_law_exception',
                'namespace_prefix' => null,
                'expected_path' => null,
                'real_path' => null,
                'offending_path' => $artifactPath ?? 'release-law',
                'message' => $throwable->getMessage(),
            ];
            $reports = ReleaseLawReportWriter::write(
                $reportDir,
                'CONTRADICTED',
                $checks,
                $contradictions,
                $artifactPath,
                ['git_commit' => $commit]
            );

            return [
                'verdict' => 'CONTRADICTED',
                'artifact_path' => $artifactPath,
                'checks' => $checks,
                'contradictions' => $contradictions,
                'reports' => $reports,
            ];
        }
    }

    /**
     * @param list<string> $command
     */
    private static function safeStdout(array $command, string $repoRoot): string
    {
        $result = ReleaseLawShell::run($command, $repoRoot);
        if ($result->exitCode !== 0) {
            return '';
        }

        return $result->stdout;
    }
}
