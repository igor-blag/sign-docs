<?php
/**
 * Minimal PDF certificate generator.
 *
 * @package SignDocs
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

final class Sign_Docs_Pdf_Certificate
{
    /**
     * @param array<string,string|int> $data
     */
    public static function generate(string $target_path, array $data): bool
    {
        $lines = self::certificate_lines($data);
        $content = "BT\n/F1 18 Tf\n72 770 Td\n" . self::pdf_text('SIGN DOCS VERIFICATION CERTIFICATE') . " Tj\n";
        $content .= "/F1 10 Tf\n0 -28 Td\n" . self::pdf_text('This public PDF records verification data for the signed document.') . " Tj\n";
        $content .= "0 -24 Td\n";

        foreach ($lines as $line) {
            foreach (self::wrap_line($line, 82) as $wrapped) {
                $content .= self::pdf_text($wrapped) . " Tj\n0 -15 Td\n";
            }

            $content .= "0 -5 Td\n";
        }

        $content .= "ET\n";

        $objects = array(
            "1 0 obj\n<< /Type /Catalog /Pages 2 0 R >>\nendobj\n",
            "2 0 obj\n<< /Type /Pages /Kids [3 0 R] /Count 1 >>\nendobj\n",
            "3 0 obj\n<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Resources << /Font << /F1 4 0 R >> >> /Contents 5 0 R >>\nendobj\n",
            "4 0 obj\n<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>\nendobj\n",
            "5 0 obj\n<< /Length " . strlen($content) . " >>\nstream\n" . $content . "endstream\nendobj\n",
        );

        $pdf = "%PDF-1.4\n";
        $offsets = array(0);

        foreach ($objects as $object) {
            $offsets[] = strlen($pdf);
            $pdf .= $object;
        }

        $xref_offset = strlen($pdf);
        $pdf .= "xref\n0 " . (count($objects) + 1) . "\n";
        $pdf .= "0000000000 65535 f \n";

        for ($i = 1; $i < count($offsets); $i++) {
            $pdf .= sprintf("%010d 00000 n \n", $offsets[$i]);
        }

        $pdf .= "trailer\n<< /Size " . (count($objects) + 1) . " /Root 1 0 R >>\n";
        $pdf .= "startxref\n" . $xref_offset . "\n%%EOF\n";

        return false !== file_put_contents($target_path, $pdf);
    }

    /**
     * @param array<string,string|int> $data
     * @return string[]
     */
    private static function certificate_lines(array $data): array
    {
        return array(
            'Document title: ' . self::plain((string) ($data['title'] ?? '')),
            'Signed at: ' . self::plain((string) ($data['signed_at'] ?? '')),
            'Signer: ' . self::plain((string) ($data['signer'] ?? '')),
            'Organization: ' . self::plain((string) ($data['organization'] ?? '')),
            'Status: ' . self::plain((string) ($data['status'] ?? 'active')),
            'Version: ' . self::plain((string) ($data['version'] ?? '1')),
            'Original SHA-256: ' . self::plain((string) ($data['sha256_hash'] ?? '')),
            'Verification URL: ' . self::plain((string) ($data['verification_url'] ?? '')),
            'Source filename: ' . self::plain((string) ($data['source_filename'] ?? '')),
        );
    }

    /**
     * @return string[]
     */
    private static function wrap_line(string $line, int $length): array
    {
        $wrapped = wordwrap($line, $length, "\n", true);

        return array_filter(explode("\n", $wrapped), static fn (string $item): bool => '' !== $item);
    }

    private static function pdf_text(string $text): string
    {
        $text = str_replace(array('\\', '(', ')'), array('\\\\', '\\(', '\\)'), $text);

        return '(' . $text . ')';
    }

    private static function plain(string $value): string
    {
        $value = html_entity_decode(wp_strip_all_tags($value), ENT_QUOTES, 'UTF-8');
        $value = self::transliterate($value);
        $value = preg_replace('/[^\x20-\x7E]/', '', $value);

        return trim((string) $value);
    }

    private static function transliterate(string $value): string
    {
        $map = array(
            'А' => 'A', 'Б' => 'B', 'В' => 'V', 'Г' => 'G', 'Д' => 'D', 'Е' => 'E', 'Ё' => 'E',
            'Ж' => 'Zh', 'З' => 'Z', 'И' => 'I', 'Й' => 'Y', 'К' => 'K', 'Л' => 'L', 'М' => 'M',
            'Н' => 'N', 'О' => 'O', 'П' => 'P', 'Р' => 'R', 'С' => 'S', 'Т' => 'T', 'У' => 'U',
            'Ф' => 'F', 'Х' => 'Kh', 'Ц' => 'Ts', 'Ч' => 'Ch', 'Ш' => 'Sh', 'Щ' => 'Sch',
            'Ъ' => '', 'Ы' => 'Y', 'Ь' => '', 'Э' => 'E', 'Ю' => 'Yu', 'Я' => 'Ya',
            'а' => 'a', 'б' => 'b', 'в' => 'v', 'г' => 'g', 'д' => 'd', 'е' => 'e', 'ё' => 'e',
            'ж' => 'zh', 'з' => 'z', 'и' => 'i', 'й' => 'y', 'к' => 'k', 'л' => 'l', 'м' => 'm',
            'н' => 'n', 'о' => 'o', 'п' => 'p', 'р' => 'r', 'с' => 's', 'т' => 't', 'у' => 'u',
            'ф' => 'f', 'х' => 'kh', 'ц' => 'ts', 'ч' => 'ch', 'ш' => 'sh', 'щ' => 'sch',
            'ъ' => '', 'ы' => 'y', 'ь' => '', 'э' => 'e', 'ю' => 'yu', 'я' => 'ya',
            '№' => 'N',
        );

        return strtr($value, $map);
    }
}
