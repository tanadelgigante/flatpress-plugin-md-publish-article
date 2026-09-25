<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Unit tests for ImageUploader — images dir resolution.
 *
 * Regression coverage (R22): FlatPress 1.5.x / 1.4.x define IMAGES_DIR as a
 * blog-root-RELATIVE path (defaults.php: FP_CONTENT . 'images/') that core
 * always resolves against the absolute ABS_PATH constant. ImageUploader used
 * IMAGES_DIR as-is, which only works when the PHP process CWD equals the blog
 * root. These tests pin the new resolveImagesDir()/getImagesDir() behaviour:
 *   - with ABS_PATH available  -> the relative IMAGES_DIR is prefixed;
 *   - without ABS_PATH         -> legacy behaviour is preserved;
 *   - an absolute IMAGES_DIR   -> never double-prefixed.
 */
class ImageUploaderTest extends TestCase {

    /** @var ImageUploader */
    private $uploader;

    protected function setUp(): void {
        $this->uploader = new ImageUploader();
    }

    // ── getImagesDir (default runtime: no ABS_PATH in tests/bootstrap.php) ──

    public function testGetImagesDirReturnsImagesDirConstantWhenAbsPathMissing(): void {
        // In the test runtime ABS_PATH is NOT defined (tests/bootstrap.php),
        // so the legacy behaviour must be preserved: IMAGES_DIR as-is.
        $this->assertFalse(defined('ABS_PATH'), 'test runtime must not define ABS_PATH');
        $this->assertSame(rtrim(IMAGES_DIR, '/\\'), $this->uploader->getImagesDir());
    }

    // ── resolveImagesDir: with ABS_PATH (FlatPress 1.4.x / 1.5.x runtime) ──

    public function testResolveImagesDirPrefixesRelativeDirWithAbsPath(): void {
        // FlatPress defaults: ABS_PATH = '/var/www/blog/', IMAGES_DIR = 'fp-content/images/'
        $dir = $this->uploader->resolveImagesDir('fp-content/images/', '/var/www/blog/');
        $this->assertSame('/var/www/blog/fp-content/images', $dir);
    }

    public function testResolveImagesDirHandlesAbsPathWithoutTrailingSlash(): void {
        // ABS_PATH from defaults.php always ends with '/', but be tolerant.
        $dir = $this->uploader->resolveImagesDir('fp-content/images/', '/var/www/blog');
        $this->assertSame('/var/www/blog/fp-content/images', $dir);
    }

    public function testResolveImagesDirRemovesTrailingSlashFromRelativeDir(): void {
        $dir = $this->uploader->resolveImagesDir('images/', '/blog/');
        $this->assertSame('/blog/images', $dir);
    }

    public function testResolveImagesDirDoesNotDoublePrefixAbsoluteImagesDir(): void {
        // Some runtimes define IMAGES_DIR as an absolute path already
        // (e.g. the unit-test bootstrap). It must never be prefixed twice.
        $dir = $this->uploader->resolveImagesDir('/var/www/blog/fp-content/images/', '/var/www/blog/');
        $this->assertSame('/var/www/blog/fp-content/images', $dir);
    }

    public function testResolveImagesDirTreatsWindowsDriveAsAbsolute(): void {
        $dir = $this->uploader->resolveImagesDir('C:\\blog\\fp-content\\images\\', 'D:\\other\\');
        $this->assertSame('C:\\blog\\fp-content\\images', $dir);
    }

    // ── resolveImagesDir: without ABS_PATH (legacy / unit-test runtime) ──

    public function testResolveImagesDirWithoutAbsPathKeepsRelativeDir(): void {
        // FlatPress 1.4.x without ABS_PATH (or custom constants): the bare
        // relative path is kept, exactly as before the regression fix.
        $dir = $this->uploader->resolveImagesDir('fp-content/images/', null);
        $this->assertSame('fp-content/images', $dir);
    }

    public function testResolveImagesDirWithoutAbsPathKeepsAbsoluteDir(): void {
        $dir = $this->uploader->resolveImagesDir('/srv/blog/fp-content/images/', null);
        $this->assertSame('/srv/blog/fp-content/images', $dir);
    }

    public function testResolveImagesDirIgnoresEmptyAbsPath(): void {
        $dir = $this->uploader->resolveImagesDir('fp-content/images/', '');
        $this->assertSame('fp-content/images', $dir);
    }
}