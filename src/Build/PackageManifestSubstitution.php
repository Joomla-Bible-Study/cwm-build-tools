<?php

declare(strict_types=1);

namespace CWM\BuildTools\Build;

use CWM\BuildTools\Release\TokenSubstituter;

/**
 * Substitutes a package manifest's placeholder tokens in place, for the
 * instant it takes to zip it, then restores the original bytes.
 *
 * `package.manifestTokens` exists because some manifest templates use an
 * Ant/Phing-style `##TOKEN##` convention (Akeeba's `##VERSION##`/`##DATE##`)
 * instead of `__DEPLOY_VERSION__`. That template is meant to stay a
 * template — `release-system`'s `build/templates/pkg_ars.xml` ships
 * `##VERSION##` in git forever, resolved fresh on every build, exactly the
 * way Phing's own `<replacetokens>` filterchain never touches the source
 * file either, only a copy. `versionTracking.substituteTokens`'s normal
 * `paths` walk is the wrong tool here: it is a *permanent* rewrite meant for
 * source that should carry the real version once released (Joomla core's
 * `@since __DEPLOY_VERSION__` convention) — running it against this
 * template would burn the placeholder out of git on the next release.
 *
 * So the manifest is treated the same way {@see ChildTokenSubstitution}
 * treats a `subBuild` child's working tree: substituted on disk just long
 * enough to be read into the zip, restored in a `finally` no matter what
 * happens in between. Both resolve token values through the same
 * {@see TokenSubstituter::resolveValue()}, so `{version}` / `{date:FORMAT}`
 * / literal behave identically everywhere in this codebase — there is one
 * substitution engine, not two.
 *
 * Usage is strictly paired, same as `ChildTokenSubstitution`:
 *
 *     $sub = new PackageManifestSubstitution($manifestPath, $tokens);
 *     $sub->apply($version);
 *     try { ...zip it... } finally { $sub->restore(); }
 */
final class PackageManifestSubstitution
{
    private ?string $backup = null;

    /**
     * @param string                 $manifestPath Absolute path to the manifest file.
     * @param array<string, string>  $tokens       Literal placeholder text => value template.
     */
    public function __construct(
        private readonly string $manifestPath,
        private readonly array $tokens,
    ) {
    }

    /**
     * The literal placeholder text a `{version}`-mapped token would still be
     * carrying if the manifest's `<version>` was read before this ran.
     *
     * Lets {@see Packager} detect the chicken-and-egg case — the manifest's
     * own `<version>` field IS the token — before it ends up baked into an
     * output filename or threaded to a `self` include as literal `##VERSION##`.
     *
     * @return list<string>
     */
    public function versionPlaceholders(): array
    {
        $placeholders = [];

        foreach ($this->tokens as $placeholder => $template) {
            if ($template === '{version}') {
                $placeholders[] = $placeholder;
            }
        }

        return $placeholders;
    }

    /**
     * Rewrite the manifest in place, remembering the original bytes.
     *
     * A no-op when no tokens are configured, so callers can construct this
     * unconditionally and call `apply()`/`restore()` without an `if`.
     */
    public function apply(string $version): void
    {
        if ($this->tokens === []) {
            return;
        }

        $contents = file_get_contents($this->manifestPath);

        if ($contents === false) {
            throw new \RuntimeException("Could not read package manifest: $this->manifestPath");
        }

        $this->backup = $contents;
        $substituted  = $contents;

        foreach ($this->tokens as $placeholder => $template) {
            $substituted = str_replace($placeholder, TokenSubstituter::resolveValue($template, $version), $substituted);
        }

        if (file_put_contents($this->manifestPath, $substituted) === false) {
            throw new \RuntimeException("Could not write package manifest: $this->manifestPath");
        }
    }

    /**
     * Put the manifest back byte for byte.
     *
     * Safe to call when `apply()` did nothing (no tokens configured) and
     * safe to call twice — the caller runs it from a `finally`.
     */
    public function restore(): void
    {
        if ($this->backup === null) {
            return;
        }

        file_put_contents($this->manifestPath, $this->backup);
        $this->backup = null;
    }
}
