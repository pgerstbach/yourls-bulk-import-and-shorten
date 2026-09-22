<?php

/*
Plugin Name:    Bulk Import and Shorten
Plugin URI:     https://github.com/vaughany/yourls-bulk-import-and-shorten
Description:    A YOURLS plugin allowing importing of URLs in bulk to be shortened or (optionally) with a custom short URL.
Version:        0.5
Release date:   2026-09-22
Author:         Paul Vaughan
Author URI:     http://github.com/vaughany/
*/

/**
 * https://github.com/YOURLS/YOURLS/wiki/Coding-Standards
 * https://github.com/YOURLS/YOURLS/wiki#for-developpers
 * https://github.com/YOURLS/YOURLS/wiki/Plugin-List#get-your-plugin-listed-here
*/

// No direct call.
if ( !defined ('YOURLS_ABSPATH') ) { die(); }

// Register admin page.
yourls_add_action( 'plugins_loaded', 'vaughany_bias_add_page' );

// Handle import.
yourls_add_action( 'load-vaughany_bias', 'vaughany_bias_handle_post' );


function vaughany_bias_add_page() {
    yourls_register_plugin_page( 'vaughany_bias', 'Bulk Import and Shorten', 'vaughany_bias_display_page' );
}

function vaughany_bias_display_page() {
    echo <<<EOT
<h2>Bulk Import and Shorten</h2>
<p>Import links as long URLs and let YOURLS shorten them for you according to your settings.</p>
<p>Upload a .csv file in the following format:</p>
<ul>
  <li>First column - required: a long URL</li>
  <li>Second column - optional: a short URL of your choosing (otherwise one will be created by YOURLS according to your settings)</li>
  <li>Third column - optional: a title of your choosing.</li>
</ul>
<p>I don't know what will happen if two short links point to the same long link - this might or might not be allowed, according to your settings.</p>

<h3>Import</h3>
EOT;

    echo '<form action="' . yourls_remove_query_arg( array( 'import', 'export', 'nonce', 'action' ) ) . '" method="post" accept-charset="utf-8" enctype="multipart/form-data">' . PHP_EOL;
    echo '  ' . yourls_nonce_field( 'vaughany_bias_import', 'nonce', false, false );
    echo '  <input type="file" name="import" value="">' . PHP_EOL;
    echo '  <input type="submit" name="import" value="Upload">' . PHP_EOL;
    echo '</form>' . PHP_EOL;
}

function vaughany_bias_handle_post() {

    // Warn before the user even picks a file if the server cannot accept
    // uploads at all: PHP silently drops them, so the import would just
    // reload the page without any error message. This hook runs before
    // YOURLS renders the page, so notices added here are actually shown.
    if ( !filter_var( ini_get( 'file_uploads' ), FILTER_VALIDATE_BOOLEAN ) ) {
        yourls_add_notice( 'File uploads are disabled on this server (file_uploads=Off in php.ini), so no file can be imported. Ask your administrator or hoster to enable file uploads.', 'error' );
        // The warning above already explains the problem: don't add a
        // confusing "no file received" notice on top after a POST.
        if ( ( $_SERVER['REQUEST_METHOD'] ?? '' ) === 'POST' ) {
            return;
        }
    }

    // Import was requested: report problems instead of silently reloading.
    if ( ( $_SERVER['REQUEST_METHOD'] ?? '' ) === 'POST' ) {

        if ( empty( $_FILES['import'] ) ) {
            // No file arrived. If $_POST is empty as well, PHP dropped the
            // whole request body because it exceeded post_max_size.
            if ( empty( $_POST ) && (int) ( $_SERVER['CONTENT_LENGTH'] ?? 0 ) > 0 ) {
                yourls_add_notice( 'No file received: the upload exceeded post_max_size (' . ini_get( 'post_max_size' ) . '). Increase it in your PHP settings or upload a smaller file.', 'error' );
            } else {
                yourls_add_notice( 'No file received. Please select a .csv file and try again.', 'error' );
            }
            return;
        }

        if ( empty( $_POST['nonce'] ) || !yourls_verify_nonce( 'vaughany_bias_import', $_POST['nonce'] ) ) {
            yourls_add_notice( 'Invalid or missing security token. Please try again.', 'error' );
            return;
        }

        $result = vaughany_bias_import_urls( $_FILES['import'] );

        // import_urls() already showed a specific error notice: don't add
        // a generic one on top.
        if ( $result['error'] ) {
            return;
        }

        if ( $result['imported'] > 0 || $result['skipped'] > 0 ) {
            $message = $result['imported'] . ' URL' . ( $result['imported'] === 1 ? '' : 's' ) . ' imported.';
            if ( $result['skipped'] > 0 ) {
                $message .= ' ' . $result['skipped'] . ' row' . ( $result['skipped'] === 1 ? '' : 's' ) . ' skipped.';
                if ( !empty( $result['skip_reason'] ) ) {
                    $message .= ' (' . $result['skip_reason'] . ')';
                }
            }
        } else {
            $message = 'No URLs imported.';
        }
        if ( $result['keywords_taken'] > 0 ) {
            $message .= ' ' . $result['keywords_taken'] . ' requested keyword' . ( $result['keywords_taken'] === 1 ? ' was' : 's were' ) . ' already taken, generated ones were used instead.';
        }
        yourls_add_notice( $message, $result['imported'] > 0 ? 'notice' : 'error' );
    }

}

/**
 * Import the urls
 * @param array $file Uploaded file to be imported
 * @return array 'imported' => count of imported URLs, 'skipped' => count of
 *               skipped rows, 'skip_reason' => most common reason rows failed,
 *               'keywords_taken' => count of taken keywords replaced by
 *               generated ones, 'error' => true if a specific error notice
 *               was already shown
 */
function vaughany_bias_import_urls( $file ) {

    $result = array( 'imported' => 0, 'skipped' => 0, 'skip_reason' => '', 'keywords_taken' => 0, 'error' => false );

    // Check the upload error code first: when an upload fails, tmp_name is
    // empty and is_uploaded_file() would only report a generic error.
    if ( isset( $file['error'] ) && $file['error'] !== UPLOAD_ERR_OK ) {
        yourls_add_notice( vaughany_bias_upload_error_message( $file['error'] ), 'error' );
        $result['error'] = true;
        return $result;
    }

    if ( !is_uploaded_file( $file['tmp_name'] ) ) {
        yourls_add_notice( 'Not an uploaded file.', 'error' );
        $result['error'] = true;
        return $result;
    }

    // Only handle .csv files: check the file extension rather than the
    // browser-provided MIME type, which varies (text/csv, text/plain,
    // application/vnd.ms-excel, application/octet-stream, ...).
    $extension = strtolower( pathinfo( $file['name'], PATHINFO_EXTENSION ) );
    if ( $extension !== 'csv' ) {
        yourls_add_notice( 'Not a .csv file.', 'error' );
        $result['error'] = true;
        return $result;
    }

    $count  = 0;
    $fh     = fopen( $file['tmp_name'], 'r' );
    $skip_reasons = array();
    $skip_messages = array();

    // If the file handle is okay.
    if ( $fh ) {

        // Get each line in turn as an array, comma-separated. A length of 0
        // means no limit, so long URLs are not truncated.
        while ( ( $csv = fgetcsv( $fh, 0, ',', '"', '' ) ) !== false ) {

            // Strip a UTF-8 byte order mark and skip blank lines.
            $url = trim( str_replace( "\xEF\xBB\xBF", '', (string) $csv[0] ) );
            if ( $url === '' ) {
                continue;
            }

            $keyword = $title = '';
            $requested_keyword = '';

            if ( !empty( $csv[1] ) ) {
                // Trim out cruft and slashes.
                $new_keyword = trim( str_replace( '/', '', $csv[1] ) );

                // If the requested keyword is not free, import the row with a
                // generated one instead of silently using a different short
                // URL than the CSV asked for.
                if ( yourls_keyword_is_free( $new_keyword ) ) {
                    $keyword = $new_keyword;
                } else {
                    $requested_keyword = $new_keyword;
                }
            }

            if ( !empty( $csv[2] ) ) {
                $title = trim( $csv[2] );
            } else {
                $title = vaughany_bias_create_title_from_url( $url );
            }

            // Add a new link (passing the keyword) and get the result.
            $add = yourls_add_new_link( $url, $keyword, $title );

            if ( $add['status'] == 'success' ) {
                $count++;
                if ( $requested_keyword !== '' ) {
                    $result['keywords_taken']++;
                }
            } else {
                $result['skipped']++;
                $reason = $add['code'] ?? 'error';
                $skip_reasons[ $reason ] = ( $skip_reasons[ $reason ] ?? 0 ) + 1;
                // Keep the first readable message for each code as a fallback
                // for codes without a known text below.
                if ( !isset( $skip_messages[ $reason ] ) && !empty( $add['message'] ) ) {
                    $skip_messages[ $reason ] = $add['message'];
                }
            }
        }

        fclose( $fh );

        if ( $result['skipped'] > 0 && !empty( $skip_reasons ) ) {
            arsort( $skip_reasons );
            $code = array_key_first( $skip_reasons );
            $result['skip_reason'] = vaughany_bias_skip_reason_text( $code, $skip_messages[ $code ] ?? '' );
        }

    } else {
        yourls_add_notice('File handle is bad.', 'error');
        $result['error'] = true;
    }

    $result['imported'] = $count;
    return $result;
}

/**
 * Human readable message for a PHP upload error code
 * @param int $error One of the UPLOAD_ERR_* constants
 * @return string
 */
function vaughany_bias_upload_error_message( $error ) {
    switch ( $error ) {
        case UPLOAD_ERR_INI_SIZE:
            return 'The file is larger than upload_max_filesize (' . ini_get( 'upload_max_filesize' ) . ').';
        case UPLOAD_ERR_FORM_SIZE:
            return 'The file is larger than the upload form allows.';
        case UPLOAD_ERR_NO_FILE:
            return 'Please select a file first.';
        case UPLOAD_ERR_PARTIAL:
            return 'The file was only partially uploaded, please try again.';
        case UPLOAD_ERR_NO_TMP_DIR:
            return 'Missing a temporary folder on the server, contact your administrator.';
        case UPLOAD_ERR_CANT_WRITE:
            return 'Failed to write the file to disk, contact your administrator.';
        case UPLOAD_ERR_EXTENSION:
            return 'A PHP extension stopped the file upload, contact your administrator.';
        default:
            return 'Upload failed with error code ' . intval( $error ) . '.';
    }
}

/**
 * Short human readable text for the most common add_new_link error codes
 * @param string $code Error code as returned by yourls_add_new_link()
 * @param string $fallback First message yourls_add_new_link() returned for
 *                          this code, used for unknown codes
 * @return string
 */
function vaughany_bias_skip_reason_text( $code, $fallback = '' ) {
    switch ( $code ) {
        case 'error:url':
            return 'URL already exists in the database';
        case 'error:keyword':
            return 'requested short URL already taken';
        case 'error:nourl':
            return 'missing or malformed URL';
        default:
            return $fallback !== '' ? $fallback : 'unknown error';
    }
}

/**
 * Create a title from the URL
 *
 * @param string $url   New in v0.2: Creating a title from the URL, so one is not fetched from the URL, slowing down the import.
 * @return string
 */
function vaughany_bias_create_title_from_url( string $url ) : string {
    return yourls_sanitize_title( str_replace(['http://', 'https://', '/'], '', $url) );
}
