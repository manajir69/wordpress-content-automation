<?php
declare(strict_types=1);

namespace App\Services;

use App\Security\Crypto;
use PDO;

final class BlogAutomationService
{
    public function __construct(private PDO $db) {}

    /** @return array<int,array{site_id:int,site_name:string,result:string,message:string,article_id?:int}> */
    public function runDueSites(): array
    {
        $sites = $this->db->query("SELECT id,site_name FROM blog_settings WHERE enabled=1 ORDER BY id")->fetchAll();
        $results = [];
        foreach ($sites as $site) {
            try {
                $result = $this->runSite((int)$site['id'], false);
            } catch (\Throwable $e) {
                $result = ['result' => 'failed', 'message' => substr($e->getMessage(), 0, 1500)];
            }
            $results[] = ['site_id' => (int)$site['id'], 'site_name' => (string)$site['site_name']] + $result;
        }
        return $results;
    }

    /** @return array{result:string,message:string,article_id?:int} */
    public function runSite(int $siteId, bool $manual = false): array
    {
        if ($siteId < 1) throw new \RuntimeException('Select a WordPress site first.');
        $lockName = 'social_blog_automation_' . $siteId;
        $lock = $this->db->prepare('SELECT GET_LOCK(?,1)');
        $lock->execute([$lockName]);
        if ((int)$lock->fetchColumn() !== 1) {
            return ['result' => 'skipped', 'message' => 'Another blog run is already active.'];
        }
        $articleId = null;
        try {
            $settingsQuery = $this->db->prepare('SELECT * FROM blog_settings WHERE id=?');
            $settingsQuery->execute([$siteId]);
            $settings = $settingsQuery->fetch();
            if (!$settings) throw new \RuntimeException('WordPress site not found.');
            if (!$manual && !(bool)$settings['enabled']) return ['result' => 'skipped', 'message' => 'Blog automation is disabled.'];
            if (!$manual && !$this->isDue($settings)) return ['result' => 'skipped', 'message' => 'The next blog article is not due yet.'];

            if (!$manual) {
                $claim = $this->db->prepare('UPDATE blog_settings SET last_run_on=? WHERE id=? AND (last_run_on IS NULL OR last_run_on<>?)');
                $today = date('Y-m-d');
                $claim->execute([$today, $siteId, $today]);
                if ($claim->rowCount() !== 1) return ['result' => 'skipped', 'message' => 'Blog automation already ran today.'];
            }

            $failedQuery = $this->db->prepare("SELECT * FROM blog_articles WHERE blog_site_id=? AND status='failed' AND wp_post_id IS NULL ORDER BY id ASC LIMIT 1");
            $failedQuery->execute([$siteId]);
            $failed = $failedQuery->fetch();
            if ($failed) return $this->retryWordPressDelivery($settings, $failed);

            $provider = in_array((string)($settings['ai_provider'] ?? 'openai'), ['openai','anthropic','qwen'], true)
                ? (string)($settings['ai_provider'] ?? 'openai') : 'openai';
            $apiKey = Crypto::decrypt((string)($settings['encrypted_ai_api_key'] ?? ''));
            if ($apiKey === '') {
                $envKey = ['openai'=>'OPENAI_API_KEY','anthropic'=>'ANTHROPIC_API_KEY','qwen'=>'DASHSCOPE_API_KEY'][$provider];
                $apiKey = trim((string)\env($envKey, ''));
            }
            $appPassword = Crypto::decrypt((string)($settings['encrypted_wp_app_password'] ?? ''));
            $recentQuery = $this->db->prepare('SELECT topic,focus_keyphrase,sources_json FROM blog_articles WHERE blog_site_id=? ORDER BY id DESC LIMIT 50');
            $recentQuery->execute([$siteId]);
            $recentRows = $recentQuery->fetchAll();
            $recent = array_map(static fn(array $row): string => $row['topic'] . ' | ' . $row['focus_keyphrase'], $recentRows);

            $wordpress = new WordPressBlogService();
            $ai = new BlogAIService($apiKey, (string)($settings['ai_model'] ?: 'gpt-5-mini'), $provider);
            $links = $wordpress->linkInventory((string)$settings['site_url']);
            $contentMode = (string)($settings['content_mode'] ?? 'woocommerce');
            if ($contentMode === 'server_folder') {
                $source = (new FolderBlogContentService($this->db))->next($settings);
                $research = $ai->researchFolder($source, $recent, (string)$settings['topics'], (string)($settings['system_prompt'] ?? ''), (string)$settings['site_name']);
                $article = $ai->writeFolder($source, $research, $links, (string)($settings['system_prompt'] ?? ''), (string)$settings['site_name']);
                $uploaded = $wordpress->uploadFolderImages($settings, $appPassword, (array)$source['images'], (string)$article['image_alt']);
                $folderMedia = [
                    'name'=>(string)$source['title'],
                    'url'=>(string)$settings['site_url'],
                    'featured_image'=>$uploaded[0],
                    'images'=>$uploaded,
                    'reference_prefix'=>'Website reference',
                    'link_text'=>'Visit the website',
                ];
                $article = $this->attachProductImages($article, $folderMedia);
                $article = $this->ensureRequiredLinks($article, $folderMedia, $links, (string)$settings['site_url']);
                array_unshift($article['sources'], ['name'=>'Folder Content #' . (string)$source['key'], 'url'=>(string)$settings['site_url']]);
            } else {
                $products = $wordpress->productInventory($settings, $appPassword);
                $product = $this->selectProduct($products, $recentRows);
                if (!$product) throw new \RuntimeException('No published WooCommerce product with a usable product image was found.');
                $research = $ai->researchProduct($product, $recent, (string)$settings['topics'], (string)($settings['system_prompt'] ?? ''), (string)$settings['site_name']);
                $article = $ai->writeProduct($product, $research, $links, (string)($settings['system_prompt'] ?? ''), (string)$settings['site_name']);
                $product = $wordpress->uploadBrandedProductImages($settings, $appPassword, $product, (string)$article['image_alt']);
                $article = $this->attachProductImages($article, $product);
                $article = $this->ensureRequiredLinks($article, $product, $links, (string)$settings['site_url']);
                $article['category_name'] = $this->relevantCategoryName($product);
                $article['source_product_id'] = (int)$product['id'];
                array_unshift($article['sources'], ['name'=>'WooCommerce Product #' . (int)$product['id'], 'url'=>(string)$product['url']]);
            }
            [$seoScore, $seoChecks, $internalLinks] = $this->seoReview($article, (string)$settings['site_url']);

            $insert = $this->db->prepare('INSERT INTO blog_articles(blog_site_id,topic,focus_keyphrase,title,slug,excerpt,content_html,seo_title,meta_description,image_alt,featured_media_id,sources_json,internal_links_json,tags_json,seo_score,seo_checks_json,status) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,"draft")');
            try {
                $insert->execute([
                    $siteId,
                    $article['topic'], $article['focus_keyphrase'], $article['title'], $article['slug'], $article['excerpt'], $article['content_html'],
                    $article['seo_title'], $article['meta_description'], $article['image_alt'], $article['featured_media_id'] ?: null,
                    json_encode($article['sources'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                    json_encode($internalLinks, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                    json_encode($article['tags'], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                    $seoScore, json_encode($seoChecks, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                ]);
            } catch (\PDOException $e) {
                if ((string)$e->getCode() === '23000') throw new \RuntimeException('AI selected a previously used focus keyphrase. Run again for a new topic.');
                throw $e;
            }
            $articleId = (int)$this->db->lastInsertId();

            try {
                $wp = $wordpress->createArticle($settings, $appPassword, $article);
                $wpStatus = (string)($wp['status'] ?? 'draft');
                $localStatus = $wpStatus === 'publish' ? 'published' : 'draft';
                $update = $this->db->prepare('UPDATE blog_articles SET wp_post_id=?,wp_post_url=?,wp_status=?,status=?,published_at=?,error_message=NULL WHERE id=?');
                $update->execute([(int)$wp['id'], (string)($wp['link'] ?? ''), $wpStatus, $localStatus, $localStatus === 'published' ? date('Y-m-d H:i:s') : null, $articleId]);
                $this->db->prepare('UPDATE blog_settings SET last_success_at=?,last_run_on=? WHERE id=?')->execute([date('Y-m-d H:i:s'), date('Y-m-d'), $siteId]);
                $message = ($contentMode === 'server_folder' ? 'Folder-content article ' : 'WooCommerce product article ') . $wpStatus . ' created: ' . $article['title'];
                $this->log($siteId, 'success', $message, $articleId);
                return ['result' => 'success', 'message' => $message, 'article_id' => $articleId];
            } catch (\Throwable $e) {
                $message = substr($e->getMessage(), 0, 1500);
                $this->db->prepare('UPDATE blog_articles SET status="failed",error_message=? WHERE id=?')->execute([$message, $articleId]);
                throw $e;
            }
        } catch (\Throwable $e) {
            $message = substr($e->getMessage(), 0, 1500);
            $this->log($siteId, 'failed', $message, $articleId);
            throw $e;
        } finally {
            $release = $this->db->prepare('SELECT RELEASE_LOCK(?)');
            $release->execute([$lockName]);
        }
    }

    /** @param array<string,mixed> $settings */
    private function isDue(array $settings): bool
    {
        if (date('H:i') < substr((string)$settings['post_time'], 0, 5)) return false;
        if ((string)($settings['last_run_on'] ?? '') === date('Y-m-d')) return false;
        if (empty($settings['last_success_at'])) return true;
        $lastDate = substr((string)$settings['last_success_at'], 0, 10);
        $last = \DateTimeImmutable::createFromFormat('!Y-m-d', $lastDate);
        if (!$last) return true;
        $dueDate = $last->modify('+' . max(1, (int)$settings['frequency_days']) . ' days')->format('Y-m-d');
        return date('Y-m-d') >= $dueDate;
    }

    /** @param array<string,mixed> $settings @param array<string,mixed> $failed @return array{result:string,message:string,article_id:int} */
    private function retryWordPressDelivery(array $settings, array $failed): array
    {
        $article = $failed;
        $article['sources'] = json_decode((string)($failed['sources_json'] ?? ''), true) ?: [];
        $article['tags'] = json_decode((string)($failed['tags_json'] ?? ''), true) ?: [];
        $password = Crypto::decrypt((string)($settings['encrypted_wp_app_password'] ?? ''));
        $wp = (new WordPressBlogService())->createArticle($settings, $password, $article);
        $wpStatus = (string)($wp['status'] ?? 'draft');
        $localStatus = $wpStatus === 'publish' ? 'published' : 'draft';
        $this->db->prepare('UPDATE blog_articles SET wp_post_id=?,wp_post_url=?,wp_status=?,status=?,published_at=?,error_message=NULL WHERE id=?')->execute([
            (int)$wp['id'], (string)($wp['link'] ?? ''), $wpStatus, $localStatus,
            $localStatus === 'published' ? date('Y-m-d H:i:s') : null, (int)$failed['id'],
        ]);
        $siteId = (int)$settings['id'];
        $this->db->prepare('UPDATE blog_settings SET last_success_at=?,last_run_on=? WHERE id=?')->execute([date('Y-m-d H:i:s'), date('Y-m-d'), $siteId]);
        $message = 'Retried WordPress delivery successfully: ' . $failed['title'];
        $this->log($siteId, 'success', $message, (int)$failed['id']);
        return ['result' => 'success', 'message' => $message, 'article_id' => (int)$failed['id']];
    }

    /** @param array<string,mixed> $article @return array{0:int,1:array<string,bool>,2:string[]} */
    private function seoReview(array $article, string $siteUrl): array
    {
        $key = trim((string)$article['focus_keyphrase']);
        $html = (string)$article['content_html'];
        $plain = trim(strip_tags($html));
        $intro = function_exists('mb_substr') ? mb_substr($plain, 0, 700, 'UTF-8') : substr($plain, 0, 700);
        preg_match_all('/<a\s[^>]*href=["\']([^"\']+)["\']/i', $html, $matches);
        $links = array_values(array_unique($matches[1] ?? []));
        $host = strtolower((string)parse_url($siteUrl, PHP_URL_HOST));
        $internal = array_values(array_filter($links, static fn(string $url): bool => strtolower((string)parse_url($url, PHP_URL_HOST)) === $host));
        $external = array_values(array_filter($links, static fn(string $url): bool => ($linkHost = strtolower((string)parse_url($url, PHP_URL_HOST))) !== '' && $linkHost !== $host));
        $checks = [
            'Keyphrase in title' => stripos((string)$article['title'], $key) !== false,
            'Concise SEO title' => $this->textLength((string)$article['seo_title']) >= 35 && $this->textLength((string)$article['seo_title']) <= 65,
            'Keyphrase in slug' => $this->slugContains((string)$article['slug'], $key),
            'Keyphrase in meta description' => stripos((string)$article['meta_description'], $key) !== false,
            'Meta description length' => $this->textLength((string)$article['meta_description']) >= 120 && $this->textLength((string)$article['meta_description']) <= 156,
            'Keyphrase in introduction' => function_exists('mb_stripos') ? mb_stripos($intro, $key, 0, 'UTF-8') !== false : stripos($intro, $key) !== false,
            'Keyphrase in an H2' => preg_match('/<h2[^>]*>[^<]*' . preg_quote($key, '/') . '/i', $html) === 1,
            'No duplicate H1 in body' => preg_match('/<h1\b/i', $html) !== 1,
            'Useful H2 sections' => preg_match_all('/<h2\b/i', $html) >= 3,
            'Internal links' => count($internal) >= 2,
            'Research source links' => count($external) >= 2,
            'Bangla summary' => str_contains($html, 'বাংলায় সংক্ষেপে') && preg_match('/[\x{0980}-\x{09FF}]/u', $html) === 1,
            'Relevant WordPress image' => (int)($article['featured_media_id'] ?? 0) > 0,
            'Image alt text' => stripos((string)($article['image_alt'] ?? ''), $key) !== false && stripos($html, '<img') !== false,
            'Substantial article' => str_word_count($plain) >= 700,
        ];
        $score = (int)round((count(array_filter($checks)) / count($checks)) * 100);
        return [$score, $checks, $internal];
    }

    private function slugContains(string $slug, string $keyphrase): bool
    {
        $needle = strtolower(trim(preg_replace('/[^a-z0-9]+/i', '-', $keyphrase) ?? '', '-'));
        return $needle !== '' && str_contains(strtolower($slug), $needle);
    }

    private function textLength(string $value): int
    {
        return function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value);
    }

    /** @param array<int,array<string,mixed>> $products @param array<int,array<string,mixed>> $recentRows @return array<string,mixed>|null */
    private function selectProduct(array $products, array $recentRows): ?array
    {
        $recentIds = [];
        foreach ($recentRows as $row) {
            $sources = json_decode((string)($row['sources_json'] ?? ''), true);
            if (!is_array($sources)) continue;
            foreach ($sources as $source) {
                if (is_array($source) && preg_match('/^WooCommerce Product #(\d+)$/', (string)($source['name'] ?? ''), $match)) $recentIds[(int)$match[1]] = true;
            }
        }
        $fallback = null;
        foreach ($products as $product) {
            if (!$this->productHasImage($product)) continue;
            $fallback ??= $product;
            if (!isset($recentIds[(int)$product['id']])) return $product;
        }
        return $fallback;
    }

    /** @param array<string,mixed> $product */
    private function productHasImage(array $product): bool
    {
        if (is_array($product['featured_image'] ?? null) && (int)($product['featured_image']['id'] ?? 0) > 0 && str_starts_with((string)($product['featured_image']['url'] ?? ''), 'https://')) return true;
        foreach ((array)($product['images'] ?? []) as $image) {
            if (is_array($image) && (int)($image['id'] ?? 0) > 0 && str_starts_with((string)($image['url'] ?? ''), 'https://')) return true;
        }
        return false;
    }

    /** @param array<string,mixed> $article @param array<string,mixed> $product @return array<string,mixed> */
    private function attachProductImages(array $article, array $product): array
    {
        $featured = is_array($product['featured_image'] ?? null) ? $product['featured_image'] : null;
        $all = [];
        foreach ((array)($product['images'] ?? []) as $image) {
            if (!is_array($image) || !str_starts_with((string)($image['url'] ?? ''), 'https://')) continue;
            $all[(string)$image['url']] = $image;
        }
        if ($featured && str_starts_with((string)($featured['url'] ?? ''), 'https://')) $all[(string)$featured['url']] = $featured;
        if ($featured === null || (int)($featured['id'] ?? 0) < 1) {
            foreach ($all as $image) if ((int)($image['id'] ?? 0) > 0) {$featured = $image; break;}
        }
        if ($featured === null || (int)($featured['id'] ?? 0) < 1) throw new \RuntimeException('The selected product has no WordPress attachment suitable for a featured image.');

        $description = [];
        $other = [];
        foreach ($all as $image) {
            if (($image['source'] ?? '') === 'description') $description[] = $image; else $other[] = $image;
        }
        $candidates = array_values(array_slice(array_merge($description, $other), 0, 5));
        if ($candidates === []) $candidates = [$featured];
        $focusKeyphrase = trim((string)($article['focus_keyphrase'] ?? ''));
        $articleAlt = trim((string)($article['image_alt'] ?? ''));
        if ($articleAlt === '' || ($focusKeyphrase !== '' && stripos($articleAlt, $focusKeyphrase) === false)) {
            $articleAlt = trim($focusKeyphrase . ' – ' . (string)$product['name'], " \t\n\r\0\x0B–");
        }
        $figures = [];
        $imageIds = [];
        foreach ($candidates as $index => $image) {
            $url = htmlspecialchars((string)$image['url'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $sourceAlt = trim((string)($image['alt'] ?? ''));
            $altText = $sourceAlt !== '' && ($focusKeyphrase === '' || stripos($sourceAlt, $focusKeyphrase) !== false)
                ? $sourceAlt
                : $articleAlt . ($index > 0 ? ' – product view ' . ($index + 1) : '');
            $alt = htmlspecialchars($altText, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $figures[] = '<figure class="product-image" style="max-width:768px;margin:24px auto;"><img src="' . $url . '" alt="' . $alt . '" width="768" height="508" loading="lazy" style="display:block;width:100%;max-width:768px;max-height:508px;height:auto;object-fit:contain;"></figure>';
            if ((int)($image['id'] ?? 0) > 0) $imageIds[] = (int)$image['id'];
        }
        $html = (string)$article['content_html'];
        $html = self::spaceArticleImages($html, $figures);
        $article['content_html'] = $html;
        $article['featured_media_id'] = (int)$featured['id'];
        $article['product_image_ids'] = array_values(array_unique(array_merge([(int)$featured['id']], $imageIds)));
        $article['image_alt'] = $articleAlt;
        return $article;
    }

    /** Place images after distinct complete text blocks, never inside a list or table.
     * Surplus images stay out of the body when there is not enough text to separate them.
     * @param string[] $figures
     */
    private static function spaceArticleImages(string $html, array $figures): string
    {
        if ($figures === []) return $html;
        preg_match_all('/<!--.*?-->|<\/?([a-z][a-z0-9]*)\b[^>]*>/is', $html, $tokens, PREG_OFFSET_CAPTURE);
        $stack = []; $slots = []; $blockStart = 0;
        $void = ['area','base','br','col','embed','hr','img','input','link','meta','param','source','track','wbr'];
        foreach ($tokens[0] as $i => [$tag, $offset]) {
            $name = strtolower($tokens[1][$i][0]);
            if ($name === '' || in_array($name, $void, true) || str_ends_with($tag, '/>')) continue;
            if (!str_starts_with($tag, '</')) {
                if ($stack === []) $blockStart = $offset;
                $stack[] = $name;
                continue;
            }
            if ($stack === [] || end($stack) !== $name) continue;
            array_pop($stack);
            if ($stack !== [] || !in_array($name, ['p','ul','ol','blockquote','table','div','section'], true)) continue;
            $end = $offset + strlen($tag);
            $block = substr($html, $blockStart, $end - $blockStart);
            $plain = trim(html_entity_decode(strip_tags($block), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            if ($plain !== '' && !preg_match('/<(?:img|figure)\b/i', $block)) $slots[] = $end;
        }
        $count = min(count($figures), count($slots));
        // Select boundaries from the original HTML; insert backwards so offsets stay valid.
        for ($i = $count - 1; $i >= 0; $i--) {
            $slot = $count === 1 ? 0 : (int)floor($i * count($slots) / $count);
            $offset = $slots[$slot];
            $html = substr($html, 0, $offset) . "\n\n" . $figures[$i] . "\n\n" . substr($html, $offset);
        }
        return $html;
    }

    /** @param array<string,mixed> $article @param array<string,mixed> $product @param array<int,array<string,string>> $inventory @return array<string,mixed> */
    private function ensureRequiredLinks(array $article, array $product, array $inventory, string $siteUrl): array
    {
        $html = (string)$article['content_html'];
        preg_match_all('/<a\s[^>]*href=["\x27]([^"\x27]+)["\x27]/i', $html, $matches);
        $existing = array_values(array_unique(array_map('strval', $matches[1] ?? [])));
        $siteHost = strtolower((string)parse_url($siteUrl, PHP_URL_HOST));
        $productUrl = trim((string)($product['url'] ?? ''));
        if ($productUrl !== '' && !in_array($productUrl, $existing, true)) {
            $prefix = trim((string)($product['reference_prefix'] ?? 'Product reference')) ?: 'Reference';
            $linkText = trim((string)($product['link_text'] ?? 'View the selected product page')) ?: 'View reference';
            $html .= '<p><strong>' . htmlspecialchars($prefix, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . ':</strong> <a href="' . htmlspecialchars($productUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '">' . htmlspecialchars($linkText, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</a>.</p>';
            $existing[] = $productUrl;
        }
        $internalCount = count(array_filter($existing, static fn(string $url): bool => strtolower((string)parse_url($url, PHP_URL_HOST)) === $siteHost));
        $externalCount = count(array_filter($existing, static fn(string $url): bool => ($host = strtolower((string)parse_url($url, PHP_URL_HOST))) !== '' && $host !== $siteHost));

        if ($internalCount < 2) {
            $need = 2 - $internalCount;
            $candidates = [[
                'title' => (string)($product['link_text'] ?? 'View the selected product'),
                'url' => (string)($product['url'] ?? ''),
            ]];
            $inventory = $this->rankInternalLinks($inventory, (string)($product['name'] ?? '') . ' ' . (string)($article['focus_keyphrase'] ?? ''));
            $candidates = array_merge($candidates, $inventory, [['title' => 'Explore more products and guides', 'url' => rtrim($siteUrl, '/') . '/']]);
            $items = [];
            foreach ($candidates as $candidate) {
                $url = trim((string)($candidate['url'] ?? ''));
                if ($url === '' || in_array($url, $existing, true) || strtolower((string)parse_url($url, PHP_URL_HOST)) !== $siteHost) continue;
                $title = trim(strip_tags((string)($candidate['title'] ?? ''))) ?: 'Related information';
                $items[] = '<li><a href="' . htmlspecialchars($url, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '">' . htmlspecialchars($title, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</a></li>';
                $existing[] = $url;
                if (count($items) >= $need) break;
            }
            if ($items !== []) $html .= '<h2>Related product and buying guides</h2><ul>' . implode('', $items) . '</ul>';
        }

        if ($externalCount < 2) {
            $need = 2 - $externalCount;
            $items = [];
            foreach ((array)($article['sources'] ?? []) as $source) {
                if (!is_array($source)) continue;
                $url = trim((string)($source['url'] ?? ''));
                $host = strtolower((string)parse_url($url, PHP_URL_HOST));
                if (!str_starts_with(strtolower($url), 'https://') || $host === '' || $host === $siteHost || in_array($url, $existing, true)) continue;
                $name = trim(strip_tags((string)($source['name'] ?? ''))) ?: $host;
                $items[] = '<li><a href="' . htmlspecialchars($url, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '">' . htmlspecialchars($name, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</a></li>';
                $existing[] = $url;
                if (count($items) >= $need) break;
            }
            if (count($items) < $need) throw new \RuntimeException('Verified research did not provide enough external source links for the product article.');
            $html .= '<h2>Sources and further reading</h2><ul>' . implode('', $items) . '</ul>';
        }

        $article['content_html'] = $html;
        return $article;
    }

    /** @param array<int,array<string,string>> $links @return array<int,array<string,string>> */
    private function rankInternalLinks(array $links, string $context): array
    {
        $tokens = array_values(array_filter(preg_split('/[^\p{L}\p{N}]+/u', strtolower($context)) ?: [], static fn(string $token): bool => strlen($token) >= 4));
        usort($links, static function (array $a, array $b) use ($tokens): int {
            $score = static function (array $link) use ($tokens): int {
                $haystack = strtolower((string)($link['title'] ?? '') . ' ' . (string)($link['url'] ?? ''));
                $total = 0;
                foreach ($tokens as $token) if (str_contains($haystack, $token)) $total++;
                return $total;
            };
            return $score($b) <=> $score($a);
        });
        return $links;
    }

    /** @param array<string,mixed> $product */
    private function relevantCategoryName(array $product): string
    {
        $generic = ['uncategorized', 'products', 'product', 'shop', 'all products'];
        foreach ((array)($product['categories'] ?? []) as $category) {
            $name = trim(html_entity_decode(strip_tags((string)$category), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            if ($name !== '' && !in_array(strtolower($name), $generic, true)) return $this->limitCategoryName($name);
        }
        return 'Buying Guides';
    }

    private function limitCategoryName(string $name): string
    {
        return function_exists('mb_substr') ? mb_substr($name, 0, 60, 'UTF-8') : substr($name, 0, 60);
    }

    private function log(int $siteId, string $result, string $message, ?int $articleId): void
    {
        try {
            $query = $this->db->prepare('INSERT INTO blog_runs(blog_site_id,result,message,article_id) VALUES(?,?,?,?)');
            $query->execute([$siteId, $result, $message, $articleId]);
        } catch (\Throwable) {
            // The original exception is more useful than a secondary logging failure.
        }
    }
}
