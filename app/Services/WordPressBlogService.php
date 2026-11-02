<?php
declare(strict_types=1);

namespace App\Services;

final class WordPressBlogService
{
    /** @param array<string,mixed> $settings @return array<string,mixed> */
    public function testConnection(array $settings, string $applicationPassword): array
    {
        $siteUrl = $this->siteUrl((string)($settings['site_url'] ?? ''));
        $username = trim((string)($settings['wp_username'] ?? ''));
        if ($username === '' || $applicationPassword === '') throw new \RuntimeException('Save the WordPress username and Application Password first.');
        return $this->request('GET', $siteUrl . '/wp-json/portfolio-blog/v1/status', $username, $applicationPassword);
    }

    /** @param array<string,mixed> $settings @return array<int,array<string,mixed>> */
    public function productInventory(array $settings, string $applicationPassword): array
    {
        $siteUrl = $this->siteUrl((string)($settings['site_url'] ?? ''));
        $username = trim((string)($settings['wp_username'] ?? ''));
        if ($username === '' || $applicationPassword === '') throw new \RuntimeException('WordPress credentials are required to read WooCommerce products.');
        $response = $this->request('GET', $siteUrl . '/wp-json/portfolio-blog/v1/products?limit=60', $username, $applicationPassword);
        $products = $response['products'] ?? [];
        if (!is_array($products)) throw new \RuntimeException('WordPress connector returned an invalid product list.');
        return array_values(array_filter($products, static fn(mixed $product): bool => is_array($product) && (int)($product['id'] ?? 0) > 0 && trim((string)($product['name'] ?? '')) !== ''));
    }

    /** @return array<int,array{title:string,url:string,type:string}> */
    public function linkInventory(string $siteUrl): array
    {
        $siteUrl = $this->siteUrl($siteUrl);
        $inventory = [];
        try {
            $rows = $this->request('GET', $siteUrl . '/wp-json/wp/v2/search?per_page=100&type=post&_fields=title,url,subtype', '', '');
            foreach ($rows as $row) {
                $title = html_entity_decode(strip_tags((string)($row['title'] ?? '')), ENT_QUOTES | ENT_HTML5, 'UTF-8');
                $url = (string)($row['url'] ?? '');
                if ($title !== '' && $this->isPublicHttpsUrl($url)) {
                    $inventory[] = ['title' => $title, 'url' => $url, 'type' => (string)($row['subtype'] ?? 'post')];
                }
            }
        } catch (\Throwable) {
            // Fall back to the standard posts/pages endpoints below.
        }
        if ($inventory !== []) return array_slice($inventory, 0, 100);
        foreach (['posts', 'pages'] as $type) {
            try {
                $rows = $this->request('GET', $siteUrl . '/wp-json/wp/v2/' . $type . '?per_page=40&status=publish&_fields=link,title', '', '');
            } catch (\Throwable) {
                continue;
            }
            if (!is_array($rows)) continue;
            foreach ($rows as $row) {
                $title = html_entity_decode(strip_tags((string)($row['title']['rendered'] ?? '')), ENT_QUOTES | ENT_HTML5, 'UTF-8');
                $url = (string)($row['link'] ?? '');
                if ($title !== '' && $this->isPublicHttpsUrl($url)) {
                    $inventory[] = ['title' => $title, 'url' => $url, 'type' => rtrim($type, 's')];
                }
            }
        }
        return array_slice($inventory, 0, 60);
    }

    /** @return array<int,array{id:int,title:string,url:string,alt:string}> */
    public function mediaInventory(string $siteUrl): array
    {
        $siteUrl = $this->siteUrl($siteUrl);
        try {
            $rows = $this->request('GET', $siteUrl . '/wp-json/wp/v2/media?per_page=60&media_type=image&status=inherit&_fields=id,source_url,alt_text,title', '', '');
        } catch (\Throwable) {
            return [];
        }
        $media = [];
        foreach ($rows as $row) {
            $url = (string)($row['source_url'] ?? '');
            if (!$this->isPublicHttpsUrl($url)) continue;
            $media[] = [
                'id' => (int)($row['id'] ?? 0),
                'title' => html_entity_decode(strip_tags((string)($row['title']['rendered'] ?? '')), ENT_QUOTES | ENT_HTML5, 'UTF-8'),
                'url' => $url,
                'alt' => trim((string)($row['alt_text'] ?? '')),
            ];
        }
        return array_values(array_filter($media, static fn(array $item): bool => $item['id'] > 0));
    }

    /** @param array<string,mixed> $settings @param array<int,array<string,string>> $images @return array<int,array{id:int,url:string,alt:string,source:string}> */
    public function uploadFolderImages(array $settings, string $applicationPassword, array $images, string $altBase): array
    {
        $siteUrl = $this->siteUrl((string)($settings['site_url'] ?? ''));
        $username = trim((string)($settings['wp_username'] ?? ''));
        if ($username === '' || $applicationPassword === '') throw new \RuntimeException('WordPress credentials are required to upload folder images.');
        $uploaded = [];
        foreach (array_slice($images, 0, 5) as $index => $image) {
            $path = (string)($image['path'] ?? '');
            $mime = (string)($image['mime'] ?? '');
            if (!is_file($path) || !in_array($mime, ['image/jpeg','image/png','image/webp'], true)) continue;
            if ((int)filesize($path) > 12 * 1024 * 1024) throw new \RuntimeException('Folder image exceeds the 12 MB upload limit: ' . basename($path));
            $filename = preg_replace('/[^A-Za-z0-9._-]+/', '-', basename((string)($image['filename'] ?? basename($path)))) ?: 'blog-image-' . ($index + 1) . '.jpg';
            $prepared = BlogImageBrandingService::apply($settings, $path, $filename);
            $path = $prepared['path']; $mime = $prepared['mime']; $filename = $prepared['filename'];
            $media = $this->uploadMedia($siteUrl, $username, $applicationPassword, $path, $filename, $mime);
            $id = (int)($media['id'] ?? 0);
            if ($id < 1) throw new \RuntimeException('WordPress returned an invalid media ID.');
            $alt = trim($altBase) . ($index > 0 ? ' – image ' . ($index + 1) : '');
            try {
                $this->request('POST', $siteUrl . '/wp-json/wp/v2/media/' . $id, $username, $applicationPassword, ['alt_text'=>$alt]);
            } catch (\Throwable) {
                // The article still receives its own image alt text below.
            }
            $url = (string)($media['media_details']['sizes']['medium_large']['source_url'] ?? $media['source_url'] ?? '');
            if (!$this->isPublicHttpsUrl($url)) throw new \RuntimeException('WordPress returned an invalid media URL.');
            $uploaded[] = ['id'=>$id, 'url'=>$url, 'alt'=>$alt, 'source'=>'folder'];
        }
        if ($uploaded === []) throw new \RuntimeException('No folder image could be uploaded to WordPress.');
        return $uploaded;
    }

    /**
     * Upload branded copies of WooCommerce product images without modifying
     * the originals. The returned product keeps its commercial metadata but
     * points article rendering at the new WordPress media attachments.
     *
     * @param array<string,mixed> $settings @param array<string,mixed> $product
     * @return array<string,mixed>
     */
    public function uploadBrandedProductImages(array $settings,string $applicationPassword,array $product,string $altBase):array
    {
        if(empty($settings['blog_branding_enabled']))return $product;
        $siteUrl=$this->siteUrl((string)($settings['site_url']??''));$username=trim((string)($settings['wp_username']??''));
        if($username===''||$applicationPassword==='')throw new \RuntimeException('WordPress credentials are required to upload branded product images.');
        $sourceImages=[];$featured=is_array($product['featured_image']??null)?$product['featured_image']:null;
        if($featured&&$this->isPublicHttpsUrl((string)($featured['url']??'')))$sourceImages[(string)$featured['url']]=$featured;
        foreach((array)($product['images']??[]) as $image)if(is_array($image)&&$this->isPublicHttpsUrl((string)($image['url']??'')))$sourceImages[(string)$image['url']]=$image;
        $uploaded=[];
        foreach(array_slice(array_values($sourceImages),0,5) as $index=>$image){
            $download=$this->downloadImage((string)$image['url']);
            try{
                $filename='product-'.max(1,(int)($product['id']??0)).'-'.($index+1).'.'.$download['extension'];
                $prepared=BlogImageBrandingService::apply($settings,$download['path'],$filename);
                $media=$this->uploadMedia($siteUrl,$username,$applicationPassword,$prepared['path'],$prepared['filename'],$prepared['mime']);$id=(int)($media['id']??0);
                if($id<1)throw new \RuntimeException('WordPress returned an invalid branded media ID.');
                $alt=trim($altBase).($index>0?' – image '.($index+1):'');
                try{$this->request('POST',$siteUrl.'/wp-json/wp/v2/media/'.$id,$username,$applicationPassword,['alt_text'=>$alt]);}catch(\Throwable){}
                $url=(string)($media['media_details']['sizes']['medium_large']['source_url']??$media['source_url']??'');
                if(!$this->isPublicHttpsUrl($url))throw new \RuntimeException('WordPress returned an invalid branded media URL.');
                $uploaded[]=['id'=>$id,'url'=>$url,'alt'=>$alt,'source'=>(string)($image['source']??'product')];
            }finally{@unlink($download['path']);}
        }
        if($uploaded===[])throw new \RuntimeException('No WooCommerce product image could be branded and uploaded.');
        $product['featured_image']=$uploaded[0];$product['images']=$uploaded;
        return $product;
    }

    /** @param array<string,mixed> $article @return array<string,mixed> */
    public function createArticle(array $settings, string $applicationPassword, array $article): array
    {
        $siteUrl = $this->siteUrl((string)($settings['site_url'] ?? ''));
        $username = trim((string)($settings['wp_username'] ?? ''));
        if ($username === '' || $applicationPassword === '') {
            throw new \RuntimeException('WordPress username and Application Password are required.');
        }

        $payload = [
            'title' => (string)$article['title'],
            'slug' => (string)$article['slug'],
            'content_html' => (string)$article['content_html'],
            'excerpt' => (string)$article['excerpt'],
            'status' => ($settings['publish_mode'] ?? 'draft') === 'publish' ? 'publish' : 'draft',
            'focus_keyphrase' => (string)$article['focus_keyphrase'],
            'seo_title' => (string)$article['seo_title'],
            'meta_description' => (string)$article['meta_description'],
            'image_alt' => (string)($article['image_alt'] ?? ''),
            'tags' => array_values(array_slice(array_filter((array)($article['tags'] ?? []), 'is_string'), 0, 8)),
            'sources' => array_values((array)($article['sources'] ?? [])),
            'category_id' => max(0, (int)($settings['category_id'] ?? 0)),
            'category_name' => trim((string)($article['category_name'] ?? 'Buying Guides')),
            'featured_media_id' => max(0, (int)($article['featured_media_id'] ?? $settings['featured_media_id'] ?? 0)),
            'source_product_id' => max(0, (int)($article['source_product_id'] ?? 0)),
            'product_image_ids' => array_values(array_filter(array_map('intval', (array)($article['product_image_ids'] ?? [])), static fn(int $id): bool => $id > 0)),
        ];

        $result = $this->request(
            'POST',
            $siteUrl . '/wp-json/portfolio-blog/v1/articles',
            $username,
            $applicationPassword,
            $payload
        );
        if (empty($result['id'])) {
            throw new \RuntimeException('WordPress connector returned an invalid response.');
        }
        return $result;
    }

    private function siteUrl(string $url): string
    {
        $url = rtrim(trim($url), '/');
        if (!$this->isPublicHttpsUrl($url)) {
            throw new \RuntimeException('WordPress Site URL must be a public HTTPS URL.');
        }
        return $url;
    }

    private function isPublicHttpsUrl(string $url): bool
    {
        if (!filter_var($url, FILTER_VALIDATE_URL) || !str_starts_with(strtolower($url), 'https://')) return false;
        $host = strtolower((string)parse_url($url, PHP_URL_HOST));
        if ($host === '' || $host === 'localhost' || str_ends_with($host, '.local')) return false;
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;
        }
        return true;
    }

    /** @return array<string,mixed> */
    private function uploadMedia(string $siteUrl, string $username, string $password, string $path, string $filename, string $mime): array
    {
        $body = file_get_contents($path);
        if ($body === false) throw new \RuntimeException('Could not read folder image: ' . basename($path));
        $ch = curl_init($siteUrl . '/wp-json/wp/v2/media');
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 120,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_HTTPHEADER => ['Accept: application/json', 'Content-Type: ' . $mime, 'Content-Disposition: attachment; filename="' . $filename . '"'],
            CURLOPT_USERPWD => $username . ':' . $password,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_FOLLOWLOCATION => false,
        ]);
        $raw = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        if ($raw === false || $status < 200 || $status >= 300) {
            throw new \RuntimeException('WordPress media upload failed (HTTP ' . $status . '): ' . ($error ?: substr((string)$raw, 0, 700)));
        }
        $decoded = json_decode((string)$raw, true);
        if (!is_array($decoded)) throw new \RuntimeException('WordPress returned invalid media JSON.');
        return $decoded;
    }

    /** @return array{path:string,mime:string,extension:string} */
    private function downloadImage(string $url):array
    {
        if(!$this->isPublicHttpsUrl($url))throw new \RuntimeException('Product image URL must be public HTTPS.');
        $data='';$tooLarge=false;$ch=curl_init($url);
        curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>false,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_TIMEOUT=>45,CURLOPT_CONNECTTIMEOUT=>10,CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,CURLOPT_WRITEFUNCTION=>static function($ch,string $chunk)use(&$data,&$tooLarge):int{if(strlen($data)+strlen($chunk)>12*1024*1024){$tooLarge=true;return 0;}$data.=$chunk;return strlen($chunk);}]);
        $ok=curl_exec($ch);$status=(int)curl_getinfo($ch,CURLINFO_RESPONSE_CODE);$error=curl_error($ch);curl_close($ch);
        if($tooLarge)throw new \RuntimeException('Product image is larger than 12 MB.');
        if($ok===false||$status<200||$status>=300)throw new \RuntimeException('Product image download failed (HTTP '.$status.'): '.$error);
        $info=@getimagesizefromstring($data);$mime=(string)($info['mime']??'');$extensions=['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp'];
        if($info===false||!isset($extensions[$mime])||(int)($info[0]??0)<320||(int)($info[1]??0)<320)throw new \RuntimeException('Product image is invalid or smaller than 320 × 320 pixels.');
        $path=tempnam(sys_get_temp_dir(),'blog-brand-');if($path===false||file_put_contents($path,$data,LOCK_EX)===false)throw new \RuntimeException('Could not create a temporary product image.');
        return ['path'=>$path,'mime'=>$mime,'extension'=>$extensions[$mime]];
    }

    /** @param array<string,mixed>|null $payload @return array<mixed> */
    private function request(string $method, string $url, string $username, string $password, ?array $payload = null): array
    {
        $headers = ['Accept: application/json'];
        if ($payload !== null) $headers[] = 'Content-Type: application/json';
        $ch = curl_init($url);
        $options = [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 60,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_FOLLOWLOCATION => false,
        ];
        if ($username !== '') $options[CURLOPT_USERPWD] = $username . ':' . $password;
        if ($payload !== null) $options[CURLOPT_POSTFIELDS] = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        curl_setopt_array($ch, $options);
        $raw = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        if ($raw === false || $status < 200 || $status >= 300) {
            throw new \RuntimeException('WordPress request failed (HTTP ' . $status . '): ' . ($error ?: substr((string)$raw, 0, 700)));
        }
        $decoded = json_decode((string)$raw, true);
        if (!is_array($decoded)) throw new \RuntimeException('WordPress returned invalid JSON.');
        return $decoded;
    }
}
