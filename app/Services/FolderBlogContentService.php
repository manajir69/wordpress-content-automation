<?php
declare(strict_types=1);

namespace App\Services;

use PDO;

final class FolderBlogContentService
{
    public const ROOT = BASE_PATH . '/page-content';

    public function __construct(private PDO $db) {}

    /** @param array<string,mixed> $settings */
    public function validate(array $settings): void
    {
        $slug = self::normalizeName((string)($settings['folder_source_slug'] ?? ''));
        $root = realpath(self::ROOT);
        $siteRoot = realpath(self::ROOT . '/' . $slug);
        if ($root === false || $siteRoot === false || !is_dir($siteRoot) || !str_starts_with($siteRoot, $root . DIRECTORY_SEPARATOR)) {
            throw new \RuntimeException('Folder source was not found: page-content/' . $slug);
        }
        foreach (array_values(array_filter(glob($siteRoot . '/*') ?: [], 'is_dir')) as $folder) {
            try {
                $this->readFolder($siteRoot, $folder, $slug . '/' . basename($folder));
                return;
            } catch (\RuntimeException) {
                continue;
            }
        }
        throw new \RuntimeException('Folder source has no valid topic folder with title.txt, description.txt and an image.');
    }

    /** @param array<string,mixed> $settings @return array<string,mixed> */
    public function next(array $settings): array
    {
        $slug = self::normalizeName((string)($settings['folder_source_slug'] ?? ''));
        $this->validate($settings);
        $siteRoot = (string)realpath(self::ROOT . '/' . $slug);
        $used = $this->usedKeys((int)$settings['id']);
        $folders = array_values(array_filter(glob($siteRoot . '/*') ?: [], 'is_dir'));
        usort($folders, static fn(string $a, string $b): int => strnatcasecmp(basename($a), basename($b)));
        $problems = [];
        foreach ($folders as $folder) {
            $relative = $slug . '/' . basename($folder);
            $key = hash('sha256', $relative);
            if (isset($used[$key])) continue;
            try {
                return $this->readFolder($siteRoot, $folder, $relative) + ['key'=>$key, 'folder'=>basename($folder), 'source_slug'=>$slug];
            } catch (\RuntimeException $e) {
                $problems[] = basename($folder) . ': ' . $e->getMessage();
            }
        }
        $detail = $problems === [] ? '' : ' First invalid folder: ' . $problems[0];
        throw new \RuntimeException('No unused valid blog content folder is available.' . $detail);
    }

    public static function normalizeName(string $input): string
    {
        $name = trim(str_replace('\\', '/', $input), " \t\n\r\0\x0B/");
        if (str_contains($name, '/')) $name = basename($name);
        if (!preg_match('/^[\p{L}\p{N}][\p{L}\p{N} _-]{0,63}$/u', $name)) {
            throw new \RuntimeException('Folder source name may contain letters, numbers, spaces, dash, underscore or Bangla; maximum 64 characters.');
        }
        return $name;
    }

    /** @return array<string,bool> */
    private function usedKeys(int $siteId): array
    {
        $query = $this->db->prepare('SELECT sources_json FROM blog_articles WHERE blog_site_id=?');
        $query->execute([$siteId]);
        $used = [];
        foreach ($query->fetchAll(PDO::FETCH_COLUMN) as $json) {
            $sources = json_decode((string)$json, true);
            if (!is_array($sources)) continue;
            foreach ($sources as $source) {
                if (is_array($source) && preg_match('/^Folder Content #([a-f0-9]{64})$/', (string)($source['name'] ?? ''), $match)) $used[$match[1]] = true;
            }
        }
        return $used;
    }

    /** @return array{title:string,description:string,images:array<int,array<string,string>>} */
    private function readFolder(string $siteRoot, string $folder, string $relative): array
    {
        $realFolder = realpath($folder);
        if ($realFolder === false || !str_starts_with($realFolder, $siteRoot . DIRECTORY_SEPARATOR)) throw new \RuntimeException('Unsafe folder path.');
        $titlePath = $realFolder . '/title.txt';
        $descriptionPath = $realFolder . '/description.txt';
        if (!is_file($titlePath) || !is_file($descriptionPath)) throw new \RuntimeException('title.txt and description.txt are required.');
        $title = $this->readText($titlePath, 300);
        $description = $this->readText($descriptionPath, 20000);
        if ($title === '' || $description === '') throw new \RuntimeException('title.txt and description.txt cannot be empty.');
        $images = [];
        foreach (glob($realFolder . '/*') ?: [] as $file) {
            if (!is_file($file) || !preg_match('/\.(?:jpe?g|png|webp)$/i', $file)) continue;
            $realFile = realpath($file);
            if ($realFile === false || !str_starts_with($realFile, $realFolder . DIRECTORY_SEPARATOR)) continue;
            $info = @getimagesize($realFile);
            $mime = (string)($info['mime'] ?? '');
            if ($info === false || !in_array($mime, ['image/jpeg','image/png','image/webp'], true)) continue;
            $images[] = ['path'=>$realFile, 'filename'=>basename($realFile), 'mime'=>$mime, 'relative'=>$relative . '/' . basename($realFile)];
        }
        usort($images, static fn(array $a, array $b): int => strnatcasecmp($a['filename'], $b['filename']));
        $images = array_slice($images, 0, 5);
        if ($images === []) throw new \RuntimeException('Add at least one JPG, PNG or WebP image.');
        return ['title'=>$title, 'description'=>$description, 'images'=>$images];
    }

    private function readText(string $path, int $limit): string
    {
        $text = file_get_contents($path, false, null, 0, $limit + 1);
        if ($text === false || strlen($text) > $limit) throw new \RuntimeException(basename($path) . ' could not be read or is too large.');
        return trim((string)preg_replace('/\R{3,}/u', "\n\n", str_replace("\0", '', $text)));
    }
}
