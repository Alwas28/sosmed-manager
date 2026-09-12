<?php

namespace App\Services\Image;

use App\Models\PostTemplateSetting;
use GdImage;

/**
 * Deterministic "house style" post template (Blueprint §5/§6 media step):
 * a rounded banner card floating over the lower-middle of the photo (inset
 * from the edges, not full-bleed) with the headline centred inside it, a
 * configurable-position logo watermark, and a row of platform badges (each
 * with its own @username) sitting inside the card below the headline —
 * drawn with GD, not an AI image model. AI is only asked to *write* the
 * headline text (see AiService::suggestTitle); pixel-perfect, always-
 * identical brand placement is a job for a deterministic renderer, not a
 * generative one.
 */
class PostTemplateCompositor
{
    /** Text fallback, used only when the bundled icon font is missing. */
    private const array PLATFORM_INITIALS = [
        'facebook' => 'f',
        'instagram' => 'IG',
        'twitter' => 'X',
        'linkedin' => 'in',
        'tiktok' => 'TT',
    ];

    /**
     * Font Awesome 6 Free "Brands" glyph codepoints (hex) — draws the real
     * platform logo instead of a text initial. Codepoints confirmed against
     * FortAwesome/Font-Awesome's metadata/icons.json for the 6.5.1 release
     * (the same version this app already loads for its UI icons).
     */
    private const array PLATFORM_GLYPHS = [
        'facebook' => 'f39e',  // facebook-f
        'instagram' => 'f16d', // instagram
        'twitter' => 'e61b',   // x-twitter
        'linkedin' => 'f0e1',  // linkedin-in
        'tiktok' => 'e07b',    // tiktok
    ];

    /** Flat brand-colour approximation for each platform's icon glyph. */
    private const array PLATFORM_COLORS = [
        'facebook' => '1877F2',
        'instagram' => 'C13584',
        'twitter' => '000000',
        'linkedin' => '0A66C2',
        'tiktok' => '000000',
    ];

    /**
     * @param  string  $mediaLabel  Kept for call-site compatibility (e.g. "Foto"/"Video" from the content form) —
     *                              no longer rendered onto the image itself; the social row now speaks for itself
     *                              via each platform's own @username instead of a generic "Foto: Brand" caption.
     * @param  PostTemplateSetting|null  $settingsOverride  Use these settings instead of the saved singleton —
     *                                                      lets the settings page preview unsaved field changes.
     * @param  string|null  $logoPathOverride  Absolute path to use as the logo instead of $settings->logo_path —
     *                                         lets the settings page preview a just-selected (not yet saved) upload.
     * @param  string|null  $fontPathOverride  Same idea as $logoPathOverride, for the headline font.
     * @return array{bytes: string, mime: string}
     */
    public function compose(
        string $imageBytes,
        string $headline,
        string $mediaLabel,
        ?PostTemplateSetting $settingsOverride = null,
        ?string $logoPathOverride = null,
        ?string $fontPathOverride = null,
    ): array {
        $settings = $settingsOverride ?? PostTemplateSetting::current();

        $im = @imagecreatefromstring($imageBytes);
        if (! $im instanceof GdImage) {
            throw new TemplateException('Berkas bukan gambar yang valid.');
        }

        $im = $this->fixOrientation($im, $imageBytes);

        if (! imageistruecolor($im)) {
            imagepalettetotruecolor($im);
        }

        $width = imagesx($im);
        $height = imagesy($im);

        $fontPath = $fontPathOverride ?? ($settings->hasFont() ? $settings->fontAbsolutePath() : null);
        $logoPath = $logoPathOverride ?? ($settings->hasLogo() ? $settings->logoAbsolutePath() : null);
        $bannerColor = $this->allocateHex($im, $settings->banner_color);
        $textColor = $this->allocateHex($im, $settings->text_color);

        // The card is inset from the sides and floats a short distance above
        // the very bottom edge — both distances are configurable per the
        // "Template Postingan" settings page (tepi kiri/kanan, jarak bawah),
        // matching the reference: a centered banner, not a full-bleed strip.
        $marginX = (int) max(12, round($width * ($settings->card_margin_x / 100)));
        $bottomGap = (int) max(18, round($height * ($settings->card_margin_bottom / 100)));
        $radius = (int) max(10, round($width * 0.02));
        $cardWidth = $width - 2 * $marginX;

        $pad = (int) max(14, round($width * 0.032));
        $contentWidth = $cardWidth - 2 * $pad;

        // The card is a FIXED size (also settings-driven) — it never grows
        // with a long caption. Instead the headline text shrinks (and, as a
        // last resort, gets an ellipsis) to fit the space that's left after
        // padding and the social row.
        $cardHeight = (int) max(70, round($height * ($settings->card_height / 100)));
        $cardHeight = min($cardHeight, $height - $bottomGap - 10);
        $cardBottom = $height - $bottomGap;
        $cardTop = $cardBottom - $cardHeight;

        $showSocial = $settings->show_social_row && ! empty($settings->social_platforms);
        $hasUsernames = $this->hasAnyUsername($settings);
        // All three are admin-configurable (px at a 1080px-wide reference
        // photo) and scale proportionally with the actual photo width;
        // fitHeadline() only shrinks the headline below its size if a long
        // caption wouldn't otherwise fit the fixed card — the icon/username
        // sizes are not auto-shrunk, they're a direct, independent setting.
        $initialHeadlineSize = (int) max(10, round($settings->headline_font_size * $width / 1080));
        $iconDiameter = (int) max(16, round($settings->social_icon_size * $width / 1080));
        $usernameFontSize = (int) max(8, round($settings->social_username_font_size * $width / 1080));
        $lineSpacing = $settings->line_spacing > 0 ? $settings->line_spacing : 1.30;
        $availableHeight = max(1, $cardHeight - 2 * $pad);

        $fit = $this->fitHeadline(
            $fontPath,
            trim($headline) !== '' ? $headline : ' ',
            $contentWidth,
            $availableHeight,
            $initialHeadlineSize,
            $lineSpacing,
            $showSocial,
            $hasUsernames,
            $iconDiameter,
            $usernameFontSize,
        );
        ['lines' => $lines, 'size' => $headlineSize, 'lineHeight' => $lineHeight, 'socialGap' => $socialGap, 'socialBlock' => $socialBlock] = $fit;

        $this->roundedRect($im, $marginX, $cardTop, $marginX + $cardWidth, $cardBottom, $radius, $bannerColor);

        // Headline + social row are vertically centred as one block within
        // the fixed card, so a short caption doesn't look pinned to the top.
        $headlineBlock = count($lines) * $lineHeight;
        $contentHeight = $headlineBlock + $socialBlock;
        $extra = max(0, $availableHeight - $contentHeight);
        $blockTop = $cardTop + $pad + (int) round($extra / 2);

        // Headline: each line is centred within the card, not left-aligned.
        $y = $blockTop + (int) round($headlineSize * 0.85);
        foreach ($lines as $line) {
            $lineWidth = $this->measure($fontPath, $headlineSize, $line);
            $lineX = $marginX + (int) round(($cardWidth - $lineWidth) / 2);
            $this->drawText($im, $fontPath, $headlineSize, $lineX, $y, $textColor, $line);
            $y += $lineHeight;
        }

        // Social row sits inside the card, below the headline, centred.
        if ($showSocial) {
            $rowY = $blockTop + $headlineBlock + $socialGap;
            $this->drawSocialRow($im, $fontPath, $iconDiameter, $usernameFontSize, $marginX, $cardWidth, $rowY, $textColor, $settings);
        }

        if ($settings->show_logo && $logoPath) {
            $this->stampLogo($im, $logoPath, $width, $height, $settings->logo_position);
        }

        $bytes = $this->encode($im);
        imagedestroy($im);

        return $bytes;
    }

    /**
     * Filled rounded rectangle — GD has no native primitive for one, so this
     * overlays a cross of solid rectangles with a filled circle at each
     * corner. Safe for a flat fill colour (no anti-aliasing seams).
     */
    private function roundedRect(GdImage $im, int $x1, int $y1, int $x2, int $y2, int $radius, int $color): void
    {
        $radius = min($radius, (int) (($x2 - $x1) / 2), (int) (($y2 - $y1) / 2));

        imagefilledrectangle($im, $x1 + $radius, $y1, $x2 - $radius, $y2, $color);
        imagefilledrectangle($im, $x1, $y1 + $radius, $x2, $y2 - $radius, $color);
        imagefilledellipse($im, $x1 + $radius, $y1 + $radius, $radius * 2, $radius * 2, $color);
        imagefilledellipse($im, $x2 - $radius, $y1 + $radius, $radius * 2, $radius * 2, $color);
        imagefilledellipse($im, $x1 + $radius, $y2 - $radius, $radius * 2, $radius * 2, $color);
        imagefilledellipse($im, $x2 - $radius, $y2 - $radius, $radius * 2, $radius * 2, $color);
    }

    /** @return array<int, string> */
    private function wrap(?string $fontPath, int $size, string $text, int $maxWidth, int $maxLines = 8): array
    {
        $words = preg_split('/\s+/', trim($text)) ?: [''];
        $lines = [];
        $current = '';

        foreach ($words as $word) {
            $test = $current === '' ? $word : $current.' '.$word;
            if ($this->measure($fontPath, $size, $test) > $maxWidth && $current !== '') {
                $lines[] = $current;
                $current = $word;
            } else {
                $current = $test;
            }

            if (count($lines) >= $maxLines) {
                break;
            }
        }

        if ($current !== '' && count($lines) < $maxLines) {
            $lines[] = $current;
        }

        return $lines === [] ? [''] : $lines;
    }

    /**
     * Finds the largest headline size (down to a floor) whose wrapped lines,
     * plus the social row, fit within the card's fixed available height. If
     * even the smallest size still overflows, the trailing lines are cut and
     * the last visible one gets an ellipsis — the card's size never changes
     * to accommodate a long caption. Note: only the headline shrinks here —
     * $iconDiameter/$usernameFontSize are the admin's own direct settings
     * and are never auto-shrunk, only accounted for as fixed space to leave
     * room for.
     *
     * @return array{lines: array<int, string>, size: int, lineHeight: int, socialGap: int, socialBlock: int}
     */
    private function fitHeadline(
        ?string $fontPath,
        string $headline,
        int $contentWidth,
        int $availableHeight,
        int $initialSize,
        float $lineSpacing,
        bool $showSocial,
        bool $hasUsernames,
        int $iconDiameter,
        int $usernameFontSize,
    ): array {
        $size = $initialSize;
        $minSize = (int) max(11, round($initialSize * 0.55));

        while (true) {
            $lineHeight = (int) round($size * $lineSpacing);
            $lines = $this->wrap($fontPath, $size, $headline, $contentWidth, 8);
            $socialGap = $showSocial ? (int) round($size * 0.5) : 0;
            // When at least one platform has a username configured, every
            // badge reserves a line of space underneath it (even platforms
            // without one, so the row stays visually aligned).
            $usernameExtra = $hasUsernames ? (4 + $usernameFontSize) : 0;
            $socialBlock = $showSocial ? $socialGap + $iconDiameter + $usernameExtra : 0;

            $fits = count($lines) * $lineHeight + $socialBlock <= $availableHeight;

            if ($fits || $size <= $minSize) {
                $maxLines = max(1, (int) floor(($availableHeight - $socialBlock) / $lineHeight));
                if (count($lines) > $maxLines) {
                    $lines = array_slice($lines, 0, $maxLines);
                    $last = count($lines) - 1;
                    $lines[$last] = $this->ellipsize($fontPath, $size, $lines[$last], $contentWidth);
                }

                return compact('lines', 'size', 'lineHeight', 'socialGap', 'socialBlock');
            }

            $size--;
        }
    }

    /** Trims text and appends an ellipsis until it fits within $maxWidth. */
    private function ellipsize(?string $fontPath, int $size, string $text, int $maxWidth): string
    {
        if ($this->measure($fontPath, $size, $text) <= $maxWidth) {
            return $text;
        }

        $text = rtrim($text);
        while ($text !== '' && $this->measure($fontPath, $size, $text.'…') > $maxWidth) {
            $text = mb_substr($text, 0, -1);
        }

        return rtrim($text).'…';
    }

    private function measure(?string $fontPath, int $size, string $text): int
    {
        if ($fontPath) {
            $box = imagettfbbox($size, 0, $fontPath, $text);

            return abs($box[2] - $box[0]);
        }

        return imagefontwidth(5) * strlen($text);
    }

    private function drawText(GdImage $im, ?string $fontPath, int $size, int $x, int $y, int $color, string $text): void
    {
        if ($fontPath) {
            imagettftext($im, $size, 0, $x, $y, $color, $fontPath, $text);
        } else {
            // imagestring's $y is the top of the glyph, not the baseline.
            imagestring($im, 5, $x, $y - imagefontheight(5), $text, $color);
        }
    }

    /**
     * Absolute path to the bundled Font Awesome 6 "Brands" webfont (checked
     * into the repo at `resources/fonts/`, same version the admin UI already
     * loads from cdnjs) — used to draw the real platform logo glyphs onto
     * the social row. Not user-configurable; falls back to text initials if
     * the file is ever missing.
     */
    private function iconFontPath(): ?string
    {
        $path = resource_path('fonts/fa-brands-400.ttf');

        return is_file($path) ? $path : null;
    }

    /** Whether any enabled platform has a username configured — decides if the row reserves a username line at all. */
    private function hasAnyUsername(PostTemplateSetting $settings): bool
    {
        $usernames = $settings->social_usernames ?? [];

        foreach ($settings->social_platforms as $platform) {
            if (trim((string) ($usernames[$platform] ?? '')) !== '') {
                return true;
            }
        }

        return false;
    }

    /**
     * Badges drawn as one row, horizontally centred within the card. Each
     * badge is a circle (colour configurable, white by default) with the
     * platform's real logo glyph (Font Awesome Brands) on top — either in
     * its own flat brand colour, or a single colour the admin picks for
     * every icon (e.g. plain white) — falling back to a plain text initial
     * if the icon font isn't available — with that platform's own @username
     * centred just underneath it, since a brand's handle often differs from
     * one platform to the next.
     */
    private function drawSocialRow(
        GdImage $im,
        ?string $fontPath,
        int $diameter,
        int $usernameSize,
        int $cardX,
        int $cardWidth,
        int $y,
        int $textColor,
        PostTemplateSetting $settings,
    ): void {
        $badgeBg = $this->allocateHex($im, $settings->icon_bg_color ?: '#FFFFFF');
        // In "custom" mode every icon shares one colour instead of each
        // platform's real brand colour — null here means "use brand colours".
        $customInk = $settings->icon_style === 'custom'
            ? $this->allocateHex($im, $settings->icon_color ?: '#FFFFFF')
            : null;
        $badgeInk = $customInk ?? imagecolorallocate($im, 20, 20, 20);
        $gap = 10;
        $iconFont = $this->iconFontPath();
        // Same ratios the old headline-derived sizing used to keep (glyph
        // ≈ 44% of the circle's diameter, a single-letter fallback ≈ 50%).
        $iconSize = (int) max(8, round($diameter * 0.441));
        $initialSize = (int) max(9, round($diameter * 0.5));
        $usernameGap = 4;
        $usernames = $settings->social_usernames ?? [];
        $platforms = $settings->social_platforms;

        // Each badge is as wide as its icon circle or its username text,
        // whichever is bigger, so a long handle doesn't overlap its neighbour.
        $badgeWidths = [];
        foreach ($platforms as $platform) {
            $username = trim((string) ($usernames[$platform] ?? ''));
            $usernameWidth = $username !== '' ? $this->measure($fontPath, $usernameSize, $username) : 0;
            $badgeWidths[$platform] = max($diameter, $usernameWidth);
        }

        $rowWidth = array_sum($badgeWidths) + max(0, count($platforms) - 1) * $gap;
        $cursor = $cardX + (int) round(($cardWidth - $rowWidth) / 2);
        $iconCenterY = $y + (int) ($diameter / 2);

        foreach ($platforms as $platform) {
            $badgeWidth = $badgeWidths[$platform];
            $badgeCenterX = $cursor + (int) ($badgeWidth / 2);

            imagefilledellipse($im, $badgeCenterX, $iconCenterY, $diameter, $diameter, $badgeBg);

            if ($iconFont && isset(self::PLATFORM_GLYPHS[$platform])) {
                $glyph = mb_chr((int) hexdec(self::PLATFORM_GLYPHS[$platform]), 'UTF-8');
                $ink = $customInk ?? $this->allocateHex($im, self::PLATFORM_COLORS[$platform] ?? '141414');
                [$glyphX, $glyphY] = $this->centerTextOrigin($iconFont, $iconSize, $glyph, $badgeCenterX, $iconCenterY);
                $this->drawText($im, $iconFont, $iconSize, $glyphX, $glyphY, $ink, $glyph);
            } else {
                $initial = self::PLATFORM_INITIALS[$platform] ?? strtoupper(substr((string) $platform, 0, 1));
                [$initialX, $initialY] = $this->centerTextOrigin($fontPath, $initialSize, $initial, $badgeCenterX, $iconCenterY);
                $this->drawText($im, $fontPath, $initialSize, $initialX, $initialY, $badgeInk, $initial);
            }

            $username = trim((string) ($usernames[$platform] ?? ''));
            if ($username !== '') {
                $usernameWidth = $this->measure($fontPath, $usernameSize, $username);
                $usernameX = $badgeCenterX - (int) round($usernameWidth / 2);
                $usernameY = $y + $diameter + $usernameGap + $usernameSize;
                $this->drawText($im, $fontPath, $usernameSize, $usernameX, $usernameY, $textColor, $username);
            }

            $cursor += $badgeWidth + $gap;
        }
    }

    /**
     * Draw-origin (x, y) that puts $text's actual ink bounding box centred
     * on ($centerX, $centerY) — imagettftext's $x/$y is the baseline origin,
     * not a visual centre, so a fixed baseline offset (e.g. "70% of the
     * circle's diameter") drifts off-centre between glyphs of different
     * height/descent. imagettfbbox's own corner offsets correct for that.
     *
     * @return array{int, int}
     */
    private function centerTextOrigin(?string $fontPath, int $size, string $text, int $centerX, int $centerY): array
    {
        if (! $fontPath) {
            $width = $this->measure($fontPath, $size, $text);

            return [$centerX - (int) round($width / 2), $centerY + (int) round(imagefontheight(5) / 2)];
        }

        $box = imagettfbbox($size, 0, $fontPath, $text);
        $width = abs($box[4] - $box[0]);
        $height = abs($box[5] - $box[1]);

        $x = $centerX - (int) round($width / 2) - $box[0];
        $y = $centerY + (int) round($height / 2) - $box[1];

        return [$x, $y];
    }

    private function stampLogo(GdImage $im, ?string $logoPath, int $canvasWidth, int $canvasHeight, string $position): void
    {
        if (! $logoPath || ! is_file($logoPath)) {
            return;
        }

        $logo = @imagecreatefrompng($logoPath);
        if (! $logo instanceof GdImage) {
            return;
        }

        $logoW = imagesx($logo);
        $logoH = imagesy($logo);

        $targetW = (int) min($canvasWidth * 0.18, 180, $logoW);
        $targetH = (int) round($logoH * ($targetW / $logoW));

        $resized = imagecreatetruecolor($targetW, $targetH);
        imagealphablending($resized, false);
        imagesavealpha($resized, true);
        $transparent = imagecolorallocatealpha($resized, 0, 0, 0, 127);
        imagefilledrectangle($resized, 0, 0, $targetW, $targetH, $transparent);
        imagecopyresampled($resized, $logo, 0, 0, 0, 0, $targetW, $targetH, $logoW, $logoH);

        $margin = (int) max(12, round($canvasWidth * 0.025));
        [$x, $y] = $this->resolveLogoPosition($position, $canvasWidth, $canvasHeight, $targetW, $targetH, $margin);

        imagealphablending($im, true);
        imagecopy($im, $resized, $x, $y, 0, 0, $targetW, $targetH);

        imagedestroy($logo);
        imagedestroy($resized);
    }

    /**
     * Top-left corner (x, y) for the logo given a 9-point anchor position —
     * mirrors App\Enums\LogoPosition's values. Unknown/legacy values fall
     * back to top-left, the previous fixed placement.
     *
     * @return array{int, int}
     */
    private function resolveLogoPosition(string $position, int $canvasWidth, int $canvasHeight, int $logoWidth, int $logoHeight, int $margin): array
    {
        $left = $margin;
        $centerX = (int) round(($canvasWidth - $logoWidth) / 2);
        $right = $canvasWidth - $logoWidth - $margin;

        $top = $margin;
        $centerY = (int) round(($canvasHeight - $logoHeight) / 2);
        $bottom = $canvasHeight - $logoHeight - $margin;

        return match ($position) {
            'top-center' => [$centerX, $top],
            'top-right' => [$right, $top],
            'middle-left' => [$left, $centerY],
            'center' => [$centerX, $centerY],
            'middle-right' => [$right, $centerY],
            'bottom-left' => [$left, $bottom],
            'bottom-center' => [$centerX, $bottom],
            'bottom-right' => [$right, $bottom],
            default => [$left, $top], // top-left
        };
    }

    private function allocateHex(GdImage $im, string $hex): int
    {
        $hex = ltrim($hex, '#');
        if (strlen($hex) !== 6) {
            $hex = '0B5E34';
        }

        [$r, $g, $b] = array_map(fn ($c) => hexdec($c), str_split($hex, 2));

        return imagecolorallocate($im, (int) $r, (int) $g, (int) $b);
    }

    private function fixOrientation(GdImage $im, string $originalBytes): GdImage
    {
        if (! function_exists('exif_read_data')) {
            return $im;
        }

        try {
            $stream = fopen('php://temp', 'r+');
            fwrite($stream, $originalBytes);
            rewind($stream);
            $exif = @exif_read_data($stream);
            fclose($stream);
        } catch (\Throwable) {
            return $im;
        }

        $orientation = $exif['Orientation'] ?? 1;

        return match ($orientation) {
            3 => imagerotate($im, 180, 0) ?: $im,
            6 => imagerotate($im, -90, 0) ?: $im,
            8 => imagerotate($im, 90, 0) ?: $im,
            default => $im,
        };
    }

    /** @return array{bytes: string, mime: string} */
    private function encode(GdImage $im): array
    {
        ob_start();
        imagejpeg($im, null, 90);
        $bytes = ob_get_clean();

        if ($bytes === false) {
            throw new TemplateException('Gagal membuat gambar hasil template.');
        }

        return ['bytes' => $bytes, 'mime' => 'image/jpeg'];
    }
}
