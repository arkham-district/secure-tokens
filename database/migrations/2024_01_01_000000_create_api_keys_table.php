<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Create the `api_keys` table for storing Ed25519 API key pairs.
 *
 * Schema:
 *   - id: Auto-incrementing primary key.
 *   - tokenable_type: Polymorphic model class (e.g., "App\Models\User").
 *   - tokenable_id: Polymorphic model ID.
 *   - name:           Human-readable label for the key.
 *   - prefix:         Token prefix including environment (e.g., "sk_live_").
 *   - secret_key:     Ed25519 secret key, encrypted via Laravel's encryption.
 *   - public_key:     Ed25519 public key in plain text (unique, used for lookups).
 *   - abilities:      JSON array of granted abilities/scopes (nullable).
 *   - last_used_at:   Timestamp of last authenticated request (nullable).
 *   - expires_at:     Expiration timestamp (nullable, null = never expires).
 *   - created_at:     Standard Laravel timestamp.
 *   - updated_at:     Standard Laravel timestamp.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('api_keys', function (Blueprint $table) {
            $table->id();
            $table->morphs('tokenable');
            $table->string('name');
            $table->string('prefix', 10);
            $table->text('secret_key');
            $table->string('public_key')->unique();
            $table->text('abilities')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('api_keys');
    }
};
