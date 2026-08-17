<?php

declare(strict_types=1);

return [
    // Debug mode. Controls error detail display, debug toolbar, and debug headers.
    // Override with APP_DEBUG env var. MUST be false in production.
    'debug' => filter_var(getenv('APP_DEBUG') ?: false, FILTER_VALIDATE_BOOLEAN),

    // Minimum log level for the default log handler.
    // Override with LOG_LEVEL env var. Values: debug, info, notice, warning, error, critical, alert, emergency.
    'log_level' => getenv('LOG_LEVEL') ?: 'warning',

    // Application environment. Controls dev-only features (fallback account, CORS relaxation).
    // Override with APP_ENV env var. Values: local, dev, development, staging, production.
    'environment' => getenv('APP_ENV') ?: 'production',

    // RFC 9727 API Catalog. The public route exists only when a canonical
    // HTTPS base URL and at least one installed public API contribution exist.
    // APP_URL is never inferred from the request Host header.
    'api_catalog' => [
        'enabled' => getenv('APP_URL') !== false && getenv('APP_URL') !== '',
        'base_url' => getenv('APP_URL') ?: '',
    ],

    // Experimental ARD v0.9 / AI Catalog 1.0 discovery. Off by default.
    // When enabled, add 2-5 public representative queries per installed key,
    // such as `mcp:public`; never include private prompts or real user data.
    'ai_catalog' => [
        'enabled' => false,
        'base_url' => getenv('APP_URL') ?: '',
        'representative_queries' => [],
    ],

    // WAASEYAA_APP_SECRET is intentionally consumed directly by the kernel
    // before database boot. Do not copy master or derived key bytes into config.

    // Operator health probe: verifies a known non-root URL reaches the router.
    // Leave APP_URL unset only when no public HTTP server is expected to be live.
    'diagnostics' => [
        'clean_url_probe_url' => getenv('APP_URL') ?: '',
    ],

    // SQLite database path. Null means "resolve in kernel":
    // WAASEYAA_DB env var -> {projectRoot}/storage/waaseyaa.sqlite fallback.
    // Set an explicit path here to override both.
    'database' => null,

    // Desired-state configuration bundle. Runtime reads the active database
    // generation, never this directory. Override only with the canonical
    // WAASEYAA_CONFIG_SYNC_PATH bootstrap selector.
    'config' => [
        'sync_path' => null,
        'allow_external_sync_path' => false,
    ],

    // File storage root for LocalFileRepository (media package).
    'files_dir' => getenv('WAASEYAA_FILES_DIR') ?: __DIR__ . '/../storage/files',

    // Bearer auth settings for machine clients.
    // JWT uses HS256 with this shared secret.
    'jwt_secret' => getenv('WAASEYAA_JWT_SECRET') ?: '',
    // API key map: raw key => uid. Example: ['dev-machine-key' => 1].
    'api_keys' => [],
    // Optional application-declared operational fields that must stay absent
    // from every generic Admin and JSON:API read/query surface. Framework
    // migration bookkeeping is already included; applications add exact
    // entity-type/field names here rather than configuring each surface.
    // 'entity' => [
    //     'internal_fields_by_type' => ['node' => ['legacy_origin']],
    // ],


    // Optional closed-world generic JSON:API entity-type policy. When this key
    // is absent, package/app `api: true` declarations retain their current
    // behavior. To narrow a deployment, uncomment it and list every exact
    // registered type the generic adapter may expose. The list cannot elevate
    // `api: false`, and stale/unknown ids fail boot intentionally.
    // 'api' => [
    //     'entity_type_allowlist' => ['node', 'node_type', 'media'],
    // ],

    // Dev-only fallback account for local built-in server workflows.
    // Must remain false outside local development.
    'auth' => [
        'dev_fallback_account' => filter_var(
            getenv('WAASEYAA_DEV_FALLBACK_ACCOUNT') ?: false,
            FILTER_VALIDATE_BOOLEAN,
        ),
    ],

    // Upload validation (POST /api/media/upload). MIME types are sniffed
    // from file contents (ext-fileinfo) and validation fails closed — the
    // client-declared type is never trusted. 'image/svg+xml' (script-capable)
    // and 'application/octet-stream' (matches any unrecognized binary) are
    // deliberately NOT in the default allowlist; add them here explicitly to
    // opt back in.
    'upload_max_bytes' => 10 * 1024 * 1024, // 10 MiB
    'upload_allowed_mime_types' => [
        'image/jpeg',
        'image/png',
        'image/gif',
        'image/webp',
        'application/pdf',
        'text/plain',
    ],

    // Allowed CORS origins for the admin SPA.
    'cors_origins' => ['http://localhost:3000', 'http://127.0.0.1:3000'],

    // Locale negotiation defaults used by public SSR path resolution.
    'i18n' => [
        'languages' => [
            ['id' => 'en', 'label' => 'English', 'is_default' => true],
        ],
    ],

    // Translation behaviour for content entities. (M-006 / FR-037, FR-041, C-004)
    //
    // - read_active_language (bool, default false): when true, read paths resolve the
    //   active language translation via EntityTranslationManager. Default false keeps
    //   the legacy behaviour (read the base entity row) so existing installs are
    //   unaffected until they opt in. Override with WAASEYAA_TRANSLATION_READ_ACTIVE_LANGUAGE.
    // - fallback_chain (?array, default null): null means "use the i18n default language
    //   list order as the fallback chain". Set an explicit list of language ids
    //   (e.g. ['oj', 'en']) to override per-site.
    'translation' => [
        'read_active_language' => filter_var(
            getenv('WAASEYAA_TRANSLATION_READ_ACTIVE_LANGUAGE') ?: false,
            FILTER_VALIDATE_BOOLEAN,
        ),
        'fallback_chain' => null,
    ],

    // SSR theme id discovered from Composer package metadata.
    // Theme packages expose extra.waaseyaa.theme in composer.json.
    'ssr' => [
        'theme' => getenv('WAASEYAA_SSR_THEME') ?: '',
        'cache_max_age' => (int) (getenv('WAASEYAA_SSR_CACHE_MAX_AGE') ?: 300),
    ],

    // AI embedding pipeline configuration.
    'ai' => [
        // 'ollama' or 'openai'. Empty disables embedding generation.
        'embedding_provider' => getenv('WAASEYAA_EMBEDDING_PROVIDER') ?: '',
        'ollama_endpoint' => getenv('WAASEYAA_OLLAMA_ENDPOINT') ?: 'http://127.0.0.1:11434/api/embeddings',
        'ollama_model' => getenv('WAASEYAA_OLLAMA_MODEL') ?: 'nomic-embed-text',
        'openai_credential_reference' => [
            'provider' => getenv('WAASEYAA_OPENAI_SECRET_PROVIDER') ?: '',
            'identifier' => getenv('WAASEYAA_OPENAI_SECRET_ID') ?: '',
            'secret_class' => 'provider-credential',
            'purpose' => 'waaseyaa.ai.embedding.v1',
        ],
        'openai_embedding_model' => getenv('WAASEYAA_OPENAI_EMBEDDING_MODEL') ?: 'text-embedding-3-small',
        // Per-entity field selection used for embedding text extraction.
        'embedding_fields' => [
            'node' => ['title', 'body'],
        ],
    ],
];
