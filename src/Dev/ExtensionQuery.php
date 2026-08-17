<?php

declare(strict_types=1);

namespace CWM\BuildTools\Dev;

/**
 * Asks a Joomla site what it has registered in `#__extensions`.
 *
 * "Does this site hold this type/folder/element, and what is its id" is a
 * question every harness asks and each one answered its own way. Proclaim alone
 * had three probes doing it — `ext-id`, `assert-library-present`,
 * `missing-scripture-stack`.
 *
 * ## `folder` is the part that gets got wrong
 *
 * It is `''` or `NULL` for everything that is not a plugin, and for plugins it
 * is the only thing distinguishing two that share an element — `plg_system_x`
 * and `plg_content_x` are both `element = 'x'`. A probe that omits it finds
 * whichever row the server returns first and reports a confident answer about
 * the wrong extension.
 *
 * So `folder` is matched when supplied and left out of the WHERE clause when it
 * is not. That is not the same as matching `folder = ''`: a caller asking "is
 * element x installed anywhere" wants the row whatever its group, and a caller
 * naming a group wants only that group.
 *
 * `client_id` behaves the same way, for the same reason — a module can exist as
 * both a site and an administrator extension under one element.
 *
 * Inputs are project-controlled manifest values (trusted, per the build-tools
 * threat model). Every value is bound regardless; the interpolation is the table
 * prefix, which cannot be bound.
 */
final class ExtensionQuery
{
    public function __construct(private readonly TestSite $site)
    {
    }

    /**
     * The full `#__extensions` row for one extension, or null when absent.
     *
     * @param  string       $type      `component`, `library`, `plugin`, `module`, `package`, `file`.
     * @param  string       $element   The element name as the manifest declares it.
     * @param  string|null  $folder    Plugin group. Omitted from the query when null.
     * @param  int|null     $clientId  0 site / 1 administrator. Omitted when null.
     *
     * @return array<string, mixed>|null
     */
    public function find(string $type, string $element, ?string $folder = null, ?int $clientId = null): ?array
    {
        $sql    = 'SELECT * FROM ' . $this->site->table('#__extensions') . ' WHERE type = ? AND element = ?';
        $params = [$type, $element];

        if ($folder !== null) {
            $sql .= ' AND folder = ?';
            $params[] = $folder;
        }

        if ($clientId !== null) {
            $sql .= ' AND client_id = ?';
            $params[] = $clientId;
        }

        $stmt = $this->site->db()->prepare($sql);
        $stmt->execute($params);

        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        return $row === false ? null : $row;
    }

    /**
     * The extension_id, or null when the extension is not registered.
     *
     * The common shape of `ext-id`-style probes.
     */
    public function id(string $type, string $element, ?string $folder = null, ?int $clientId = null): ?int
    {
        $row = $this->find($type, $element, $folder, $clientId);

        return $row === null ? null : (int) $row['extension_id'];
    }

    /** Whether the extension is registered at all. */
    public function exists(string $type, string $element, ?string $folder = null, ?int $clientId = null): bool
    {
        return $this->find($type, $element, $folder, $clientId) !== null;
    }

    /**
     * Whether the extension is registered *and* enabled.
     *
     * Kept distinct from {@see exists()} because a disabled extension is
     * installed — a probe that conflates the two reports a failed install for a
     * site where someone simply switched something off.
     */
    public function isEnabled(string $type, string $element, ?string $folder = null, ?int $clientId = null): bool
    {
        $row = $this->find($type, $element, $folder, $clientId);

        return $row !== null && (int) $row['enabled'] === 1;
    }

    /**
     * The registered version, read from `manifest_cache`.
     *
     * Joomla stores the manifest as JSON in that column at install time, so this
     * is what the *site* believes it has — which is the question worth asking
     * after an upgrade, and not necessarily what the files on disk say.
     */
    public function version(string $type, string $element, ?string $folder = null, ?int $clientId = null): ?string
    {
        $row = $this->find($type, $element, $folder, $clientId);

        if ($row === null || !isset($row['manifest_cache'])) {
            return null;
        }

        $cache = json_decode((string) $row['manifest_cache'], true);

        if (!is_array($cache) || !isset($cache['version']) || !is_string($cache['version'])) {
            return null;
        }

        return $cache['version'];
    }

    /**
     * Every registered extension whose element matches, across all groups.
     *
     * The "which of these did we actually install" question, and the one that
     * makes a shared-element collision visible instead of silently resolving it.
     *
     * @return list<array<string, mixed>>
     */
    public function findAll(string $type, string $element): array
    {
        $stmt = $this->site->db()->prepare(
            'SELECT * FROM ' . $this->site->table('#__extensions')
            . ' WHERE type = ? AND element = ? ORDER BY folder, client_id'
        );
        $stmt->execute([$type, $element]);

        return $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    }
}
