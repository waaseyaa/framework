<?php
declare(strict_types=1);
// Tokenizer census: named declarations only; no repository code is executed.
$root = $argv[1];
$files = json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
$result = [];
foreach ($files as $file) {
    $tokens = token_get_all(file_get_contents($root . '/' . $file));
    $namespace = '';
    for ($i = 0, $count = count($tokens); $i < $count; $i++) {
        $token = $tokens[$i];
        if (!is_array($token)) continue;
        if ($token[0] === T_NAMESPACE) {
            $namespace = '';
            for ($j = $i + 1; $j < $count; $j++) {
                if ($tokens[$j] === ';' || $tokens[$j] === '{') break;
                if (is_array($tokens[$j]) && in_array($tokens[$j][0], [T_STRING, T_NAME_QUALIFIED, T_NS_SEPARATOR], true)) $namespace .= $tokens[$j][1];
            }
        }
        if (!in_array($token[0], [T_CLASS, T_INTERFACE, T_TRAIT, T_ENUM], true)) continue;
        $j = $i + 1;
        while ($j < $count && is_array($tokens[$j]) && in_array($tokens[$j][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) $j++;
        if ($j >= $count || !is_array($tokens[$j]) || $tokens[$j][0] !== T_STRING) continue;
        $result[] = ['symbol' => ($namespace === '' ? '' : $namespace . '\\') . $tokens[$j][1], 'kind' => token_name($token[0]), 'path' => $file, 'line' => $token[2]];
    }
}
echo json_encode($result, JSON_THROW_ON_ERROR);
