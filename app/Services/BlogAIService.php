<?php
declare(strict_types=1);

namespace App\Services;

final class BlogAIService
{
    public function __construct(private string $apiKey, private string $model = 'gpt-5-mini', private string $provider = 'openai')
    {
        if (trim($this->apiKey) === '') throw new \RuntimeException(ucfirst($this->provider) . ' API key is required for researched blog articles.');
        if (trim($this->model) === '') $this->model = 'gpt-5-mini';
        if (!in_array($this->provider, ['openai', 'anthropic', 'qwen'], true)) $this->provider = 'openai';
    }

    public static function defaultPrompt(): string
    {
        return <<<'PROMPT'
You are a senior e-commerce content strategist and SEO editor for the configured WooCommerce website and its Bangladesh audience.

Write original, useful buyer-guidance articles for real Bangladeshi customers. The selected WooCommerce product provides context and a practical example; it must not dominate the article. At least 70% of the article's useful information must come from independent web research, while no more than 30% may discuss the store product. The main language must be natural professional English. Add one short section titled "বাংলায় সংক্ষেপে" with 3–5 genuinely useful Bangla sentences; do not translate the whole article side by side.

Research before writing. Prefer authoritative manufacturers, standards bodies, established design publications and other trustworthy sources. Never copy their wording. Never invent prices, availability, measurements, certifications, guarantees or claims. If sources disagree, omit the claim.

SEO and structure rules:
- One unique focus keyphrase with clear local search intent. Keep location relevance in the article, meta description, internal links and supporting copy; do not force Bangladesh, Dhaka or another location into the title or focus keyphrase.
- The slug, introduction, meta description and at least one H2 must use the focus keyphrase naturally. The title should express the same subject in natural reader-friendly wording and does not need to repeat the exact keyphrase.
- Keep the SEO title concise with the focus keyphrase near the beginning. Write a unique 120–156 character meta description containing the exact keyphrase.
- Do not put an H1 inside the article body; WordPress uses the post title as the only H1.
- Cover the topic thoroughly (normally about 900–1,500 English words when the topic needs it), without padding to reach a word count.
- Use logical H2/H3 sections, short paragraphs, mostly concise active-voice sentences, natural transition words, practical examples and a concise FAQ.
- Use the focus keyphrase and close variations naturally across the article; never force repetition.
- Add 2–4 relevant internal links from the supplied website link inventory when genuinely useful.
- Add followed outbound links to the most useful research sources in context and finish with a short Sources section.
- Avoid keyword stuffing, generic filler, exaggerated sales language and repetitive conclusions.
- Make the article substantially more useful than a summary of search results.
- Write a natural, specific, human-sounding title, normally 5–11 words. Do not copy the full catalog product title into the blog title.
- Do not use a colon, em dash, en dash, vertical bar or spaced hyphen as a title separator.
- Avoid formulaic titles and repeated templates such as "buying guide", "practical guide", "complete guide", "ultimate guide", or a list of verbs such as "choose, size and install". Use such wording only when it is genuinely the clearest description and has not appeared in recent titles.
- Do not add Bangladesh, Dhaka or another location to a title merely for SEO. Include a location only when the article is specifically about that location and the title reads naturally without it being an SEO suffix.
- Do not reproduce or closely paraphrase the product description. Do not add a specification section, specification table or attribute dump; readers can see those on the product page.
- Link naturally to the supplied WooCommerce product and never promote a different product. Put one brief, helpful call to action within the first 20% of the article; promotional copy must remain restrained.
- Do not generate, suggest or embed any image URL; the application inserts only images belonging to the selected product.
- Return only the requested JSON. Never wrap JSON in Markdown fences.
PROMPT;
    }

    public static function folderDefaultPrompt(): string
    {
        return <<<'PROMPT'
You are a senior content strategist and SEO editor for a Bangladesh-focused portfolio or service website.

The administrator supplies a title, description and real project/service images in one server folder. Use that supplied material as first-party context, but write an original article rather than copying it. At least 70% of the article's useful information must come from independent web research; supplied content should remain at or below 30%.

Write natural professional English for Bangladeshi customers, with one short section titled "বাংলায় সংক্ষেপে" containing 3–5 useful Bangla sentences. Use a concise, natural, human-sounding title. Put one restrained, relevant call to action within the first 20% of the article.

SEO and structure rules:
- Use one unique local-intent focus keyphrase naturally in the slug, introduction, meta description and at least one H2. The title should express the same subject naturally and does not have to contain the exact keyphrase.
- Keep location relevance in the article and metadata. Do not force Bangladesh, Dhaka or another location into the title or focus keyphrase unless the article is specifically about that place.
- Write titles normally 5–11 words long. Do not use a colon, em dash, en dash, vertical bar or spaced hyphen as a separator.
- Avoid repeated AI-style formulas such as "buying guide", "practical guide", "complete guide", "ultimate guide", and verb-list endings such as "choose, measure and care".
- Use the WordPress post title as the only H1; never put H1 in content_html.
- Write about 900–1,500 useful words with logical H2/H3 sections, short paragraphs, practical guidance and a concise FAQ.
- Add 2–4 relevant internal links from the supplied website inventory.
- Add followed links to trustworthy external research sources in context and finish with a short Sources section.
- Create one stable, concise WordPress category name of 2–4 words relevant to the topic.
- Never invent project details, prices, measurements, availability, certifications, guarantees or claims.
- Do not return image URLs or image elements; the application inserts only the administrator's folder images.
- Return only the requested JSON with no Markdown fences.
PROMPT;
    }

    /** @param array<string,mixed> $source @param string[] $recentTopics @return array<string,mixed> */
    public function researchFolder(array $source, array $recentTopics, string $topicAngles = '', string $customPrompt = '', string $siteName = 'the website'): array
    {
        $instructions = (trim($customPrompt) !== '' ? trim($customPrompt) : self::folderDefaultPrompt()) . "\n\n" . self::mandatoryPrompt() . <<<'PROMPT'

Mandatory folder requirements:
- The supplied folder title and description define the subject. Do not switch to an unrelated subject.
- Treat supplied statements only as first-party context; never invent missing details.
- Use web search for supporting education and verify external factual claims.
- Return valid JSON only with: topic, focus_keyphrase, search_intent, facts, suggested_sections.
PROMPT;
        $input = "Website/brand: " . trim($siteName) . "\n\nFOLDER TITLE:\n" . $this->limitText((string)($source['title'] ?? ''), 300) .
            "\n\nFOLDER DESCRIPTION:\n" . $this->limitText((string)($source['description'] ?? ''), 5000) .
            "\n\nAdministrator's article angles:\n" . trim($topicAngles) .
            "\n\nRecent topics/keyphrases (do not repeat):\n" . json_encode($recentTopics, JSON_UNESCAPED_UNICODE) .
            "\n\nResearch a useful article angle for this exact folder subject. Use at least 2 reliable external sources.";
        $response = $this->responses([
            'model'=>$this->model,
            'instructions'=>$instructions,
            'tools'=>[['type'=>'web_search','search_context_size'=>'medium']],
            'include'=>['web_search_call.action.sources'],
            'input'=>$input,
            'store'=>false,
        ]);
        $research = $this->decodeJson($this->outputText($response));
        if (trim((string)($research['focus_keyphrase'] ?? '')) === '') throw new \RuntimeException('AI research did not return a folder-content focus keyphrase.');
        $research['topic'] = trim((string)($research['topic'] ?? $source['title'] ?? ''));
        $research['sources'] = $this->normalizeSources($research['facts'] ?? [], $this->collectUrls($response));
        if (count($research['sources']) < 2) throw new \RuntimeException('Folder-content research returned too few usable sources.');
        return $research;
    }

    /** @param array<string,mixed> $source @param array<string,mixed> $research @param array<int,array<string,string>> $links @return array<string,mixed> */
    public function writeFolder(array $source, array $research, array $links, string $customPrompt = '', string $siteName = 'the website'): array
    {
        $instructions = (trim($customPrompt) !== '' ? trim($customPrompt) : self::folderDefaultPrompt()) . "\n\n" . self::mandatoryPrompt() . <<<'PROMPT'

Mandatory folder requirements:
- Stay faithful to the supplied folder subject, without inventing missing project or service details.
- Return no img or figure elements and no image URLs; folder images are inserted after generation.
- Return valid JSON only with no Markdown fence.
PROMPT;
        $input = "Website/brand: " . trim($siteName) . "\n\nFOLDER TITLE:\n" . $this->limitText((string)($source['title'] ?? ''), 300) .
            "\n\nFOLDER DESCRIPTION:\n" . $this->limitText((string)($source['description'] ?? ''), 5000) .
            "\n\nVERIFIED RESEARCH:\n" . json_encode($research, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) .
            "\n\nAVAILABLE INTERNAL LINKS:\n" . json_encode(array_slice($links, 0, 30), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) .
            "\n\nReturn one JSON object with exactly: topic, focus_keyphrase, title, slug, excerpt, content_html, seo_title, meta_description, image_alt, category_name, tags. " .
            "Follow the administrator's editable prompt. Use no H1. Keep meta_description 120–156 characters with the exact focus keyphrase. " .
            "content_html may use only p, h2, h3, ul, ol, li, strong, em, a and blockquote.";
        $response = $this->responses(['model'=>$this->model,'instructions'=>$instructions,'input'=>$input,'store'=>false]);
        $article = $this->decodeJson($this->outputText($response));
        foreach (['title','focus_keyphrase','content_html','seo_title','meta_description'] as $field) {
            if (trim((string)($article[$field] ?? '')) === '') throw new \RuntimeException('AI folder article is missing ' . $field . '.');
        }
        $article['topic'] = trim((string)($article['topic'] ?? $research['topic'] ?? $source['title']));
        $article['focus_keyphrase'] = trim((string)$article['focus_keyphrase']);
        $article['slug'] = $this->slug((string)($article['slug'] ?? $article['focus_keyphrase']));
        $article['excerpt'] = trim((string)($article['excerpt'] ?? ''));
        $article['content_html'] = $this->normalizeHtml((string)$article['content_html']);
        $article['seo_title'] = trim((string)$article['seo_title']);
        $article['meta_description'] = trim((string)$article['meta_description']);
        $article['image_alt'] = trim((string)($article['image_alt'] ?? $article['focus_keyphrase']));
        $article['category_name'] = $this->limitText(trim(strip_tags((string)($article['category_name'] ?? 'Interior Design'))), 60) ?: 'Interior Design';
        $article['tags'] = array_values(array_unique(array_slice(array_filter(array_map('trim', (array)($article['tags'] ?? []))), 0, 6)));
        $article['sources'] = $research['sources'];
        return $article;
    }

    /** @param array<string,mixed> $product @param string[] $recentTopics @return array<string,mixed> */
    public function researchProduct(array $product, array $recentTopics, string $editorialAngles = '', string $customPrompt = '', string $siteName = 'the website'): array
    {
        $instructions = (trim($customPrompt) !== '' ? trim($customPrompt) : self::defaultPrompt()) . "\n\n" . self::mandatoryPrompt() . <<<'PROMPT'

Mandatory product requirements:
- The selected WooCommerce product below is the article subject. Do not switch to another product.
- Treat the supplied product name, category, description summary and URL as first-party context, but never invent a missing fact.
- Use web search for helpful surrounding knowledge and verify every external factual claim.
- Return valid JSON only with: topic, focus_keyphrase, search_intent, facts, suggested_sections.
PROMPT;
        $productFacts = $product;
        unset($productFacts['images'], $productFacts['featured_image']);
        if (isset($productFacts['description'])) $productFacts['description'] = $this->limitText((string)$productFacts['description'], 2000);
        if (isset($productFacts['short_description'])) $productFacts['short_description'] = $this->limitText((string)$productFacts['short_description'], 800);
        $input = "Website/brand: " . trim($siteName) . "\n\nSELECTED WOOCOMMERCE PRODUCT:\n" .
            json_encode($productFacts, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) .
            "\n\nAdministrator's preferred product-article angles:\n" . trim($editorialAngles) .
            "\n\nRecent article topics/keyphrases (create a different angle):\n" . json_encode($recentTopics, JSON_UNESCAPED_UNICODE) .
            "\n\nResearch a useful, purchase-oriented article angle for this exact product. Preserve product facts exactly. Use at least 2 reliable external sources for general guidance.";
        $response = $this->responses([
            'model' => $this->model,
            'instructions' => $instructions,
            'tools' => [['type' => 'web_search', 'search_context_size' => 'medium']],
            'include' => ['web_search_call.action.sources'],
            'input' => $input,
            'store' => false,
        ]);
        $research = $this->decodeJson($this->outputText($response));
        if (trim((string)($research['focus_keyphrase'] ?? '')) === '') throw new \RuntimeException('AI research did not return a product focus keyphrase.');
        $research['topic'] = trim((string)($research['topic'] ?? $product['name'] ?? ''));
        $research['sources'] = $this->normalizeSources($research['facts'] ?? [], $this->collectUrls($response));
        if (count($research['sources']) < 2) throw new \RuntimeException('Product research returned too few usable sources.');
        return $research;
    }

    /** @param array<string,mixed> $product @param array<string,mixed> $research @param array<int,array<string,string>> $links @return array<string,mixed> */
    public function writeProduct(array $product, array $research, array $links, string $customPrompt = '', string $siteName = 'the website'): array
    {
        $instructions = (trim($customPrompt) !== '' ? trim($customPrompt) : self::defaultPrompt()) . "\n\n" . self::mandatoryPrompt() . <<<'PROMPT'

Mandatory product requirements:
- Write about only the selected WooCommerce product and link to its exact product URL.
- Product facts must match the supplied WooCommerce data. Omit missing facts; never guess.
- Return no img or figure elements and no image URLs. Product images are inserted after generation.
- Return valid JSON only, with no Markdown fence.
PROMPT;
        $productFacts = $product;
        unset($productFacts['images'], $productFacts['featured_image']);
        if (isset($productFacts['description'])) $productFacts['description'] = $this->limitText((string)$productFacts['description'], 2000);
        if (isset($productFacts['short_description'])) $productFacts['short_description'] = $this->limitText((string)$productFacts['short_description'], 800);
        $input = "Website/brand: " . trim($siteName) . "\n\nSELECTED PRODUCT:\n" .
            json_encode($productFacts, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) .
            "\n\nVERIFIED RESEARCH:\n" . json_encode($research, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) .
            "\n\nAVAILABLE INTERNAL LINKS:\n" . json_encode(array_slice($links, 0, 30), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) .
            "\n\nReturn one JSON object with exactly: topic, focus_keyphrase, title, slug, excerpt, content_html, seo_title, meta_description, image_alt, tags. " .
            "Write the article by following the administrator's editable writing prompt above. Include the exact product link naturally and use no H1. " .
            "Keep meta_description 120–156 characters with the exact focus keyphrase. content_html may use only p, h2, h3, ul, ol, li, strong, em, a and blockquote.";
        $response = $this->responses(['model'=>$this->model,'instructions'=>$instructions,'input'=>$input,'store'=>false]);
        $article = $this->decodeJson($this->outputText($response));
        foreach (['title','focus_keyphrase','content_html','seo_title','meta_description'] as $field) {
            if (trim((string)($article[$field] ?? '')) === '') throw new \RuntimeException('AI product article is missing ' . $field . '.');
        }
        $article['topic'] = trim((string)($article['topic'] ?? $research['topic'] ?? $product['name']));
        $article['focus_keyphrase'] = trim((string)$article['focus_keyphrase']);
        $article['slug'] = $this->slug((string)($article['slug'] ?? $article['focus_keyphrase']));
        $article['excerpt'] = trim((string)($article['excerpt'] ?? ''));
        $article['content_html'] = $this->normalizeHtml((string)$article['content_html']);
        $article['seo_title'] = trim((string)$article['seo_title']);
        $article['meta_description'] = trim((string)$article['meta_description']);
        $article['image_alt'] = trim((string)($article['image_alt'] ?? $product['name']));
        $article['tags'] = array_values(array_unique(array_slice(array_filter(array_map('trim', (array)($article['tags'] ?? []))), 0, 6)));
        $article['sources'] = $research['sources'];
        return $article;
    }

    private static function mandatoryPrompt(): string
    {
        return <<<'PROMPT'
Mandatory system requirements:
- Use independent web research rather than copying a product feed or source description, and do not invent factual claims.
- WordPress supplies the only H1 from the post title; content_html must contain no H1.
- Titles must sound written for a person: specific, varied and normally 5–11 words. Never append Bangladesh, Dhaka or another location as an SEO suffix.
- Titles must not use a colon, em dash, en dash, vertical bar or spaced hyphen separator. Avoid repetitive guide formulas and action-list endings.
- Return valid JSON only, with no Markdown fence or commentary outside it.
PROMPT;
    }

    /** @param string[] $recentTopics @return array<string,mixed> */
    public function research(string $topicSeeds, array $recentTopics, string $customPrompt = '', string $siteName = 'the website'): array
    {
        $instructions = (trim($customPrompt) !== '' ? trim($customPrompt) : self::defaultPrompt()) . "\n\n" . self::mandatoryPrompt();
        $input = "Website/brand name: " . trim($siteName) . "\n\nChoose one valuable, non-duplicate blog topic and research it on the web.\n\n" .
            "Topic areas supplied by the administrator:\n" . trim($topicSeeds) . "\n\n" .
            "Recently used topics/keyphrases (do not repeat or lightly rephrase):\n" . json_encode($recentTopics, JSON_UNESCAPED_UNICODE) . "\n\n" .
            "Target country: Bangladesh. The source must be independent web research rather than copied product descriptions. " .
            "Return JSON with: topic, focus_keyphrase, search_intent, facts (array of objects with statement, source_name, source_url), and suggested_sections (array). Use at least 3 reliable source URLs.";

        $response = $this->responses([
            'model' => $this->model,
            'instructions' => $instructions,
            'tools' => [['type' => 'web_search', 'search_context_size' => 'medium']],
            'include' => ['web_search_call.action.sources'],
            'input' => $input,
            'store' => false,
        ]);
        $research = $this->decodeJson($this->outputText($response));
        if (trim((string)($research['topic'] ?? '')) === '' || trim((string)($research['focus_keyphrase'] ?? '')) === '') {
            throw new \RuntimeException('AI research did not return a topic and focus keyphrase.');
        }
        $research['sources'] = $this->normalizeSources($research['facts'] ?? [], $this->collectUrls($response));
        if (count($research['sources']) < 2) throw new \RuntimeException('Web research returned too few usable sources.');
        return $research;
    }

    /** @param array<string,mixed> $research @param array<int,array<string,string>> $links @param array<int,array<string,mixed>> $media @return array<string,mixed> */
    public function write(array $research, array $links, array $media, string $customPrompt = '', string $siteName = 'the website'): array
    {
        $instructions = (trim($customPrompt) !== '' ? trim($customPrompt) : self::defaultPrompt()) . "\n\n" . self::mandatoryPrompt();
        $input = "Website/brand name: " . trim($siteName) . "\n\nWrite and self-edit the final article using only the verified research below.\n\nRESEARCH:\n" .
            json_encode($research, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) .
            "\n\nAVAILABLE INTERNAL LINKS FROM THIS WEBSITE (use 2–4 only when relevant):\n" .
            json_encode(array_slice($links, 0, 60), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) .
            "\n\nAVAILABLE WORDPRESS MEDIA (select one relevant id, or 0 only if none fits):\n" .
            json_encode(array_slice($media, 0, 60), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) .
            "\n\nReturn one JSON object with exactly: topic, focus_keyphrase, title, slug, excerpt, content_html, seo_title, meta_description, image_alt, featured_media_id, tags. " .
            "Keep seo_title concise, put the focus keyphrase near its beginning, and keep meta_description between 120 and 156 characters including the exact focus keyphrase. " .
            "content_html may use only p, h2, h3, ul, ol, li, strong, em, a and blockquote. It must contain no h1. tags must be an array of 3–6 short strings.";

        $response = $this->responses([
            'model' => $this->model,
            'instructions' => $instructions,
            'input' => $input,
            'store' => false,
        ]);
        $article = $this->decodeJson($this->outputText($response));
        foreach (['title','focus_keyphrase','content_html','seo_title','meta_description'] as $field) {
            if (trim((string)($article[$field] ?? '')) === '') throw new \RuntimeException('AI article is missing ' . $field . '.');
        }
        $article['topic'] = trim((string)($article['topic'] ?? $research['topic']));
        $article['focus_keyphrase'] = trim((string)$article['focus_keyphrase']);
        $article['slug'] = $this->slug((string)($article['slug'] ?? $article['focus_keyphrase']));
        $article['excerpt'] = trim((string)($article['excerpt'] ?? ''));
        $article['content_html'] = $this->normalizeHtml((string)$article['content_html']);
        $article['seo_title'] = trim((string)$article['seo_title']);
        $article['meta_description'] = trim((string)$article['meta_description']);
        $article['image_alt'] = trim((string)($article['image_alt'] ?? $article['focus_keyphrase']));
        $allowedMedia = [];
        foreach ($media as $item) $allowedMedia[(int)($item['id'] ?? 0)] = $item;
        $selectedMediaId = max(0, (int)($article['featured_media_id'] ?? 0));
        $article['featured_media_id'] = isset($allowedMedia[$selectedMediaId]) ? $selectedMediaId : 0;
        if ($article['featured_media_id'] > 0 && stripos($article['content_html'], '<img') === false) {
            $selected = $allowedMedia[$article['featured_media_id']];
            $src = htmlspecialchars((string)$selected['url'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $alt = htmlspecialchars($article['image_alt'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $article['content_html'] = '<figure><img src="' . $src . '" alt="' . $alt . '"></figure>' . $article['content_html'];
        }
        $article['tags'] = array_values(array_unique(array_slice(array_filter(array_map('trim', (array)($article['tags'] ?? []))), 0, 6)));
        $article['sources'] = $research['sources'];
        return $article;
    }

    /** @param array<string,mixed> $payload @return array<string,mixed> */
    private function responses(array $payload): array
    {
        if ($this->provider === 'anthropic') return $this->anthropicResponse($payload);
        if ($this->provider === 'qwen') return $this->qwenResponse($payload);

        $usesBuiltInTools = !empty($payload['tools']);
        $payload['max_output_tokens'] ??= $usesBuiltInTools ? 4000 : 6000;
        $payload['reasoning'] ??= ['effort' => 'low'];
        $payload['text'] ??= ['verbosity' => $usesBuiltInTools ? 'low' : 'medium'];
        if ($usesBuiltInTools) $payload['max_tool_calls'] ??= 3;

        return $this->jsonRequest(
            'https://api.openai.com/v1/responses',
            ['Content-Type: application/json', 'Authorization: Bearer ' . $this->apiKey],
            $payload,
            'OpenAI'
        );
    }

    /** @param array<string,mixed> $payload @return array<string,mixed> */
    private function anthropicResponse(array $payload): array
    {
        $messages = [['role' => 'user', 'content' => (string)($payload['input'] ?? '')]];
        $request = [
            'model' => $this->model,
            'max_tokens' => 8000,
            'system' => (string)($payload['instructions'] ?? ''),
            'messages' => $messages,
        ];
        if (!empty($payload['tools'])) {
            $request['tools'] = [['type' => 'web_search_20250305', 'name' => 'web_search', 'max_uses' => 6]];
        }
        $headers = ['Content-Type: application/json', 'x-api-key: ' . $this->apiKey, 'anthropic-version: 2023-06-01'];
        $decoded = $this->jsonRequest('https://api.anthropic.com/v1/messages', $headers, $request, 'Claude');
        if (($decoded['stop_reason'] ?? '') === 'pause_turn') {
            $request['messages'][] = ['role' => 'assistant', 'content' => (array)($decoded['content'] ?? [])];
            $decoded = $this->jsonRequest('https://api.anthropic.com/v1/messages', $headers, $request, 'Claude');
        }
        $parts = [];
        foreach ((array)($decoded['content'] ?? []) as $block) {
            if (($block['type'] ?? '') === 'text' && is_string($block['text'] ?? null)) $parts[] = $block['text'];
        }
        $decoded['output_text'] = trim(implode("\n", $parts));
        return $decoded;
    }

    /** @param array<string,mixed> $payload @return array<string,mixed> */
    private function qwenResponse(array $payload): array
    {
        $request = [
            'model' => $this->model,
            'messages' => [
                ['role' => 'system', 'content' => (string)($payload['instructions'] ?? '')],
                ['role' => 'user', 'content' => (string)($payload['input'] ?? '')],
            ],
        ];
        if (!empty($payload['tools'])) {
            $request['enable_search'] = true;
            $request['search_options'] = ['forced_search' => true, 'search_strategy' => 'max'];
        }
        $decoded = $this->jsonRequest(
            'https://dashscope-intl.aliyuncs.com/compatible-mode/v1/chat/completions',
            ['Content-Type: application/json', 'Authorization: Bearer ' . $this->apiKey],
            $request,
            'Qwen'
        );
        $content = $decoded['choices'][0]['message']['content'] ?? '';
        if (is_array($content)) {
            $parts = [];
            foreach ($content as $block) if (is_array($block) && is_string($block['text'] ?? null)) $parts[] = $block['text'];
            $content = implode("\n", $parts);
        }
        $decoded['output_text'] = is_string($content) ? trim($content) : '';
        return $decoded;
    }

    /** @param string[] $headers @param array<string,mixed> $payload @return array<string,mixed> */
    private function jsonRequest(string $url, array $headers, array $payload, string $providerName): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 300,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
        ]);
        $raw = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        if ($raw === false || $status < 200 || $status >= 300) {
            throw new \RuntimeException($providerName . ' blog request failed (HTTP ' . $status . '): ' . ($error ?: substr((string)$raw, 0, 900)));
        }
        $decoded = json_decode((string)$raw, true);
        if (!is_array($decoded)) throw new \RuntimeException($providerName . ' returned invalid JSON.');
        return $decoded;
    }

    /** @param array<string,mixed> $response */
    private function outputText(array $response): string
    {
        if (isset($response['output_text']) && is_string($response['output_text'])) return $response['output_text'];
        $parts = [];
        foreach ((array)($response['output'] ?? []) as $item) {
            foreach ((array)($item['content'] ?? []) as $content) {
                if (($content['type'] ?? '') === 'output_text' && is_string($content['text'] ?? null)) $parts[] = $content['text'];
            }
        }
        $text = trim(implode("\n", $parts));
        if ($text === '') throw new \RuntimeException(ucfirst($this->provider) . ' returned no blog text.');
        return $text;
    }

    /** @return array<string,mixed> */
    private function decodeJson(string $text): array
    {
        $text = trim(preg_replace('/^```(?:json)?\s*|\s*```$/i', '', trim($text)) ?? $text);
        $start = strpos($text, '{');
        $end = strrpos($text, '}');
        if ($start !== false && $end !== false) $text = substr($text, $start, $end - $start + 1);
        $decoded = json_decode($text, true);
        if (!is_array($decoded)) throw new \RuntimeException('AI returned malformed blog JSON.');
        return $decoded;
    }

    /** @param mixed $facts @param string[] $extraUrls @return array<int,array<string,string>> */
    private function normalizeSources(mixed $facts, array $extraUrls): array
    {
        $sources = [];
        foreach ((array)$facts as $fact) {
            if (!is_array($fact)) continue;
            $url = trim((string)($fact['source_url'] ?? ''));
            if (!$this->validUrl($url)) continue;
            $sources[$url] = ['name' => trim((string)($fact['source_name'] ?? parse_url($url, PHP_URL_HOST))), 'url' => $url];
        }
        foreach ($extraUrls as $url) {
            if ($this->validUrl($url) && !isset($sources[$url])) $sources[$url] = ['name' => (string)parse_url($url, PHP_URL_HOST), 'url' => $url];
        }
        return array_values(array_slice($sources, 0, 12, true));
    }

    /** @param mixed $value @return string[] */
    private function collectUrls(mixed $value): array
    {
        $urls = [];
        if (is_array($value)) {
            foreach ($value as $key => $child) {
                if ($key === 'url' && is_string($child) && $this->validUrl($child)) $urls[] = $child;
                else $urls = array_merge($urls, $this->collectUrls($child));
            }
        }
        return array_values(array_unique($urls));
    }

    private function validUrl(string $url): bool
    {
        return filter_var($url, FILTER_VALIDATE_URL) !== false && str_starts_with(strtolower($url), 'https://');
    }

    private function slug(string $value): string
    {
        $slug = strtolower(trim($value));
        $slug = preg_replace('/[^a-z0-9]+/', '-', $slug) ?? '';
        $slug = trim($slug, '-');
        return substr($slug !== '' ? $slug : 'interior-guide-bangladesh-' . date('Ymd'), 0, 190);
    }

    private function normalizeHtml(string $html): string
    {
        $html = preg_replace_callback('/<\/?h1\b[^>]*>/i', static fn(array $m): string => str_starts_with(strtolower($m[0]), '</') ? '</h2>' : '<h2>', $html) ?? $html;
        $html = preg_replace('/<figure\b[^>]*>.*?<\/figure>/is', '', $html) ?? $html;
        $html = preg_replace('/<img\b[^>]*>/i', '', $html) ?? $html;
        return trim($html);
    }

    private function limitText(string $value, int $limit): string
    {
        return function_exists('mb_substr') ? mb_substr($value, 0, $limit, 'UTF-8') : substr($value, 0, $limit);
    }
}
