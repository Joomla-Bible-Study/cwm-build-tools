<?php

declare(strict_types=1);

namespace CWM\BuildTools\Tests\Dev;

use CWM\BuildTools\Dev\DeprecationScanner;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class DeprecationScannerTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/cwm-deprecation-tests-' . bin2hex(random_bytes(6));
        mkdir($this->tmpDir, 0o777, true);
    }

    protected function tearDown(): void
    {
        $this->rrmdir($this->tmpDir);
    }

    #[Test]
    public function clean_tree_yields_no_findings(): void
    {
        $this->write('admin/src/Field/SeriesField.php', "<?php\nclass SeriesField extends ModalSelectField {}\n");
        $this->write('media/js/dialog.es6.mjs', "import JoomlaDialog from 'joomla.dialog';\nnew JoomlaDialog({}).show();\n");

        self::assertSame([], (new DeprecationScanner())->scan($this->tmpDir));
    }

    #[Test]
    public function flags_bootstrap_modal_asset_in_php(): void
    {
        $this->write('admin/tmpl/edit.php', "<?php\n\$wa->useScript('bootstrap.modal');\n");

        $findings = (new DeprecationScanner())->scan($this->tmpDir);

        self::assertCount(1, $findings);
        self::assertSame('bootstrap-modal-asset', $findings[0]['rule']);
        self::assertSame(2, $findings[0]['line']);
    }

    #[Test]
    public function flags_data_bs_toggle_modal_markup(): void
    {
        $this->write('admin/src/Field/ServerField.php', "<?php\n\$html = ' data-bs-toggle=\"modal\"';\n");

        $findings = (new DeprecationScanner())->scan($this->tmpDir);

        self::assertCount(1, $findings);
        self::assertSame('data-bs-toggle-modal', $findings[0]['rule']);
    }

    #[Test]
    public function flags_iframe_modal_handler_in_both_php_and_js_syntax(): void
    {
        $this->write('admin/foo.php', "<?php\n\$opts = ['handler' => 'iframe'];\n");
        $this->write('media/js/bar.es6.js', "const o = { handler: 'iframe' };\n");

        $rules = array_column((new DeprecationScanner())->scan($this->tmpDir), 'rule');

        self::assertSame(['iframe-modal-handler', 'iframe-modal-handler'], $rules);
    }

    #[Test]
    public function flags_joomla_modal_js_api_and_jquery_global(): void
    {
        $this->write('media/js/picker.es6.js', "window.parent.Joomla.Modal.getCurrent().close();\njQuery('#x').hide();\n");

        $rules = array_column((new DeprecationScanner())->scan($this->tmpDir), 'rule');

        self::assertContains('joomla-modal-js-api', $rules);
        self::assertContains('jquery-global', $rules);
    }

    #[Test]
    public function skips_vendor_node_modules_and_minified_bundles(): void
    {
        $this->write('vendor/x/edit.php', "<?php \$wa->useScript('bootstrap.modal');\n");
        $this->write('node_modules/y/z.js', "jQuery('#x');\n");
        $this->write('media/js/picker.min.js', "Joomla.Modal.getCurrent();\n");

        self::assertSame([], (new DeprecationScanner())->scan($this->tmpDir));
    }

    #[Test]
    public function does_not_follow_symlinked_directories(): void
    {
        $this->write('real/edit.php', "<?php \$wa->useScript('bootstrap.modal');\n");
        symlink($this->tmpDir . '/real', $this->tmpDir . '/linked');

        // Only the real directory is walked; the symlink is not followed, so
        // the single finding is not double-counted.
        self::assertCount(1, (new DeprecationScanner())->scan($this->tmpDir));
    }

    private function write(string $relative, string $contents): void
    {
        $path = $this->tmpDir . '/' . $relative;
        @mkdir(\dirname($path), 0o777, true);
        file_put_contents($path, $contents);
    }

    private function rrmdir(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($iterator as $entry) {
            if ($entry->isDir() && !$entry->isLink()) {
                @rmdir($entry->getPathname());
            } else {
                @unlink($entry->getPathname());
            }
        }

        @rmdir($path);
    }
}
