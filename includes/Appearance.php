<?php
namespace ACh\WPAccounts;

defined('ABSPATH') || exit;

/** Local appearance overrides and supported native global-style fallbacks. */
final class Appearance
{
    private static bool $applied = false;

    private static function color(mixed $value): string
    {
        return is_string($value) && preg_match('/\A#[0-9a-f]{6}\z/iD',$value) ? $value : '';
    }

    private static function foreground(string $hex): string
    {
        $channels = array_map(static function($offset) use($hex): float {
            $v = hexdec(substr($hex,$offset,2))/255;
            return $v<=0.04045 ? $v/12.92 : (($v+0.055)/1.055)**2.4;
        },[1,3,5]);
        return $channels[0]*0.2126+$channels[1]*0.7152+$channels[2]*0.0722 > 0.179 ? '#000000' : '#ffffff';
    }

    private static function preset(array $items,string $slug,string $key): mixed
    {
        foreach ($items as $item) {
            if (!is_array($item)) { continue; }
            if (($item['slug']??null)===$slug) { return $item[$key]??null; }
            if (($found=self::preset($item,$slug,$key))!==null) { return $found; }
        }
        return null;
    }

    /** Resolve WP's native typography, accepting only CSS font-family tokens. */
    private static function themeFont(): string
    {
        if (!function_exists('wp_get_global_styles')) { return ''; }
        $value=wp_get_global_styles(['typography','fontFamily']);
        if (is_string($value) && preg_match('/\A(?:var:preset\|font-family\||var\(--wp--preset--font-family--)([a-z0-9-]+)\)?\z/iD',$value,$m)) {
            $value=self::preset((array)wp_get_global_settings(['typography','fontFamilies']),$m[1],'fontFamily');
        }
        return is_string($value) && preg_match("/\A[\p{L}\p{N} ,_\-\x22\x27]+\z/uD",$value) ? $value : '';
    }

    /** Only an existing theme token or validated hex, never raw style text. */
    private static function themeColor(array $path): string
    {
        if (!function_exists('wp_get_global_styles')) { return ''; }
        $value = wp_get_global_styles($path);
        if ($color = self::color($value)) { return $color; }
        if (is_string($value) && preg_match('/\Avar:preset\|color\|([a-z0-9-]+)\z/iD',$value,$m) &&
            function_exists('wp_get_global_settings')) {
            return self::color(self::preset((array)wp_get_global_settings(['color','palette']),$m[1],'color'));
        }
        return '';
    }

    public static function assets(): void
    {
        if (self::$applied) { return; }
        self::$applied = true;
        $style=Settings::appearance();
        $declarations=[];
        $standalone=[];
        $accent=self::color($style['accent']);
        if ($accent!=='') {
            $declarations[]='--ach-accent:'.$accent;
            $declarations[]='--ach-on-accent:'.self::foreground($accent);
        } elseif ($theme=self::themeColor(['elements','button','color','background'])) {
            $standalone[]='--ach-accent:'.$theme;
            $standalone[]='--ach-on-accent:'.self::foreground($theme);
        }
        $background=self::color($style['background']);
        if ($background!=='' || ($background=self::themeColor(['color','background']))) {
            $standalone[]='--ach-page:'.$background;
        }
        if ($style['radius']!==null) { $declarations[]='--ach-radius:'.(int)$style['radius'].'px'; }
        $fontCss='';
        if ($style['font_id']) {
            $url=wp_get_attachment_url($style['font_id']);
            if (is_string($url) && Frontend::localUrl($url,'')!=='' && !preg_match('/[\x00-\x20<>"\x27()\\\\]/',$url)) {
                $fontCss='@font-face{font-family:ACHAccountLocal;src:url("'.$url.'");font-display:swap;}';
                $declarations[]='--ach-font:ACHAccountLocal,system-ui,sans-serif';
            }
        }
        $themeFont=$fontCss==='' ? self::themeFont() : '';
        if ($declarations || $standalone || $fontCss || $themeFont!=='') {
            wp_add_inline_style('ach-wp-accounts',$fontCss.
                ($declarations ? '.ach-auth-body,.ach-accounts{'.implode(';',$declarations).'}' : '').
                ($standalone ? '.ach-auth-body{'.implode(';',$standalone).'}' : '').
                ($accent!=='' ? '.ach-accounts .ach-accounts-primary{background-color:var(--ach-accent);color:var(--ach-on-accent);}' : (!empty($theme) ? '.ach-auth-body .ach-accounts .ach-accounts-primary{background-color:var(--ach-accent);color:var(--ach-on-accent);}' : '')).
                ($fontCss!=='' ? '.ach-auth-body,.ach-accounts{font-family:var(--ach-font);}' : ($themeFont!=='' ? 'html.ach-auth-document{font-family:'.$themeFont.';}' : '')));
        }
    }

    public static function logo(): int
    {
        $style=Settings::appearance();
        return match($style['logo_mode']) {
            'name'=>0,
            'custom'=>$style['logo_id'],
            default=>(int)get_theme_mod('custom_logo'),
        };
    }
}
