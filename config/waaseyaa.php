<?php

declare(strict_types=1);

return [
    // SQLite database path. Null means "resolve in kernel":
    // WAASEYAA_DB env var -> {projectRoot}/storage/waaseyaa.sqlite fallback.
    // Set an explicit path here to override both.
    'database' => null,

    // Config sync directory. Override with WAASEYAA_CONFIG_DIR env var.
    'config_dir' => getenv('WAASEYAA_CONFIG_DIR') ?: __DIR__ . '/sync',

    // File storage root for LocalFileRepository (media package).
    'files_dir' => getenv('WAASEYAA_FILES_DIR') ?: __DIR__ . '/../storage/files',

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
    ],

    // Upload validation (POST /api/media/upload).
    'upload_max_bytes' => 10 * 1024 * 1024, // 10 MiB
    'upload_allowed_mime_types' => [
        'image/jpeg',
        'image/png',
        'image/gif',
        'image/webp',
        'image/svg+xml',
        'application/pdf',
        'text/plain',
        'application/octet-stream',
    ],

    // Allowed CORS origins for the admin SPA.
    'cors_origins' => ['http://localhost:3000', 'http://127.0.0.1:3000'],

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
        'openai_api_key' => getenv('OPENAI_API_KEY') ?: '',
        'openai_embedding_model' => getenv('WAASEYAA_OPENAI_EMBEDDING_MODEL') ?: 'text-embedding-3-small',
        // Per-entity field selection used for embedding text extraction.
        'embedding_fields' => [
            'node' => ['title', 'body'],
        ],
    ],
];
