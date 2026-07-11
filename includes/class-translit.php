<?php
/**
 * Transliteration utilities for filenames.
 *
 * @package SignDocs
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

final class Sign_Docs_Translit
{
    public const MAX_FILENAME_LENGTH = 100;

    private const CYRILLIC_MAP = array(
        'а' => 'a',
        'б' => 'b',
        'в' => 'v',
        'г' => 'g',
        'д' => 'd',
        'е' => 'e',
        'ё' => 'e',
        'ж' => 'zh',
        'з' => 'z',
        'и' => 'i',
        'й' => 'y',
        'к' => 'k',
        'л' => 'l',
        'м' => 'm',
        'н' => 'n',
        'о' => 'o',
        'п' => 'p',
        'р' => 'r',
        'с' => 's',
        'т' => 't',
        'у' => 'u',
        'ф' => 'f',
        'х' => 'kh',
        'ц' => 'ts',
        'ч' => 'ch',
        'ш' => 'sh',
        'щ' => 'sch',
        'ъ' => '',
        'ы' => 'y',
        'ь' => '',
        'э' => 'e',
        'ю' => 'yu',
        'я' => 'ya',
    );

    public static function filename_from_title(string $title): string
    {
        $title = mb_strtolower(trim($title), 'UTF-8');

        $result = '';
        $length = mb_strlen($title, 'UTF-8');
        for ($i = 0; $i < $length; ++$i) {
            $char = mb_substr($title, $i, 1, 'UTF-8');
            if (isset(self::CYRILLIC_MAP[$char])) {
                $result .= self::CYRILLIC_MAP[$char];
            } elseif (preg_match('/[a-z0-9]/', $char)) {
                $result .= $char;
            } elseif (' ' === $char || '-' === $char || '_' === $char) {
                $result .= '-';
            }
        }

        $result = preg_replace('/-+/', '-', $result);
        $result = trim($result, '-');

        if ('' === $result) {
            return 'document';
        }

        if (strlen($result) > self::MAX_FILENAME_LENGTH) {
            $result = (string) substr($result, 0, self::MAX_FILENAME_LENGTH);
            $result = rtrim($result, '-');
        }

        return $result;
    }
}
