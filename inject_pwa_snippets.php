<?php
// inject_pwa_snippets.php
// Usage: run from project root: php inject_pwa_snippets.php
// It will search for .php files and insert include lines for pwa-head.php and pwa-footer.php
// Backups are made with .bak extension.

$projectFolder = __DIR__ . '';
$headInclude = "<?php include __DIR__.'/pwa-head.php'; ?>\n";
$footerInclude = "\n<?php include __DIR__.'/pwa-footer.php'; ?>";

$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($projectFolder));
foreach ($iterator as $file) {
    if ($file->isFile()) {
        $path = $file->getPathname();
        if (preg_match('/\\.php$/i', $path)) {
            $content = file_get_contents($path);
            $orig = $content;
            // insert head include after <head> tag (if not present)
            if (stripos($content, "pwa-head.php") === false && stripos($content, '<head') !== false) {
                $content = preg_replace('/<head[^>]*>/i', '$0\n' . $headInclude, $content, 1);
            }
            // insert footer include before </body> tag (if not present)
            if (stripos($content, "pwa-footer.php") === false && stripos($content, '</body>') !== false) {
                $content = preg_replace('/<\\/body>/i', $footerInclude . '\n</body>', $content, 1);
            }
            if ($content !== $orig) {
                // backup original
                copy($path, $path . '.bak');
                file_put_contents($path, $content);
                echo "Updated: $path\n";
            }
        }
    }
}
echo "Done. Backups have .bak extension.\n";
?>