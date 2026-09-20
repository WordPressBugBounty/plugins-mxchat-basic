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
    const MIGRATION_KEY      = 'mxchat_pinecone_docs_migration';
    const UPSERT_BATCH       = 40;   // 1536-dim vectors are ~30 KB of JSON each; the request cap is 2 MB
    const MIGRATE_BATCH      = 40;
    const OPTION             = 'mxchat_pinecone_addon_options';

    /** Fields the retrieval and listing paths ask for (never the vector unless needed). */
    const RECORD_FIELDS = array('text', 'source_url', 'type', 'bot_id', 'is_chunked', 'chunk_index', 'total_chunks', 'parent_url_hash', 'role_restriction', 'created_at', 'last_updated');

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
     */
    public static function keyword_filter(array $tokens) {
        $clauses = array();
        foreach ($tokens as $token) {
            $op = preg_match('/[.\-]/', $token) ? '$match_phrase' : '$match_any';
            $clauses[] = array(self::TEXT_FIELD => array($op => $token));
        }
        if (empty($clauses)) {
            return null;
        }
        return count($clauses) === 1 ? $clauses[0] : array('$or' => $clauses);
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
     * Create a document index for the active embedding dimension.
     * @return array|WP_Error the IndexModel
     */
    public static function create_document_index($name, $dimension, $cloud, $region, $language, $api_key) {
        $name = strtolower(sanitize_text_field($name));
        if (!preg_match('/^[a-z0-9]([a-z0-9-]{0,43}[a-z0-9])?$/', $name)) {
            return new WP_Error('pinecone_index', 'Index names are 1-45 lowercase letters, digits and hyphens.');
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
        $options = get_option('mxchat_options', array());
        $selected_model = $options['embedding_model'] ?? 'text-embedding-ada-002';
        $model_dimensions = array(
            'text-embedding-ada-002' => 1536,
            'text-embedding-3-small' => 1536,
            'text-embedding-3-large' => 3072,
            'voyage-2'               => 1024,
            'voyage-large-2'         => 1536,
            'voyage-3-large'         => 2048,
            'gemini-embedding-001'   => 1536,
        );
        if (strpos($selected_model, 'voyage-3-large') === 0) {
            return intval($options['voyage_output_dimension'] ?? 2048);
        }
        if (strpos($selected_model, 'gemini-embedding') === 0) {
            return intval($options['gemini_output_dimension'] ?? 1536);
        }
        $dimension = apply_filters('mxchat_pinecone_expected_dimension', $model_dimensions[$selected_model] ?? 1536, $selected_model);
        return (int) $dimension;
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

    /**
     * Validate and start (or resume) a migration. @return array|WP_Error state
     */
    public static function migrate_start($source_host, array $target_cfg, $restart = false) {
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
        $existing = self::migration_get();
        if (!$restart && !empty($existing) && ($existing['source_host'] ?? '') === $source_host && ($existing['target_host'] ?? '') === $target_cfg['host'] && ($existing['status'] ?? '') === 'running') {
            return $existing; // resume
        }
        $stats = self::classic_stats($source_host, $target_cfg['api_key']);
        if (is_wp_error($stats)) {
            return $stats;
        }
        $namespace = (string) ($target_cfg['namespace'] ?? '');
        if ($namespace !== '') {
            $total = isset($stats['namespaces'][$namespace]['vectorCount']) ? (int) $stats['namespaces'][$namespace]['vectorCount'] : 0;
        } else {
            $total = (int) ($stats['totalVectorCount'] ?? 0);
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
            'status'       => 'running',
            'source_host'  => $source_host,
            'target_host'  => $target_cfg['host'],
            'target_name'  => (string) ($target_index['name'] ?? ''),
            'namespace'    => $namespace,
            'total'        => $total,
            'copied'       => 0,
            'token'        => '',
            'batches'      => 0,
            'started'      => time(),
            'error'        => '',
            'source_name'  => '',
        );
        $source_index = self::find_index_by_host($source_host, $target_cfg['api_key']);
        if (!is_wp_error($source_index)) {
            $state['source_name'] = (string) ($source_index['name'] ?? '');
        }
        return self::migration_set($state);
    }

    /**
     * Copy one page: list → fetch (values + metadata) → documents/upsert.
     * Idempotent — the same ids land in the same place on a re-run.
     * @return array|WP_Error state
     */
    public static function migrate_step(array $state, array $target_cfg, $batch = self::MIGRATE_BATCH) {
        if (($state['status'] ?? '') !== 'running') {
            return $state;
        }
        if (($state['target_host'] ?? '') !== ($target_cfg['host'] ?? '')) {
            return new WP_Error('migrate', 'The document index host changed since the migration started. Start it again.');
        }
        $page = self::classic_list_page($state['source_host'], $target_cfg['api_key'], $state['namespace'], $batch, $state['token']);
        if (is_wp_error($page)) {
            $state['error'] = $page->get_error_message();
            return self::migration_set($state);
        }
        if (!empty($page['ids'])) {
            $vectors = self::classic_fetch($state['source_host'], $target_cfg['api_key'], $state['namespace'], $page['ids']);
            if (is_wp_error($vectors)) {
                $state['error'] = $vectors->get_error_message();
                return self::migration_set($state);
            }
            $documents = array();
            foreach ($vectors as $id => $vector) {
                if (empty($vector['values']) || !is_array($vector['values'])) {
                    continue;
                }
                $metadata = is_array($vector['metadata'] ?? null) ? $vector['metadata'] : array();
                $documents[] = self::build_document((string) ($vector['id'] ?? $id), (string) ($metadata['text'] ?? ''), $vector['values'], $metadata);
            }
            $result = self::upsert($documents, $target_cfg);
            if (is_wp_error($result)) {
                $state['error'] = $result->get_error_message();
                return self::migration_set($state);
            }
            $state['copied'] = (int) $state['copied'] + count($documents);
        }
        $state['batches'] = (int) $state['batches'] + 1;
        $state['token']   = $page['next'];
        $state['error']   = '';
        if ($page['next'] === '') {
            $state['status'] = 'done';
            $state['finished'] = time();
        }
        return self::migration_set($state);
    }

    // =====================================================================
    // AJAX (Knowledge → Pinecone card)
    // =====================================================================

    public static function boot() {
        add_action('wp_ajax_mxchat_pinecone_docs_create_index', array(__CLASS__, 'ajax_create_index'));
        add_action('wp_ajax_mxchat_pinecone_docs_check_index', array(__CLASS__, 'ajax_check_index'));
        add_action('wp_ajax_mxchat_pinecone_docs_migrate', array(__CLASS__, 'ajax_migrate'));
        add_action('wp_ajax_mxchat_pinecone_docs_delete_old_index', array(__CLASS__, 'ajax_delete_old_index'));
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
        $dimension = self::expected_dimension();
        $index = self::create_document_index($name, $dimension, $cloud, $region, $language, $api_key);
        if (is_wp_error($index)) {
            wp_send_json_error(array('message' => $index->get_error_message()));
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
        $changes = array(
            'mxchat_pinecone_api_key'            => $api_key,
            'mxchat_pinecone_host'               => $host,
            'mxchat_pinecone_index'              => strtolower($name),
            'mxchat_pinecone_index_type'         => 'document',
            'mxchat_pinecone_docs_verified_host' => $host,
            'mxchat_pinecone_docs_cloud'         => array_key_exists($cloud, self::clouds()) ? $cloud : 'aws',
            'mxchat_pinecone_docs_region'        => $region ?: 'us-east-1',
            'mxchat_pinecone_docs_language'      => array_key_exists($language, self::languages()) ? $language : 'en',
        );
        // Remember the classic index so Migrate can copy from it.
        $previous_host = (string) ($options['mxchat_pinecone_host'] ?? '');
        if ($previous_host !== '' && $previous_host !== $host && self::options_index_type($options) === 'vector' && empty($options['mxchat_pinecone_vector_host'])) {
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
            'message'   => $ready
                ? sprintf(esc_html__('Document index "%1$s" created (%2$d dimensions, cosine, full-text search on the chunk text). Host filled in and saved.', 'mxchat'), strtolower($name), $dimension)
                : sprintf(esc_html__('Document index "%1$s" is being created (%2$d dimensions). The host is filled in and saved; run Check index in a minute to confirm it is ready.', 'mxchat'), strtolower($name), $dimension),
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
        $check = self::check_document_index($host, $api_key, self::expected_dimension());
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

    /** Migrate: mode start | step | status | reset. Each step copies one page. */
    public static function ajax_migrate() {
        self::ajax_guard();
        $mode = isset($_POST['mode']) ? sanitize_key($_POST['mode']) : 'status';
        $options = get_option(self::OPTION, array());
        $options = is_array($options) ? $options : array();
        $target  = self::cfg_from_options($options);
        if ($mode === 'reset') {
            self::migration_clear();
            wp_send_json_success(array('state' => array()));
        }
        if ($mode === 'status') {
            wp_send_json_success(array('state' => self::migration_get()));
        }
        if ($target['index_type'] !== 'document') {
            wp_send_json_error(array('message' => esc_html__('Save the Pinecone settings with the index type set to Document index first.', 'mxchat')));
        }
        if (strtolower((string) ($options['mxchat_pinecone_docs_verified_host'] ?? '')) !== strtolower($target['host'])) {
            wp_send_json_error(array('message' => esc_html__('Run Check index on the document index host before migrating.', 'mxchat')));
        }
        if ($mode === 'start') {
            $source_host = isset($_POST['source_host']) ? sanitize_text_field(wp_unslash($_POST['source_host'])) : '';
            $restart     = !empty($_POST['restart']);
            $state = self::migrate_start($source_host, $target, $restart);
            if (is_wp_error($state)) {
                wp_send_json_error(array('message' => $state->get_error_message()));
            }
            if ($source_host !== '' && $source_host !== (string) ($options['mxchat_pinecone_vector_host'] ?? '')) {
                self::persist_options(array('mxchat_pinecone_vector_host' => $state['source_host'], 'mxchat_pinecone_vector_index' => $state['source_name']));
            }
            wp_send_json_success(array('state' => $state));
        }
        if ($mode === 'step') {
            $state = self::migration_get();
            if (empty($state)) {
                wp_send_json_error(array('message' => esc_html__('No migration in progress. Click Migrate to start one.', 'mxchat')));
            }
            $state = self::migrate_step($state, $target);
            if (is_wp_error($state)) {
                wp_send_json_error(array('message' => $state->get_error_message()));
            }
            if (!empty($state['error'])) {
                wp_send_json_error(array('message' => $state['error'], 'state' => $state));
            }
            wp_send_json_success(array('state' => $state));
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
