<?php
declare(strict_types=1);

namespace App\Services;

final class BlogImageBrandingService
{
    private const ROOT = BASE_PATH . '/storage/branding';
    private const FONT = BASE_PATH . '/assets/fonts/NotoSansBengali-Regular.ttf';
    private const POSITIONS = [
        'top-left','top-center','top-right',
        'middle-left','middle-center','middle-right',
        'bottom-left','bottom-center','bottom-right',
    ];

    /** @return string[] */
    public static function positions(): array { return self::POSITIONS; }

    /** @param array<string,mixed> $upload */
    public static function saveLogo(int $siteId,array $upload):string
    {
        if($siteId<1)throw new \RuntimeException('Save the website before uploading a logo.');
        $error=(int)($upload['error']??UPLOAD_ERR_NO_FILE);
        if($error===UPLOAD_ERR_NO_FILE)throw new \RuntimeException('Choose a logo image first.');
        if($error!==UPLOAD_ERR_OK)throw new \RuntimeException('Logo upload failed with code '.$error.'.');
        $tmp=(string)($upload['tmp_name']??'');$size=(int)($upload['size']??0);
        if($tmp===''||!is_file($tmp)||$size<1||$size>5*1024*1024)throw new \RuntimeException('Logo must be a valid image no larger than 5 MB.');
        $data=file_get_contents($tmp);$info=$data===false?false:@getimagesizefromstring($data);$mime=is_array($info)?(string)($info['mime']??''):'';
        $extensions=['image/png'=>'png','image/jpeg'=>'jpg','image/webp'=>'webp'];
        if($data===false||!isset($extensions[$mime]))throw new \RuntimeException('Logo must be a PNG, JPG or WebP image.');
        if((int)($info[0]??0)<32||(int)($info[1]??0)<32||(int)($info[0]??0)>4096||(int)($info[1]??0)>4096)throw new \RuntimeException('Logo dimensions must be between 32 and 4096 pixels.');
        $dir='site-'.$siteId;self::ensure(self::ROOT.'/'.$dir);
        $relative=$dir.'/logo-'.substr(hash('sha256',$data),0,16).'.'.$extensions[$mime];
        if(!is_file(self::ROOT.'/'.$relative)&&file_put_contents(self::ROOT.'/'.$relative,$data,LOCK_EX)===false)throw new \RuntimeException('Could not save the website logo.');
        return $relative;
    }

    public static function deleteLogo(?string $relative):void
    {
        $path=self::logoPath((string)$relative);if($path!==null&&is_file($path))@unlink($path);
    }

    /** @return array{data:string,mime:string}|null */
    public static function readLogo(string $relative):?array
    {
        $path=self::logoPath($relative);if($path===null)return null;
        $data=file_get_contents($path);$info=$data===false?false:@getimagesizefromstring($data);
        return $data!==false&&$info!==false?['data'=>$data,'mime'=>(string)($info['mime']??'application/octet-stream')]:null;
    }

    /** @param array<string,mixed> $settings @return array{path:string,mime:string,filename:string} */
    public static function apply(array $settings,string $sourcePath,string $filename):array
    {
        $info=@getimagesize($sourcePath);$mime=(string)($info['mime']??'');
        if(!is_file($sourcePath)||!in_array($mime,['image/jpeg','image/png','image/webp'],true))throw new \RuntimeException('An article image is invalid or unreadable.');
        if(empty($settings['blog_branding_enabled']))return ['path'=>$sourcePath,'mime'=>$mime,'filename'=>$filename];
        $logo=self::logoPath((string)($settings['blog_logo_path']??''));$text=trim((string)($settings['blog_brand_text']??''));
        if($logo===null&&$text==='')throw new \RuntimeException('Image branding is enabled, but no logo or branding text is configured.');
        if(!function_exists('imagecreatefromstring')||!function_exists('imagecreatetruecolor')||!function_exists('imagejpeg'))throw new \RuntimeException('PHP GD is required for blog image branding. No external image API is used.');
        $signature=hash('sha256',json_encode([
            'v'=>3,'source'=>hash_file('sha256',$sourcePath),'logo'=>$logo?hash_file('sha256',$logo):'','text'=>$text,
            'logo_size'=>(int)($settings['blog_logo_size_pct']??12),'logo_position'=>(string)($settings['blog_logo_position']??'top-right'),
            'text_color'=>(string)($settings['blog_text_color']??'#FFFFFF'),'background_enabled'=>!empty($settings['blog_text_background_enabled']),
            'background_opacity'=>(int)($settings['blog_text_background_opacity']??35),'background_color'=>(string)($settings['blog_text_background_color']??'#050F19'),'text_position'=>(string)($settings['blog_text_position']??'bottom-center'),
            'opacity'=>(int)($settings['blog_text_opacity']??90),'text_size'=>(int)($settings['blog_text_size_pct']??3),
        ],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR));
        $siteId=max(1,(int)($settings['id']??0));$dir=self::ROOT.'/rendered/site-'.$siteId;self::ensure($dir);
        $destination=$dir.'/'.substr($signature,0,32).'.jpg';
        if(!is_file($destination)||(filesize($destination)?:0)<1)self::render($settings,$sourcePath,$destination,$logo,$text);
        $base=pathinfo($filename,PATHINFO_FILENAME);$base=preg_replace('/[^A-Za-z0-9._-]+/','-',(string)$base)?:'blog-image';
        return ['path'=>$destination,'mime'=>'image/jpeg','filename'=>$base.'-branded.jpg'];
    }

    private static function render(array $settings,string $sourcePath,string $destination,?string $logoPath,string $text):void
    {
        $data=file_get_contents($sourcePath);$source=$data===false?false:@imagecreatefromstring($data);
        if($source===false)throw new \RuntimeException('An article image could not be opened for branding.');
        $source=self::orient($source,$sourcePath);$width=imagesx($source);$height=imagesy($source);
        $canvas=imagecreatetruecolor($width,$height);$white=imagecolorallocate($canvas,255,255,255);imagefill($canvas,0,0,$white);imagecopy($canvas,$source,0,0,0,0,$width,$height);imagedestroy($source);imagealphablending($canvas,true);
        if($logoPath!==null)self::drawLogo($canvas,$logoPath,$settings);
        if($text!=='')self::drawText($canvas,$text,$settings);
        $saved=false;foreach([90,84,76] as $quality){if(!imagejpeg($canvas,$destination,$quality))continue;if((filesize($destination)?:PHP_INT_MAX)<=12*1024*1024){$saved=true;break;}}
        imagedestroy($canvas);if(!$saved){@unlink($destination);throw new \RuntimeException('The branded blog image could not be kept below 12 MB.');}
    }

    private static function drawLogo($canvas,string $path,array $settings):void
    {
        $data=file_get_contents($path);$logo=$data===false?false:@imagecreatefromstring($data);if($logo===false)throw new \RuntimeException('The saved website logo is unreadable.');
        $width=imagesx($canvas);$height=imagesy($canvas);$logoWidth=imagesx($logo);$logoHeight=imagesy($logo);
        $percent=max(5,min(40,(int)($settings['blog_logo_size_pct']??12)));$targetWidth=max(32,(int)round($width*$percent/100));$targetHeight=max(16,(int)round($logoHeight*$targetWidth/max(1,$logoWidth)));
        $maxHeight=(int)round($height*.28);if($targetHeight>$maxHeight){$targetHeight=$maxHeight;$targetWidth=max(16,(int)round($logoWidth*$targetHeight/max(1,$logoHeight)));}
        [$x,$y]=self::coordinates($width,$height,$targetWidth,$targetHeight,self::position((string)($settings['blog_logo_position']??'top-right')));
        imagealphablending($logo,true);imagesavealpha($logo,true);imagecopyresampled($canvas,$logo,$x,$y,0,0,$targetWidth,$targetHeight,$logoWidth,$logoHeight);imagedestroy($logo);
    }

    /** One explicit, non-empty input line makes one pill; never drop a line. */
    private static function drawText($canvas, string $text, array $settings, int $scale = 4): void
    {
        if (!function_exists('imagettftext')) throw new \RuntimeException('Image branding requires PHP GD with FreeType for Unicode text and icons.');
        $lines = BrandingText::lines($text);
        if (!$lines) return;
        $width = imagesx($canvas); $height = imagesy($canvas);
        $position = self::position((string)($settings['blog_text_position'] ?? 'bottom-center'));
        $fontSize = max(1, min(120, min($width, $height) * max(1, min(8, (int)($settings['blog_text_size_pct'] ?? 3))) / 100));
        $maxWidth = max(1, min($width * .88, $width - 2 * max(16, round($width * .035))));
        $maxHeight = max(1, $height - 2 * max(16, round($height * .035)));
        $runs = array_map([BrandingText::class, 'runs'], $lines);
        // Measure and rasterize the small branding layer at 4x, then downsample.
        // Leave the original photo and logo at their native resolution.
        $fontSize *= $scale; $maxWidth *= $scale; $maxHeight *= $scale;
        // Fit the complete group, including long unbroken links and many lines.
        do {
            $paddingX = max(2 * $scale, (int)round($fontSize * .65));
            $paddingY = max($scale, (int)round($fontSize * .35));
            $gap = max($scale, (int)round($fontSize * .3));
            $stroke = max($scale, (int)round($fontSize * .045));
            $items = []; $blockWidth = 0; $blockHeight = 0;
            foreach ($runs as $lineRuns) {
                $item = BrandingText::measure($lineRuns, $fontSize);
                $item['boxWidth'] = $item['width'] + 2 * ($paddingX + $stroke);
                $item['boxHeight'] = max((int)ceil($fontSize * 1.25), $item['height']) + 2 * ($paddingY + $stroke);
                $items[] = $item;
                $blockWidth += $item['boxWidth'];
                $blockHeight = max($blockHeight, $item['boxHeight']);
            }
            $blockWidth += (count($items) - 1) * $gap;
            if ($blockWidth <= $maxWidth && $blockHeight <= $maxHeight) break;
            $fontSize -= .5 * $scale;
            if ($fontSize < $scale) throw new \RuntimeException('Branding text cannot fit this image. Use fewer or shorter lines.');
        } while (true);
        $targetWidth = (int)ceil($blockWidth / $scale);
        $targetHeight = (int)ceil($blockHeight / $scale);
        // Bound extra memory for unusually large originals, without resizing them.
        if ($targetWidth * $targetHeight * $scale * $scale > 8000000 && $scale > 1) {
            self::drawText($canvas, $text, $settings, max(1, intdiv($scale, 2)));
            return;
        }
        [$destinationX,$destinationY] = self::coordinates($width, $height, $targetWidth, $targetHeight, $position);
        $layer = imagecreatetruecolor($targetWidth * $scale, $targetHeight * $scale);
        if ($layer === false) throw new \RuntimeException('Could not allocate the branding layer.');
        imagealphablending($layer, false);
        imagesavealpha($layer, true);
        imagefill($layer, 0, 0, imagecolorallocatealpha($layer, 0, 0, 0, 127));
        $x = 0; $y = 0;
        $opacity = max(10, min(100, (int)($settings['blog_text_opacity'] ?? 90)));
        $backgroundOpacity = max(0, min(100, (int)($settings['blog_text_background_opacity'] ?? 35)));
        $backgroundEnabled = !array_key_exists('blog_text_background_enabled',$settings) || !empty($settings['blog_text_background_enabled']);
        [$r,$g,$b] = self::color((string)($settings['blog_text_color'] ?? '#FFFFFF'), [255,255,255]);
        $foreground = imagecolorallocatealpha($layer,$r,$g,$b,127-(int)round($opacity*1.27));
        [$r,$g,$b] = self::color((string)($settings['blog_text_background_color'] ?? '#050F19'), [5,15,25]);
        $background = imagecolorallocatealpha($layer,$r,$g,$b,127-(int)round($backgroundOpacity*1.27));
        try {
            foreach ($items as $item) {
                imagealphablending($layer, false);
                $pillX = $x;
                $pillY = $y + (int)(($blockHeight - $item['boxHeight']) / 2);
                self::drawPill($layer,(int)$pillX,$pillY,$item['boxWidth'],$item['boxHeight'],$stroke,$foreground,$background,$backgroundEnabled && $backgroundOpacity > 0);
                $textX = $pillX + $paddingX + $stroke;
                $baseline = $pillY + (int)(($item['boxHeight']-$item['height'])/2) - $item['top'];
                imagealphablending($layer, true);
                foreach ($item['runs'] as $run) {
                    imagettftext($layer,$fontSize*.75,0,(int)round($textX-$run['left']),$baseline,$foreground,BrandingText::gdFont($run['font']),BrandingText::gdText($run['text']));
                    $textX += $run['width'];
                }
                $x += $item['boxWidth'] + $gap;
            }
            imagealphablending($canvas, true);
            imagecopyresampled($canvas, $layer, $destinationX, $destinationY, 0, 0,
                $targetWidth, $targetHeight, $targetWidth * $scale, $targetHeight * $scale);
        } finally {
            unset($layer);
        }
    }

    /** Scanlines avoid overlapping alpha at rounded corners or under the border. */
    private static function drawPill($canvas, int $x, int $y, int $width, int $height, int $stroke, int $foreground, int $background, bool $fill): void
    {
        $radius = min($height / 2, $width / 2);
        for ($row = 0; $row < $height; $row++) {
            $dy = max(0, $radius - ($row + .5), ($row + .5) - ($height - $radius));
            $inset = (int)ceil($radius - sqrt(max(0, $radius*$radius-$dy*$dy)));
            $left = $x + $inset; $right = $x + $width - 1 - $inset;
            $innerRadius = max(0, $radius-$stroke);
            $innerDy = max(0, $radius-($row+.5), ($row+.5)-($height-$radius));
            if ($row < $stroke || $row >= $height-$stroke || $innerDy >= $innerRadius) {
                if ($left <= $right) imageline($canvas,$left,$y+$row,$right,$y+$row,$foreground);
                continue;
            }
            $innerInset = (int)ceil($radius-sqrt(max(0,$innerRadius*$innerRadius-$innerDy*$innerDy)));
            $innerLeft = $x+$innerInset; $innerRight = $x+$width-1-$innerInset;
            if ($innerLeft>$left) imageline($canvas,$left,$y+$row,$innerLeft-1,$y+$row,$foreground);
            if ($innerRight<$right) imageline($canvas,$innerRight+1,$y+$row,$right,$y+$row,$foreground);
            if ($fill && $innerLeft <= $innerRight) imageline($canvas,$innerLeft,$y+$row,$innerRight,$y+$row,$background);
        }
    }

    private static function coordinates(int $w,int $h,int $iw,int $ih,string $position):array{$mx=max(16,(int)round($w*.035));$my=max(16,(int)round($h*.035));$x=str_ends_with($position,'left')?$mx:(str_ends_with($position,'right')?$w-$iw-$mx:(int)(($w-$iw)/2));$y=str_starts_with($position,'top')?$my:(str_starts_with($position,'bottom')?$h-$ih-$my:(int)(($h-$ih)/2));return[max(0,$x),max(0,$y)];}
    private static function position(string $value):string{return in_array($value,self::POSITIONS,true)?$value:'bottom-center';}
    private static function color(string $value,array $fallback):array{return preg_match('/^#[0-9A-Fa-f]{6}$/',$value)?[hexdec(substr($value,1,2)),hexdec(substr($value,3,2)),hexdec(substr($value,5,2))]:$fallback;}
    private static function orient($image,string $path){if(!function_exists('exif_read_data'))return $image;$info=@getimagesize($path);if((string)($info['mime']??'')!=='image/jpeg')return $image;$exif=@exif_read_data($path,'IFD0',true);$orientation=(int)($exif['IFD0']['Orientation']??$exif['Orientation']??1);if(in_array($orientation,[2,4,5,7],true)&&function_exists('imageflip'))imageflip($image,in_array($orientation,[2,5],true)?IMG_FLIP_HORIZONTAL:IMG_FLIP_VERTICAL);$angle=match($orientation){3=>180,5,6=>-90,7,8=>90,default=>0};if($angle!==0){$rotated=imagerotate($image,$angle,0);if($rotated!==false){imagedestroy($image);$image=$rotated;}}return $image;}
    private static function logoPath(string $relative):?string{$relative=trim(str_replace('\\','/',$relative),'/');if($relative===''||str_contains($relative,'..')||str_contains($relative,"\0"))return null;$root=realpath(self::ROOT);$path=realpath(self::ROOT.'/'.$relative);if($root===false||$path===false||!is_file($path)||!str_starts_with($path,$root.DIRECTORY_SEPARATOR))return null;$info=@getimagesize($path);return $info!==false&&in_array((string)($info['mime']??''),['image/png','image/jpeg','image/webp'],true)?$path:null;}
    private static function ensure(string $dir):void{if(!is_dir($dir)&&!mkdir($dir,0775,true)&&!is_dir($dir))throw new \RuntimeException('Could not create the blog image-branding directory.');}
}
