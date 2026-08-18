<?php

declare(strict_types=1);

namespace CWM\BuildTools\Build;

use ZipArchive;

/**
 * Adds a file to a zip with a fixed timestamp, so artifacts are reproducible.
 *
 * `ZipArchive` records each entry's filesystem mtime in the zip headers. Two
 * builds from an identical source tree therefore produce different bytes
 * whenever a file has merely been rewritten — same content, different
 * timestamp — and the artifact's size does not change, so nothing looks wrong.
 *
 * That is not hypothetical. `pkg_licenseportal` 1.5.0 published a local
 * artifact and a GitHub asset of identical length, 389213 bytes, with different
 * hashes, and the update stream advertised the checksums of the file users did
 * not receive. It went unnoticed across two releases (#132), and the cause was
 * this (#134): a rebuild regenerated `media/js/**`, only those entries got
 * fresh mtimes, and the diff followed what had been rebuilt rather than what
 * had changed.
 *
 * ## Every entry goes through here
 *
 * A `setMtimeName()` call after each `addFile()` would work and would rot: the
 * next call site added elsewhere silently reintroduces the problem, and the
 * symptom is invisible until someone hashes two artifacts by hand. So adding an
 * entry and normalising it are one operation, and a test asserts no raw
 * `addFile()` survives in this namespace.
 */
final class ZipEntry
{
    /**
     * The timestamp every entry is recorded with.
     *
     * Epoch, the conventional choice for reproducible archives. Nothing
     * consumes an artifact's mtimes — Joomla reads the manifest and the files,
     * and unzip does not care — so the value only has to be constant.
     */
    public const FIXED_MTIME = 0;

    /**
     * Add a file to the archive, recorded at a fixed timestamp.
     *
     * @param  ZipArchive  $zip    Open archive.
     * @param  string      $path   Absolute path to the file on disk.
     * @param  string      $entry  Path the file takes inside the archive.
     *
     * @return bool  Whether the file was added. A failed `setMtimeName` is not
     *               treated as failure: the entry is present and correct, and
     *               only its reproducibility is lost — worth not aborting a
     *               release over.
     */
    public static function add(ZipArchive $zip, string $path, string $entry): bool
    {
        if (!$zip->addFile($path, $entry)) {
            return false;
        }

        $zip->setMtimeName($entry, self::FIXED_MTIME);

        return true;
    }
}
