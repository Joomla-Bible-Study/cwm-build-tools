<?php

declare(strict_types=1);

namespace CWM\BuildTools\Dev;

/**
 * House-style rules for how database queries are constructed.
 *
 * Consistency guards rather than correctness ones — nothing here is deprecated
 * in Joomla 5 or 6, and code that trips these rules works. They exist because
 * consistency does not hold on its own: Proclaim reached 666 uses of
 * `$db->getQuery(true)` against 9 of the documented `$db->createQuery()`, not
 * by decision but because new code copies what it finds nearby. Without a
 * check, it grows back — which is why this moved out of one project's
 * `build/lint-queries.sh` and into the shared tooling every CWM extension
 * already depends on.
 *
 * The rules are data for {@see DeprecationScanner}, which is the walking and
 * matching engine; it takes a ruleset in its constructor precisely so a second
 * lint can reuse it rather than re-implement the tree walk, the symlink guard
 * and the exclusion handling.
 */
final class QueryStyleRules
{
    /**
     * Conventional Joomla source roots, used when a project configures none.
     *
     * Matches the layout `LinkResolver` already assumes. A project whose source
     * lives elsewhere sets `lint.paths[]`.
     *
     * @var list<string>
     */
    public const DEFAULT_PATHS = ['admin', 'api', 'site', 'modules', 'plugins', 'components', 'libraries', 'src'];

    /**
     * The ruleset.
     *
     * Only the no-argument form is legal to flag. `$db->getQuery()` without
     * `true` returns the *current* query rather than a new one — a different
     * operation, and deliberately untouched. The pattern therefore requires the
     * literal `true` argument rather than matching the method name.
     *
     * @return list<array{id: string, label: string, extensions: list<string>, pattern: string, message: string}>
     */
    public static function rules(): array
    {
        return [
            [
                'id'         => 'get-query-true',
                'label'      => '$db->getQuery(true)',
                'extensions' => ['php'],
                'pattern'    => '/->\s*getQuery\s*\(\s*true\s*\)/',
                'message'    => 'Build queries with $db->createQuery(). The no-argument $db->getQuery() returns the current query and is a different operation — it is allowed.',
            ],
        ];
    }
}
