<?php
/**
 * Sign Docs contract test runner.
 *
 * Run: php tests/run.php
 *
 * @package SignDocs
 */

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

/**
 * @param mixed $actual
 * @param mixed $expected
 */
function assert_same($actual, $expected, string $message): void
{
    if ($actual !== $expected) {
        throw new RuntimeException(
            $message . "\nExpected: " . var_export($expected, true) . "\nActual: " . var_export($actual, true)
        );
    }
}

function assert_true(bool $condition, string $message): void
{
    if (! $condition) {
        throw new RuntimeException($message);
    }
}

function assert_error_code($actual, string $code, string $message): void
{
    assert_true($actual instanceof WP_Error, $message . ' Expected WP_Error.');
    assert_same($actual->get_error_code(), $code, $message);
}

function make_pdf(string $name, string $body = 'Sign Docs Test PDF'): string
{
    $dir = $GLOBALS['sign_docs_test_upload_base'] . '/fixtures';
    wp_mkdir_p($dir);
    $path = $dir . '/' . $name;
    file_put_contents($path, "%PDF-1.4\n1 0 obj\n<<>>\nendobj\ntrailer\n<<>>\n%%EOF\n" . $body);

    return $path;
}

/**
 * @param callable():void $test
 */
function run_test(string $name, callable $test): void
{
    sign_docs_tests_reset();

    try {
        $test();
        echo "PASS {$name}\n";
    } catch (Throwable $throwable) {
        echo "FAIL {$name}\n";
        echo $throwable->getMessage() . "\n";
        exit(1);
    } finally {
        sign_docs_tests_rrmdir((string) ($GLOBALS['sign_docs_test_upload_base'] ?? ''));
    }
}

run_test(
    'prepare stores immutable original, server hash and private pending state',
    static function (): void {
        $source = make_pdf('original.pdf', 'original-a');
        $post_id = Sign_Docs_Document_Service::prepare_from_local_pdf(
            $source,
            array(
                'post_title' => 'Short title',
                'full_title' => 'Full title',
                'signed_at' => '2026-05-15 10:20:30',
                'source_filename' => 'uploaded.pdf',
                'signer_name' => 'Signer',
                'signer_position' => 'Director',
                'signer_organization' => 'Organization',
            )
        );

        assert_true(is_int($post_id), 'Prepare should return a post id.');
        assert_same(get_post_status($post_id), 'private', 'Pending public copy must not be published.');
        assert_same(Sign_Docs_Meta::get($post_id, 'document_status'), 'needs_public_copy', 'Document status should wait for public copy.');
        assert_same(Sign_Docs_Meta::get($post_id, 'full_title'), 'Full title', 'Full title should be preserved separately.');

        $original_path = Sign_Docs_Meta::get($post_id, 'original_file_path');
        assert_true(is_file($original_path), 'Original control copy should exist.');
        assert_true(str_contains($original_path, '/uploads/sign-docs/2026/05/' . (string) $post_id . '/original.pdf'), 'Original path should use the contract directory structure.');
        assert_same(Sign_Docs_Meta::get($post_id, 'sha256_hash'), hash_file('sha256', $original_path), 'Stored SHA-256 should be calculated from the saved original.');
        assert_same(Sign_Docs_Meta::get($post_id, 'stamped_file_path'), '', 'Stamped copy path should be empty until complete.');
        assert_same(Sign_Docs_Meta::get($post_id, 'verification_url'), 'https://example.test/sign-docs/' . (string) $post_id . '/', 'Verification URL should be stored for QR data.');
    }
);

run_test(
    'complete stores stamped pdf, audit fields and publishes document',
    static function (): void {
        $post_id = Sign_Docs_Document_Service::prepare_from_local_pdf(
            make_pdf('original.pdf', 'original-b'),
            array(
                'post_title' => 'Document',
                'signed_at' => '2026-05-16 10:20:30',
            )
        );
        assert_true(is_int($post_id), 'Prepare should succeed.');

        $result = Sign_Docs_Document_Service::complete_with_stamped_pdf($post_id, make_pdf('stamped.pdf', 'stamped-b'));

        assert_same($result, true, 'Complete should succeed.');
        assert_same(get_post_status($post_id), 'publish', 'Completed document should be published.');
        assert_same(Sign_Docs_Meta::get($post_id, 'document_status'), 'active', 'Completed document should be active.');
        assert_true(is_file(Sign_Docs_Meta::get($post_id, 'stamped_file_path')), 'Stamped public copy should be saved.');
        assert_same(Sign_Docs_Meta::get($post_id, 'stamped_file_hash'), hash_file('sha256', Sign_Docs_Meta::get($post_id, 'stamped_file_path')), 'Stamped hash should be stored.');
        assert_same(Sign_Docs_Meta::get($post_id, 'completed_by_user_id'), '7', 'Completing user id should be audited.');
        assert_same(Sign_Docs_Meta::get($post_id, 'completed_ip'), '127.0.0.1', 'Completing IP should be audited.');
    }
);

run_test(
    'complete rejects changed original hash',
    static function (): void {
        $post_id = Sign_Docs_Document_Service::prepare_from_local_pdf(
            make_pdf('original.pdf', 'original-c'),
            array('post_title' => 'Document')
        );
        assert_true(is_int($post_id), 'Prepare should succeed.');

        file_put_contents(Sign_Docs_Meta::get($post_id, 'original_file_path'), "%PDF-1.4\nchanged\n%%EOF");

        $result = Sign_Docs_Document_Service::complete_with_stamped_pdf($post_id, make_pdf('stamped.pdf', 'stamped-c'));

        assert_error_code($result, 'sign_docs_original_hash_mismatch', 'Complete should reject a tampered original.');
        assert_same(get_post_status($post_id), 'private', 'Rejected document should remain private.');
        assert_same(Sign_Docs_Meta::get($post_id, 'document_status'), 'needs_public_copy', 'Rejected document should keep pending status.');
    }
);

run_test(
    'complete rejects documents outside pending state',
    static function (): void {
        $post_id = Sign_Docs_Document_Service::prepare_from_local_pdf(
            make_pdf('original.pdf', 'original-d'),
            array('post_title' => 'Document')
        );
        assert_true(is_int($post_id), 'Prepare should succeed.');
        update_post_meta($post_id, 'document_status', 'active');

        $result = Sign_Docs_Document_Service::complete_with_stamped_pdf($post_id, make_pdf('stamped.pdf', 'stamped-d'));

        assert_error_code($result, 'sign_docs_invalid_document_state', 'Complete should require needs_public_copy state.');
    }
);

run_test(
    'replacement applies only after new stamped copy is completed',
    static function (): void {
        $first_id = Sign_Docs_Document_Service::prepare_from_local_pdf(
            make_pdf('first.pdf', 'first'),
            array(
                'post_title' => 'First',
                'signed_at' => '2026-05-10 10:00:00',
            )
        );
        assert_true(is_int($first_id), 'First prepare should succeed.');
        assert_same(Sign_Docs_Document_Service::complete_with_stamped_pdf($first_id, make_pdf('first-stamped.pdf', 'first-stamped')), true, 'First complete should succeed.');

        $second_id = Sign_Docs_Document_Service::prepare_from_local_pdf(
            make_pdf('second.pdf', 'second'),
            array(
                'post_title' => 'Second',
                'signed_at' => '2026-05-11 10:00:00',
                'replaces_post_id' => $first_id,
            )
        );
        assert_true(is_int($second_id), 'Second prepare should succeed.');
        assert_same(Sign_Docs_Meta::get($first_id, 'document_status'), 'active', 'Previous document should stay active while replacement is pending.');
        assert_same(Sign_Docs_Meta::get($second_id, 'document_version'), '2', 'Replacement should get the next version.');

        assert_same(Sign_Docs_Document_Service::complete_with_stamped_pdf($second_id, make_pdf('second-stamped.pdf', 'second-stamped')), true, 'Second complete should succeed.');
        assert_same(Sign_Docs_Meta::get($first_id, 'document_status'), 'replaced', 'Previous document should be marked as replaced after successful completion.');
        assert_same(Sign_Docs_Meta::get($first_id, 'replaced_by_post_id'), (string) $second_id, 'Previous document should point to replacement.');
    }
);

run_test(
    'admin delete filters archive sign-docs records but allow service rollback',
    static function (): void {
        $post_id = wp_insert_post(
            array(
                'post_type' => Sign_Docs_Post_Type::POST_TYPE,
                'post_status' => 'publish',
                'post_title' => 'Document',
            ),
            true
        );
        $post = $GLOBALS['sign_docs_test_posts'][$post_id];

        $result = Sign_Docs_Admin::archive_instead_of_trash(null, $post);
        assert_same($result, false, 'Trash should be blocked for sign-docs.');
        assert_same(Sign_Docs_Meta::get($post_id, 'document_status'), 'archived', 'Trash should archive sign-docs document.');

        update_post_meta($post_id, 'document_status', 'active');
        $property = new ReflectionProperty(Sign_Docs_Document_Service::class, 'rollback_delete_in_progress');
        $property->setValue(null, true);
        $rollback_result = Sign_Docs_Admin::archive_instead_of_delete('continue', $post, true);
        $property->setValue(null, false);

        assert_same($rollback_result, 'continue', 'Rollback delete should pass through admin delete filter.');
        assert_same(Sign_Docs_Meta::get($post_id, 'document_status'), 'active', 'Rollback delete should not archive the document.');
    }
);

run_test(
    'REST permissions require upload capability and edit access on complete',
    static function (): void {
        $GLOBALS['sign_docs_test_current_caps'] = array();
        assert_same(Sign_Docs_REST_Controller::can_upload(), false, 'Upload endpoints should reject users without the dedicated capability.');

        $GLOBALS['sign_docs_test_current_caps'] = array(Sign_Docs_Settings::UPLOAD_CAPABILITY => true);
        assert_same(Sign_Docs_REST_Controller::can_upload(), true, 'Upload endpoints should accept users with the dedicated capability.');

        $post_id = wp_insert_post(
            array(
                'post_type' => Sign_Docs_Post_Type::POST_TYPE,
                'post_status' => 'private',
                'post_title' => 'Document',
            ),
            true
        );
        $response = Sign_Docs_REST_Controller::complete(new WP_REST_Request(array('post_id' => $post_id)));
        assert_error_code($response, 'sign_docs_invalid_post', 'Complete callback should require edit_post for the target document.');
        assert_same($response->get_error_data(), array('status' => 403), 'Complete edit_post failure should return 403.');
    }
);

run_test(
    'REST meta schema exposes only editor fields',
    static function (): void {
        $GLOBALS['sign_docs_test_current_caps'] = array('edit_post' => true);
        Sign_Docs_Meta::register();

        foreach (array('full_title', 'document_status', 'document_version', 'source_filename') as $key) {
            assert_same($GLOBALS['sign_docs_test_registered_meta'][$key]['args']['show_in_rest'], true, "{$key} should be exposed in REST.");
        }

        foreach (array('original_file_path', 'original_file_url', 'stamped_file_path', 'stamped_file_url', 'sha256_hash', 'qr_code_data', 'signer_user_id', 'completed_ip', 'completed_user_agent', 'completed_by_user_id') as $key) {
            assert_same($GLOBALS['sign_docs_test_registered_meta'][$key]['args']['show_in_rest'], false, "{$key} should stay internal.");
        }

        $auth = $GLOBALS['sign_docs_test_registered_meta']['full_title']['args']['auth_callback'];
        assert_same($auth(true, 'full_title', 0), false, 'Meta auth should reject missing post id.');
        assert_same($auth(true, 'full_title', 10), true, 'Meta auth should allow edit_post users.');
    }
);

echo "All Sign Docs contract tests passed.\n";
