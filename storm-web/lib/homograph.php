<?php
/**
 * lib/homograph.php — IDN homograph / lookalike domain generator.
 *
 * 9 categories of domain confusion:
 *   1. Mono-script Cyrillic      (a→а, c→с, e→е, o→о, p→р, ...)
 *   2. Mono-script Armenian       (o→օ, h→հ, n→ո, u→ս)
 *   3. Mono-script Greek          (o→ο)
 *   4. Mixed-script               (partial Cyrillic/Armenian/Greek substitution)
 *   5. Latin diacritic            (a→ā, c→ċ, e→ē, ...)
 *   6. Digit swap                 (o→0, l→1, s→5, ...)
 *   7. Bigram swap                (m→rn, w→vv, d→cl, u→ii)
 *   8. Edit distance              (prepend/append/swap/delete/double)
 *   9. TLD swap                   (.com→.co, .net→.org, ...)
 */

define('HOMO_CYRILLIC', [
    'a' => "\u{0430}", 'c' => "\u{0441}", 'e' => "\u{0435}",
    'o' => "\u{043E}", 'p' => "\u{0440}", 'x' => "\u{0445}",
    'y' => "\u{0443}", 'i' => "\u{0456}", 'j' => "\u{0458}",
    's' => "\u{0455}", 'h' => "\u{04BB}", 'k' => "\u{043A}",
]);

define('HOMO_ARMENIAN', [
    'o' => "\u{0585}", 'h' => "\u{0570}",
    'n' => "\u{0578}", 'u' => "\u{057D}",
]);

define('HOMO_GREEK', ['o' => "\u{03BF}"]);

define('HOMO_DIACRITIC', [
    'a' => "\u{0101}", 'b' => "\u{1E03}", 'c' => "\u{010B}", 'd' => "\u{1E0B}",
    'e' => "\u{0113}", 'f' => "\u{1E1F}", 'g' => "\u{0121}", 'h' => "\u{1E23}",
    'i' => "\u{012B}", 'j' => "\u{0135}", 'k' => "\u{1E33}", 'l' => "\u{013A}",
    'm' => "\u{1E41}", 'n' => "\u{0144}", 'o' => "\u{014D}", 'p' => "\u{1E57}",
    'q' => "q\u{0323}", 'r' => "\u{0155}", 's' => "\u{015B}", 't' => "\u{1E6B}",
    'u' => "\u{016B}", 'v' => "\u{1E7D}", 'w' => "\u{1E87}", 'x' => "\u{1E8B}",
    'y' => "\u{1E8F}", 'z' => "\u{017A}",
]);

define('HOMO_DIGIT_SWAPS', [
    'o' => '0', 'l' => '1', 'i' => '1', 's' => '5', 'b' => '8', 'g' => '9', 'z' => '2',
]);

define('HOMO_BIGRAMS', [
    'm'  => 'rn', 'rn' => 'm',
    'w'  => 'vv', 'vv' => 'w',
    'd'  => 'cl', 'cl' => 'd',
    'u'  => 'ii', 'ii' => 'u',
]);

define('HOMO_TLD_SWAPS', [
    'com' => ['co', 'cm', 'om', 'net', 'org', 'info', 'biz'],
    'net' => ['com', 'org', 'co', 'bet', 'vet'],
    'org' => ['com', 'net', 'co'],
    'io'  => ['co', 'com', 'oi'],
    'ir'  => ['com', 'net', 'org', 'co', 'info', 'co.ir', 'ac.ir'],
    'dev' => ['com', 'co'],
    'app' => ['com', 'co'],
]);

/**
 * Generate homograph/lookalike domain suggestions.
 *
 * @param string $domain  e.g. "example.com"
 * @return array Array of suggestions with {display, punycode, category, script, registrable, why, weakness}
 */
function homograph_generate(string $domain): array {
    $domain = strtolower(trim($domain));
    $parts  = explode('.', $domain);
    if (count($parts) < 2) return [];

    $tld  = array_pop($parts);
    $name = implode('.', $parts);

    $suggestions = [];
    $seen = [];

    // 1-3. Full mono-script substitution
    foreach (['cyrillic' => HOMO_CYRILLIC, 'armenian' => HOMO_ARMENIAN, 'greek' => HOMO_GREEK] as $script => $map) {
        $replaced = homograph_replace_all($name, $map);
        if ($replaced !== $name) {
            $full = $replaced . '.' . $tld;
            if (!isset($seen[$full])) {
                $seen[$full] = true;
                $suggestions[] = homograph_entry($full, "mono-{$script}", $script, "Full {$script} substitution");
            }
        }
    }

    // 4. Mixed-script (replace one char at a time)
    $chars = mb_str_split($name);
    $all_maps = array_merge(HOMO_CYRILLIC, HOMO_ARMENIAN, HOMO_GREEK);
    foreach ($chars as $i => $ch) {
        if (isset($all_maps[$ch])) {
            $new = $chars;
            $new[$i] = $all_maps[$ch];
            $full = implode('', $new) . '.' . $tld;
            if (!isset($seen[$full])) {
                $seen[$full] = true;
                $suggestions[] = homograph_entry($full, 'mixed-script', 'mixed', "Position {$i}: {$ch} → {$all_maps[$ch]}");
            }
        }
    }

    // 5. Latin diacritic
    foreach ($chars as $i => $ch) {
        if (isset(HOMO_DIACRITIC[$ch])) {
            $new = $chars;
            $new[$i] = HOMO_DIACRITIC[$ch];
            $full = implode('', $new) . '.' . $tld;
            if (!isset($seen[$full])) {
                $seen[$full] = true;
                $suggestions[] = homograph_entry($full, 'diacritic', 'latin-ext', "Position {$i}: {$ch} → " . HOMO_DIACRITIC[$ch]);
            }
        }
    }

    // 6. Digit swap
    foreach ($chars as $i => $ch) {
        if (isset(HOMO_DIGIT_SWAPS[$ch])) {
            $new = $chars;
            $new[$i] = HOMO_DIGIT_SWAPS[$ch];
            $full = implode('', $new) . '.' . $tld;
            if (!isset($seen[$full])) {
                $seen[$full] = true;
                $suggestions[] = homograph_entry($full, 'digit-swap', 'ascii', "Position {$i}: {$ch} → " . HOMO_DIGIT_SWAPS[$ch]);
            }
        }
    }

    // 7. Bigram swap
    foreach (HOMO_BIGRAMS as $from => $to) {
        $pos = 0;
        while (($pos = strpos($name, $from, $pos)) !== false) {
            $replaced = substr($name, 0, $pos) . $to . substr($name, $pos + strlen($from));
            $full = $replaced . '.' . $tld;
            if (!isset($seen[$full])) {
                $seen[$full] = true;
                $suggestions[] = homograph_entry($full, 'bigram', 'ascii', "{$from} → {$to} at position {$pos}");
            }
            $pos++;
        }
    }

    // 8. Edit distance (typosquatting)
    // 8a. Character doubling
    foreach ($chars as $i => $ch) {
        $new = $chars;
        array_splice($new, $i, 0, [$ch]);
        $full = implode('', $new) . '.' . $tld;
        if (!isset($seen[$full]) && $full !== $domain) {
            $seen[$full] = true;
            $suggestions[] = homograph_entry($full, 'typo-double', 'ascii', "Double '{$ch}' at {$i}");
        }
    }
    // 8b. Character deletion
    if (count($chars) > 2) {
        foreach ($chars as $i => $ch) {
            $new = $chars;
            array_splice($new, $i, 1);
            $full = implode('', $new) . '.' . $tld;
            if (!isset($seen[$full]) && $full !== $domain) {
                $seen[$full] = true;
                $suggestions[] = homograph_entry($full, 'typo-delete', 'ascii', "Delete '{$ch}' at {$i}");
            }
        }
    }
    // 8c. Adjacent swap
    for ($i = 0; $i < count($chars) - 1; $i++) {
        if ($chars[$i] !== $chars[$i + 1]) {
            $new = $chars;
            [$new[$i], $new[$i + 1]] = [$new[$i + 1], $new[$i]];
            $full = implode('', $new) . '.' . $tld;
            if (!isset($seen[$full])) {
                $seen[$full] = true;
                $suggestions[] = homograph_entry($full, 'typo-swap', 'ascii', "Swap {$chars[$i]}{$chars[$i+1]} at {$i}");
            }
        }
    }

    // 8d. Hyphen insertion
    for ($i = 1; $i < count($chars); $i++) {
        $new = $chars;
        array_splice($new, $i, 0, ['-']);
        $full = implode('', $new) . '.' . $tld;
        if (!isset($seen[$full])) {
            $seen[$full] = true;
            $suggestions[] = homograph_entry($full, 'hyphen', 'ascii', "Hyphen after position {$i}");
        }
    }

    // 8e. Prefix/suffix
    foreach (['login-', 'secure-', 'my-', 'auth-'] as $prefix) {
        $full = $prefix . $name . '.' . $tld;
        if (!isset($seen[$full])) {
            $seen[$full] = true;
            $suggestions[] = homograph_entry($full, 'prefix', 'ascii', "Prefix: {$prefix}");
        }
    }
    foreach (['-login', '-secure', '-verify', '-auth'] as $suffix) {
        $full = $name . $suffix . '.' . $tld;
        if (!isset($seen[$full])) {
            $seen[$full] = true;
            $suggestions[] = homograph_entry($full, 'suffix', 'ascii', "Suffix: {$suffix}");
        }
    }

    // 9. TLD swap
    if (isset(HOMO_TLD_SWAPS[$tld])) {
        foreach (HOMO_TLD_SWAPS[$tld] as $alt_tld) {
            $full = $name . '.' . $alt_tld;
            if (!isset($seen[$full])) {
                $seen[$full] = true;
                $suggestions[] = homograph_entry($full, 'tld-swap', 'ascii', ".{$tld} → .{$alt_tld}");
            }
        }
    }

    return $suggestions;
}

function homograph_replace_all(string $str, array $map): string {
    $chars = mb_str_split($str);
    foreach ($chars as &$ch) {
        if (isset($map[$ch])) $ch = $map[$ch];
    }
    return implode('', $chars);
}

function homograph_entry(string $display, string $category, string $script, string $why): array {
    $punycode = homograph_to_punycode($display);
    return [
        'display'     => $display,
        'punycode'    => $punycode,
        'category'    => $category,
        'script'      => $script,
        'registrable' => $punycode !== $display,
        'why'         => $why,
        'weakness'    => homograph_weakness($category),
    ];
}

function homograph_to_punycode(string $domain): string {
    $labels = explode('.', $domain);
    $result = [];
    foreach ($labels as $label) {
        if (preg_match('/[^\x20-\x7E]/', $label)) {
            $encoded = function_exists('idn_to_ascii')
                ? idn_to_ascii($label, IDNA_NONTRANSITIONAL_TO_ASCII, INTL_IDNA_VARIANT_UTS46)
                : $label;
            $result[] = $encoded ?: $label;
        } else {
            $result[] = $label;
        }
    }
    return implode('.', $result);
}

function homograph_weakness(string $category): string {
    $map = [
        'mono-cyrillic'  => 'Chrome shows punycode for mixed/Cyrillic-only domains since v58',
        'mono-armenian'  => 'Less browser protection than Cyrillic; many registrars block IDN',
        'mono-greek'     => 'Limited character coverage; some browsers warn',
        'mixed-script'   => 'Most modern browsers show punycode for mixed-script domains',
        'diacritic'      => 'May display normally in address bar; user must notice diacritics',
        'digit-swap'     => 'No browser warning; purely visual confusion',
        'bigram'         => 'No browser warning; relies on reading speed',
        'typo-double'    => 'No browser warning; common typing mistake',
        'typo-delete'    => 'No browser warning; common typing mistake',
        'typo-swap'      => 'No browser warning; common typing mistake',
        'hyphen'         => 'Looks intentional; low suspicion',
        'prefix'         => 'Common subdomain pattern; medium suspicion',
        'suffix'         => 'Common subdomain pattern; medium suspicion',
        'tld-swap'       => 'No warning; relies on user checking TLD',
    ];
    return $map[$category] ?? 'Unknown weakness profile';
}
