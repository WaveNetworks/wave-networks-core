<?php
/**
 * i18nFunctions.php — translation for the app, core and every sub-admin (task #2695).
 *
 * Source strings stay in English in the code:
 *     echo h(wn_t('people.count', '{count} people', ['count' => $n]));
 *
 * wn_t() returns plain text, so escape it like any other string. Catalogs are published by the
 * multi-lingual sub-admin as one flat JSON file per locale in multilingual/config/catalogs/
 * (`config/` survives a deploy). They are read straight from disk: no DB, no hook, no sub-admin
 * code in the request. With no catalog, wn_t() returns the English default and wn_locale() says
 * 'en', so an app without the sub-admin behaves exactly as it did.
 *
 * The name is wn_t, not t: a global t() in core would be a fatal redeclare for any app or test
 * that declares its own after core loads.
 */

if (!defined('WN_I18N_DEFAULT_LOCALE')) define('WN_I18N_DEFAULT_LOCALE', 'en');
if (!defined('WN_I18N_COOKIE')) define('WN_I18N_COOKIE', 'wn_locale');

if (!function_exists('wn_i18n_dir')) {
    /** Where published catalogs live. WN_I18N_DIR overrides it for tests. */
    function wn_i18n_dir() {
        return defined('WN_I18N_DIR') ? WN_I18N_DIR : dirname(__DIR__, 3) . '/multilingual/config/catalogs';
    }
}

if (!function_exists('wn_locale_normalize')) {
    /**
     * A BCP 47 tag in canonical case ('pt_br' → 'pt-BR'), or '' when it is not one. Catalog
     * file names come from this, so its output can never hold a path separator or a dot.
     */
    function wn_locale_normalize($tag) {
        $tag = str_replace('_', '-', trim((string) $tag, " \t\r\n"));
        if (!preg_match('/\A[A-Za-z]{2,3}(-[A-Za-z0-9]{2,8}){0,2}\z/', $tag)) return '';
        $parts = explode('-', $tag);
        $out = [strtolower(array_shift($parts))];
        foreach ($parts as $p) {
            if (strlen($p) === 4 && ctype_alpha($p)) $out[] = ucfirst(strtolower($p));
            elseif (strlen($p) === 2 && ctype_alpha($p)) $out[] = strtoupper($p);
            else $out[] = strtolower($p);
        }
        return implode('-', $out);
    }
}

if (!function_exists('wn_locale_fallbacks')) {
    /** 'zh-Hant-TW' → ['zh-Hant-TW', 'zh-Hant', 'zh']. The default locale needs no catalog. */
    function wn_locale_fallbacks($locale) {
        $locale = wn_locale_normalize($locale);
        if ($locale === '') return [];
        $parts = explode('-', $locale);
        $out = [];
        while ($parts) {
            $out[] = implode('-', $parts);
            array_pop($parts);
        }
        return $out;
    }
}

if (!function_exists('wn_i18n_locales')) {
    /** Published locales, the default first. Read once per request. */
    function wn_i18n_locales($reset = false) {
        static $list = null;
        if ($reset) $list = null;
        if ($list !== null) return $list;
        $list = [WN_I18N_DEFAULT_LOCALE];
        foreach (glob(wn_i18n_dir() . '/*.json') ?: [] as $f) {
            $tag = wn_locale_normalize(basename($f, '.json'));
            if ($tag !== '' && !in_array($tag, $list, true)) $list[] = $tag;
        }
        return $list;
    }
}

if (!function_exists('wn_locale_match')) {
    /** The published locale that serves $tag ('pt-BR' can be served by 'pt'), or ''. */
    function wn_locale_match($tag, array $available) {
        foreach (wn_locale_fallbacks($tag) as $candidate) {
            if (in_array($candidate, $available, true)) return $candidate;
        }
        return '';
    }
}

if (!function_exists('wn_locale_negotiate')) {
    /** Best published locale for an Accept-Language header, or ''. Ties keep the header's order. */
    function wn_locale_negotiate($header, array $available) {
        $ranked = [];
        foreach (explode(',', (string) $header) as $i => $item) {
            $bits = explode(';', trim($item));
            $q = 1.0;
            foreach (array_slice($bits, 1) as $param) {
                if (preg_match('/^\s*q=([0-9.]+)\s*$/i', $param, $m)) $q = (float) $m[1];
            }
            if ($q > 0 && trim($bits[0]) !== '') $ranked[] = [$q, $i, trim($bits[0])];
        }
        usort($ranked, fn($a, $b) => [$b[0], $a[1]] <=> [$a[0], $b[1]]);
        foreach ($ranked as [, , $tag]) {
            $hit = wn_locale_match($tag, $available);
            if ($hit !== '') return $hit;
        }
        return '';
    }
}

if (!function_exists('wn_set_locale')) {
    /** Use $locale for the rest of this request (a queued email, a cron run). '' clears it. */
    function wn_set_locale($locale) {
        $GLOBALS['__wn_locale'] = (string) $locale === '' ? null : wn_locale_normalize($locale);
    }
}

if (!function_exists('wn_locale')) {
    /** The locale for this request: wn_set_locale(), then the cookie, then the browser. */
    function wn_locale() {
        $available = wn_i18n_locales();
        foreach ([$GLOBALS['__wn_locale'] ?? '', $_COOKIE[WN_I18N_COOKIE] ?? ''] as $wanted) {
            $hit = wn_locale_match($wanted, $available);
            if ($hit !== '') return $hit;
        }
        $hit = wn_locale_negotiate($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? '', $available);
        return $hit !== '' ? $hit : WN_I18N_DEFAULT_LOCALE;
    }
}

if (!function_exists('wn_i18n_catalog')) {
    /** key => translation for one locale. [] when it is missing or unreadable. Cached per request. */
    function wn_i18n_catalog($locale, $reset = false) {
        static $cache = [];
        if ($reset) $cache = [];
        $locale = wn_locale_normalize($locale);
        if ($locale === '') return [];
        if (isset($cache[$locale])) return $cache[$locale];
        $file = wn_i18n_dir() . '/' . $locale . '.json';
        $data = is_file($file) ? json_decode((string) @file_get_contents($file), true) : null;
        if (is_file($file) && !is_array($data)) error_log("wn_i18n_catalog: unreadable catalog $locale");
        return $cache[$locale] = is_array($data) ? $data : [];
    }
}

if (!function_exists('wn_i18n_format')) {
    /**
     * Fill {name} params. A message with ICU syntax ({n, plural, ...}) goes through intl when the
     * host has it. Returns null when ICU syntax cannot be formatted, so wn_t() can use the default.
     */
    function wn_i18n_format($message, array $params, $locale) {
        $message = (string) $message;
        $isIcu = (bool) preg_match('/\{\s*\w+\s*,\s*(plural|select|selectordinal|number|date|time)\b/', $message);
        if ($isIcu) {
            if (!class_exists('MessageFormatter')) return null;
            try {
                $out = MessageFormatter::formatMessage($locale, $message, $params);
            } catch (Throwable $e) {
                $out = false;
            }
            return $out === false ? null : $out;
        }
        $swap = [];
        foreach ($params as $k => $v) {
            if (is_scalar($v) || $v === null) $swap['{' . $k . '}'] = (string) $v;
        }
        return strtr($message, $swap);
    }
}

if (!function_exists('wn_t')) {
    /**
     * The translation of $key for this request, falling back pt-BR → pt → $default. Never throws:
     * a bad translation shows the English default, never a broken page. $locale overrides the
     * request's locale (an email in the recipient's language).
     */
    function wn_t($key, $default, array $params = [], $locale = null) {
        $locale = $locale === null ? wn_locale() : (string) $locale;
        foreach (wn_locale_fallbacks($locale) as $candidate) {
            $catalog = wn_i18n_catalog($candidate);
            if (!isset($catalog[$key]) || !is_string($catalog[$key]) || $catalog[$key] === '') continue;
            $out = wn_i18n_format($catalog[$key], $params, $candidate);
            if ($out !== null) return $out;
            break;
        }
        $out = wn_i18n_format($default, $params, WN_I18N_DEFAULT_LOCALE);
        return $out !== null ? $out : (string) $default;
    }
}

if (!function_exists('wn_locale_dir')) {
    /** 'rtl' or 'ltr', for <html dir="">. */
    function wn_locale_dir($locale = null) {
        $base = explode('-', wn_locale_normalize($locale ?? wn_locale()))[0];
        return in_array($base, ['ar', 'he', 'fa', 'ur'], true) ? 'rtl' : 'ltr';
    }
}
