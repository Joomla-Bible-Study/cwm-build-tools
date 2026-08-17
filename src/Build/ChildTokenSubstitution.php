<?php

declare(strict_types=1);

namespace CWM\BuildTools\Build;

use CWM\BuildTools\Config\ProfileResolver;
use CWM\BuildTools\Release\TokenSubstituter;

/**
 * Substitutes a `subBuild` child's placeholder tokens with the child's OWN
 * version for the duration of the build, then puts its working tree back.
 *
 * Token substitution is a release step: `cwm-release` runs it once, in the
 * outer repo, over the outer repo's `substituteTokens.paths`. A `subBuild`
 * runs the child's *build*, never its release — so the child used to be
 * packaged with whatever placeholders its committed source carried, every
 * time, with no configuration that could fix it (#89).
 *
 * The outer repo cannot cover for it either. When the child is a git
 * submodule — which is the case this exists for — substituting it from the
 * parent stamps another repo's source with the parent's version, which is
 * what Proclaim 10.4.1 did to a plugin (Joomla-Bible-Study/Proclaim#1704) and
 * what `TokenSubstituter`
 * has refused to do during descent since 1.16.0.
 *
 * So the substitution has to happen here, where the child's own version is
 * knowable: read from the child's manifest, exactly as the child's own
 * release would have used.
 *
 * ⚠️ This deliberately substitutes a tree whose root is usually a submodule.
 * That is correct — it is standing in for the child's own release — and it
 * works because `TokenSubstituter`'s submodule guard is an iterator filter
 * that only sees entries encountered during descent, never the walk root
 * itself. `ChildTokenSubstitutionTest::substitutes_a_child_whose_root_is_a_submodule()`
 * pins that behaviour: if the guard is ever hardened to check the root too,
 * that test fails loudly rather than this class silently substituting nothing.
 * A nested submodule *inside* the child is still skipped, as it must be.
 *
 * Usage is strictly paired — `apply()` then `restore()` in a `finally`, so a
 * failed sub-build cannot leave the child rewritten:
 *
 *     $sub = new ChildTokenSubstitution($childRoot);
 *     $sub->apply();
 *     try { ...build... } finally { $sub->restore(); }
 */
final class ChildTokenSubstitution
{
    /** @var array<string, string> Absolute path => contents before substitution. */
    private array $backup = [];

    public function __construct(
        private readonly string $childRoot,
    ) {
    }

    /**
     * Substitute the child's tree, remembering what to put back.
     *
     * A child with no `cwm-build.config.json`, or one that has not opted into
     * `substituteTokens`, is left alone — same opt-in as the rest of the
     * pipeline. A child that HAS opted in but whose version cannot be resolved
     * throws: silently skipping there would reproduce exactly the failure this
     * class exists to prevent, and do it invisibly.
     *
     * @return int Files rewritten.
     */
    public function apply(): int
    {
        $configFile = $this->childRoot . '/cwm-build.config.json';

        if (!is_file($configFile)) {
            return 0;
        }

        $config = json_decode((string) file_get_contents($configFile), true);

        if (!is_array($config)) {
            return 0;
        }

        $tracking         = ProfileResolver::resolve($config);
        $substituteConfig = $tracking['substituteTokens'] ?? null;

        if (!is_array($substituteConfig)) {
            return 0;
        }

        $version = $this->childVersion($config);

        // The installer is shipped source but lives in `build/`, which no
        // project lists under `paths` — and for this child it is where the
        // failing tokens actually are (#75, CWMScriptureLinks#29).
        $substituteConfig['paths'] = TokenSubstituter::pathsWithInstaller($substituteConfig, $config);

        $substituter = new TokenSubstituter($this->childRoot, $substituteConfig);

        foreach ($substituter->filesContainingToken() as $file) {
            $contents = file_get_contents($file);

            if ($contents !== false) {
                $this->backup[$file] = $contents;
            }
        }

        return count($substituter->substitute($version));
    }

    /**
     * Put every rewritten file back byte for byte.
     *
     * Safe to call when `apply()` did nothing, and safe to call twice — the
     * caller runs it from a `finally`, which fires on paths where nothing was
     * substituted at all.
     */
    public function restore(): void
    {
        foreach ($this->backup as $file => $contents) {
            file_put_contents($file, $contents);
        }

        $this->backup = [];
    }

    /**
     * The child's own version, from the child's own manifest.
     *
     * Never the outer version: the whole point is that a submodule child
     * releases on its own cadence, so `@since 1.2.5` in the child and
     * `@since 10.5.8` in the parent are both correct at the same moment.
     *
     * @param array<string, mixed> $config
     */
    private function childVersion(array $config): string
    {
        $candidates = [
            $config['package']['manifest']    ?? null,
            $config['manifests']['package']   ?? null,
            $config['build']['manifest']      ?? null,
        ];

        foreach ($candidates as $relative) {
            if (!is_string($relative) || $relative === '') {
                continue;
            }

            $absolute = $this->childRoot . '/' . ltrim($relative, '/');

            if (is_file($absolute)) {
                return (new ManifestReader($absolute))->version();
            }
        }

        throw new \RuntimeException(
            "subBuild child at {$this->childRoot} configures substituteTokens but no readable manifest "
            . "(package.manifest / manifests.package / build.manifest), so its own version cannot be "
            . "resolved. Without it the child would be packaged with the literal token in it."
        );
    }
}
