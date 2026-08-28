<?php
/**
 * brandingValidation.php — compliance checks for branding + store assets.
 *
 * See docs/Branding.md. The short version: pwt shipped a PWA icon that was a blank
 * white square — a valid 512x512 PNG, HTTP 200, correct filename, ONE unique colour —
 * and every check it had passed. So the useful rules here are SEMANTIC, not structural.
 * Dimensions and file types matter only because stores hard-reject on them.
 *
 * Shared deliberately: the admin UI, the (future) branding:write service-key API and the
 * MCP tools must all reach the same verdict, or an agent will happily upload something
 * the UI would have refused.
 *
 * Severity:
 *   BLOCK — would be rejected by a store, or would ship visibly broken. Refuse it.
 *   WARN  — save it, but say so.
 * A BLOCK on one slot must never prevent saving unrelated fields.
 */

if (!defined('BRANDING_BLOCK')) define('BRANDING_BLOCK', 'block');
if (!defined('BRANDING_WARN'))  define('BRANDING_WARN',  'warn');

/**
 * Slot catalogue. `w`/`h` null = any. `sizes` = list of accepted [w,h] pairs.
 * `alpha`: true = required, false = must NOT have one, null = don't care.
 */
/**
 * @param string|null $tier 'pwa' (every app) or 'store' (only apps that ship a mobile
 *                          binary). Null returns everything.
 *
 * NOT every web app ships a mobile app, but EVERY app needs PWA assets — so the store
 * tier is opt-in per app and must never be presented as missing work for an app that
 * will never see a store.
 */
function branding_slot_specs($tier = null) {
    $all = [
        // ── Master. Everything else derives from this one file. ──────────────
        'icon_master' => [
            'label' => 'App icon (master)', 'min_w' => 1024, 'min_h' => 1024, 'square' => true,
            'formats' => ['png', 'svg', 'webp'], 'tier' => 'pwa', 'rasterized' => true,
            'help' => 'One high-resolution square master. Every derived icon comes from it. '
                    . 'Rasterised server-side, so a font-dependent SVG cannot be used here.',
        ],

        // ── In-app chrome ────────────────────────────────────────────────────
        'logo_light' => ['label' => 'Logo (light backgrounds)', 'formats' => ['png','svg','webp','jpg'], 'tier' => 'pwa'],
        'logo_dark'  => ['label' => 'Logo (dark backgrounds)',  'formats' => ['png','svg','webp','jpg'], 'tier' => 'pwa'],
        'favicon'    => ['label' => 'Favicon', 'square' => true, 'formats' => ['png','svg','ico','webp'], 'tier' => 'pwa'],

        // ── Apple ────────────────────────────────────────────────────────────
        'apple_icon_1024' => [ 'tier' => 'store',
            'label' => 'App Store icon', 'sizes' => [[1024,1024]], 'alpha' => false,
            'formats' => ['png'], 'derived_from' => 'icon_master',
            'help' => 'Apple rejects an icon with an alpha channel.',
        ],
        'screenshot_iphone_69' => [ 'tier' => 'store',
            'label' => 'iPhone 6.9" screenshots', 'sizes' => [[1290,2796],[1320,2868],[2796,1290],[2868,1320]],
            'formats' => ['png','jpg'], 'multiple' => true, 'max' => 10,
        ],
        'screenshot_ipad_13' => [ 'tier' => 'store',
            'label' => 'iPad 13" screenshots', 'sizes' => [[2064,2752],[2048,2732],[2752,2064],[2732,2048]],
            'formats' => ['png','jpg'], 'multiple' => true, 'max' => 10,
        ],

        // ── Google Play ──────────────────────────────────────────────────────
        'play_icon_512' => [ 'tier' => 'store',
            'label' => 'Play Store icon', 'sizes' => [[512,512]], 'formats' => ['png'],
            'derived_from' => 'icon_master',
        ],
        'feature_graphic' => [ 'tier' => 'store',
            'label' => 'Play feature graphic', 'sizes' => [[1024,500]], 'alpha' => false,
            'formats' => ['png','jpg'], 'help' => 'Google Play will not publish a listing without one.',
        ],
        'screenshot_phone' => [ 'tier' => 'store',
            'label' => 'Play phone screenshots', 'min_w' => 320, 'min_h' => 320, 'max_w' => 3840, 'max_h' => 3840,
            'formats' => ['png','jpg'], 'multiple' => true, 'min_count' => 2, 'max' => 8,
        ],
        'screenshot_tablet_7'  => ['tier' => 'store', 'label' => 'Play 7" tablet screenshots',  'min_w' => 320, 'max_w' => 3840, 'formats' => ['png','jpg'], 'multiple' => true, 'max' => 8],
        'screenshot_tablet_10' => [ 'tier' => 'store','label' => 'Play 10" tablet screenshots', 'min_w' => 320, 'max_w' => 3840, 'formats' => ['png','jpg'], 'multiple' => true, 'max' => 8],

        // ── PWA / Android build ──────────────────────────────────────────────
        'maskable_512'        => ['tier' => 'pwa', 'rasterized' => true, 'label' => 'Maskable icon', 'sizes' => [[512,512]], 'formats' => ['png'], 'derived_from' => 'icon_master', 'maskable' => true],
        'adaptive_background' => [ 'tier' => 'store','label' => 'Adaptive icon background', 'square' => true, 'formats' => ['png','svg']],
        'pwa_screenshot_mobile' => ['tier' => 'pwa', 'label' => 'PWA screenshot (mobile)', 'formats' => ['png','jpg'], 'form_factor' => 'narrow'],
        'pwa_screenshot_tablet' => ['tier' => 'pwa', 'label' => 'PWA screenshot (tablet)', 'formats' => ['png','jpg'], 'form_factor' => 'wide'],
        'pwa_screenshot_wide'   => ['tier' => 'pwa', 'label' => 'PWA screenshot (wide)',   'formats' => ['png','jpg'], 'form_factor' => 'wide'],
    ];
    if ($tier === null) return $all;
    return array_filter($all, function ($s) use ($tier) { return ($s['tier'] ?? 'pwa') === $tier; });
}

/** Basic facts about an image file. Returns null if it isn't a readable image. */
function branding_image_info($path) {
    if (!is_readable($path)) return null;
    $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    if ($ext === 'svg') {
        return ['w' => null, 'h' => null, 'ext' => 'svg', 'alpha' => true, 'bytes' => filesize($path)];
    }
    $d = @getimagesize($path);
    if (!$d) return null;
    $map = [IMAGETYPE_PNG => 'png', IMAGETYPE_JPEG => 'jpg', IMAGETYPE_WEBP => 'webp',
            IMAGETYPE_GIF => 'gif', IMAGETYPE_ICO => 'ico'];
    $ext = $map[$d[2]] ?? $ext;
    $alpha = false;
    if ($ext === 'png') {
        // colour type 4 (grey+alpha) and 6 (RGBA) carry a channel; 3 (palette) may carry tRNS.
        $fh = fopen($path, 'rb');
        $hdr = fread($fh, 33); fclose($fh);
        $ct = ord(substr($hdr, 25, 1));
        $alpha = in_array($ct, [4, 6], true) || strpos(file_get_contents($path, false, null, 0, 4096), 'tRNS') !== false;
    } elseif ($ext === 'webp' || $ext === 'gif') {
        $alpha = true; // conservative: may carry one
    }
    return ['w' => $d[0], 'h' => $d[1], 'ext' => $ext, 'alpha' => $alpha, 'bytes' => filesize($path)];
}

/** Load any raster into a GD truecolor image with alpha preserved, or null. */
function branding_load_gd($path) {
    $i = branding_image_info($path);
    if (!$i || $i['ext'] === 'svg') return null;
    switch ($i['ext']) {
        case 'png':  $im = @imagecreatefrompng($path); break;
        case 'jpg':  $im = @imagecreatefromjpeg($path); break;
        case 'webp': $im = @imagecreatefromwebp($path); break;
        case 'gif':  $im = @imagecreatefromgif($path); break;
        default: return null;
    }
    return $im ?: null;
}

/**
 * Is every sampled pixel the same? A blank asset is a valid PNG of the right size and
 * passes every structural check — sampling the pixels is the only way to catch it.
 */
function branding_is_flat($path, $grid = 16) {
    $im = branding_load_gd($path);
    if (!$im) return false;
    $w = imagesx($im); $h = imagesy($im);
    $sx = max(1, (int)($w / $grid)); $sy = max(1, (int)($h / $grid));
    $seen = null;
    for ($y = 0; $y < $h; $y += $sy) {
        for ($x = 0; $x < $w; $x += $sx) {
            $px = imagecolorat($im, $x, $y);
            if ($seen === null) { $seen = $px; continue; }
            if ($px !== $seen) { imagedestroy($im); return false; }
        }
    }
    imagedestroy($im);
    return true;
}

/**
 * Distinct colours once composited onto $hex. White-on-white collapses to 1 — which is
 * exactly how a favicon set to the inverse-colour variant becomes an invisible icon.
 */
function branding_distinct_on($path, $hex, $grid = 24) {
    $im = branding_load_gd($path);
    if (!$im) return null;
    $hex = ltrim($hex, '#');
    if (strlen($hex) !== 6) return null;
    [$br, $bg, $bb] = [hexdec(substr($hex,0,2)), hexdec(substr($hex,2,2)), hexdec(substr($hex,4,2))];
    $w = imagesx($im); $h = imagesy($im);
    $sx = max(1, (int)($w / $grid)); $sy = max(1, (int)($h / $grid));
    $seen = [];
    for ($y = 0; $y < $h; $y += $sy) {
        for ($x = 0; $x < $w; $x += $sx) {
            $c = imagecolorat($im, $x, $y);
            $a = ($c >> 24) & 0x7F;                 // 0 opaque .. 127 transparent
            $f = 1 - ($a / 127);
            $r = (int)(((($c >> 16) & 0xFF) * $f) + ($br * (1 - $f)));
            $g = (int)(((($c >> 8)  & 0xFF) * $f) + ($bg * (1 - $f)));
            $b = (int)((( $c        & 0xFF) * $f) + ($bb * (1 - $f)));
            $seen[($r >> 3) . ',' . ($g >> 3) . ',' . ($b >> 3)] = true;   // 5-bit buckets
        }
    }
    imagedestroy($im);
    return count($seen);
}

/**
 * Does this SVG paint text through a font? Glyphs then depend on a font the renderer may
 * not have — and the server, the phone and the CI box are three different renderers. This
 * is the check that would have caught pwt's monogram rendering as a fallback serif
 * everywhere, in a file that was otherwise perfectly valid.
 */
function branding_svg_has_text($path) {
    if (!is_readable($path)) return false;
    if (strtolower(pathinfo($path, PATHINFO_EXTENSION)) !== 'svg') return false;
    $s = file_get_contents($path);
    if ($s === false) return false;
    $s = preg_replace('/<!--.*?-->/s', '', $s);
    return (bool) preg_match('/<(text|tspan|textPath)\b/i', $s);
}

/**
 * Does this SVG carry its own font, rather than naming one and hoping? An @font-face
 * with a data: URI is self-contained and renders identically everywhere; a bare
 * font-family is a wish.
 */
function branding_svg_embeds_font($path) {
    if (!is_readable($path)) return false;
    $s = file_get_contents($path);
    if ($s === false) return false;
    return (bool) preg_match('/@font-face/i', $s)
        && (bool) preg_match('/src\s*:\s*url\(\s*[\'"]?data:/i', $s);
}

/**
 * Fraction of opaque content falling outside the maskable safe zone — the centred circle
 * of diameter 80% of the icon. Android launchers crop everything beyond it.
 */
function branding_outside_safe_zone($path, $grid = 48) {
    $im = branding_load_gd($path);
    if (!$im) return null;
    $w = imagesx($im); $h = imagesy($im);
    $cx = $w / 2; $cy = $h / 2; $r = min($w, $h) * 0.4;
    $sx = max(1, (int)($w / $grid)); $sy = max(1, (int)($h / $grid));
    $in = 0; $out = 0;
    for ($y = 0; $y < $h; $y += $sy) {
        for ($x = 0; $x < $w; $x += $sx) {
            $c = imagecolorat($im, $x, $y);
            if ((($c >> 24) & 0x7F) > 100) continue;      // transparent — not content
            $d = sqrt((($x - $cx) ** 2) + (($y - $cy) ** 2));
            if ($d > $r) $out++; else $in++;
        }
    }
    imagedestroy($im);
    $total = $in + $out;
    return $total ? ($out / $total) : 0.0;
}

/**
 * Validate one file for one slot.
 *
 * $ctx may carry 'background' (hex the asset will sit on) and 'compare_to' (another
 * asset path, for the light/dark identical check).
 *
 * Returns ['ok' => bool, 'violations' => [['level','code','message'], ...]].
 * ok is false only when at least one BLOCK is present.
 */
function branding_validate_asset($slot, $path, array $ctx = []) {
    $specs = branding_slot_specs();
    $v = [];
    $add = function ($level, $code, $msg) use (&$v) { $v[] = ['level' => $level, 'code' => $code, 'message' => $msg]; };

    if (!isset($specs[$slot])) {
        $add(BRANDING_BLOCK, 'unknown_slot', "Unknown branding slot '$slot'.");
        return ['ok' => false, 'violations' => $v];
    }
    $spec = $specs[$slot];
    $label = $spec['label'];

    $info = branding_image_info($path);
    if (!$info) {
        $add(BRANDING_BLOCK, 'not_an_image', "$label: the file is not a readable image.");
        return ['ok' => false, 'violations' => $v];
    }

    // ── Format ───────────────────────────────────────────────────────────────
    if (!empty($spec['formats']) && !in_array($info['ext'], $spec['formats'], true)) {
        $add(BRANDING_BLOCK, 'bad_format',
            "$label: {$info['ext']} is not accepted here (" . implode(', ', $spec['formats']) . ").");
    }

    // ── Vector text: a font dependency, fatal only where WE rasterise ────────
    // SVG is a first-class format for web/PWA and must not be discouraged: a browser
    // renders it at full quality at any size. The failure is narrower than "SVG bad" —
    // it is text drawn through a font that is not embedded in the file, because the
    // glyphs then depend on whatever the renderer happens to have installed.
    //
    // Where WE rasterise the file server-side (icon_master and anything derived from
    // it) that is fatal and provable: this codebase already shipped a monogram that
    // came out as a fallback serif because the build box had no such font, and nothing
    // downstream could tell. Elsewhere the browser usually does something reasonable,
    // so it is worth saying but not worth refusing.
    if ($info['ext'] === 'svg' && branding_svg_has_text($path) && !branding_svg_embeds_font($path)) {
        if (!empty($spec['rasterized'])) {
            $add(BRANDING_BLOCK, 'svg_text_rasterized',
                "$label: this SVG draws text through a font that is not embedded in the file, and "
              . "this slot is rasterised on the server — where that font does not exist. The mark "
              . "would silently come out in a substitute typeface. Convert the text to paths, embed "
              . "the font, or upload a PNG for this slot.");
        } else {
            $add(BRANDING_WARN, 'svg_text',
                "$label: this SVG draws text through a font that is not embedded, so it renders in "
              . "whatever typeface the viewer happens to have. Converting the text to paths makes it "
              . "identical everywhere.");
        }
    }

    // ── Dimensions ───────────────────────────────────────────────────────────
    if ($info['w'] !== null) {
        if (!empty($spec['sizes'])) {
            $match = false;
            foreach ($spec['sizes'] as $s) if ($info['w'] == $s[0] && $info['h'] == $s[1]) { $match = true; break; }
            if (!$match) {
                $want = implode(' or ', array_map(function ($s) { return "{$s[0]}×{$s[1]}"; }, $spec['sizes']));
                $add(BRANDING_BLOCK, 'bad_size', "$label: must be $want — this is {$info['w']}×{$info['h']}.");
            }
        }
        foreach ([['min_w','w','at least','<'], ['min_h','h','at least','<']] as [$k, $dim, $word, $op]) {
            if (!empty($spec[$k]) && $info[$dim] < $spec[$k]) {
                $add(BRANDING_BLOCK, 'too_small', "$label: needs to be $word {$spec[$k]}px — this is {$info[$dim]}px.");
            }
        }
        foreach ([['max_w','w'], ['max_h','h']] as [$k, $dim]) {
            if (!empty($spec[$k]) && $info[$dim] > $spec[$k]) {
                $add(BRANDING_BLOCK, 'too_large', "$label: must be at most {$spec[$k]}px — this is {$info[$dim]}px.");
            }
        }
        if (!empty($spec['square']) && $info['w'] !== $info['h']) {
            $add(BRANDING_BLOCK, 'not_square', "$label: must be square — this is {$info['w']}×{$info['h']}.");
        }
    }

    // ── Alpha ────────────────────────────────────────────────────────────────
    if (array_key_exists('alpha', $spec) && $spec['alpha'] === false && $info['alpha']) {
        $add(BRANDING_BLOCK, 'has_alpha',
            "$label: must not have a transparency channel — it is a hard store rejection. "
          . "Flatten it onto a solid background first.");
    }

    // ── Blank ────────────────────────────────────────────────────────────────
    if ($info['ext'] !== 'svg' && branding_is_flat($path)) {
        $add(BRANDING_BLOCK, 'flat_colour',
            "$label: every sampled pixel is the same colour — the image is blank. This is "
          . "usually a failed export or a rasteriser that dropped the artwork.");
    }

    // ── Invisible against the surface it will actually sit on ────────────────
    if (!empty($ctx['background']) && $info['ext'] !== 'svg') {
        $n = branding_distinct_on($path, $ctx['background']);
        if ($n !== null && $n <= 1) {
            $add(BRANDING_BLOCK, 'invisible_on_background',
                "$label: invisible against {$ctx['background']} — composited onto it, the whole "
              . "image is one colour. A light mark on a light background disappears.");
        } elseif ($n !== null && $n <= 3) {
            $add(BRANDING_WARN, 'low_contrast_on_background',
                "$label: very low contrast against {$ctx['background']}.");
        }
    }

    // ── Maskable safe zone ───────────────────────────────────────────────────
    if (!empty($spec['maskable']) && $info['ext'] !== 'svg') {
        $out = branding_outside_safe_zone($path);
        if ($out !== null && $out > 0.02) {
            $add(BRANDING_WARN, 'outside_safe_zone',
                "$label: " . round($out * 100) . "% of the artwork sits outside the maskable safe "
              . "zone and will be cropped by Android launchers.");
        }
    }

    // ── Light/dark that are the same file ────────────────────────────────────
    if (!empty($ctx['compare_to']) && is_readable($ctx['compare_to'])
        && filesize($ctx['compare_to']) === $info['bytes']
        && md5_file($ctx['compare_to']) === md5_file($path)) {
        $add(BRANDING_WARN, 'light_dark_identical',
            "$label: identical to its counterpart variant, so one of the two is wrong.");
    }

    $ok = true;
    foreach ($v as $x) if ($x['level'] === BRANDING_BLOCK) { $ok = false; break; }
    return ['ok' => $ok, 'violations' => $v];
}
