<?php
declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

use App\Database;
use App\Security\Auth;
use App\Security\Crypto;
use App\Security\Csrf;
use App\Services\BlogAIService;
use App\Services\BlogAutomationService;
use App\Services\BlogImageBrandingService;
use App\Services\BrandingText;
use App\Services\FolderBlogContentService;
use App\Services\WordPressBlogService;

header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: no-referrer');
header("Permissions-Policy: camera=(), microphone=(), geolocation=()");
header("Content-Security-Policy: default-src 'self'; img-src 'self' https: data: blob:; style-src 'self' 'unsafe-inline'; form-action 'self'; frame-ancestors 'none'; base-uri 'self'");
if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') header('Strict-Transport-Security: max-age=31536000');

$db = Database::connection();
$route = (string)($_GET['view'] ?? (Auth::check() ? 'dashboard' : 'login'));
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        Csrf::verify();
        handlePost($route, $db);
    } catch (Throwable $e) {
        flash('error', substr($e->getMessage(), 0, 1500));
        $site = max(0, (int)($_POST['site_id'] ?? 0));
        redirect('index.php?view=' . ($route === 'login' ? 'login' : 'sites') . ($site ? '&site=' . $site : ''));
    }
}

if ($route === 'login') {
    if (Auth::check()) redirect('index.php');
    render('Login', loginScreen(), false, 'login');
    exit;
}
if ($route === 'logout') {
    redirect(Auth::check() ? 'index.php' : 'index.php?view=login');
}
Auth::require();
if (!in_array($route, ['dashboard','sites'], true)) $route = 'dashboard';
$content = $route === 'sites' ? sitesScreen($db) : dashboardScreen($db);
render($route === 'sites' ? 'Websites' : 'Dashboard', $content, true, $route);

function handlePost(string $route, PDO $db): never
{
    if ($route === 'login') {
        if (!Auth::attempt((string)($_POST['email'] ?? ''), (string)($_POST['password'] ?? ''))) {
            throw new RuntimeException('Invalid email or password.');
        }
        redirect('index.php');
    }
    if ($route === 'logout') {
        Auth::logout();
        redirect('index.php?view=login');
    }
    Auth::require();
    if ($route !== 'sites') throw new RuntimeException('Invalid action.');

    $action = (string)($_POST['action'] ?? 'save');
    $siteId = max(0, (int)($_POST['site_id'] ?? 0));
    if ($action === 'test_connection') {
        $settings = findSite($db, $siteId);
        $status = (new WordPressBlogService())->testConnection($settings, Crypto::decrypt((string)$settings['encrypted_wp_app_password']));
        if (empty($status['yoast_active'])) throw new RuntimeException('WordPress connected, but Yoast SEO is not active.');
        $contentMode = (string)($settings['content_mode'] ?? 'woocommerce');
        if ($contentMode === 'woocommerce' && empty($status['woocommerce_active'])) throw new RuntimeException('WordPress connected, but WooCommerce is not active.');
        if ($contentMode === 'server_folder') (new FolderBlogContentService($db))->validate($settings);
        flash('success', 'Connected as ' . (string)$status['user'] . '; ' . ($contentMode === 'server_folder' ? 'folder source and ' : 'WooCommerce and ') . 'Yoast SEO ' . (string)$status['yoast_version'] . ' detected.');
        redirect('index.php?view=sites&site=' . $siteId);
    }
    if ($action === 'run') {
        set_time_limit(240);
        $result = (new BlogAutomationService($db))->runSite($siteId, true);
        audit($db, 'blog.manual_run', 'blog_article', (int)($result['article_id'] ?? 0));
        flash($result['result'] === 'success' ? 'success' : 'error', $result['message']);
        redirect('index.php?view=sites&site=' . $siteId);
    }

    $siteName = trim((string)($_POST['site_name'] ?? ''));
    if ($siteName === '') throw new RuntimeException('Website name is required.');
    $siteUrl = rtrim(trim((string)($_POST['site_url'] ?? '')), '/');
    if (!filter_var($siteUrl, FILTER_VALIDATE_URL) || !str_starts_with(strtolower($siteUrl), 'https://')) {
        throw new RuntimeException('WordPress Site URL must be a valid HTTPS URL.');
    }
    $wpUsername = trim((string)($_POST['wp_username'] ?? ''));
    if ($wpUsername === '') throw new RuntimeException('WordPress username is required.');
    $topics = trim((string)($_POST['topics'] ?? ''));
    if ($topics === '') throw new RuntimeException('Add at least one topic area.');
    $current = $siteId > 0 ? findSite($db, $siteId) : null;
    $wpPassword = trim((string)($_POST['wp_app_password'] ?? ''));
    if (!$current && $wpPassword === '') throw new RuntimeException('WordPress Application Password is required for a new website.');
    $apiKey = trim((string)($_POST['ai_api_key'] ?? ''));
    $provider = in_array((string)($_POST['ai_provider'] ?? 'openai'), ['openai','anthropic','qwen'], true) ? (string)$_POST['ai_provider'] : 'openai';
    $encryptedWp = $wpPassword !== '' ? Crypto::encrypt($wpPassword) : (string)$current['encrypted_wp_app_password'];
    $sameProvider = !$current || $provider === (string)($current['ai_provider'] ?? 'openai');
    $encryptedApi = $apiKey !== '' ? Crypto::encrypt($apiKey) : ($sameProvider ? (string)($current['encrypted_ai_api_key'] ?? '') : '');
    $frequency = max(1, min(30, (int)($_POST['frequency_days'] ?? 7)));
    $postTime = preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', (string)($_POST['post_time'] ?? '')) ? (string)$_POST['post_time'] : '03:00';
    $mode = ($_POST['publish_mode'] ?? 'draft') === 'publish' ? 'publish' : 'draft';
    $modelDefaults = ['openai'=>'gpt-5-mini','anthropic'=>'claude-sonnet-4-6','qwen'=>'qwen-plus'];
    $model = trim((string)($_POST['ai_model'] ?? '')) ?: $modelDefaults[$provider];
    $contentMode = ($_POST['content_mode'] ?? 'woocommerce') === 'server_folder' ? 'server_folder' : 'woocommerce';
    $folderSource = $contentMode === 'server_folder' ? FolderBlogContentService::normalizeName((string)($_POST['folder_source_slug'] ?? '')) : null;
    $systemPrompt = $action === 'load_recommended_prompt'
        ? ($contentMode === 'server_folder' ? BlogAIService::folderDefaultPrompt() : BlogAIService::defaultPrompt())
        : trim((string)($_POST['system_prompt'] ?? ''));
    $brandingEnabled=isset($_POST['blog_branding_enabled'])?1:0;
    $brandText=trim(str_replace(["\r\n","\r"],"\n",(string)($_POST['blog_brand_text']??'')));
    BrandingText::validate($brandText);
    $backgroundOpacity=max(0,min(100,(int)($_POST['blog_text_background_opacity']??35)));
    foreach(['blog_text_color','blog_text_background_color'] as $colorName){if(!preg_match('/^#[0-9A-Fa-f]{6}$/',(string)($_POST[$colorName]??'')))throw new RuntimeException('Enter valid six-digit HEX branding colors.');}
    $logoSize=max(5,min(40,(int)($_POST['blog_logo_size_pct']??12)));
    $logoPosition=in_array((string)($_POST['blog_logo_position']??''),BlogImageBrandingService::positions(),true)?(string)$_POST['blog_logo_position']:'top-right';
    $textPosition=in_array((string)($_POST['blog_text_position']??''),BlogImageBrandingService::positions(),true)?(string)$_POST['blog_text_position']:'bottom-center';
    $textOpacity=max(10,min(100,(int)($_POST['blog_text_opacity']??90)));
    $textSize=max(1,min(8,(int)($_POST['blog_text_size_pct']??3)));
    $textColor=preg_match('/^#[0-9A-Fa-f]{6}$/',(string)($_POST['blog_text_color']??''))?strtoupper((string)$_POST['blog_text_color']):'#FFFFFF';
    $backgroundColor=preg_match('/^#[0-9A-Fa-f]{6}$/',(string)($_POST['blog_text_background_color']??''))?strtoupper((string)$_POST['blog_text_background_color']):'#050F19';
    $backgroundEnabled=isset($_POST['blog_text_background_enabled'])?1:0;
    $logoUpload=$_FILES['blog_logo']??[];$logoError=(int)($logoUpload['error']??UPLOAD_ERR_NO_FILE);$clearLogo=isset($_POST['clear_blog_logo']);
    if(!in_array($logoError,[UPLOAD_ERR_OK,UPLOAD_ERR_NO_FILE],true))throw new RuntimeException('Logo upload failed with code '.$logoError.'.');
    $existingLogo=$clearLogo?'':(string)($current['blog_logo_path']??'');
    if($brandingEnabled&&$brandText===''&&$existingLogo===''&&$logoError!==UPLOAD_ERR_OK)throw new RuntimeException('Enable branding only after adding a logo or branding text.');
    $values = [$siteName,$siteUrl,$wpUsername,$encryptedWp,isset($_POST['enabled'])?1:0,$frequency,$postTime,$mode,$provider,$model,$encryptedApi,$topics,$systemPrompt,max(0,(int)($_POST['category_id']??0))?:null,max(0,(int)($_POST['featured_media_id']??0))?:null,$contentMode,$folderSource];
    try {
        if ($current) {
            $query=$db->prepare('UPDATE blog_settings SET site_name=?,site_url=?,wp_username=?,encrypted_wp_app_password=?,enabled=?,frequency_days=?,post_time=?,publish_mode=?,ai_provider=?,ai_model=?,encrypted_ai_api_key=?,topics=?,system_prompt=?,category_id=?,featured_media_id=?,content_mode=?,folder_source_slug=? WHERE id=?');
            $query->execute([...$values,$siteId]);
        } else {
            $query=$db->prepare('INSERT INTO blog_settings(site_name,site_url,wp_username,encrypted_wp_app_password,enabled,frequency_days,post_time,publish_mode,ai_provider,ai_model,encrypted_ai_api_key,topics,system_prompt,category_id,featured_media_id,content_mode,folder_source_slug) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
            $query->execute($values);
            $siteId=(int)$db->lastInsertId();
        }
        $savedLogo=$existingLogo;
        if($logoError===UPLOAD_ERR_OK)$savedLogo=BlogImageBrandingService::saveLogo($siteId,$logoUpload);
        $branding=$db->prepare('UPDATE blog_settings SET blog_branding_enabled=?,blog_logo_path=?,blog_logo_size_pct=?,blog_logo_position=?,blog_brand_text=?,blog_text_color=?,blog_text_background_enabled=?,blog_text_background_color=?,blog_text_background_opacity=?,blog_text_position=?,blog_text_opacity=?,blog_text_size_pct=? WHERE id=?');
        $branding->execute([$brandingEnabled,$savedLogo!==''?$savedLogo:null,$logoSize,$logoPosition,$brandText,$textColor,$backgroundEnabled,$backgroundColor,$backgroundOpacity,$textPosition,$textOpacity,$textSize,$siteId]);
        $oldLogo=(string)($current['blog_logo_path']??'');if($oldLogo!==''&&$oldLogo!==$savedLogo)BlogImageBrandingService::deleteLogo($oldLogo);
    } catch (PDOException $e) {
        if ((string)$e->getCode() === '23000') throw new RuntimeException('This WordPress Site URL is already added.');
        throw $e;
    }
    audit($db,$current?'blog.site_updated':'blog.site_created','blog_setting',$siteId);
    flash('success',$action === 'load_recommended_prompt' ? 'Recommended prompt loaded and website settings saved.' : ($current?'Website settings updated.':'WordPress website added.'));
    redirect('index.php?view=sites&site='.$siteId);
}

/** @return array<string,mixed> */
function findSite(PDO $db, int $id): array
{
    if ($id < 1) throw new RuntimeException('Select a website first.');
    $query=$db->prepare('SELECT * FROM blog_settings WHERE id=?');
    $query->execute([$id]);
    $site=$query->fetch();
    if (!$site) throw new RuntimeException('Website not found.');
    return $site;
}

function dashboardScreen(PDO $db): string
{
    $sites=(int)$db->query('SELECT COUNT(*) FROM blog_settings')->fetchColumn();
    $enabled=(int)$db->query('SELECT COUNT(*) FROM blog_settings WHERE enabled=1')->fetchColumn();
    $articles=(int)$db->query('SELECT COUNT(*) FROM blog_articles')->fetchColumn();
    $published=(int)$db->query("SELECT COUNT(*) FROM blog_articles WHERE status='published'")->fetchColumn();
    $drafts=(int)$db->query("SELECT COUNT(*) FROM blog_articles WHERE status='draft'")->fetchColumn();
    $failed=(int)$db->query("SELECT COUNT(*) FROM blog_articles WHERE status='failed'")->fetchColumn();
    $siteRows=$db->query('SELECT s.*,(SELECT COUNT(*) FROM blog_articles a WHERE a.blog_site_id=s.id) article_count,(SELECT COUNT(*) FROM blog_articles a WHERE a.blog_site_id=s.id AND a.status="published") published_count,(SELECT COUNT(*) FROM blog_articles a WHERE a.blog_site_id=s.id AND a.status="draft") draft_count FROM blog_settings s ORDER BY s.site_name,s.id')->fetchAll();
    $recent=$db->query('SELECT a.*,s.site_name FROM blog_articles a JOIN blog_settings s ON s.id=a.blog_site_id ORDER BY a.id DESC LIMIT 12')->fetchAll();
    $cron=cronMeta($db);

    $html='<div class="cards dashboard-cards">'.card('WordPress websites',$sites).card('Enabled automation',$enabled).card('Generated articles',$articles).card('Published',$published).card('WordPress drafts',$drafts).card('Failed deliveries',$failed).'</div>';
    $html.='<section class="panel schedule-overview"><div class="section-heading"><div><span class="eyebrow">Live overview</span><h2>Publishing schedule</h2><p>See every website’s delivery mode, most recent article and next automation time in one place.</p></div><a class="button-link" href="index.php?view=sites&new=1">+ Add website</a></div><div class="cron-health '.e($cron['state']).'"><div><span class="cron-dot"></span><strong>Blog cron: '.e($cron['label']).'</strong></div><p>'.e($cron['message']).'</p></div><div class="schedule-board">';
    if($siteRows===[])$html.='<p class="empty">No website added yet.</p>';
    foreach($siteRows as $site){
        $latestQuery=$db->prepare('SELECT * FROM blog_articles WHERE blog_site_id=? ORDER BY id DESC LIMIT 1');$latestQuery->execute([(int)$site['id']]);$latest=$latestQuery->fetch()?:[];
        $schedule=scheduleMeta($site);$delivery=(string)($site['publish_mode']??'draft')==='publish'?'Publish immediately':'WordPress draft';
        $html.='<article class="site-status-card"><div class="site-status-head"><div><h3>'.e(displayText($site['site_name'])).'</h3><a href="'.e($site['site_url']).'" target="_blank" rel="noopener">'.e(preg_replace('#^https?://#','',(string)$site['site_url'])).' ↗</a></div>'.badge(!empty($site['enabled'])?'active':'inactive').'</div>';
        $html.='<div class="site-status-grid"><div><span>Next automation</span><strong class="schedule-state '.e($schedule['state']).'">'.e($schedule['label']).'</strong><small>'.e($schedule['date']).' · Asia/Dhaka</small></div><div><span>Delivery mode</span><strong>'.e($delivery).'</strong><small>'.(int)$site['published_count'].' published · '.(int)$site['draft_count'].' drafts</small></div></div>';
        if($latest!==[]){$html.='<div class="latest-article"><span>Latest article</span><strong>'.e(displayText($latest['title'])).'</strong><small>Created '.e(formatDateTime($latest['created_at'])).' · '.(!empty($latest['published_at'])?'Published '.e(formatDateTime($latest['published_at'])):'Not published — saved as WordPress '.e((string)($latest['wp_status']?:'draft'))).'</small></div>';}
        else{$html.='<div class="latest-article empty-compact">No article generated yet.</div>';}
        $html.='<a class="card-link" href="index.php?view=sites&site='.(int)$site['id'].'">Open website settings and history →</a></article>';
    }
    $html.='</div></section>';

    $html.='<section class="panel article-history"><div class="section-heading"><div><span class="eyebrow">All websites</span><h2>Recent article history</h2><p>Draft and published WordPress articles with their actual creation and publication dates.</p></div><span class="count-pill">'.count($recent).' recent</span></div>';
    if($recent===[])return $html.'<p class="empty">No article generated yet.</p></section>';
    $html.='<div class="table"><table><thead><tr><th>Website</th><th>Article</th><th>Delivery</th><th>Created</th><th>Published</th><th></th></tr></thead><tbody>';
    foreach($recent as $article){$html.='<tr><td><strong>'.e(displayText($article['site_name'])).'</strong></td><td>'.e(displayText($article['title'])).'</td><td>'.badge($article['status']).'<small>WordPress: '.e((string)($article['wp_status']?:'not delivered')).'</small></td><td>'.e(formatDateTime($article['created_at'])).'</td><td>'.(!empty($article['published_at'])?e(formatDateTime($article['published_at'])):'<span class="not-published">Not published</span>').'</td><td>'.(!empty($article['wp_post_url'])?'<a href="'.e($article['wp_post_url']).'" target="_blank" rel="noopener">Open ↗</a>':'—').'</td></tr>';}
    return $html.'</tbody></table></div></section>';
}

function sitesScreen(PDO $db): string
{
    $sites=$db->query('SELECT s.*,(SELECT COUNT(*) FROM blog_articles a WHERE a.blog_site_id=s.id) article_count FROM blog_settings s ORDER BY s.site_name,s.id')->fetchAll();
    $requested=max(0,(int)($_GET['site']??0));
    $adding=isset($_GET['new']);
    if(!$adding&&$requested===0&&$sites!==[])$requested=(int)$sites[0]['id'];
    $settings=[];
    foreach($sites as $site)if(!$adding&&(int)$site['id']===$requested){$settings=$site;break;}
    if(!$adding&&$settings===[]&&$sites!==[]){$settings=$sites[0];$requested=(int)$settings['id'];}
    $siteId=(int)($settings['id']??0);
    $articles=[];$runs=[];
    if($siteId>0){
        $q=$db->prepare('SELECT * FROM blog_articles WHERE blog_site_id=? ORDER BY id DESC LIMIT 30');$q->execute([$siteId]);$articles=$q->fetchAll();
        $q=$db->prepare('SELECT * FROM blog_runs WHERE blog_site_id=? ORDER BY id DESC LIMIT 12');$q->execute([$siteId]);$runs=$q->fetchAll();
    }
    $contentMode=(string)($settings['content_mode']??'woocommerce');
    $isFolder=$contentMode==='server_folder';
    $provider=in_array((string)($settings['ai_provider']??'openai'),['openai','anthropic','qwen'],true)?(string)($settings['ai_provider']??'openai'):'openai';
    $defaultTopics=$isFolder?"Project stories and practical design guidance\nService benefits and customer questions\nIndustry trends, maintenance and how-to guides\nTopics relevant to the website's Bangladesh audience":"Product benefits and practical use cases\nBuying, measurement and selection guidance\nInstallation, maintenance and care advice\nProduct comparisons and Bangladesh customer questions";
    $prompt=trim((string)($settings['system_prompt']??''))!==''?(string)$settings['system_prompt']:($isFolder?BlogAIService::folderDefaultPrompt():BlogAIService::defaultPrompt());

    $html='<div class="panel site-manager"><div class="section-heading"><div><h2>WordPress websites</h2><p>Each website has separate credentials, topics, schedule and article history.</p></div><a class="button-link" href="index.php?view=sites&new=1">+ Add website</a></div><div class="site-tabs">';
    if($sites===[])$html.='<span class="empty">No website added yet.</span>';
    foreach($sites as $site){$active=!$adding&&(int)$site['id']===$siteId?'active':'';$html.='<a class="'.$active.'" href="index.php?view=sites&site='.(int)$site['id'].'"><span>'.e(displayText($site['site_name'])).'</span><small>'.(int)$site['article_count'].' articles · '.(!empty($site['enabled'])?'enabled':'paused').'</small></a>';}
    $html.='</div></div>';

    $html.='<div class="blog-layout"><div class="panel"><div class="section-heading"><div><h2>'.($siteId?'Edit website':'Add WordPress website').'</h2><p>Choose WooCommerce products or Facebook-style server folders independently for each website.</p></div>'.($siteId?badge(!empty($settings['enabled'])?'active':'inactive'):'').'</div><form method="post" enctype="multipart/form-data">'.Csrf::input().'<input type="hidden" name="action" value="save"><input type="hidden" name="site_id" value="'.$siteId.'">';
    $html.='<label class="check-row"><input type="checkbox" name="enabled" value="1" '.(!empty($settings['enabled'])?'checked':'').'><span><strong>Enable scheduled automation</strong><small>The standalone cron checks this website independently.</small></span></label>';
    $html.=field('Website name','site_name',$settings['site_name']??'',true,'text','Example: Example Studio Store');
    $html.='<div class="form-grid">'.field('WordPress Site URL','site_url',$settings['site_url']??'',true,'url').field('WordPress username','wp_username',$settings['wp_username']??'',true,'text','Application Password user; dedicated Editor recommended.').'</div>';
    $html.=field('WordPress Application Password','wp_app_password','',false,'password',$siteId?'Leave blank to keep the saved password.':'Required for a new website; not the normal login password.');
    $html.=selectField('Content source mode','content_mode',['woocommerce'=>'WooCommerce products','server_folder'=>'Server folders: images + title.txt + description.txt'],$contentMode);
    $html.=field('Folder source name','folder_source_slug',$settings['folder_source_slug']??'',false,'text','Folder mode only. Example: my-portfolio → /content-automation/page-content/my-portfolio/');
    $positions=['top-left'=>'Top left','top-center'=>'Top center','top-right'=>'Top right','middle-left'=>'Middle left','middle-center'=>'Middle center','middle-right'=>'Middle right','bottom-left'=>'Bottom left','bottom-center'=>'Bottom center','bottom-right'=>'Bottom right'];
    $logoData=BlogImageBrandingService::readLogo((string)($settings['blog_logo_path']??''));$logoSrc=$logoData?'data:'.e($logoData['mime']).';base64,'.base64_encode($logoData['data']):'';
    $html.='<section class="image-branding-settings" id="blog-branding-settings"><div class="section-heading"><div><span class="eyebrow">Image ownership</span><h3>Logo and text branding</h3><p>Applied to both WooCommerce and server-folder article images before WordPress upload. Original images stay unchanged; no image API is used.</p></div></div>';
    $html.='<label class="check-row"><input type="checkbox" name="blog_branding_enabled" value="1" '.(!empty($settings['blog_branding_enabled'])?'checked':'').'><span><strong>Add branding to article images</strong><small>New articles receive branded WordPress media copies.</small></span></label>';
    $html.='<div class="branding-preview" id="blog-branding-preview"><span>Article image preview</span><img id="blog-logo-preview" src="'.$logoSrc.'" '.($logoSrc===''?'hidden':'').' alt="Logo preview"><div id="blog-text-preview">'.e(trim((string)($settings['blog_brand_text']??''))?:'').'</div></div>';
    $html.='<div class="form-grid"><label>Logo image<input id="blog-logo-input" type="file" name="blog_logo" accept="image/png,image/jpeg,image/webp"><small>PNG, JPG or WebP; maximum 5 MB.</small></label>'.selectField('Logo position','blog_logo_position',$positions,(string)($settings['blog_logo_position']??'top-right')).'</div>';
    if($logoSrc!=='')$html.='<label class="check-row compact-check"><input type="checkbox" name="clear_blog_logo" value="1"><span><strong>Remove saved logo</strong></span></label>';
    $html.='<label>Logo size <output data-brand-value="blog_logo_size_pct"></output><input type="range" name="blog_logo_size_pct" min="5" max="40" value="'.(int)($settings['blog_logo_size_pct']??12).'"></label>';
    $html.='<label>Branding text — one pill per line<textarea name="blog_brand_text" rows="3">'.e((string)($settings['blog_brand_text']??'')).'</textarea><small>Up to 300 characters. All non-empty lines appear side by side in one row. Icons: ☎ ✆ ◎ 🌐 📞 📍. Long text shrinks to fit.</small></label>';
    $html.='<div class="form-grid">'.selectField('Text position','blog_text_position',$positions,(string)($settings['blog_text_position']??'bottom-center')).'<label>Text size <output data-brand-value="blog_text_size_pct"></output><input type="range" name="blog_text_size_pct" min="1" max="8" value="'.(int)($settings['blog_text_size_pct']??3).'"></label></div>';
    $html.='<div class="form-grid">'.colorField('Text & border color','blog_text_color',$settings['blog_text_color']??'#FFFFFF').colorField('Background color','blog_text_background_color',$settings['blog_text_background_color']??'#050F19').'</div>';
    $html.='<label class="check-row compact-check"><input type="checkbox" name="blog_text_background_enabled" value="1" '.(!array_key_exists('blog_text_background_enabled',$settings)||!empty($settings['blog_text_background_enabled'])?'checked':'').'><span><strong>Show text background color</strong><small>Enable to see background color and opacity; turn off for a transparent fill.</small></span></label>';
    $html.='<label>Text & border opacity <output data-brand-value="blog_text_opacity"></output><input type="range" name="blog_text_opacity" min="10" max="100" value="'.(int)($settings['blog_text_opacity']??90).'"></label><label>Background opacity <output data-brand-value="blog_text_background_opacity"></output><input type="range" name="blog_text_background_opacity" min="0" max="100" value="'.(int)($settings['blog_text_background_opacity']??35).'"></label></section>';
    $nextPost=$siteId>0?nextPostDate($settings):'Save website first';
    $html.='<div class="schedule-grid">'.selectField('Article interval','frequency_days',['1'=>'Every day','2'=>'Every 2 days','3'=>'Every 3 days','5'=>'Every 5 days','7'=>'Every 7 days','10'=>'Every 10 days','15'=>'Every 15 days'],(string)($settings['frequency_days']??7)).field('Research start time','post_time',substr((string)($settings['post_time']??'03:00'),0,5),true,'time','Asia/Dhaka; cron may start a few minutes later.').'<label>Next automation schedule<input type="text" value="'.e($nextPost).'" readonly><small>Shows Due now when the configured time has passed and the cron is waiting to run.</small></label></div>';
    $html.=selectField('WordPress delivery mode','publish_mode',['draft'=>'Create WordPress Draft (recommended)','publish'=>'Publish immediately'],(string)($settings['publish_mode']??'draft'));
    $html.='<div class="provider-grid">'.selectField('AI provider','ai_provider',['openai'=>'OpenAI','anthropic'=>'Claude (Anthropic)','qwen'=>'Qwen (Alibaba Cloud)'],$provider).field('AI model','ai_model',$settings['ai_model']??'gpt-5-mini',true,'text','Examples: gpt-5-mini, claude-sonnet-4-6, qwen-plus').field('Website-specific AI API key','ai_api_key','',false,'password','Leave blank to keep the saved key. Each provider requires its own API key.').'</div>';
    $productTopics="Product benefits and practical use cases\nBuying, measurement and selection guidance\nInstallation, maintenance and care advice\nProduct comparisons and Bangladesh customer questions";
    $folderTopics="Interior project stories and design inspiration\nSpace planning and practical interior guidance\nMaterials, lighting, colours and maintenance advice\nResidential and commercial interior solutions in Bangladesh";
    $html.='<label><span id="article-angles-label">'.($isFolder?'Portfolio article angles — one per line':'Product article angles — one per line').'</span><textarea id="article-angles-editor" name="topics" rows="4">'.e($settings['topics']??$defaultTopics).'</textarea></label>';
    $html.='<div class="prompt-editor"><div class="section-heading"><div><h3>AI writing prompt</h3><p>This exact prompt controls article tone, 70/30 research balance, CTA position, title style and SEO writing. Edit it here—no PHP or ZIP change is needed later.</p></div><button class="secondary" type="button" id="load-recommended-prompt" data-product-prompt="'.e(base64_encode(BlogAIService::defaultPrompt())).'" data-folder-prompt="'.e(base64_encode(BlogAIService::folderDefaultPrompt())).'" data-product-topics="'.e(base64_encode($productTopics)).'" data-folder-topics="'.e(base64_encode($folderTopics)).'">Load recommended prompt</button></div><textarea id="system-prompt-editor" name="system_prompt" rows="22">'.e($prompt).'</textarea><small id="recommended-prompt-status">Choose the content source mode, then load its recommended prompt.</small></div>';
    $html.=field('Fixed WordPress Blog Category ID','category_id',$settings['category_id']??'',false,'number','Optional. Leave blank to use the relevant product category name; the connector creates it when missing.');
    $html.='<button>'.($siteId?'Update Website Settings':'Add Website').'</button></form></div>';

    $html.='<div><div class="panel"><h2>Test this website</h2>';
    if($siteId){$hidden='<input type="hidden" name="site_id" value="'.$siteId.'">';$html.='<p>Connection test verifies WordPress, Yoast and the selected content source without AI cost.</p><div class="inline"><form method="post">'.Csrf::input().$hidden.'<button class="secondary" name="action" value="test_connection">Test connection & source</button></form><form method="post">'.Csrf::input().$hidden.'<button name="action" value="run">Generate one article now</button></form></div>';}
    else $html.='<p class="empty">Save the website before testing.</p>';
    $html.='</div><div class="panel cost-guard"><span class="eyebrow">API cost protection</span><h2>Automatic cost guard</h2><ul><li>Not due yet: no AI request</li><li>Maximum one automatic generation attempt per website per day</li><li>WordPress delivery retry reuses the saved article—no new AI request</li><li>Connection test never calls AI</li></ul></div><div class="panel"><h2>Recent runs</h2>';
    if($runs===[])$html.='<p class="empty">No run yet for this website.</p>';else{$html.='<div class="run-list">';foreach($runs as $run)$html.='<div class="run-item"><div>'.badge($run['result']).'<time>'.e($run['created_at']).'</time></div><p>'.e($run['message']).'</p></div>';$html.='</div>';}
    $html.='</div></div></div>';

    $html.='<div class="panel"><div class="section-heading"><div><h2>Generated articles</h2><p>'.e(displayText($settings['site_name']??'Select a website')).' — site-wise WordPress delivery history.</p></div><span class="count-pill">'.count($articles).' recent</span></div>';
    if($articles===[])return $html.'<p class="empty">No article generated for this website.</p></div>';
    foreach($articles as $article){$checks=json_decode((string)($article['seo_checks_json']??''),true);if(!is_array($checks))$checks=[];$score=(int)$article['seo_score'];$html.='<article class="blog-article"><div class="section-heading"><div><h3>'.e(displayText($article['title'])).'</h3><p><strong>Focus keyphrase:</strong> '.e($article['focus_keyphrase']).'</p><p class="article-dates"><strong>Created:</strong> '.e(formatDateTime($article['created_at'])).' <span>·</span> <strong>Published:</strong> '.(!empty($article['published_at'])?e(formatDateTime($article['published_at'])):'Not published (WordPress '.e((string)($article['wp_status']?:'draft')).')').'</p></div>'.badge($article['status']).'</div><div class="seo-meter"><span style="width:'.max(0,min(100,$score)).'%"></span></div><p class="seo-score">SEO checklist: <strong>'.$score.'%</strong> · WordPress ID: '.($article['wp_post_id']?(int)$article['wp_post_id']:'not delivered').'</p>';
        if($checks!==[]){$html.='<div class="seo-checks">';foreach($checks as $label=>$passed)$html.='<span class="'.($passed?'passed':'warning').'">'.($passed?'✓':'!').' '.e($label).'</span>';$html.='</div>';}
        if(!empty($article['wp_post_url']))$html.='<p><a href="'.e($article['wp_post_url']).'" target="_blank" rel="noopener">Open WordPress post ↗</a></p>';if(!empty($article['error_message']))$html.='<p class="error">'.e($article['error_message']).'</p>';$html.='</article>';}
    return $html.'</div>';
}

function render(string $title,string $content,bool $auth,string $current):void
{
    // Root wrapper serves public/assets; direct public document roots serve assets.
    $assetBase=realpath((string)($_SERVER['SCRIPT_FILENAME']??''))===realpath(__FILE__)?'assets/':'public/assets/';
    $flashes=$_SESSION['flash']??[];unset($_SESSION['flash']);
    ?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="theme-color" content="#071827"><title><?=e($title)?> — Blog Automation</title><link rel="stylesheet" href="<?=e($assetBase)?>app.css?v=20261003-fix"><script src="<?=e($assetBase)?>app.js?v=20261003-fix" defer></script><script src="<?=e($assetBase)?>branding-preview.js?v=20261003-fix" defer></script></head><body><?php if($auth):?><header><div class="header-inner"><a class="brand" href="index.php"><span class="brand-mark">B</span><span class="brand-copy">Blog Automation<small>Multi-site Publishing Studio</small></span></a><nav><a class="<?=$current==='dashboard'?'active':''?>" href="index.php">Dashboard</a><a class="<?=$current==='sites'?'active':''?>" href="index.php?view=sites">Websites</a><form method="post" action="index.php?view=logout"><?=Csrf::input()?><button class="link">Logout</button></form></nav></div></header><?php endif?><main><?php if($auth):?><div class="page-heading"><div><h1><?=e($title)?></h1><p><?=$current==='sites'?'Configure and monitor every WordPress website.':'Independent AI research and WordPress publishing.'?></p></div><span class="status-pill">Automation configured</span></div><?php endif?><?php foreach($flashes as $flash):?><div class="flash <?=e($flash['type'])?>"><?=e($flash['message'])?></div><?php endforeach?><?=$content?></main></body></html><?php
}

function loginScreen():string{return '<div class="login panel"><h1>Blog Automation login</h1><form method="post">'.Csrf::input().field('Email','email','',true,'email').field('Password','password','',true,'password').'<button>Log in</button></form></div>';}
function field(string $label,string $name,mixed $value='',bool $required=false,string $type='text',string $hint=''):string{return '<label>'.e($label).'<input type="'.e($type).'" name="'.e($name).'" value="'.e($value).'" '.($required?'required':'').'>'.($hint?'<small>'.e($hint).'</small>':'').'</label>';}
function area(string $label,string $name,mixed $value):string{return '<label>'.e($label).'<textarea name="'.e($name).'" rows="4">'.e($value).'</textarea></label>';}
function areaRows(string $label,string $name,mixed $value,int $rows):string{return '<label>'.e($label).'<textarea name="'.e($name).'" rows="'.max(2,min(30,$rows)).'">'.e($value).'</textarea></label>';}
function selectField(string $label,string $name,array $options,mixed $selected):string{$html='<label>'.e($label).'<select name="'.e($name).'" required>';foreach($options as $value=>$text)$html.='<option value="'.e($value).'" '.((string)$value===(string)$selected?'selected':'').'>'.e($text).'</option>';return $html.'</select></label>';}
function card(string $label,int $value):string{return '<div class="card"><span>'.e($label).'</span><strong>'.$value.'</strong></div>';}
function badge(string $value):string{return '<span class="badge '.e($value).'">'.e($value).'</span>';}
function displayText(mixed $value):string{return html_entity_decode((string)$value,ENT_QUOTES|ENT_HTML5,'UTF-8');}
/** @param array<string,mixed> $settings */
function nextPostDate(array $settings):string
{
    $schedule=scheduleMeta($settings);
    return ($schedule['state']==='due'?'Due now — ':'').$schedule['date'];
}
/** @param array<string,mixed> $settings @return array{state:string,label:string,date:string} */
function scheduleMeta(array $settings):array
{
    $time=substr((string)($settings['post_time']??'03:00'),0,5);
    $interval=max(1,(int)($settings['frequency_days']??7));
    $now=new DateTimeImmutable('now');
    $scheduled=null;
    if(!empty($settings['last_success_at'])){
        $last=DateTimeImmutable::createFromFormat('!Y-m-d H:i',substr((string)$settings['last_success_at'],0,10).' '.$time);
        if($last)$scheduled=$last->modify('+'.$interval.' days');
    }
    if(!$scheduled)$scheduled=new DateTimeImmutable('today '.$time);
    $todaySlot=new DateTimeImmutable('today '.$time);
    // When an old due date has already passed but today's configured time has
    // not arrived, the cron correctly waits for today's slot. Reflect that in
    // the dashboard instead of incorrectly showing "Due now" after midnight.
    if($scheduled<$todaySlot&&$now<$todaySlot)$scheduled=$todaySlot;
    if((string)($settings['last_run_on']??'')===$now->format('Y-m-d')&&$scheduled<=$now)$scheduled=new DateTimeImmutable('tomorrow '.$time);
    if(empty($settings['enabled']))return ['state'=>'paused','label'=>'Paused','date'=>$scheduled->format('d M Y, h:i A')];
    if($scheduled<=$now)return ['state'=>'due','label'=>'Due now','date'=>$scheduled->format('d M Y, h:i A')];
    return ['state'=>'upcoming','label'=>'Scheduled','date'=>$scheduled->format('d M Y, h:i A')];
}
function formatDateTime(mixed $value):string
{
    $text=trim((string)$value);if($text==='')return '—';
    try{return (new DateTimeImmutable($text))->format('d M Y, h:i A');}catch(Throwable){return $text;}
}
/** @return array{state:string,label:string,message:string} */
function cronMeta(PDO $db):array
{
    $query=$db->query('SELECT created_at,details FROM audit_logs WHERE action="blog.cron_checked" ORDER BY id DESC LIMIT 1');
    $row=$query->fetch();
    if(!$row)return ['state'=>'unknown','label'=>'Not detected','message'=>'No cron heartbeat has been recorded yet. Confirm the cPanel cron command after uploading this update.'];
    $details=json_decode((string)($row['details']??''),true);
    $heartbeatTime=is_array($details)&&!empty($details['checked_at'])?(string)$details['checked_at']:(string)$row['created_at'];
    try{$last=new DateTimeImmutable($heartbeatTime);}catch(Throwable){return ['state'=>'unknown','label'=>'Unknown','message'=>'The latest cron heartbeat time could not be read.'];}
    $minutes=max(0,(int)floor((time()-$last->getTimestamp())/60));
    if($minutes<=30)return ['state'=>'healthy','label'=>'Healthy','message'=>'Last checked '.formatDateTime($heartbeatTime).' ('.$minutes.' minute'.($minutes===1?'':'s').' ago).'];
    return ['state'=>'stale','label'=>'Needs attention','message'=>'Last checked '.formatDateTime($heartbeatTime).' ('.$minutes.' minutes ago). The cPanel cron may be stopped or using the wrong command.'];
}
function audit(PDO $db,string $action,string $type,int $id):void{$query=$db->prepare('INSERT INTO audit_logs(admin_id,action,entity_type,entity_id) VALUES(?,?,?,?)');$query->execute([Auth::id(),$action,$type,$id]);}

function colorField(string $label,string $name,mixed $value):string{return '<label>'.e($label).'<span class="branding-color-pair"><input type="color" name="'.e($name).'" value="'.e($value).'" aria-label="'.e($label).' picker" required><input type="text" class="branding-hex" data-color-for="'.e($name).'" value="'.e($value).'" maxlength="7" pattern="#[0-9A-Fa-f]{6}" placeholder="#FFFFFF" aria-label="'.e($label).' HEX code" spellcheck="false" required></span></label>';}
