<?php

declare(strict_types=1);

use Waaseyaa\Foundation\Migration\Migration;
use Waaseyaa\Foundation\Migration\SchemaBuilder;
use Waaseyaa\Foundation\Migration\TableBuilder;

/** Creates engagement-owned identity, projection, receipt, quota and upgrade state. */
return new class extends Migration {
    private const TABLES = [
        'engagement_upgrade_progress',
        'engagement_actor_erasure',
        'engagement_root_erasure',
        'engagement_rate_limits',
        'engagement_policy_state',
        'engagement_receipts',
        'engagement_comment_index',
        'engagement_like_identity',
    ];

    public function up(SchemaBuilder $schema): void
    {
        if (!$schema->hasTable('engagement_like_identity')) {
            $schema->create('engagement_like_identity', static function (TableBuilder $table): void {
                $table->integer('actor_id');
                $table->string('root_type');
                $table->integer('root_id');
                $table->string('root_uuid', 128);
                $table->string('reaction_type');
                $table->string('reaction_uuid', 128)->unique();
                $table->unique(['actor_id', 'root_type', 'root_uuid', 'reaction_type']);
            });
        }

        if (!$schema->hasTable('engagement_comment_index')) {
            $schema->create('engagement_comment_index', static function (TableBuilder $table): void {
                $table->string('comment_uuid', 128);
                $table->integer('comment_id')->unique();
                $table->string('root_type');
                $table->integer('root_id');
                $table->string('root_uuid', 128);
                $table->string('parent_uuid', 128)->nullable();
                $table->integer('author_id');
                $table->integer('created_at');
                $table->string('lifecycle', 32);
                $table->integer('removed_at')->nullable();
                $table->primary(['comment_uuid']);
                $table->index(['root_type', 'root_id', 'created_at', 'comment_id']);
                $table->index(['parent_uuid', 'created_at', 'comment_id']);
            });
        }

        if (!$schema->hasTable('engagement_receipts')) {
            $schema->create('engagement_receipts', static function (TableBuilder $table): void {
                $table->string('key_digest', 128);
                $table->integer('actor_id');
                $table->string('request_hash', 128);
                $table->string('result_uuid', 128);
                $table->integer('created_at');
                $table->integer('expires_at');
                $table->primary(['key_digest']);
                $table->index(['expires_at']);
                $table->index(['actor_id']);
            });
        }

        if (!$schema->hasTable('engagement_policy_state')) {
            $schema->create('engagement_policy_state', static function (TableBuilder $table): void {
                $table->integer('id');
                $table->integer('visibility_generation');
                $table->primary(['id']);
            });
            $schema->getConnection()->insert('engagement_policy_state', [
                'id' => 1,
                'visibility_generation' => 1,
            ]);
        }

        if (!$schema->hasTable('engagement_rate_limits')) {
            $schema->create('engagement_rate_limits', static function (TableBuilder $table): void {
                $table->string('limiter_key', 255);
                $table->text('state_json');
                $table->float('expires_at');
                $table->primary(['limiter_key']);
                $table->index(['expires_at']);
            });
        }

        if (!$schema->hasTable('engagement_upgrade_progress')) {
            $schema->create('engagement_upgrade_progress', static function (TableBuilder $table): void {
                $table->string('id', 32);
                $table->integer('last_id')->default(0);
                $table->integer('complete')->default(0);
                $table->primary(['id']);
            });
        }

        if (!$schema->hasTable('engagement_root_erasure')) {
            $schema->create('engagement_root_erasure', static function (TableBuilder $table): void {
                $table->string('root_type');
                $table->string('root_uuid', 128);
                $table->integer('requested_at');
                $table->primary(['root_type', 'root_uuid']);
            });
        }

        if (!$schema->hasTable('engagement_actor_erasure')) {
            $schema->create('engagement_actor_erasure', static function (TableBuilder $table): void {
                $table->integer('actor_id');
                $table->integer('requested_at');
                $table->primary(['actor_id']);
            });
        }
    }

    public function down(SchemaBuilder $schema): void
    {
        foreach (self::TABLES as $table) {
            $schema->dropIfExists($table);
        }
    }
};
