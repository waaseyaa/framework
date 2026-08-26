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

    // Experimental ARD v0.9 / AI Catalog 1.0 discovery. This is deliberately
    // off by default. Applications opt in and own any public representative
    // queries; installed packages contribute only deployment-neutral artifacts.
    'ai_catalog' => [
        'enabled' => false,
        'base_url' => getenv('APP_URL') ?: '',
        'representative_queries' => [],
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

    // Runtime/broadcast storage. The worker-acceptance harness pins this under
    // a disposable tree so public/ is never used as CWD-relative ./storage.
    'storage_path' => getenv('WAASEYAA_STORAGE_PATH') ?: (__DIR__ . '/../storage'),

    // Bearer auth settings for machine clients.
    // JWT uses HS256 with this shared secret.
    'jwt_secret' => getenv('WAASEYAA_JWT_SECRET') ?: '',
    // API key map: raw key => uid. Example: ['dev-machine-key' => 1].
    'api_keys' => [],
    // Dev-only fallback account for local built-in server workflows.
    // Must remain false outside local development.
    'auth' => [
        'dev_fallback_account' => filter_var(
            getenv('WAASEYAA_DEV_FALLBACK_ACCOUNT') ?: false,
            FILTER_VALIDATE_BOOLEAN,
        ),
        // Optional independent HMAC key for reset/verify/invite tokens.
        // A valid AUTH_TOKEN_SECRET is an override. Empty/absent derives a
        // versioned purpose key from application-secret custody. Invalid
        // explicit values fail closed and never fall back to raw app_secret.
        'token_secret' => getenv('AUTH_TOKEN_SECRET') ?: '',
    ],

    // Global HTTP request boundaries. Keep both enabled in normal operation;
    // the flags are explicit emergency rollback controls.
    'http_security' => [
        'rate_limit' => [
            'enabled' => true,
            'max_attempts' => 60,
            'window_seconds' => 60,
        ],
        'body_size_limit' => [
            'enabled' => true,
            'max_bytes' => 1024 * 1024,
        ],
    ],

    // Deployment-sensitive response headers remain opt-in, but are configured
    // on the kernel-owned middleware so the class is never registered twice.
    'security_headers' => [
        'csp' => null,
        'hsts_enabled' => false,
        'hsts_max_age' => 31_536_000,
        'frame_options' => 'SAMEORIGIN',
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

    // Trusted reverse-proxy IPs/CIDRs for X-Forwarded-* header handling.
    //
    // When the app sits behind a TLS-terminating proxy (Caddy, nginx,
    // a load balancer) that talks HTTP to PHP-FPM, set this to the
    // proxy's IP, CIDR range, or the Symfony sentinel `REMOTE_ADDR`
    // (meaning "trust the immediate connecting peer").
    //
    // Empty list = no trusted proxies = X-Forwarded-* headers are
    // ignored (the safe default for setups without a TLS terminator).
    //
    // Override with `TRUSTED_PROXIES` env var (comma-separated values,
    // e.g. `10.0.0.0/8,192.168.0.0/16` or `REMOTE_ADDR`). Config wins
    // when both are present.
    //
    // See packages/foundation/src/Kernel/HttpKernel.php
    // ::applyTrustedProxiesFromConfig() and issue #1394.
    'trusted_proxies' => [],

    // Locale negotiation defaults used by public SSR path resolution.
    'i18n' => [
        'languages' => [
            ['id' => 'en', 'label' => 'English', 'is_default' => true],
        ],
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
