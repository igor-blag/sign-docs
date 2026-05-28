<?php
/**
 * AI-assisted document metadata suggestions.
 *
 * @package SignDocs
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

final class Sign_Docs_AI_Metadata
{
    private const ABILITY_NAME = 'sign-docs/suggest-document-metadata';
    private const ABILITY_CATEGORY = 'sign-docs';

    public static function register_ability_category(): void
    {
        if (! function_exists('wp_register_ability_category')) {
            return;
        }

        wp_register_ability_category(
            self::ABILITY_CATEGORY,
            array(
                'label' => __('Sign Docs', 'sign-docs'),
                'description' => __('Document signing and metadata helpers.', 'sign-docs'),
            )
        );
    }

    public static function register_abilities(): void
    {
        if (! function_exists('wp_register_ability')) {
            return;
        }

        wp_register_ability(
            self::ABILITY_NAME,
            array(
                'label' => __('Suggest signed document metadata', 'sign-docs'),
                'description' => __('Suggests document category, type, institution, date, number, subject, and titles from the first page text of an educational organization PDF.', 'sign-docs'),
                'category' => self::ABILITY_CATEGORY,
                'input_schema' => self::input_schema(),
                'output_schema' => self::output_schema(),
                'execute_callback' => array(self::class, 'suggest'),
                'permission_callback' => array(self::class, 'can_suggest'),
                'meta' => array(
                    'annotations' => array(
                        'readonly' => true,
                        'destructive' => false,
                        'idempotent' => false,
                    ),
                    'mcp' => array(
                        'type' => 'tool',
                    ),
                    'show_in_rest' => true,
                ),
            )
        );
    }

    /**
     * @param mixed $input
     */
    public static function can_suggest($input = null): bool
    {
        return Sign_Docs_Settings::current_user_can_upload_documents();
    }

    /**
     * @param mixed $input
     * @return array<string,mixed>|WP_Error
     */
    public static function suggest($input)
    {
        $input = is_array($input) ? $input : array();
        $first_page_text = isset($input['first_page_text']) ? sanitize_textarea_field((string) $input['first_page_text']) : '';
        $source_filename = isset($input['source_filename']) ? sanitize_file_name((string) $input['source_filename']) : '';

        $first_page_text = self::compact_text($first_page_text);
        if (mb_strlen($first_page_text) < 40) {
            return new WP_Error(
                'sign_docs_first_page_text_too_short',
                __('The first page text is too short for reliable AI suggestions.', 'sign-docs'),
                array('status' => 400)
            );
        }

        if (! function_exists('wp_ai_client_prompt')) {
            return new WP_Error(
                'sign_docs_ai_client_unavailable',
                __('The WordPress AI Client is not available.', 'sign-docs'),
                array('status' => 503)
            );
        }

        $prompt_builder = wp_ai_client_prompt(self::prompt($first_page_text, $source_filename))
            ->using_system_instruction(self::system_instruction());

        if (self::is_ai_provider_configured('openrouter')) {
            $prompt_builder = $prompt_builder->using_provider('openrouter');

            $openrouter_config = self::openrouter_model_config();
            if (null !== $openrouter_config) {
                $prompt_builder = $prompt_builder->using_model_config($openrouter_config);
            }
        }

        $prompt_builder = $prompt_builder
            ->using_model_preference(
                array('openrouter', 'google/gemini-2.5-flash-lite')
            )
            ->as_json_response(self::ai_response_schema());

        if (method_exists($prompt_builder, 'is_supported_for_text_generation') && ! $prompt_builder->is_supported_for_text_generation()) {
            return new WP_Error(
                'sign_docs_ai_text_generation_unavailable',
                __('No approved AI connector with text generation support is available.', 'sign-docs'),
                array('status' => 503)
            );
        }

        $response = $prompt_builder->generate_text();
        if (is_wp_error($response)) {
            return $response;
        }

        $decoded = json_decode((string) $response, true);
        if (! is_array($decoded)) {
            return new WP_Error(
                'sign_docs_ai_invalid_response',
                __('The AI response could not be parsed as JSON.', 'sign-docs'),
                array('status' => 502)
            );
        }

        return self::normalize_suggestion($decoded);
    }

    private static function openrouter_model_config(): ?object
    {
        $model_config_class = 'WordPress\\AiClient\\Providers\\Models\\DTO\\ModelConfig';
        if (! class_exists($model_config_class)) {
            return null;
        }

        $model_config = new $model_config_class();
        if (method_exists($model_config, 'setCustomOption')) {
            $model_config->setCustomOption('reasoning', array('exclude' => true));
        }

        return $model_config;
    }

    private static function is_ai_provider_configured(string $provider_id): bool
    {
        if (! class_exists('WordPress\\AiClient\\AiClient')) {
            return false;
        }

        try {
            $registry = call_user_func(array('WordPress\\AiClient\\AiClient', 'defaultRegistry'));
            return $registry->hasProvider($provider_id) && $registry->isProviderConfigured($provider_id);
        } catch (Throwable $e) {
            return false;
        }
    }

    /**
     * @return array<string,mixed>
     */
    public static function input_schema(): array
    {
        return array(
            'type' => 'object',
            'properties' => array(
                'first_page_text' => array(
                    'type' => 'string',
                    'description' => __('Text extracted from the first page of the PDF.', 'sign-docs'),
                ),
                'source_filename' => array(
                    'type' => 'string',
                    'description' => __('Original uploaded PDF file name.', 'sign-docs'),
                ),
            ),
            'required' => array('first_page_text'),
            'additionalProperties' => false,
        );
    }

    /**
     * @return array<string,mixed>
     */
    public static function output_schema(): array
    {
        return array(
            'type' => 'object',
            'properties' => array(
                'document_category' => array('type' => 'string'),
                'document_type_label' => array('type' => 'string'),
                'document_type_term_id' => array('type' => 'integer'),
                'document_institution' => array('type' => 'string'),
                'document_date' => array('type' => 'string'),
                'document_number' => array('type' => 'string'),
                'document_subject' => array('type' => 'string'),
                'post_title' => array('type' => 'string'),
                'full_title' => array('type' => 'string'),
                'confidence' => array('type' => 'number'),
                'warnings' => array(
                    'type' => 'array',
                    'items' => array('type' => 'string'),
                ),
            ),
        );
    }

    /**
     * @return array<string,mixed>
     */
    private static function ai_response_schema(): array
    {
        $schema = self::output_schema();
        $schema['additionalProperties'] = false;
        $schema['required'] = array(
            'document_category',
            'document_type_label',
            'document_type_term_id',
            'document_institution',
            'document_date',
            'document_number',
            'document_subject',
            'post_title',
            'full_title',
            'confidence',
            'warnings',
        );

        return $schema;
    }

    private static function system_instruction(): string
    {
        return implode(
            "\n",
            array(
                'You extract structured metadata for Russian educational organization documents.',
                'Use only the provided first-page text, filename, organization context, and available WordPress taxonomy terms.',
                'Return JSON only. Do not invent dates, numbers, organizations, or titles that are not supported by the text.',
                'Choose document_category by slug: local-act, external-regulation, or other-document.',
                'Choose document_type_term_id only from the available document type terms. If unsure, choose the closest "Иное" or empty value 0.',
                'For document_subject, return the title topic without surrounding Russian quotation marks.',
                'For document_date, use dd.mm.yyyy when the date is clear.',
                'For document_number, omit the leading № symbol.',
                'For document_institution, use genitive case if the text clearly contains an issuing authority for an external regulation. Leave empty for local acts unless the text explicitly requires it.',
                'Set confidence from 0 to 1 and add short Russian warnings for uncertain fields.',
            )
        );
    }

    private static function prompt(string $first_page_text, string $source_filename): string
    {
        $settings = Sign_Docs_Settings::get();
        $context = array(
            'source_filename' => $source_filename,
            'default_organization' => $settings['signer_organization'],
            'document_categories' => self::category_terms(),
            'document_types' => self::type_terms(),
            'known_institutions' => self::institution_terms(),
        );

        return '<context>' . wp_json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "</context>\n"
            . '<first-page-text>' . $first_page_text . '</first-page-text>';
    }

    /**
     * @param array<string,mixed> $decoded
     * @return array<string,mixed>
     */
    private static function normalize_suggestion(array $decoded): array
    {
        $category = sanitize_key((string) ($decoded['document_category'] ?? ''));
        if (! array_key_exists($category, self::category_terms())) {
            $category = '';
        }

        $type_term_id = absint($decoded['document_type_term_id'] ?? 0);
        $type_label = sanitize_text_field((string) ($decoded['document_type_label'] ?? ''));
        $known_types = self::type_terms();
        if ($type_term_id > 0 && isset($known_types[$type_term_id])) {
            $type_label = $known_types[$type_term_id]['name'];
            if ('' === $category) {
                $category = $known_types[$type_term_id]['category'];
            }
        } elseif ('' !== $type_label) {
            $matched_type = self::match_type_by_label($type_label);
            if (null !== $matched_type) {
                $type_term_id = $matched_type['term_id'];
                $type_label = $matched_type['name'];
                if ('' === $category) {
                    $category = $matched_type['category'];
                }
            }
        }

        $confidence = isset($decoded['confidence']) ? (float) $decoded['confidence'] : 0.5;
        $warnings = array();
        if (isset($decoded['warnings']) && is_array($decoded['warnings'])) {
            foreach ($decoded['warnings'] as $warning) {
                $warning = sanitize_text_field((string) $warning);
                if ('' !== $warning) {
                    $warnings[] = $warning;
                }
            }
        }

        return array(
            'document_category' => $category,
            'document_type_label' => $type_label,
            'document_type_term_id' => $type_term_id,
            'document_institution' => sanitize_text_field((string) ($decoded['document_institution'] ?? '')),
            'document_date' => sanitize_text_field((string) ($decoded['document_date'] ?? '')),
            'document_number' => ltrim(sanitize_text_field((string) ($decoded['document_number'] ?? '')), "№ \t\n\r\0\x0B"),
            'document_subject' => trim(sanitize_text_field((string) ($decoded['document_subject'] ?? '')), " \t\n\r\0\x0B\"'«»"),
            'post_title' => sanitize_text_field((string) ($decoded['post_title'] ?? '')),
            'full_title' => sanitize_textarea_field((string) ($decoded['full_title'] ?? '')),
            'confidence' => max(0, min(1, $confidence)),
            'warnings' => $warnings,
        );
    }

    /**
     * @return array<string,string>
     */
    private static function category_terms(): array
    {
        $terms = get_terms(array('taxonomy' => 'sign_doc_category', 'hide_empty' => false));
        $items = array();
        if (is_array($terms)) {
            foreach ($terms as $term) {
                if ($term instanceof WP_Term) {
                    $items[$term->slug] = $term->name;
                }
            }
        }

        return $items;
    }

    /**
     * @return array<int,array{term_id:int,name:string,slug:string,category:string,parent:int}>
     */
    private static function type_terms(): array
    {
        $terms = get_terms(array('taxonomy' => 'sign_doc_type', 'hide_empty' => false));
        $items = array();
        if (! is_array($terms)) {
            return $items;
        }

        foreach ($terms as $term) {
            if (! $term instanceof WP_Term || (int) $term->parent <= 0) {
                continue;
            }

            $items[(int) $term->term_id] = array(
                'term_id' => (int) $term->term_id,
                'name' => $term->name,
                'slug' => $term->slug,
                'category' => self::category_for_type_parent((int) $term->parent),
                'parent' => (int) $term->parent,
            );
        }

        return $items;
    }

    /**
     * @return array<int,string>
     */
    private static function institution_terms(): array
    {
        $terms = get_terms(array('taxonomy' => 'sign_doc_institution', 'hide_empty' => false));
        $items = array();
        if (is_array($terms)) {
            foreach ($terms as $term) {
                if ($term instanceof WP_Term) {
                    $items[(int) $term->term_id] = $term->name;
                }
            }
        }

        return $items;
    }

    /**
     * @return array{term_id:int,name:string,slug:string,category:string,parent:int}|null
     */
    private static function match_type_by_label(string $label): ?array
    {
        $needle = mb_strtolower(trim($label));
        foreach (self::type_terms() as $term) {
            if ($needle === mb_strtolower($term['name']) || $needle === mb_strtolower($term['slug'])) {
                return $term;
            }
        }

        return null;
    }

    private static function category_for_type_parent(int $parent_id): string
    {
        $parent = get_term($parent_id, 'sign_doc_type');
        if (! $parent instanceof WP_Term) {
            return 'other-document';
        }

        return array(
            'local-acts' => 'local-act',
            'external-regulations' => 'external-regulation',
            'other-documents' => 'other-document',
        )[$parent->slug] ?? 'other-document';
    }

    private static function compact_text(string $text): string
    {
        $text = str_replace(array("\r\n", "\r"), "\n", $text);
        $text = (string) preg_replace('/[ \t]+/', ' ', $text);
        $text = (string) preg_replace('/\n{3,}/', "\n\n", $text);

        return trim($text);
    }
}
