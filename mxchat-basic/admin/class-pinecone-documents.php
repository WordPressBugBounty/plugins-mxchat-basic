<?php
/**
 * Pinecone document index adapter (plan 362c31).
 *
 * A Pinecone "document index" is a schema-based index (API version 2026-07)
 * whose string fields can carry a BM25 full-text index. MxChat offers it as a
 * second, OPT-IN index type beside the classic vector index: same API key,
 * host and namespace fields, an "Index type" choice on the Pinecone card, and
 * a copy-migration that moves an existing classic index across with zero
 * embedding calls (the classic index already holds the chunk text in metadata
 * and the vector in values).
 *
 * Everything the plugin does against a document index goes through this class:
 * the data plane (documents/upsert, /search, /fetch, /list, /delete), the
 * control plane (create / describe / delete index) and the migration. The
 * classic vector code paths are NOT modified — each of them carries a one-line
 * guard that hands a document-index configuration to the matching method here,
 * so a site on the classic index runs exactly the code it ran before.
 *
 * Field layout of a document (fixed — the schema is created by this class):
 *   _id        same ids as the classic index (md5(source_url), <md5>_chunk_N, manual_*)
 *   text       the chunk text, full-text indexed (string field, language from settings)
 *   embedding  the dense vector, dimension = the active embedding model's (client-supplied)
 *   everything else (source_url, type, bot_id, chunk_index, total_chunks, is_chunked,
 *   parent_url_hash, role_restriction, created_at, last_updated) = auto-indexed metadata.
 *
 * Response shapes measured 2026-09-19 against a live document index:
 *   search  → {matches:[{_id,_score,<fields…>}], namespace, usage}   (fields are FLAT on the hit)
 *   fetch   → {documents:{<id>:{_id,<fields…>}}}                     (embedding included unless include_fields)
 *   list    → POST body {prefix,limit,pagination_token} → {documents:[{_id}], pagination:{next}}
 *   upsert  → 202 {upserted_count}; delete → 202 {}; a classic /query on a document index → 400.
 *   Numeric fields come back as floats (chunk_index 0.0) — normalize_metadata() casts them.
 *   A dense score_by cannot be combined with a text score_by in one search; hybrid is
 *   a dense search plus a text-match FILTER, or two searches fused client-side.
 */

if (!defined('ABSPATH')) {
    exit;
}

class MxChat_Pinecone_Documents {

    const API_VERSION        = '2026-07';
    const TEXT_FIELD         = 'text';
    const VECTOR_FIELD       = 'embedding';
    const CODES_FIELD        = 'codes';  // plan 3e83e4: normalised part-number tokens stored beside the text
    const CODES_MAX          = 60;       // per document — a long spec sheet must not bloat the 40 KB metadata cap
    const CODE_MIN_LEN       = 4;        // shorter tokens would match everything
    const CODE_MAX_LEN       = 32;
    const MIGRATION_KEY      = 'mxchat_pinecone_docs_migration';
    const UPSERT_BATCH       = 40;   // 1536-dim vectors are ~30 KB of JSON each; the request cap is 2 MB
    const MIGRATE_BATCH      = 40;
    const MIGRATE_STEP_SECONDS = 12; // pages copied per AJAX step until this much wall time is used (3e83e4)
    const MIGRATE_RETRIES    = 3;    // attempts per Pinecone call inside a step, 1 s / 2 s / 4 s apart
    const OPTION             = 'mxchat_pinecone_addon_options';

    /** Fields the retrieval and listing paths ask for (never the vector unless needed). */
    const RECORD_FIELDS = array('text', 'source_url', 'title', 'type', 'bot_id', 'is_chunked', 'chunk_index', 'total_chunks', 'parent_url_hash', 'role_restriction', 'created_at', 'last_updated');

    public static function clouds() {
        return array('aws' => 'AWS', 'gcp' => 'GCP', 'azure' => 'Azure');
    }

    public static function languages() {
        return array(
            'en' => 'English', 'de' => 'German', 'fr' => 'French', 'es' => 'Spanish', 'it' => 'Italian',
            'nl' => 'Dutch', 'pt' => 'Portuguese', 'sv' => 'Swedish', 'da' => 'Danish', 'no' => 'Norwegian',
            'fi' => 'Finnish', 'ru' => 'Russian', 'tr' => 'Turkish', 'ar' => 'Arabic', 'hu' => 'Hungarian',
            'ro' => 'Romanian', 'el' => 'Greek',
        );
    }

    // =====================================================================
    // Configuration
    // =====================================================================

    /**
     * 'vector' | 'document' from a mxchat_pinecone_addon_options-shaped array.
     */
    public static function options_index_type(array $options) {
        return (isset($options['mxchat_pinecone_index_type']) && $options['mxchat_pinecone_index_type'] === 'document') ? 'document' : 'vector';
    }

    /**
     * Resolve the Pinecone connection for a bot: the bot's own config when the
     * Multi-Bot add-on supplies one (it may carry 'index_type'), else the
     * site-wide option. Same precedence the classic paths use.
     *
     * @return array{api_key:string,host:string,namespace:string,index_type:string,use_pinecone:bool}
     */
    public static function config($bot_id = 'default') {
        if ($bot_id === 'testing') {
            $bot_id = 'default';
        }
        if ($bot_id !== 'default' && class_exists('MxChat_Multi_Bot_Manager')) {
            $bot_config = apply_filters('mxchat_get_bot_pinecone_config', array(), $bot_id);
            if (is_array($bot_config) && !empty($bot_config)) {
                return array(
                    'api_key'      => $bot_config['api_key'] ?? '',
                    'host'         => $bot_config['host'] ?? '',
                    'namespace'    => $bot_config['namespace'] ?? '',
                    'index_type'   => (($bot_config['index_type'] ?? 'vector') === 'document') ? 'document' : 'vector',
                    'use_pinecone' => !empty($bot_config['use_pinecone']),
                );
            }
        }
        $options = get_option(self::OPTION, array());
        if (!is_array($options)) {
            $options = array();
        }
        return self::cfg_from_options($options);
    }

    /**
     * Same shape as config(), from an options array (the KB screen passes those around).
     */
    public static function cfg_from_options(array $options) {
        return array(
            'api_key'      => $options['mxchat_pinecone_api_key'] ?? '',
            'host'         => $options['mxchat_pinecone_host'] ?? '',
            'namespace'    => $options['mxchat_pinecone_namespace'] ?? '',
            'index_type'   => self::options_index_type($options),
            'use_pinecone' => isset($options['mxchat_use_pinecone']) && (string) $options['mxchat_use_pinecone'] === '1',
        );
    }

    /** Is this bot's Pinecone configuration a document index? */
    public static function is_document_index($bot_id = 'default') {
        $cfg = self::config($bot_id);
        return $cfg['index_type'] === 'document' && !empty($cfg['host']) && !empty($cfg['api_key']);
    }

    /**
     * Is this host the site's document index? For the delete helpers that
     * receive bare credentials (no options array, no bot id).
     */
    public static function is_document_host($host) {
        $host = strtolower(trim((string) $host, '/'));
        if ($host === '') {
            return false;
        }
        $options = get_option(self::OPTION, array());
        $is = is_array($options)
            && self::options_index_type($options) === 'document'
            && strtolower(trim((string) ($options['mxchat_pinecone_host'] ?? ''), '/')) === $host;
        return (bool) apply_filters('mxchat_pinecone_is_document_host', $is, $host);
    }

    /** The "processed content" row the Knowledge screens build per indexed post. */
    public static function processed_row($vector_id, $url, array $metadata) {
        $created_at = $metadata['created_at'] ?? '';
        $processed_date = 'Recently';
        $timestamp = current_time('timestamp');
        if (!empty($created_at)) {
            $ts = is_numeric($created_at) ? (int) $created_at : strtotime($created_at);
            if ($ts) {
                $timestamp = $ts;
                $processed_date = human_time_diff($ts, current_time('timestamp')) . ' ago';
            }
        }
        return array(
            'db_id'          => $vector_id,
            'processed_date' => $processed_date,
            'url'            => $url,
            'source'         => 'pinecone',
            'timestamp'      => $timestamp,
        );
    }

    /** Is this options array (mxchat_pinecone_addon_options shape) a document index? */
    public static function is_document_options($options) {
        if (!is_array($options)) {
            return false;
        }
        $cfg = self::cfg_from_options($options);
        return $cfg['index_type'] === 'document' && !empty($cfg['host']) && !empty($cfg['api_key']);
    }

    /** Namespace path segment: Pinecone addresses the default namespace as __default__. */
    public static function namespace_path($namespace) {
        $namespace = (string) $namespace;
        return $namespace === '' ? '__default__' : rawurlencode($namespace);
    }

    /** Stats report the default namespace as "__default__" — the classic shape uses "". */
    private static function namespace_stats_key($namespace) {
        return (string) $namespace === '' ? '__default__' : (string) $namespace;
    }

    // =====================================================================
    // Transport
    // =====================================================================

    /**
     * One HTTP call with the document-API version header.
     *
     * @return array{code:int,data:mixed,raw:string,error:string}
     */
    public static function request($method, $url, $body, $api_key, $timeout = 30) {
        $args = array(
            'method'  => $method,
            'headers' => array(
                'Api-Key'                => $api_key,
                'accept'                 => 'application/json',
                'content-type'           => 'application/json',
                'X-Pinecone-Api-Version' => self::API_VERSION,
            ),
            'timeout' => $timeout,
        );
        if ($body !== null) {
            $args['body'] = wp_json_encode($body);
        }
        $response = wp_remote_request($url, $args);
        if (is_wp_error($response)) {
            return array('code' => 0, 'data' => null, 'raw' => '', 'error' => $response->get_error_message());
        }
        $code = (int) wp_remote_retrieve_response_code($response);
        $raw  = (string) wp_remote_retrieve_body($response);
        $data = $raw === '' ? array() : json_decode($raw, true);
        $error = '';
        if ($code < 200 || $code >= 300) {
            $error = is_array($data) && isset($data['error']['message']) ? $data['error']['message']
                : (is_array($data) && isset($data['message']) ? $data['message'] : substr($raw, 0, 300));
            $error = 'Pinecone API error (HTTP ' . $code . '): ' . $error;
        }
        return array('code' => $code, 'data' => $data, 'raw' => $raw, 'error' => $error);
    }

    private static function data_url(array $cfg, $path) {
        return 'https://' . $cfg['host'] . '/namespaces/' . self::namespace_path($cfg['namespace'] ?? '') . '/documents/' . ltrim($path, '/');
    }

    private static function require_cfg(array $cfg) {
        if (empty($cfg['host']) || empty($cfg['api_key'])) {
            return new WP_Error('pinecone_config', 'Pinecone is not properly configured (host or API key missing).');
        }
        return true;
    }

    // =====================================================================
    // Document operations
    // =====================================================================

    /**
     * Build one document from the pieces the classic upsert would have sent.
     * Metadata keys travel 1:1 (except 'text', which is the schema field).
     */
    public static function build_document($id, $text, array $embedding, array $metadata) {
        $doc = array('_id' => (string) $id);
        foreach ($metadata as $key => $value) {
            if ($key === self::TEXT_FIELD || $key === self::VECTOR_FIELD || $key === '_id') {
                continue;
            }
            if (is_scalar($value) || $value === null) {
                $doc[$key] = $value;
            } elseif (is_array($value)) {
                $doc[$key] = $value;
            }
        }
        $doc[self::TEXT_FIELD]   = (string) $text;
        $doc[self::VECTOR_FIELD] = array_values(array_map('floatval', $embedding));
        // 3e83e4: the normalised code set travels with every write (new content
        // and copied records alike) so a part number typed without its
        // separators can still reach the record — see index_codes().
        $codes = self::index_codes((string) $text);
        if (!empty($codes)) {
            $doc[self::CODES_FIELD] = $codes;
        } else {
            unset($doc[self::CODES_FIELD]);
        }
        return $doc;
    }

    /**
     * Upsert documents in batches. Same id → the document is replaced.
     *
     * @return true|WP_Error
     */
    public static function upsert(array $documents, array $cfg) {
        $ok = self::require_cfg($cfg);
        if (is_wp_error($ok)) {
            return $ok;
        }
        if (empty($documents)) {
            return true;
        }
        foreach (array_chunk(array_values($documents), self::UPSERT_BATCH) as $batch) {
            $res = self::request('POST', self::data_url($cfg, 'upsert'), array('documents' => $batch), $cfg['api_key'], 60);
            if ($res['error'] !== '') {
                return new WP_Error('pinecone_api', $res['error']);
            }
        }
        return true;
    }

    public static function upsert_one($id, $text, array $embedding, array $metadata, array $cfg) {
        return self::upsert(array(self::build_document($id, $text, $embedding, $metadata)), $cfg);
    }

    /**
     * Search. Returns matches normalized to the CLASSIC shape
     * [{id, score, metadata:{…}}] so the grouping code can be shared.
     *
     * @return array|WP_Error
     */
    public static function search(array $score_by, $top_k, $filter, array $cfg, $include_fields = null) {
        $ok = self::require_cfg($cfg);
        if (is_wp_error($ok)) {
            return $ok;
        }
        $top_k = max(1, min(10000, (int) $top_k));
        $body = array(
            'score_by'       => $score_by,
            'top_k'          => $top_k,
            'include_fields' => $include_fields === null ? self::RECORD_FIELDS : array_values($include_fields),
        );
        if (!empty($filter) && is_array($filter)) {
            $body['filter'] = $filter;
        }
        $res = self::request('POST', self::data_url($cfg, 'search'), $body, $cfg['api_key'], 30);
        if ($res['error'] !== '') {
            return new WP_Error('pinecone_api', $res['error']);
        }
        $matches = array();
        if (is_array($res['data']) && !empty($res['data']['matches'])) {
            foreach ($res['data']['matches'] as $hit) {
                $matches[] = self::normalize_hit($hit);
            }
        }
        return $matches;
    }

    public static function search_dense(array $vector, $top_k, $filter, array $cfg, $include_fields = null) {
        return self::search(
            array(array('type' => 'dense_vector', 'fields' => array(self::VECTOR_FIELD), 'values' => array_values($vector))),
            $top_k, $filter, $cfg, $include_fields
        );
    }

    public static function search_text($query, $top_k, $filter, array $cfg, $include_fields = null) {
        return self::search(
            array(array('type' => 'text', 'fields' => array(self::TEXT_FIELD), 'query' => (string) $query)),
            $top_k, $filter, $cfg, $include_fields
        );
    }

    /**
     * Fetch documents by id. Returns the classic /vectors/fetch shape:
     * [id => ['id' => …, 'values' => [...] (only when asked), 'metadata' => [...]]].
     */
    public static function fetch(array $ids, array $cfg, $include_fields = null, $with_vector = false) {
        $ok = self::require_cfg($cfg);
        if (is_wp_error($ok) || empty($ids)) {
            return array();
        }
        $fields = $include_fields === null ? self::RECORD_FIELDS : array_values($include_fields);
        if ($with_vector && !in_array(self::VECTOR_FIELD, $fields, true)) {
            $fields[] = self::VECTOR_FIELD;
        }
        $out = array();
        foreach (array_chunk(array_values(array_unique(array_map('strval', $ids))), 1000) as $chunk) {
            $res = self::request('POST', self::data_url($cfg, 'fetch'), array('ids' => $chunk, 'include_fields' => $fields), $cfg['api_key'], 30);
            if ($res['error'] !== '' || !is_array($res['data']) || empty($res['data']['documents'])) {
                continue;
            }
            foreach ($res['data']['documents'] as $doc_id => $doc) {
                if (!is_array($doc)) {
                    continue;
                }
                $id = isset($doc['_id']) ? (string) $doc['_id'] : (string) $doc_id;
                $row = array('id' => $id, 'metadata' => self::normalize_metadata($doc));
                if (isset($doc[self::VECTOR_FIELD]) && is_array($doc[self::VECTOR_FIELD])) {
                    $row['values'] = $doc[self::VECTOR_FIELD];
                }
                $out[$id] = $row;
            }
        }
        return $out;
    }

    /**
     * One page of ids. @return array{ids:array,next:string}|WP_Error
     */
    public static function list_page($prefix, $limit, $token, array $cfg) {
        $ok = self::require_cfg($cfg);
        if (is_wp_error($ok)) {
            return $ok;
        }
        $body = array('limit' => max(1, min(100, (int) $limit)));
        if ((string) $prefix !== '') {
            $body['prefix'] = (string) $prefix;
        }
        if ((string) $token !== '') {
            $body['pagination_token'] = (string) $token;
        }
        $res = self::request('POST', self::data_url($cfg, 'list'), $body, $cfg['api_key'], 30);
        if ($res['error'] !== '') {
            return new WP_Error('pinecone_api', $res['error']);
        }
        $ids = array();
        if (is_array($res['data']) && !empty($res['data']['documents'])) {
            foreach ($res['data']['documents'] as $doc) {
                if (isset($doc['_id'])) {
                    $ids[] = (string) $doc['_id'];
                }
            }
        }
        $next = is_array($res['data']) && isset($res['data']['pagination']['next']) ? (string) $res['data']['pagination']['next'] : '';
        return array('ids' => $ids, 'next' => $next);
    }

    /** Every id under a prefix, following pagination (capped). */
    public static function list_ids($prefix, array $cfg, $max = 10000) {
        $ids = array();
        $token = '';
        do {
            $page = self::list_page($prefix, 100, $token, $cfg);
            if (is_wp_error($page)) {
                break;
            }
            $ids = array_merge($ids, $page['ids']);
            $token = $page['next'];
        } while ($token !== '' && count($ids) < $max);
        return $ids;
    }

    /** @return true|WP_Error */
    public static function delete_ids(array $ids, array $cfg) {
        $ok = self::require_cfg($cfg);
        if (is_wp_error($ok)) {
            return $ok;
        }
        $ids = array_values(array_unique(array_map('strval', $ids)));
        if (empty($ids)) {
            return true;
        }
        foreach (array_chunk($ids, 1000) as $chunk) {
            $res = self::request('POST', self::data_url($cfg, 'delete'), array('ids' => $chunk), $cfg['api_key'], 60);
            // 404 = the namespace does not exist yet — nothing to delete.
            if ($res['error'] !== '' && $res['code'] !== 404) {
                return new WP_Error('pinecone_api', $res['error']);
            }
        }
        return true;
    }

    /** Delete every document in the namespace, or those matching a metadata filter. @return true|WP_Error */
    public static function delete_all(array $cfg, $filter = null) {
        $ok = self::require_cfg($cfg);
        if (is_wp_error($ok)) {
            return $ok;
        }
        $body = (!empty($filter) && is_array($filter)) ? array('filter' => $filter) : array('delete_all' => true);
        $res = self::request('POST', self::data_url($cfg, 'delete'), $body, $cfg['api_key'], 60);
        if ($res['error'] !== '' && $res['code'] !== 404) {
            return new WP_Error('pinecone_api', $res['error']);
        }
        return true;
    }

    /**
     * describe_index_stats, classic-shaped: namespaces keyed the classic way
     * ('' for the default namespace), totalVectorCount, dimension.
     *
     * @return array|WP_Error
     */
    public static function stats(array $cfg) {
        $ok = self::require_cfg($cfg);
        if (is_wp_error($ok)) {
            return $ok;
        }
        $res = self::request('POST', 'https://' . $cfg['host'] . '/describe_index_stats', new stdClass(), $cfg['api_key'], 15);
        if ($res['error'] !== '' || !is_array($res['data'])) {
            return new WP_Error('pinecone_api', $res['error'] !== '' ? $res['error'] : 'Empty stats response');
        }
        $namespaces = array();
        if (!empty($res['data']['namespaces']) && is_array($res['data']['namespaces'])) {
            foreach ($res['data']['namespaces'] as $name => $info) {
                $key = ($name === '__default__') ? '' : (string) $name;
                $namespaces[$key] = array('vectorCount' => (int) ($info['vectorCount'] ?? $info['recordCount'] ?? 0));
            }
        }
        return array(
            'namespaces'       => $namespaces,
            'totalVectorCount' => (int) ($res['data']['totalVectorCount'] ?? 0),
            'dimension'        => (int) ($res['data']['dimension'] ?? 0),
        );
    }

    /** Record count for the configured namespace (0 when unknown / not listed). */
    public static function count(array $cfg) {
        $stats = self::stats($cfg);
        if (is_wp_error($stats)) {
            return 0;
        }
        $ns = (string) ($cfg['namespace'] ?? '');
        if ($ns !== '') {
            return isset($stats['namespaces'][$ns]['vectorCount']) ? (int) $stats['namespaces'][$ns]['vectorCount'] : 0;
        }
        return (int) $stats['totalVectorCount'];
    }

    /**
     * Reassemble a chunked entry's text from its <md5>_chunk_N documents —
     * the document-index twin of the integrator's reassemble_chunks_from_pinecone().
     */
    public static function reassemble_chunks($source_url, array $cfg, $max_chunks = 0, &$chunk_count = 0) {
        $chunk_count = 0;
        if (empty($cfg['host']) || empty($cfg['api_key'])) {
            return '';
        }
        $ids = self::list_ids(md5($source_url) . '_chunk_', $cfg, 500);
        if (empty($ids)) {
            return '';
        }
        $docs = self::fetch($ids, $cfg, array(self::TEXT_FIELD, 'chunk_index'));
        if (empty($docs)) {
            return '';
        }
        $chunks = array();
        foreach ($docs as $doc) {
            $chunks[(int) ($doc['metadata']['chunk_index'] ?? 0)] = (string) ($doc['metadata']['text'] ?? '');
        }
        ksort($chunks);
        if ($max_chunks > 0 && count($chunks) > $max_chunks) {
            $chunks = array_slice($chunks, 0, $max_chunks, true);
        }
        $chunk_count = count($chunks);
        return implode("\n\n", $chunks);
    }

    /** Flat search hit → classic {id, score, metadata}. */
    public static function normalize_hit(array $hit) {
        $id    = isset($hit['_id']) ? (string) $hit['_id'] : '';
        $score = isset($hit['_score']) ? (float) $hit['_score'] : 0.0;
        $row   = array('id' => $id, 'score' => $score, 'metadata' => self::normalize_metadata($hit));
        if (isset($hit[self::VECTOR_FIELD]) && is_array($hit[self::VECTOR_FIELD])) {
            $row['values'] = $hit[self::VECTOR_FIELD];
        }
        return $row;
    }

    /** Document fields → the metadata array the classic code reads (ints where it expects ints). */
    public static function normalize_metadata(array $fields) {
        $meta = array();
        foreach ($fields as $key => $value) {
            if ($key === '_id' || $key === '_score' || $key === self::VECTOR_FIELD) {
                continue;
            }
            $meta[$key] = $value;
        }
        foreach (array('chunk_index', 'total_chunks', 'created_at', 'last_updated') as $int_key) {
            if (isset($meta[$int_key]) && is_numeric($meta[$int_key])) {
                $meta[$int_key] = (int) $meta[$int_key];
            }
        }
        if (isset($meta['is_chunked'])) {
            $meta['is_chunked'] = filter_var($meta['is_chunked'], FILTER_VALIDATE_BOOLEAN);
        }
        return $meta;
    }

    /** Classic {id, score, metadata} rows → the KB screen's record objects. */
    public static function records_from_matches(array $matches, $bot_id = 'default', $content_type = '') {
        $records = array();
        foreach ($matches as $match) {
            $metadata = $match['metadata'] ?? array();
            if ($content_type !== '' && (($metadata['type'] ?? 'content') !== $content_type)) {
                continue;
            }
            $created_at = $metadata['created_at'] ?? $metadata['last_updated'] ?? time();
            if (!is_numeric($created_at)) {
                $created_at = strtotime($created_at) ?: time();
            }
            $records[] = (object) array(
                'id'               => $match['id'] ?? '',
                'article_content'  => $metadata['text'] ?? '',
                'source_url'       => $metadata['source_url'] ?? '',
                'role_restriction' => $metadata['role_restriction'] ?? 'public',
                'type'             => $metadata['type'] ?? 'content',
                'bot_id'           => $bot_id,
                'created_at'       => (int) $created_at,
                'data_source'      => 'pinecone',
                'relevance_score'  => $match['score'] ?? 0,
                'chunk_index'      => isset($metadata['chunk_index']) ? (int) $metadata['chunk_index'] : null,
                'total_chunks'     => isset($metadata['total_chunks']) ? (int) $metadata['total_chunks'] : null,
                'is_chunked'       => !empty($metadata['is_chunked']),
            );
        }
        return $records;
    }

    /**
     * The KB screen listing on a document index (twin of
     * MxChat_Pinecone_Manager::mxchat_fetch_pinecone_records, cursor-paged).
     * With a search query the BM25 index answers it — no embedding call.
     */
    public static function fetch_records(array $pinecone_options, $search_query, $page, $per_page, $bot_id, $content_type) {
        $cfg   = self::cfg_from_options($pinecone_options);
        $page  = max(1, (int) $page);
        $per_page = max(1, (int) $per_page);
        $empty = array('data' => array(), 'total' => 0, 'total_in_database' => 0, 'showing_recent_only' => false, 'has_next' => false);
        if (empty($cfg['host']) || empty($cfg['api_key'])) {
            return $empty;
        }
        $total_in_database = self::count($cfg);
        $type_filter = $content_type !== '' ? array('type' => array('$eq' => (string) $content_type)) : null;

        if (trim((string) $search_query) !== '') {
            $limit = min(($page * $per_page) + 100, 500);
            $matches = self::search_text($search_query, $limit, $type_filter, $cfg);
            if (is_wp_error($matches)) {
                $matches = array();
            }
            $records = self::records_from_matches($matches, $bot_id, '');
            $offset = ($page - 1) * $per_page;
            return array(
                'data'                => array_slice($records, $offset, $per_page),
                'total'               => count($records),
                'total_in_database'   => $total_in_database,
                'showing_recent_only' => false,
                'has_next'            => ($offset + $per_page) < count($records),
            );
        }

        // Browse: one cursor step per page (plan dd6e10's rule), tokens kept per page.
        $limit = $content_type !== '' ? $per_page * 2 : $per_page;
        $pagination_key = 'mxchat_pinecone_page_' . md5($cfg['host'] . $cfg['namespace'] . $content_type . '_docs');
        $token = '';
        if ($page > 1) {
            $stored = get_transient($pagination_key);
            if ($stored && isset($stored[$page])) {
                $token = $stored[$page];
            }
        }
        $listing = self::list_page('', $limit, $token, $cfg);
        if (is_wp_error($listing)) {
            error_log('MxChat Pinecone (document index): documents/list failed — ' . $listing->get_error_message());
            $empty['total_in_database'] = $total_in_database;
            return $empty;
        }
        $has_next = $listing['next'] !== '';
        if ($has_next) {
            $stored = get_transient($pagination_key) ?: array();
            $stored[$page + 1] = $listing['next'];
            set_transient($pagination_key, $stored, 300);
        }
        $records = array();
        if (!empty($listing['ids'])) {
            $docs = self::fetch($listing['ids'], $cfg);
            $matches = array();
            foreach ($docs as $doc) {
                $matches[] = array('id' => $doc['id'], 'score' => 0, 'metadata' => $doc['metadata']);
            }
            $records = self::records_from_matches($matches, $bot_id, (string) $content_type);
            usort($records, function ($a, $b) {
                return $b->created_at <=> $a->created_at;
            });
            $records = array_slice($records, 0, $per_page);
        }
        return array(
            'data'                => $records,
            'total'               => $total_in_database,
            'total_in_database'   => $total_in_database,
            'showing_recent_only' => false,
            'count_unit'          => 'vectors',
            'cursor_pagination'   => true,
            'has_next'            => $has_next,
        );
    }

    // =====================================================================
    // Retrieval helpers (hybrid keyword leg)
    // =====================================================================

    /**
     * Code-like tokens in a question: runs that mix letters and digits, or
     * hyphen / dot joined codes (HGH25CA, SR25W, R1621-314-20, ISO-9001-2015).
     * Plain numbers and plain words are not codes. Minimum 4 characters.
     */
    public static function extract_code_tokens($text) {
        $tokens = array();
        if (!preg_match_all('/(?<![A-Za-z0-9])([A-Za-z0-9]+(?:[.\-][A-Za-z0-9]+)*)(?![A-Za-z0-9])/u', (string) $text, $m)) {
            return $tokens;
        }
        foreach ($m[1] as $candidate) {
            $candidate = rtrim($candidate, '.');
            if (strlen($candidate) < 4 || !preg_match('/\d/', $candidate)) {
                continue;
            }
            $has_letter = (bool) preg_match('/[A-Za-z]/', $candidate);
            $separators = preg_match_all('/[.\-]/', $candidate);
            if (!$has_letter && $separators < 2) {
                continue; // 2024, 26.5, 10-20
            }
            $tokens[strtoupper($candidate)] = $candidate;
        }
        return array_values($tokens);
    }

    /**
     * Text-match filter that requires at least one of the codes. Hyphenated or
     * dotted codes are phrase-matched (the tokenizer splits them, and "20" on
     * its own would match everything); single runs use $match_any.
     *
     * 3e83e4: a second leg matches the NORMALISED code set stored on each
     * document (codes $in …), which is what lets "R165321320" reach a record
     * that only ever says "R1653" — see query_codes() for the expansion.
     */
    public static function keyword_filter(array $tokens) {
        $clauses = array();
        foreach ($tokens as $token) {
            $op = preg_match('/[.\-]/', $token) ? '$match_phrase' : '$match_any';
            $clauses[] = array(self::TEXT_FIELD => array($op => $token));
        }
        $query_codes = self::query_codes($tokens);
        if (!empty($query_codes)) {
            $clauses[] = array(self::CODES_FIELD => array('$in' => $query_codes));
        }
        if (empty($clauses)) {
            return null;
        }
        return count($clauses) === 1 ? $clauses[0] : array('$or' => $clauses);
    }

    /** Upper-case, separators stripped: "R1653 213 20" → "R165321320", "hgh25ca" → "HGH25CA". */
    public static function normalize_code($code) {
        return strtoupper(preg_replace('/[\s.\-_\/]+/u', '', (string) $code));
    }

    /**
     * Code-like runs in a piece of text, INCLUDING digit groups that follow a
     * code separated by a space, hyphen or dot ("R1653 213 20" is one number
     * the way Rexroth prints it). Returns [ [head, full], … ] where head is
     * the first run and full is the whole joined run, both un-normalised.
     */
    private static function code_runs($text) {
        $runs = array();
        $text = (string) $text;
        $pattern = '/(?<![A-Za-z0-9])([A-Za-z0-9]+(?:[.\-][A-Za-z0-9]+)*)((?:[ .\-]\d{2,6}(?![A-Za-z0-9])){1,3})?(?![A-Za-z0-9])/u';
        $offset = 0;
        $length = strlen($text);
        // 0d7bd4: walked one match at a time. Only a head that IS a code keeps
        // its trailing digit groups; a plain word ("part 4471-AB-12") used to
        // take "4471" as its tail and the digit-led number was never stored.
        while ($offset < $length && preg_match($pattern, $text, $match, PREG_OFFSET_CAPTURE, $offset)) {
            $head     = rtrim($match[1][0], '.');
            $head_end = $match[1][1] + strlen($match[1][0]);
            $is_code  = strlen($head) >= self::CODE_MIN_LEN && preg_match('/\d/', $head);
            if ($is_code) {
                $has_letter = (bool) preg_match('/[A-Za-z]/', $head);
                $separators = preg_match_all('/[.\-]/', $head);
                if (!$has_letter && $separators < 2) {
                    $is_code = false; // 2024, 26.5, 10-20
                }
            }
            if (!$is_code) {
                $offset = $head_end;
                continue;
            }
            $tail   = isset($match[2]) && $match[2][1] >= 0 ? $match[2][0] : '';
            $runs[] = array($head, $head . $tail);
            $offset = $match[0][1] + strlen($match[0][0]);
        }
        return $runs;
    }

    /**
     * The normalised token set stored on a document at write time: for every
     * code-like run in the text, the separator-stripped form, the leading stem
     * (the run before the first separator) and the letters+digits stem
     * ("HGH25" of "HGH25CA"). Unique, minimum 4 characters, capped.
     */
    public static function index_codes($text) {
        $codes = array();
        foreach (self::code_runs($text) as $run) {
            list($head, $full) = $run;
            $candidates = array(self::normalize_code($full), self::normalize_code($head));
            $stem = preg_split('/[.\-\s]/', $head)[0];
            $candidates[] = self::normalize_code($stem);
            if (preg_match('/^([A-Za-z]+\d+)/', $head, $sm)) {
                $candidates[] = self::normalize_code($sm[1]);
            }
            foreach ($candidates as $c) {
                if (strlen($c) >= self::CODE_MIN_LEN && strlen($c) <= self::CODE_MAX_LEN && preg_match('/\d/', $c)) {
                    $codes[$c] = true;
                }
            }
            if (count($codes) >= self::CODES_MAX) {
                break;
            }
        }
        // 0d7bd4: strval — PHP turns an all-digit array key into an int, and
        // Pinecone refuses a list that mixes strings and numbers.
        return array_slice(array_map('strval', array_keys($codes)), 0, self::CODES_MAX);
    }

    /**
     * What a question's tokens expand to for the codes $in filter: the
     * stripped form, every prefix of it from 4 characters up (a stored stem
     * that is a prefix of what the visitor typed then matches — "R1653" for
     * "R165321320") and the letters+digits stem. Plain-language questions
     * carry no tokens and return nothing, so they are unchanged.
     */
    public static function query_codes(array $tokens) {
        $codes = array();
        foreach ($tokens as $token) {
            $full = self::normalize_code($token);
            if (strlen($full) < self::CODE_MIN_LEN || !preg_match('/\d/', $full)) {
                continue;
            }
            $full = substr($full, 0, self::CODE_MAX_LEN);
            $codes[$full] = true;
            for ($len = self::CODE_MIN_LEN; $len < strlen($full); $len++) {
                $codes[substr($full, 0, $len)] = true;
            }
            if (preg_match('/^([A-Z]+\d+)/', $full, $sm) && strlen($sm[1]) >= self::CODE_MIN_LEN) {
                $codes[$sm[1]] = true;
            }
            if (count($codes) >= 200) {
                break;
            }
        }
        return array_slice(array_map('strval', array_keys($codes)), 0, 200);
    }

    /**
     * Order keyword-leg hits so an exact code match outranks a prefix-only
     * match: a hit is exact when its stored code set (or its text) carries the
     * stripped question token itself. Dense order is kept inside each group.
     *
     * @return array{hits:array,exact:int,prefix:int}
     */
    public static function rank_keyword_hits(array $hits, array $tokens) {
        $exact_codes = array();
        foreach ($tokens as $token) {
            $n = self::normalize_code($token);
            if (strlen($n) >= self::CODE_MIN_LEN) {
                $exact_codes[$n] = true;
            }
        }
        $exact = array();
        $prefix = array();
        foreach ($hits as $hit) {
            $meta   = is_array($hit['metadata'] ?? null) ? $hit['metadata'] : array();
            $stored = is_array($meta[self::CODES_FIELD] ?? null) ? $meta[self::CODES_FIELD] : array();
            $is_exact = false;
            foreach ($stored as $code) {
                if (isset($exact_codes[strtoupper((string) $code)])) {
                    $is_exact = true;
                    break;
                }
            }
            if (!$is_exact && !empty($tokens) && isset($meta[self::TEXT_FIELD])) {
                $haystack = self::normalize_code($meta[self::TEXT_FIELD]);
                foreach ($exact_codes as $code => $_) {
                    if ($haystack !== '' && strpos($haystack, $code) !== false) {
                        $is_exact = true;
                        break;
                    }
                }
            }
            $hit['code_match'] = $is_exact ? 'exact' : 'prefix';
            if ($is_exact) {
                $exact[] = $hit;
            } else {
                $prefix[] = $hit;
            }
        }
        return array('hits' => array_merge($exact, $prefix), 'exact' => count($exact), 'prefix' => count($prefix));
    }

    /** AND two filters (either may be empty). */
    public static function merge_filters($a, $b) {
        $a = (!empty($a) && is_array($a)) ? $a : null;
        $b = (!empty($b) && is_array($b)) ? $b : null;
        if ($a === null) {
            return $b;
        }
        if ($b === null) {
            return $a;
        }
        return array('$and' => array($a, $b));
    }

    /**
     * Translate the classic query body (what the mxchat_pinecone_query_body
     * filter sees) into what a document search needs: topK → top_k, filter → filter.
     *
     * @return array{top_k:int,filter:array|null}
     */
    public static function translate_classic_body(array $body, $default_top_k) {
        $top_k = isset($body['topK']) ? absint($body['topK']) : 0;
        if ($top_k < 1 || $top_k > 1000) {
            $top_k = $default_top_k;
        }
        $filter = (!empty($body['filter']) && is_array($body['filter'])) ? $body['filter'] : null;
        return array('top_k' => $top_k, 'filter' => $filter);
    }

    // =====================================================================
    // Control plane
    // =====================================================================

    private static function control_request($method, $path, $body, $api_key) {
        return self::request($method, 'https://api.pinecone.io/' . ltrim($path, '/'), $body, $api_key, 30);
    }

    /** @return array|WP_Error the IndexModel (2026-07 shape, with schema) */
    public static function describe_index($name, $api_key) {
        $name = sanitize_text_field($name);
        if ($name === '') {
            return new WP_Error('pinecone_index', 'Index name is empty.');
        }
        $res = self::control_request('GET', 'indexes/' . rawurlencode($name), null, $api_key);
        if ($res['error'] !== '' || !is_array($res['data'])) {
            return new WP_Error('pinecone_api', $res['error'] !== '' ? $res['error'] : 'Empty describe response', array('code' => $res['code']));
        }
        return $res['data'];
    }

    /** Find an index in the project by its host. @return array|WP_Error */
    public static function find_index_by_host($host, $api_key) {
        $host = strtolower(trim(str_replace(array('https://', 'http://'), '', (string) $host), '/'));
        if ($host === '') {
            return new WP_Error('pinecone_index', 'Host is empty.');
        }
        $res = self::control_request('GET', 'indexes', null, $api_key);
        if ($res['error'] !== '' || !is_array($res['data'])) {
            return new WP_Error('pinecone_api', $res['error'] !== '' ? $res['error'] : 'Could not list indexes');
        }
        foreach (($res['data']['indexes'] ?? array()) as $index) {
            if (isset($index['host']) && strtolower($index['host']) === $host) {
                return $index;
            }
        }
        return new WP_Error('pinecone_index', sprintf('No index in this Pinecone project answers to the host %s. Check the host and that the API key belongs to the same project.', $host));
    }

    /**
     * Read a described index's schema. Classic vector indexes carry the
     * reserved _values / _sparse_values fields; a document index names its own.
     */
    public static function inspect_schema(array $index) {
        $out = array('is_document' => false, 'text_field' => '', 'dense_field' => '', 'dimension' => 0, 'metric' => '', 'language' => '');
        $fields = $index['schema']['fields'] ?? array();
        if (!is_array($fields)) {
            return $out;
        }
        foreach ($fields as $name => $spec) {
            if (!is_array($spec)) {
                continue;
            }
            if (($spec['type'] ?? '') === 'dense_vector' && strpos($name, '_') !== 0) {
                $out['dense_field'] = (string) $name;
                $out['dimension']   = (int) ($spec['dimension'] ?? 0);
                $out['metric']      = (string) ($spec['metric'] ?? '');
            }
            if (($spec['type'] ?? '') === 'string' && !empty($spec['full_text_search']) && $out['text_field'] === '') {
                $out['text_field'] = (string) $name;
                $out['language']   = (string) ($spec['full_text_search']['language'] ?? '');
            }
        }
        $out['is_document'] = $out['dense_field'] !== '' && $out['text_field'] !== '';
        return $out;
    }

    /**
     * Assert an index is a document index MxChat can use. Refuses with the
     * exact mismatch otherwise.
     *
     * @return array{ok:bool,message:string,host:string,name:string,dimension:int,expected:int}
     */
    public static function check_document_index($host, $api_key, $expected_dimension) {
        $index = self::find_index_by_host($host, $api_key);
        if (is_wp_error($index)) {
            return array('ok' => false, 'message' => $index->get_error_message(), 'host' => (string) $host, 'name' => '', 'dimension' => 0, 'expected' => (int) $expected_dimension);
        }
        $name   = (string) ($index['name'] ?? '');
        $schema = self::inspect_schema($index);
        $base   = array('host' => (string) ($index['host'] ?? $host), 'name' => $name, 'dimension' => $schema['dimension'], 'expected' => (int) $expected_dimension);
        if (!$schema['is_document']) {
            $fields = is_array($index['schema']['fields'] ?? null) ? implode(', ', array_keys($index['schema']['fields'])) : 'none';
            return $base + array('ok' => false, 'message' => sprintf('"%s" is a classic vector index (schema fields: %s), not a document index with full-text search. Create a document index with the button above, or switch the index type back to Vector index.', $name, $fields));
        }
        if ($schema['text_field'] !== self::TEXT_FIELD || $schema['dense_field'] !== self::VECTOR_FIELD) {
            return $base + array('ok' => false, 'message' => sprintf('"%s" is a document index, but its fields are named "%s" (text) and "%s" (vector). MxChat writes to fields named "%s" and "%s" — create the index with the button above so the schema matches.', $name, $schema['text_field'], $schema['dense_field'], self::TEXT_FIELD, self::VECTOR_FIELD));
        }
        if ((int) $schema['dimension'] !== (int) $expected_dimension) {
            return $base + array('ok' => false, 'message' => sprintf('"%s" stores %d-dimension vectors, but the active embedding model produces %d dimensions. Pick a matching embedding model or create a new document index for this one.', $name, $schema['dimension'], $expected_dimension));
        }
        if (empty($index['status']['ready'])) {
            return $base + array('ok' => false, 'message' => sprintf('"%s" is a matching document index but Pinecone reports it as %s. Wait a moment and check again.', $name, (string) ($index['status']['state'] ?? 'not ready')));
        }
        return $base + array('ok' => true, 'message' => sprintf('"%s" is a document index with full-text search (%s, %d dimensions, %s). Ready to use.', $name, $schema['language'] !== '' ? 'language ' . $schema['language'] : 'full-text search', $schema['dimension'], $schema['metric'] ?: 'cosine'));
    }

    /**
     * The plain-language "that name is taken" message (3e83e4) — never a bare
     * HTTP 409. Carries a free suggestion so the UI can fill it in.
     */
    public static function name_taken_message($name, $suggested = '') {
        $message = sprintf(
            /* translators: %s: Pinecone index name */
            __('An index named "%s" already exists in this Pinecone project. Pick a different name, or paste that index\'s host above and press Check index instead of creating one.', 'mxchat'),
            $name
        );
        if ($suggested !== '') {
            /* translators: %s: suggested Pinecone index name */
            $message .= ' ' . sprintf(__('Suggested free name: %s.', 'mxchat'), $suggested);
        }
        return $message;
    }

    /**
     * A free index name derived from the current one: <base>-docs, then
     * <base>-docs-2, -3 … (an existing -docs suffix is not doubled). Lists the
     * project's indexes once. @return array{name:string,taken:bool,existing:array}
     */
    public static function suggest_index_name($base, $api_key) {
        $base = strtolower(sanitize_text_field($base));
        $base = preg_replace('/[^a-z0-9-]+/', '-', $base);
        $base = trim(preg_replace('/-{2,}/', '-', $base), '-');
        $base = preg_replace('/-docs(-\d+)?$/', '', $base);
        if ($base === '') {
            $base = 'mxchat';
        }
        $existing = array();
        $res = self::control_request('GET', 'indexes', null, $api_key);
        if ($res['error'] === '' && is_array($res['data'])) {
            foreach (($res['data']['indexes'] ?? array()) as $index) {
                if (isset($index['name'])) {
                    $existing[strtolower((string) $index['name'])] = true;
                }
            }
        }
        $stem = substr($base, 0, 45 - strlen('-docs-99'));
        $stem = rtrim($stem, '-');
        $candidate = $stem . '-docs';
        for ($n = 2; isset($existing[$candidate]) && $n < 100; $n++) {
            $candidate = $stem . '-docs-' . $n;
        }
        return array('name' => $candidate, 'taken' => isset($existing[$base]), 'existing' => array_keys($existing));
    }

    /**
     * Create a document index for the active embedding dimension.
     * @return array|WP_Error the IndexModel
     */
    public static function create_document_index($name, $dimension, $cloud, $region, $language, $api_key) {
        $name = strtolower(sanitize_text_field($name));
        if (!preg_match('/^[a-z0-9]([a-z0-9-]{0,43}[a-z0-9])?$/', $name)) {
            return new WP_Error('pinecone_index', 'Index names are 1-45 lowercase letters, digits and hyphens.');
        }
        // 3e83e4: say so in plain words BEFORE Pinecone can answer 409 — the
        // name field is prefilled with the vector index being migrated away
        // from, so the first click used to collide for exactly that customer.
        $taken = self::describe_index($name, $api_key);
        if (!is_wp_error($taken)) {
            $suggest = self::suggest_index_name($name, $api_key);
            return new WP_Error('pinecone_exists', self::name_taken_message($name, $suggest['name']), array('code' => 409, 'suggested' => $suggest['name']));
        }
        $cloud = array_key_exists($cloud, self::clouds()) ? $cloud : 'aws';
        $region = sanitize_text_field($region) ?: 'us-east-1';
        $language = array_key_exists($language, self::languages()) ? $language : 'en';
        $dimension = (int) $dimension;
        if ($dimension < 1 || $dimension > 20000) {
            return new WP_Error('pinecone_index', 'Embedding dimension is out of range.');
        }
        $body = array(
            'name'       => $name,
            'deployment' => array('deployment_type' => 'managed', 'cloud' => $cloud, 'region' => $region),
            'schema'     => array('fields' => array(
                self::TEXT_FIELD   => array('type' => 'string', 'full_text_search' => array('language' => $language)),
                self::VECTOR_FIELD => array('type' => 'dense_vector', 'dimension' => $dimension, 'metric' => 'cosine'),
            )),
        );
        $res = self::control_request('POST', 'indexes', $body, $api_key);
        if ($res['code'] === 409) {
            $suggest = self::suggest_index_name($name, $api_key);
            return new WP_Error('pinecone_exists', self::name_taken_message($name, $suggest['name']), array('code' => 409, 'suggested' => $suggest['name']));
        }
        if ($res['error'] !== '' || !is_array($res['data'])) {
            return new WP_Error('pinecone_api', $res['error'] !== '' ? $res['error'] : 'Empty create response', array('code' => $res['code']));
        }
        return $res['data'];
    }

    /** @return true|WP_Error */
    public static function delete_index($name, $api_key) {
        $name = sanitize_text_field($name);
        if ($name === '') {
            return new WP_Error('pinecone_index', 'Index name is empty.');
        }
        $res = self::control_request('DELETE', 'indexes/' . rawurlencode($name), null, $api_key);
        if ($res['error'] !== '' && $res['code'] !== 404) {
            return new WP_Error('pinecone_api', $res['error']);
        }
        return true;
    }

    /** Dimension of the active embedding model (same table the KB screen uses). */
    public static function expected_dimension() {
        $dimension = self::expected_dimension_checked();
        return is_wp_error($dimension) ? 0 : (int) $dimension;
    }

    /**
     * 418afd: the dimension the site's embeddings really have, or why it is
     * not known. A Custom Provider embedding model is asked for its size
     * (MxChat_Utils::expected_embedding_dimension()); the standard dropdown is
     * disabled on such a site and used to size the index anyway. The filter
     * still has the last word, and can supply a number when the model could
     * not be asked.
     *
     * @param bool $force Ask a custom model again even after a recent failure (the card's buttons).
     * @return int|WP_Error
     */
    public static function expected_dimension_checked($force = false) {
        $options = get_option('mxchat_options', array());
        $options = is_array($options) ? $options : array();
        $custom  = isset($options['custom_provider_for_embeddings']) && $options['custom_provider_for_embeddings'] === 'on';
        $selected_model = $custom
            ? MxChat_Utils::get_selected_embedding_model($options)
            : ($options['embedding_model'] ?? 'text-embedding-ada-002');
        $dimension = MxChat_Utils::expected_embedding_dimension($options, $force);
        if (is_wp_error($dimension)) {
            $supplied = (int) apply_filters('mxchat_pinecone_expected_dimension', 0, $selected_model);
            return $supplied > 0 ? $supplied : $dimension;
        }
        if (!$custom && (strpos($selected_model, 'voyage-3-large') === 0 || strpos($selected_model, 'gemini-embedding') === 0)) {
            return (int) $dimension; // adjustable-size models: the owner's own setting, never filtered (as before)
        }
        return (int) apply_filters('mxchat_pinecone_expected_dimension', $dimension, $selected_model);
    }

    /** Plain-language refusal for the card when a custom model's size is unknown. (Plain __(): the card's JS text-escapes it.) */
    public static function dimension_unknown_message(WP_Error $error, $action) {
        $reason = trim($error->get_error_message());
        $tail = $action === 'create'
            ? __('The index was not created.', 'mxchat')
            : __('The index was not checked.', 'mxchat');
        return sprintf(
            /* translators: 1: the error the embedding endpoint returned, 2: what did not happen */
            __('Could not determine the dimension of your Custom Provider embedding model (%1$s). %2$s Check the Custom Provider embedding settings, then try again.', 'mxchat'),
            $reason !== '' ? $reason : __('no answer from the embedding endpoint', 'mxchat'),
            $tail
        );
    }

    // =====================================================================
    // Options (raw writes — the registered sanitizer rebuilds the array and
    // would flip mxchat_use_pinecone on any isset key; the autosave handler
    // writes this option the same raw way for the same reason)
    // =====================================================================

    public static function persist_options(array $changes) {
        global $wpdb;
        $raw = $wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", self::OPTION));
        $current = $raw === null ? array() : maybe_unserialize($raw);
        if (!is_array($current)) {
            $current = array();
        }
        foreach ($changes as $key => $value) {
            $current[$key] = $value;
        }
        $serialized = maybe_serialize($current);
        if ($raw === null) {
            $wpdb->insert($wpdb->options, array('option_name' => self::OPTION, 'option_value' => $serialized, 'autoload' => 'off'), array('%s', '%s', '%s'));
        } else {
            $wpdb->update($wpdb->options, array('option_value' => $serialized), array('option_name' => self::OPTION), array('%s'), array('%s'));
        }
        wp_cache_delete(self::OPTION, 'options');
        wp_cache_delete('alloptions', 'options');
        return $current;
    }

    // =====================================================================
    // Copy-migration (classic vector index → document index, no embedding calls)
    // =====================================================================

    public static function migration_get() {
        $state = get_transient(self::MIGRATION_KEY);
        return is_array($state) ? $state : array();
    }

    public static function migration_set(array $state) {
        $state['updated'] = time();
        set_transient(self::MIGRATION_KEY, $state, DAY_IN_SECONDS);
        return $state;
    }

    public static function migration_clear() {
        delete_transient(self::MIGRATION_KEY);
    }

    /**
     * 3e83e4: is there an unfinished copy INTO this host? The settings save
     * and the autosave refuse to switch the live chatbot onto a document index
     * while its copy is still running (unless the owner ticks Switch anyway),
     * so a production bot never answers from a half-filled index.
     *
     * @return array|null the running state, or null when nothing blocks
     */
    public static function migration_blocks_switch($host) {
        $host  = strtolower(trim(str_replace(array('https://', 'http://'), '', (string) $host), '/'));
        $state = self::migration_get();
        if ($host === '' || empty($state) || ($state['status'] ?? '') !== 'running') {
            return null;
        }
        if (strtolower((string) ($state['target_host'] ?? '')) !== $host) {
            return null;
        }
        return $state;
    }

    /** Plain-language line for the switch gate: "The copy into X is still running (481 of 4,503 records)…". */
    public static function switch_blocked_message(array $state) {
        return sprintf(
            /* translators: 1: document index name, 2: records copied, 3: records in total */
            __('The copy into "%1$s" is still running (%2$s of %3$s records). Let it finish before switching the chatbot to the document index, or tick Switch anyway to move it now and copy the rest afterwards.', 'mxchat'),
            (string) ($state['target_name'] ?: $state['target_host']),
            number_format_i18n((int) ($state['copied'] ?? 0)),
            number_format_i18n((int) ($state['total'] ?? 0))
        );
    }

    /**
     * Run one Pinecone call up to MIGRATE_RETRIES times, 1 s / 2 s / 4 s apart,
     * so a single dropped connection or a 5xx does not end the copy.
     *
     * @param callable $call returns array|true|WP_Error
     * @return array{result:mixed,attempts:int}
     */
    private static function with_retry(callable $call) {
        $result = null;
        $attempts = 0;
        for ($attempt = 1; $attempt <= self::MIGRATE_RETRIES; $attempt++) {
            $attempts = $attempt;
            $result = call_user_func($call);
            if (!is_wp_error($result)) {
                return array('result' => $result, 'attempts' => $attempts);
            }
            if ($attempt < self::MIGRATE_RETRIES) {
                sleep((int) pow(2, $attempt - 1));
            }
        }
        return array('result' => $result, 'attempts' => $attempts);
    }

    /** Seconds one AJAX step may spend copying pages (bounded by PHP's own limit). */
    private static function step_budget() {
        $budget = (float) apply_filters('mxchat_pinecone_migrate_step_seconds', self::MIGRATE_STEP_SECONDS);
        $limit  = (int) ini_get('max_execution_time');
        if ($limit > 0 && $limit - 8 < $budget) {
            $budget = max(3, $limit - 8);
        }
        return $budget;
    }

    /** Classic GET /vectors/list page. @return array{ids:array,next:string}|WP_Error */
    public static function classic_list_page($host, $api_key, $namespace, $limit, $token) {
        $params = array('limit' => max(1, min(100, (int) $limit)));
        if ((string) $namespace !== '') {
            $params['namespace'] = (string) $namespace;
        }
        if ((string) $token !== '') {
            $params['paginationToken'] = (string) $token;
        }
        $response = wp_remote_get('https://' . $host . '/vectors/list?' . http_build_query($params), array(
            'headers' => array('Api-Key' => $api_key, 'accept' => 'application/json'),
            'timeout' => 30,
        ));
        if (is_wp_error($response)) {
            return new WP_Error('pinecone_api', $response->get_error_message());
        }
        $code = (int) wp_remote_retrieve_response_code($response);
        $data = json_decode((string) wp_remote_retrieve_body($response), true);
        if ($code !== 200 || !is_array($data)) {
            return new WP_Error('pinecone_api', 'Source index list failed (HTTP ' . $code . '): ' . substr((string) wp_remote_retrieve_body($response), 0, 200));
        }
        $ids = array();
        foreach (($data['vectors'] ?? array()) as $vector) {
            if (isset($vector['id'])) {
                $ids[] = (string) $vector['id'];
            }
        }
        return array('ids' => $ids, 'next' => (string) ($data['pagination']['next'] ?? ''));
    }

    /** Classic GET /vectors/fetch (values + metadata). @return array|WP_Error id => vector */
    public static function classic_fetch($host, $api_key, $namespace, array $ids) {
        $vectors = array();
        foreach (array_chunk(array_values($ids), 100) as $chunk) {
            $query = array();
            foreach ($chunk as $id) {
                $query[] = 'ids=' . rawurlencode($id);
            }
            if ((string) $namespace !== '') {
                $query[] = 'namespace=' . rawurlencode($namespace);
            }
            $response = wp_remote_get('https://' . $host . '/vectors/fetch?' . implode('&', $query), array(
                'headers' => array('Api-Key' => $api_key, 'accept' => 'application/json'),
                'timeout' => 60,
            ));
            if (is_wp_error($response)) {
                return new WP_Error('pinecone_api', $response->get_error_message());
            }
            $code = (int) wp_remote_retrieve_response_code($response);
            $data = json_decode((string) wp_remote_retrieve_body($response), true);
            if ($code !== 200 || !is_array($data)) {
                return new WP_Error('pinecone_api', 'Source index fetch failed (HTTP ' . $code . ')');
            }
            if (!empty($data['vectors']) && is_array($data['vectors'])) {
                $vectors += $data['vectors'];
            }
        }
        return $vectors;
    }

    /** Classic POST /describe_index_stats (no version header). @return array|WP_Error */
    public static function classic_stats($host, $api_key) {
        $response = wp_remote_post('https://' . $host . '/describe_index_stats', array(
            'headers' => array('Api-Key' => $api_key, 'Content-Type' => 'application/json'),
            'body'    => '{}',
            'timeout' => 15,
        ));
        if (is_wp_error($response)) {
            return new WP_Error('pinecone_api', $response->get_error_message());
        }
        $code = (int) wp_remote_retrieve_response_code($response);
        $data = json_decode((string) wp_remote_retrieve_body($response), true);
        if ($code !== 200 || !is_array($data)) {
            return new WP_Error('pinecone_api', 'Source index stats failed (HTTP ' . $code . '): ' . substr((string) wp_remote_retrieve_body($response), 0, 200));
        }
        return $data;
    }

    /** Display key of a namespace in the per-namespace map — Pinecone's console names the default one __default__. */
    public static function ns_key($namespace) {
        return (string) $namespace === '' ? '__default__' : (string) $namespace;
    }

    /** Raw namespace for a display key (the default namespace is '' on the wire). */
    public static function ns_raw($key) {
        return (string) $key === '__default__' ? '' : (string) $key;
    }

    /** Classic describe_index_stats → [raw namespace => record count], the default namespace as ''. */
    private static function namespace_counts(array $stats) {
        $counts = array();
        foreach ((array) ($stats['namespaces'] ?? array()) as $ns_name => $ns_info) {
            $counts[self::ns_raw($ns_name)] = (int) ($ns_info['vectorCount'] ?? $ns_info['recordCount'] ?? 0);
        }
        return $counts;
    }

    /**
     * e50d2e: the order namespaces are copied in when every namespace is
     * asked for — the default namespace first, then the rest by name. Only
     * namespaces that hold records are queued.
     */
    private static function namespace_queue(array $counts) {
        $queue = array();
        if (!empty($counts[''])) {
            $queue[] = '';
        }
        $named = array();
        foreach ($counts as $ns => $count) {
            if ($ns !== '' && $count > 0) {
                $named[] = (string) $ns;
            }
        }
        sort($named, SORT_STRING);
        return array_merge($queue, $named);
    }

    private static function new_namespace_entry($namespace, $total, $status = 'queued') {
        return array('namespace' => (string) $namespace, 'total' => (int) $total, 'copied' => 0, 'status' => $status, 'target_count' => null);
    }

    /**
     * e50d2e: the per-namespace map of a migration state — display key →
     * namespace / total / copied / status / target_count. A state written
     * before this build copied one namespace and carries no map, so one is
     * synthesised from its single namespace; the Delete guard and the step
     * loop read every state through here.
     */
    public static function per_namespace_map(array $state) {
        if (!empty($state['per_namespace']) && is_array($state['per_namespace'])) {
            return $state['per_namespace'];
        }
        $namespace = (string) ($state['namespace'] ?? '');
        $status = ($state['status'] ?? '') === 'running' ? 'running' : (in_array($state['status'] ?? '', array('done', 'source_deleted'), true) ? 'done' : 'queued');
        $entry = self::new_namespace_entry($namespace, (int) ($state['total'] ?? 0), $status);
        $entry['copied'] = (int) ($state['copied'] ?? 0);
        $entry['target_count'] = isset($state['target_count']) ? $state['target_count'] : null;
        return array(self::ns_key($namespace) => $entry);
    }

    /**
     * e50d2e: widen a single-namespace copy — running or finished — to every
     * namespace of the source that holds records. Namespaces already copied
     * keep their entry, the rest queue behind the current one, the total
     * becomes the sum, and "other namespaces" empties because none are left
     * out any more. A finished copy with nothing left to queue stays done.
     *
     * @return array|WP_Error state
     */
    private static function extend_to_all_namespaces(array $state, $api_key) {
        $stats = self::classic_stats((string) ($state['source_host'] ?? ''), $api_key);
        if (is_wp_error($stats)) {
            return $stats;
        }
        $ns_counts = self::namespace_counts($stats);
        $map       = self::per_namespace_map($state);
        $queue     = array_values((array) ($state['queue'] ?? array()));
        $current   = isset($state['current']) ? (string) $state['current'] : (string) ($state['namespace'] ?? '');
        foreach (self::namespace_queue($ns_counts) as $ns) {
            if (isset($map[self::ns_key($ns)]) || $ns === $current || in_array($ns, $queue, true)) {
                continue;
            }
            $map[self::ns_key($ns)] = self::new_namespace_entry($ns, $ns_counts[$ns]);
            $queue[] = $ns;
        }
        $total = 0;
        foreach ($map as $entry) {
            $total += (int) ($entry['total'] ?? 0);
        }
        $state['per_namespace']    = $map;
        $state['queue']            = $queue;
        $state['current']          = $current;
        $state['namespace']        = $current;
        $state['total']            = $total;
        $state['total_all']        = (int) ($stats['totalVectorCount'] ?? $total);
        $state['other_namespaces'] = array();
        $state['all_namespaces']   = true;
        if (($state['status'] ?? '') === 'done' && !empty($queue)) {
            $next = array_shift($queue);
            $state['queue']     = $queue;
            $state['current']   = $next;
            $state['namespace'] = $next;
            $state['token']     = '';
            $state['status']    = 'running';
            $state['error']     = '';
            $state['error_at']  = 0;
            $state['per_namespace'][self::ns_key($next)]['status'] = 'running';
            unset($state['finished']);
        }
        return $state;
    }

    /**
     * Validate and start (or resume) a migration. @return array|WP_Error state
     *
     * e50d2e: $all_namespaces copies every namespace of the source that holds
     * records — the default namespace first, then the rest by name — each into
     * the same-named namespace of the document index, one after another, in
     * one run. Without it the copy is exactly 3e83e4's: the one configured
     * namespace. Asking for every namespace on a copy that is already running
     * or finished keeps what was copied and queues the rest.
     */
    public static function migrate_start($source_host, array $target_cfg, $restart = false, $all_namespaces = false) {
        $source_host = strtolower(trim(str_replace(array('https://', 'http://'), '', (string) $source_host), '/'));
        if ($source_host === '') {
            return new WP_Error('migrate', 'Enter the host of the vector index to copy from.');
        }
        if (empty($target_cfg['host']) || empty($target_cfg['api_key'])) {
            return new WP_Error('migrate', 'The document index host and API key must be saved first.');
        }
        if ($source_host === strtolower($target_cfg['host'])) {
            return new WP_Error('migrate', 'The source and the document index are the same host.');
        }
        $existing  = self::migration_get();
        $same_copy = !empty($existing) && ($existing['source_host'] ?? '') === $source_host && strtolower((string) ($existing['target_host'] ?? '')) === strtolower($target_cfg['host']);
        if (!$restart && $same_copy && ($existing['status'] ?? '') === 'running') {
            // Resume from the recorded cursor — whether the last step ended
            // cleanly or with an error (3e83e4: a second click continues,
            // it never starts over unless asked).
            $existing['error'] = '';
            if ($all_namespaces && empty($existing['all_namespaces'])) {
                $existing = self::extend_to_all_namespaces($existing, $target_cfg['api_key']);
                if (is_wp_error($existing)) {
                    return $existing;
                }
            }
            return self::migration_set($existing);
        }
        if (!$restart && $same_copy && $all_namespaces && ($existing['status'] ?? '') === 'done') {
            $extended = self::extend_to_all_namespaces($existing, $target_cfg['api_key']);
            if (is_wp_error($extended)) {
                return $extended;
            }
            return self::migration_set($extended);
        }
        $stats = self::classic_stats($source_host, $target_cfg['api_key']);
        if (is_wp_error($stats)) {
            return $stats;
        }
        $namespace = (string) ($target_cfg['namespace'] ?? '');
        // 3e83e4: the total is the SOURCE NAMESPACE's count, never the whole
        // index — the copy lists one namespace, so an index with records in
        // other namespaces used to report "done" with copied < total and no
        // explanation. The other namespaces are recorded for the UI instead.
        $ns_counts = self::namespace_counts($stats);
        $queue            = array();
        $per_namespace    = array();
        $other_namespaces = array();
        if ($all_namespaces) {
            $queue = self::namespace_queue($ns_counts);
            if (empty($queue)) {
                $queue = array($namespace); // stats listed no namespaces — copy the configured one, as before
            }
            $total = 0;
            foreach ($queue as $ns) {
                if (isset($ns_counts[$ns])) {
                    $count = (int) $ns_counts[$ns];
                } elseif ($ns === '' && empty($ns_counts)) {
                    $count = (int) ($stats['totalVectorCount'] ?? 0);
                } else {
                    $count = 0;
                }
                $per_namespace[self::ns_key($ns)] = self::new_namespace_entry($ns, $count);
                $total += $count;
            }
            $current = (string) array_shift($queue);
            $per_namespace[self::ns_key($current)]['status'] = 'running';
        } else {
            $current = $namespace;
            if (isset($ns_counts[$namespace])) {
                $total = (int) $ns_counts[$namespace];
            } elseif ($namespace === '' && empty($ns_counts)) {
                $total = (int) ($stats['totalVectorCount'] ?? 0);
            } else {
                $total = 0;
            }
            $per_namespace[self::ns_key($namespace)] = self::new_namespace_entry($namespace, $total, 'running');
            // 47c61f: in the copy order (default first, then by name). Pinecone's
            // stats object lists namespaces in a different order call to call.
            foreach (self::namespace_queue($ns_counts) as $ns_name) {
                if ((string) $ns_name !== (string) $namespace) {
                    $other_namespaces[self::ns_key($ns_name)] = (int) $ns_counts[$ns_name];
                }
            }
        }
        $source_dimension = (int) ($stats['dimension'] ?? 0);
        $target_index = self::find_index_by_host($target_cfg['host'], $target_cfg['api_key']);
        if (is_wp_error($target_index)) {
            return $target_index;
        }
        $schema = self::inspect_schema($target_index);
        if (!$schema['is_document']) {
            return new WP_Error('migrate', 'The configured host is not a document index. Run Check index first.');
        }
        if ($source_dimension > 0 && $schema['dimension'] > 0 && $source_dimension !== (int) $schema['dimension']) {
            return new WP_Error('migrate', sprintf('Dimensions differ (source %d, document index %d) — the vectors cannot be copied. Re-import your content into the document index instead.', $source_dimension, $schema['dimension']));
        }
        $state = array(
            'status'           => 'running',
            'source_host'      => $source_host,
            'target_host'      => $target_cfg['host'],
            'target_name'      => (string) ($target_index['name'] ?? ''),
            'namespace'        => $current,
            'total'            => $total,
            'total_all'        => (int) ($stats['totalVectorCount'] ?? $total),
            'other_namespaces' => $other_namespaces,
            // e50d2e: the namespace being copied, the ones still queued and
            // a per-namespace ledger — the Delete guard checks every entry.
            'all_namespaces'   => (bool) $all_namespaces,
            'current'          => $current,
            'queue'            => $queue,
            'per_namespace'    => $per_namespace,
            'copied'           => 0,
            'skipped'          => 0,
            'token'            => '',
            'batches'          => 0,
            'retries'          => 0,
            'failed_steps'     => 0,
            'started'          => time(),
            'error'            => '',
            'error_at'         => 0,
            'target_count'     => null,
            'source_name'      => '',
        );
        $source_index = self::find_index_by_host($source_host, $target_cfg['api_key']);
        if (!is_wp_error($source_index)) {
            $state['source_name'] = (string) ($source_index['name'] ?? '');
        }
        return self::migration_set($state);
    }

    /**
     * Copy pages until the step's time budget is used or the source is
     * exhausted: list → fetch (values + metadata) → documents/upsert, each
     * call retried with backoff. Idempotent — the same ids land in the same
     * place on a re-run. The cursor is persisted after EVERY page, so a step
     * that dies mid-way (proxy timeout, killed request) loses at most one
     * page of work and the next click continues from the recorded position.
     *
     * @return array|WP_Error state
     */
    public static function migrate_step(array $state, array $target_cfg, $batch = self::MIGRATE_BATCH) {
        if (($state['status'] ?? '') !== 'running') {
            return $state;
        }
        if (strtolower((string) ($state['target_host'] ?? '')) !== strtolower((string) ($target_cfg['host'] ?? ''))) {
            return new WP_Error('migrate', 'The document index host changed since the migration started. Start it again.');
        }
        $started = microtime(true);
        $budget  = self::step_budget();
        $pages   = 0;
        $api_key = $target_cfg['api_key'];
        foreach (array('retries', 'skipped', 'failed_steps', 'error_at') as $counter) {
            $state[$counter] = (int) ($state[$counter] ?? 0); // states written before 3e83e4 lack these
        }
        if (empty($state['per_namespace']) || !is_array($state['per_namespace'])) {
            // e50d2e: a state written before this build copied one namespace and has no ledger
            $state['per_namespace'] = self::per_namespace_map($state);
            $state['current']       = (string) ($state['namespace'] ?? '');
            $state['queue']         = array();
        }
        if (!isset($state['current'])) {
            $state['current'] = (string) ($state['namespace'] ?? '');
        }
        do {
            $source_host = $state['source_host'];
            $namespace   = (string) $state['current'];
            $ns_key      = self::ns_key($namespace);
            $token       = $state['token'];
            // e50d2e: the same namespace on the document side — every bot keeps reading its own
            $page_cfg    = $target_cfg;
            $page_cfg['namespace'] = $namespace;
            $listed = self::with_retry(function () use ($source_host, $api_key, $namespace, $batch, $token) {
                return self::classic_list_page($source_host, $api_key, $namespace, $batch, $token);
            });
            $state['retries'] += max(0, $listed['attempts'] - 1);
            $page = $listed['result'];
            if (is_wp_error($page)) {
                return self::migration_fail($state, 'Listing the source index failed after ' . $listed['attempts'] . ' attempts: ' . $page->get_error_message());
            }
            $ids = $page['ids'];
            if (!empty($ids)) {
                $fetched = self::with_retry(function () use ($source_host, $api_key, $namespace, $ids) {
                    return self::classic_fetch($source_host, $api_key, $namespace, $ids);
                });
                $state['retries'] += max(0, $fetched['attempts'] - 1);
                $vectors = $fetched['result'];
                if (is_wp_error($vectors)) {
                    return self::migration_fail($state, 'Reading ' . count($ids) . ' records from the source index failed after ' . $fetched['attempts'] . ' attempts: ' . $vectors->get_error_message());
                }
                $documents = array();
                $skipped = 0;
                foreach ($vectors as $id => $vector) {
                    if (empty($vector['values']) || !is_array($vector['values'])) {
                        $skipped++;
                        continue;
                    }
                    $metadata = is_array($vector['metadata'] ?? null) ? $vector['metadata'] : array();
                    $documents[] = self::build_document((string) ($vector['id'] ?? $id), (string) ($metadata['text'] ?? ''), $vector['values'], $metadata);
                }
                $skipped += max(0, count($ids) - count($vectors));
                if (!empty($documents)) {
                    $upserted = self::with_retry(function () use ($documents, $page_cfg) {
                        return self::upsert($documents, $page_cfg);
                    });
                    $state['retries'] += max(0, $upserted['attempts'] - 1);
                    if (is_wp_error($upserted['result'])) {
                        return self::migration_fail($state, 'Writing ' . count($documents) . ' records to the document index failed after ' . $upserted['attempts'] . ' attempts: ' . $upserted['result']->get_error_message());
                    }
                }
                $state['copied']  = (int) $state['copied'] + count($documents);
                $state['skipped'] = (int) ($state['skipped'] ?? 0) + $skipped;
                $state['per_namespace'][$ns_key]['copied'] = (int) ($state['per_namespace'][$ns_key]['copied'] ?? 0) + count($documents);
            }
            $state['batches'] = (int) $state['batches'] + 1;
            $state['token']   = $page['next'];
            $state['error']   = '';
            $state['error_at'] = 0;
            $pages++;
            if ($page['next'] === '') {
                // This namespace is copied: record what the document index
                // reports for it, then move on to the next queued namespace
                // inside the same time budget (e50d2e), or finish.
                $state['per_namespace'][$ns_key]['status']   = 'done';
                $state['per_namespace'][$ns_key]['finished'] = time();
                $ns_target = self::namespace_count($page_cfg);
                $state['per_namespace'][$ns_key]['target_count'] = $ns_target > 0 ? $ns_target : null;
                $queue = array_values((array) ($state['queue'] ?? array()));
                if (!empty($queue)) {
                    $next = (string) array_shift($queue);
                    $state['queue']     = $queue;
                    $state['current']   = $next;
                    $state['namespace'] = $next;
                    $state['token']     = '';
                    if (!isset($state['per_namespace'][self::ns_key($next)])) {
                        $state['per_namespace'][self::ns_key($next)] = self::new_namespace_entry($next, 0);
                    }
                    $state['per_namespace'][self::ns_key($next)]['status'] = 'running';
                    self::migration_set($state);
                    continue; // re-checks the budget, then lists the next namespace from its first page
                }
                $state['status']   = 'done';
                $state['finished'] = time();
                // The finished state names what both sides report, so the UI
                // can say "5,000 copied; the document index holds 5,000".
                $target_count = 0;
                foreach ($state['per_namespace'] as $entry) {
                    $target_count += (int) ($entry['target_count'] ?? 0);
                }
                $state['target_count'] = $target_count > 0 ? $target_count : null;
                $state['target_count_at'] = time();
                break;
            }
            self::migration_set($state); // cursor persisted per page, not per step
        } while ((microtime(true) - $started) < $budget);
        $state['last_step_pages']   = $pages;
        $state['last_step_seconds'] = round(microtime(true) - $started, 2);
        return self::migration_set($state);
    }

    /** Record count of ONE namespace on a document index (0 when not listed) — count() reads the whole index for the default namespace. */
    private static function namespace_count(array $cfg) {
        $stats = self::stats($cfg);
        if (is_wp_error($stats)) {
            return 0;
        }
        $ns = (string) ($cfg['namespace'] ?? '');
        return isset($stats['namespaces'][$ns]['vectorCount']) ? (int) $stats['namespaces'][$ns]['vectorCount'] : 0;
    }

    /**
     * e50d2e: namespaces of the OLD index that still hold records without a
     * finished, count-matching copy — read from the live source stats, so a
     * namespace filled after the copy counts too. Empty array = every record
     * on the old index has a copy; the Delete old index button refuses on
     * anything else and names what is missing.
     *
     * @return array|WP_Error display key → ['namespace','source','copied','status']
     */
    public static function uncopied_namespaces(array $state, $api_key) {
        $stats = self::classic_stats((string) ($state['source_host'] ?? ''), $api_key);
        if (is_wp_error($stats)) {
            return $stats;
        }
        $counts = self::namespace_counts($stats);
        if (empty($counts) && (int) ($stats['totalVectorCount'] ?? 0) > 0) {
            $counts[''] = (int) $stats['totalVectorCount'];
        }
        $map     = self::per_namespace_map($state);
        $missing = array();
        foreach ($counts as $ns => $count) {
            if ($count <= 0) {
                continue;
            }
            $key   = self::ns_key($ns);
            $entry = isset($map[$key]) && is_array($map[$key]) ? $map[$key] : null;
            if ($entry === null || ($entry['status'] ?? '') !== 'done' || (int) ($entry['copied'] ?? 0) < $count) {
                $missing[$key] = array(
                    'namespace' => (string) $ns,
                    'source'    => (int) $count,
                    'copied'    => $entry ? (int) ($entry['copied'] ?? 0) : 0,
                    'status'    => $entry ? (string) ($entry['status'] ?? '') : 'not copied',
                );
            }
        }
        // Named in the copy order (default first, then by name) — Pinecone's stats object order varies call to call.
        $ordered = array();
        foreach (self::namespace_queue(array_combine(array_map(array(__CLASS__, 'ns_raw'), array_keys($missing)), array_column($missing, 'source')) ?: array()) as $ns) {
            $ordered[self::ns_key($ns)] = $missing[self::ns_key($ns)];
        }
        return $ordered + $missing;
    }

    /** Plain-language refusal naming every namespace the copy is missing. (Plain __(): the card's JS text-escapes it.) */
    public static function uncopied_message(array $missing) {
        $parts = array();
        foreach ($missing as $key => $m) {
            $parts[] = sprintf(
                /* translators: 1: namespace name, 2: records copied, 3: records on the old index */
                __('%1$s (%2$s of %3$s records copied)', 'mxchat'),
                $key,
                number_format_i18n((int) $m['copied']),
                number_format_i18n((int) $m['source'])
            );
        }
        return sprintf(
            /* translators: %s: comma-separated namespace names with their counts */
            _n(
                'Namespace %s on the old index has records that are not in the document index. Tick Copy every namespace and run Migrate again, then delete. The old index stays.',
                'Namespaces %s on the old index have records that are not in the document index. Tick Copy every namespace and run Migrate again, then delete. The old index stays.',
                count($missing),
                'mxchat'
            ),
            implode(', ', $parts)
        );
    }

    /**
     * 5872ef: bots that still keep the given Pinecone host as their own (the
     * Multi-Bot add-on stores a host per bot). Core knows nothing about the
     * bots table — the add-on answers the filter, and with it inactive the
     * list is empty. Delete old index refuses while this is not empty.
     *
     * @return array bot id → bot name
     */
    public static function bots_on_host($host) {
        $host = strtolower(trim(str_replace(array('https://', 'http://'), '', (string) $host), '/'));
        if ($host === '') {
            return array();
        }
        $bots = apply_filters('mxchat_pinecone_bots_on_host', array(), $host);
        if (!is_array($bots)) {
            return array();
        }
        $named = array();
        foreach ($bots as $bot_id => $name) {
            $name = is_scalar($name) ? trim((string) $name) : '';
            $named[(string) $bot_id] = $name !== '' ? $name : (string) $bot_id;
        }
        return $named;
    }

    /** Plain-language refusal naming every bot still on the old index. (Plain __(): the card's JS text-escapes it.) */
    public static function bots_on_host_message(array $bots) {
        return sprintf(
            /* translators: 1: number of bots, 2: comma-separated bot names */
            _n(
                '%1$s bot still uses the old index: %2$s. Move it to the new index first (Multi-Bot, All Bots, Move these bots to the new index), or it will stop answering. The old index stays.',
                '%1$s bots still use the old index: %2$s. Move them to the new index first (Multi-Bot, All Bots, Move these bots to the new index), or they will stop answering. The old index stays.',
                count($bots),
                'mxchat'
            ),
            number_format_i18n(count($bots)),
            implode(', ', $bots)
        );
    }

    /** Record a step failure without ending the copy: status stays running, the cursor stays put. */
    private static function migration_fail(array $state, $message) {
        $state['error']        = (string) $message;
        $state['error_at']     = time();
        $state['failed_steps'] = (int) ($state['failed_steps'] ?? 0) + 1;
        return self::migration_set($state);
    }

    // =====================================================================
    // AJAX (Knowledge → Pinecone card)
    // =====================================================================

    public static function boot() {
        add_action('wp_ajax_mxchat_pinecone_docs_create_index', array(__CLASS__, 'ajax_create_index'));
        add_action('wp_ajax_mxchat_pinecone_docs_check_index', array(__CLASS__, 'ajax_check_index'));
        add_action('wp_ajax_mxchat_pinecone_docs_suggest_name', array(__CLASS__, 'ajax_suggest_name'));
        add_action('wp_ajax_mxchat_pinecone_docs_migrate', array(__CLASS__, 'ajax_migrate'));
        add_action('wp_ajax_mxchat_pinecone_docs_delete_old_index', array(__CLASS__, 'ajax_delete_old_index'));
    }

    /**
     * Suggest a free document-index name for the name in the field (3e83e4).
     * With a host that already answers in the project, the name is that
     * index's own — the owner is pointing at an existing document index, not
     * about to create one.
     */
    public static function ajax_suggest_name() {
        self::ajax_guard();
        $api_key = self::posted_api_key();
        $base    = isset($_POST['base']) ? sanitize_text_field(wp_unslash($_POST['base'])) : '';
        $host    = isset($_POST['host']) ? sanitize_text_field(wp_unslash($_POST['host'])) : '';
        if ($api_key === '') {
            wp_send_json_error(array('message' => esc_html__('Enter your Pinecone API key first.', 'mxchat')));
        }
        if ($host !== '') {
            $index = self::find_index_by_host($host, $api_key);
            if (!is_wp_error($index) && !empty($index['name'])) {
                wp_send_json_success(array('name' => strtolower((string) $index['name']), 'resolved' => true, 'taken' => false, 'base' => strtolower($base)));
            }
        }
        $suggest = self::suggest_index_name($base, $api_key);
        wp_send_json_success(array('name' => $suggest['name'], 'resolved' => false, 'taken' => $suggest['taken'], 'base' => strtolower($base)));
    }

    /** The document-index target for a migration call: the host in the form, else the saved one. */
    private static function posted_target_host(array $options) {
        $posted = isset($_POST['target_host']) ? sanitize_text_field(wp_unslash($_POST['target_host'])) : '';
        $posted = strtolower(trim(str_replace(array('https://', 'http://'), '', $posted), '/'));
        if ($posted !== '') {
            return $posted;
        }
        return strtolower(trim((string) ($options['mxchat_pinecone_host'] ?? ''), '/'));
    }

    private static function ajax_guard() {
        check_ajax_referer('mxchat_prompts_setting_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => esc_html__('Permission denied.', 'mxchat')), 403);
        }
    }

    private static function posted_api_key() {
        $posted = isset($_POST['api_key']) ? sanitize_text_field(wp_unslash($_POST['api_key'])) : '';
        if ($posted !== '') {
            return $posted;
        }
        $options = get_option(self::OPTION, array());
        return is_array($options) ? (string) ($options['mxchat_pinecone_api_key'] ?? '') : '';
    }

    /**
     * Create index for me: control-plane create with the active embedding
     * dimension, then fill in (and persist) the host.
     */
    public static function ajax_create_index() {
        self::ajax_guard();
        $api_key  = self::posted_api_key();
        $name     = isset($_POST['index_name']) ? sanitize_text_field(wp_unslash($_POST['index_name'])) : '';
        $cloud    = isset($_POST['cloud']) ? sanitize_key($_POST['cloud']) : 'aws';
        $region   = isset($_POST['region']) ? sanitize_text_field(wp_unslash($_POST['region'])) : 'us-east-1';
        $language = isset($_POST['language']) ? sanitize_key($_POST['language']) : 'en';
        if ($api_key === '') {
            wp_send_json_error(array('message' => esc_html__('Enter your Pinecone API key first.', 'mxchat')));
        }
        if ($name === '') {
            wp_send_json_error(array('message' => esc_html__('Enter an index name first — the new document index will be created under that name.', 'mxchat')));
        }
        $dimension = self::expected_dimension_checked(true);
        if (is_wp_error($dimension)) {
            // 418afd: never size an index from a guess
            wp_send_json_error(array('message' => self::dimension_unknown_message($dimension, 'create'), 'code' => 'dimension_unknown'));
        }
        $index = self::create_document_index($name, $dimension, $cloud, $region, $language, $api_key);
        if (is_wp_error($index)) {
            $data = $index->get_error_data();
            wp_send_json_error(array(
                'message'   => $index->get_error_message(),
                'code'      => $index->get_error_code(),
                'suggested' => is_array($data) ? (string) ($data['suggested'] ?? '') : '',
            ));
        }
        $host = (string) ($index['host'] ?? '');
        // Give Pinecone a few seconds — small serverless indexes are usually Ready quickly.
        $ready = !empty($index['status']['ready']);
        for ($i = 0; !$ready && $i < 5; $i++) {
            sleep(2);
            $described = self::describe_index($name, $api_key);
            if (!is_wp_error($described)) {
                $ready = !empty($described['status']['ready']);
                $host  = (string) ($described['host'] ?? $host);
            }
        }
        $options = get_option(self::OPTION, array());
        $options = is_array($options) ? $options : array();
        // 3e83e4: creating the index no longer moves the live chatbot. The
        // host and index type are only written when the owner saves the
        // form; here we record that this host passed the check (the save
        // gate) and the settings the index was created with.
        $changes = array(
            'mxchat_pinecone_docs_verified_host' => $host,
            'mxchat_pinecone_docs_cloud'         => array_key_exists($cloud, self::clouds()) ? $cloud : 'aws',
            'mxchat_pinecone_docs_region'        => $region ?: 'us-east-1',
            'mxchat_pinecone_docs_language'      => array_key_exists($language, self::languages()) ? $language : 'en',
        );
        if (empty($options['mxchat_pinecone_api_key'])) {
            $changes['mxchat_pinecone_api_key'] = $api_key;
        }
        // Remember the classic index so Migrate can copy from it.
        $previous_host = (string) ($options['mxchat_pinecone_host'] ?? '');
        if ($previous_host !== '' && strtolower($previous_host) !== strtolower($host) && self::options_index_type($options) === 'vector' && empty($options['mxchat_pinecone_vector_host'])) {
            $changes['mxchat_pinecone_vector_host']  = $previous_host;
            $changes['mxchat_pinecone_vector_index'] = (string) ($options['mxchat_pinecone_index'] ?? '');
        }
        self::persist_options($changes);
        wp_send_json_success(array(
            'host'      => $host,
            'name'      => strtolower($name),
            'dimension' => $dimension,
            'ready'     => $ready,
            'vector_host' => $changes['mxchat_pinecone_vector_host'] ?? ($options['mxchat_pinecone_vector_host'] ?? ''),
            // Plain text: the card's JS escapes it on render (esc_html__ here double-escaped the quotes to a literal &quot;).
            'message'   => $ready
                ? sprintf(__('Document index "%1$s" created (%2$d dimensions, cosine, full-text search on the chunk text). The host is filled in above. Your chatbot keeps answering from the current index until you save the Pinecone settings — copy your records across with Migrate first.', 'mxchat'), strtolower($name), $dimension)
                : sprintf(__('Document index "%1$s" is being created (%2$d dimensions). The host is filled in above; run Check index in a minute to confirm it is ready. Your chatbot keeps answering from the current index until you save the Pinecone settings.', 'mxchat'), strtolower($name), $dimension),
        ));
    }

    /** Check index: assert the host is a document index MxChat can use. */
    public static function ajax_check_index() {
        self::ajax_guard();
        $api_key = self::posted_api_key();
        $host    = isset($_POST['host']) ? sanitize_text_field(wp_unslash($_POST['host'])) : '';
        $host    = trim(str_replace(array('https://', 'http://'), '', $host), '/');
        if ($api_key === '' || $host === '') {
            wp_send_json_error(array('message' => esc_html__('Enter the Pinecone API key and host first.', 'mxchat')));
        }
        $dimension = self::expected_dimension_checked(true);
        if (is_wp_error($dimension)) {
            wp_send_json_error(array('ok' => false, 'message' => self::dimension_unknown_message($dimension, 'check'), 'code' => 'dimension_unknown'));
        }
        $check = self::check_document_index($host, $api_key, $dimension);
        if (!$check['ok']) {
            wp_send_json_error($check);
        }
        $options = get_option(self::OPTION, array());
        $options = is_array($options) ? $options : array();
        $changes = array(
            'mxchat_pinecone_docs_verified_host' => $check['host'],
            'mxchat_pinecone_index'              => $check['name'],
        );
        $previous_host = (string) ($options['mxchat_pinecone_host'] ?? '');
        if ($previous_host !== '' && strtolower($previous_host) !== strtolower($check['host']) && self::options_index_type($options) === 'vector' && empty($options['mxchat_pinecone_vector_host'])) {
            $changes['mxchat_pinecone_vector_host']  = $previous_host;
            $changes['mxchat_pinecone_vector_index'] = (string) ($options['mxchat_pinecone_index'] ?? '');
        }
        self::persist_options($changes);
        $check['vector_host'] = $changes['mxchat_pinecone_vector_host'] ?? ($options['mxchat_pinecone_vector_host'] ?? '');
        wp_send_json_success($check);
    }

    /**
     * Migrate: mode start | step | status | reset.
     *
     * 3e83e4: the copy is DECOUPLED from the live retrieval path. The target
     * is the document-index host in the form (posted as target_host) plus the
     * API key, checked against Pinecone at start — the saved index type is
     * never consulted, so the site keeps answering from its vector index for
     * the whole copy and the owner switches by saving the settings afterwards.
     * Each step copies pages for up to MIGRATE_STEP_SECONDS.
     */
    public static function ajax_migrate() {
        self::ajax_guard();
        $mode = isset($_POST['mode']) ? sanitize_key($_POST['mode']) : 'status';
        $options = get_option(self::OPTION, array());
        $options = is_array($options) ? $options : array();
        if ($mode === 'reset') {
            self::migration_clear();
            wp_send_json_success(array('state' => array()));
        }
        if ($mode === 'status') {
            wp_send_json_success(array('state' => self::migration_get()));
        }
        $api_key   = self::posted_api_key();
        $namespace = (string) ($options['mxchat_pinecone_namespace'] ?? '');
        if ($api_key === '') {
            wp_send_json_error(array('message' => esc_html__('Enter your Pinecone API key first.', 'mxchat')));
        }
        if ($mode === 'start') {
            $target_host = self::posted_target_host($options);
            if ($target_host === '') {
                wp_send_json_error(array('message' => esc_html__('Enter the host of the document index to copy into (or create one with Create index for me).', 'mxchat')));
            }
            $dimension = self::expected_dimension_checked(true);
            if (is_wp_error($dimension)) {
                wp_send_json_error(array('message' => self::dimension_unknown_message($dimension, 'check'), 'code' => 'dimension_unknown'));
            }
            $check = self::check_document_index($target_host, $api_key, $dimension);
            if (!$check['ok']) {
                wp_send_json_error(array('message' => $check['message']));
            }
            $target = array('api_key' => $api_key, 'host' => strtolower($check['host']), 'namespace' => $namespace, 'index_type' => 'document');
            $source_host = isset($_POST['source_host']) ? sanitize_text_field(wp_unslash($_POST['source_host'])) : '';
            $restart     = !empty($_POST['restart']);
            $all_ns      = !empty($_POST['all_namespaces']); // e50d2e: Copy every namespace
            $state = self::migrate_start($source_host, $target, $restart, $all_ns);
            if (is_wp_error($state)) {
                wp_send_json_error(array('message' => $state->get_error_message()));
            }
            $changes = array('mxchat_pinecone_docs_verified_host' => $target['host']);
            if ($source_host !== '' && strtolower($state['source_host']) !== strtolower((string) ($options['mxchat_pinecone_vector_host'] ?? ''))) {
                $changes['mxchat_pinecone_vector_host']  = $state['source_host'];
                $changes['mxchat_pinecone_vector_index'] = $state['source_name'];
            }
            self::persist_options($changes);
            wp_send_json_success(array('state' => $state, 'live_index_type' => self::options_index_type($options)));
        }
        if ($mode === 'step') {
            $state = self::migration_get();
            if (empty($state)) {
                wp_send_json_error(array('message' => esc_html__('No migration in progress. Click Migrate to start one.', 'mxchat')));
            }
            $target = array('api_key' => $api_key, 'host' => (string) ($state['target_host'] ?? ''), 'namespace' => (string) ($state['current'] ?? ($state['namespace'] ?? $namespace)), 'index_type' => 'document');
            $state = self::migrate_step($state, $target);
            if (is_wp_error($state)) {
                wp_send_json_error(array('message' => $state->get_error_message()));
            }
            if (!empty($state['error'])) {
                wp_send_json_error(array('message' => $state['error'], 'state' => $state));
            }
            wp_send_json_success(array('state' => $state, 'live_index_type' => self::options_index_type($options)));
        }
        wp_send_json_error(array('message' => esc_html__('Unknown migration action.', 'mxchat')));
    }

    /**
     * Delete old index — only after a finished copy whose count matches, only
     * with the index name typed back, never the configured document index.
     */
    public static function ajax_delete_old_index() {
        self::ajax_guard();
        $confirm = isset($_POST['confirm_name']) ? strtolower(sanitize_text_field(wp_unslash($_POST['confirm_name']))) : '';
        $options = get_option(self::OPTION, array());
        $options = is_array($options) ? $options : array();
        $target  = self::cfg_from_options($options);
        $state   = self::migration_get();
        if (empty($state) || ($state['status'] ?? '') !== 'done') {
            wp_send_json_error(array('message' => esc_html__('Finish the migration first — the old index can only be deleted after a completed copy.', 'mxchat')));
        }
        if ((int) $state['copied'] < (int) $state['total'] || (int) $state['total'] === 0) {
            wp_send_json_error(array('message' => sprintf(esc_html__('The copy count does not match (%1$d of %2$d copied). The old index stays.', 'mxchat'), (int) $state['copied'], (int) $state['total'])));
        }
        if ($target['index_type'] !== 'document' || strtolower($target['host']) !== strtolower((string) $state['target_host'])) {
            wp_send_json_error(array('message' => esc_html__('The saved document index no longer matches the migration. The old index stays.', 'mxchat')));
        }
        // e50d2e: EVERY namespace that holds records on the old index needs a
        // finished copy whose count matches — not just the one copied last.
        // Before this, a Multi-Bot or legacy-namespace site could delete
        // records that were never copied.
        $missing = self::uncopied_namespaces($state, $target['api_key']);
        // 5872ef: a bot that still keeps the old index as its own host stops
        // answering the moment that index is deleted, however complete the
        // copy is. Refuse and name the bots; when namespaces are uncopied too,
        // say both in one answer.
        $bots = self::bots_on_host((string) ($state['source_host'] ?? ''));
        if (!empty($bots)) {
            $refusal = array('message' => self::bots_on_host_message($bots), 'bots' => $bots);
            if (is_array($missing) && !empty($missing)) {
                $refusal['message'] .= ' ' . self::uncopied_message($missing);
                $refusal['uncopied'] = $missing;
            }
            wp_send_json_error($refusal);
        }
        if (is_wp_error($missing)) {
            wp_send_json_error(array('message' => sprintf(__('Could not read the old index before deleting it (%s). The old index stays.', 'mxchat'), $missing->get_error_message())));
        }
        if (!empty($missing)) {
            wp_send_json_error(array('message' => self::uncopied_message($missing), 'uncopied' => $missing));
        }
        $source = self::find_index_by_host($state['source_host'], $target['api_key']);
        if (is_wp_error($source)) {
            wp_send_json_error(array('message' => $source->get_error_message()));
        }
        $source_name = strtolower((string) ($source['name'] ?? ''));
        if ($source_name === '' || $confirm !== $source_name) {
            wp_send_json_error(array('message' => sprintf(esc_html__('Type the old index name exactly (%s) to confirm.', 'mxchat'), $source_name)));
        }
        if (strtolower((string) ($source['host'] ?? '')) === strtolower($target['host']) || $source_name === strtolower((string) ($state['target_name'] ?? ''))) {
            wp_send_json_error(array('message' => esc_html__('Refusing: that is the document index currently in use.', 'mxchat')));
        }
        $result = self::delete_index($source_name, $target['api_key']);
        if (is_wp_error($result)) {
            wp_send_json_error(array('message' => $result->get_error_message()));
        }
        self::persist_options(array('mxchat_pinecone_vector_host' => '', 'mxchat_pinecone_vector_index' => ''));
        $state['status'] = 'source_deleted';
        self::migration_set($state);
        wp_send_json_success(array('message' => sprintf(esc_html__('Old vector index "%s" deleted.', 'mxchat'), $source_name), 'state' => $state));
    }
}

MxChat_Pinecone_Documents::boot();
