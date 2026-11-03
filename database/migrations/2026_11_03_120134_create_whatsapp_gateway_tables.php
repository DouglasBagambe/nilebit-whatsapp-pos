<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wa_conversations', function (Blueprint $table) {
            $table->id();
            $table->string('sender', 40);
            $table->string('phone_number_id', 40);
            $table->unsignedBigInteger('binding_id')->nullable();
            $table->unsignedInteger('version')->default(0);
            $table->timestamp('last_inbound_at')->nullable();
            $table->timestamps();
            $table->unique(['phone_number_id', 'sender']);
        });
        Schema::create('wa_business_bindings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conversation_id')->constrained('wa_conversations');
            $table->string('backend', 20);
            $table->unsignedBigInteger('shop_id');
            $table->unsignedBigInteger('user_id');
            $table->string('name');
            $table->string('role', 20);
            $table->text('token');
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
            $table->unique(['conversation_id', 'backend', 'shop_id', 'user_id'], 'wa_binding_identity_unique');
        });
        Schema::create('wa_inbound_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conversation_id')->constrained('wa_conversations');
            $table->string('message_id', 255);
            $table->string('phone_number_id', 40);
            $table->text('payload');
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();
            $table->unique(['phone_number_id', 'message_id']);
        });
        Schema::create('wa_link_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conversation_id')->constrained('wa_conversations');
            $table->string('token_hash', 64)->unique();
            $table->timestamp('expires_at');
            $table->timestamp('consumed_at')->nullable();
            $table->timestamps();
        });
        Schema::create('wa_actions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('conversation_id')->constrained('wa_conversations');
            $table->unsignedInteger('version');
            $table->string('kind', 40);
            $table->unsignedInteger('page')->default(0);
            $table->unsignedBigInteger('binding_id')->nullable();
            $table->timestamp('expires_at');
            $table->timestamps();
        });
        Schema::create('wa_outbound_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('inbound_event_id')->unique()->constrained('wa_inbound_events');
            $table->foreignId('conversation_id')->constrained('wa_conversations');
            $table->text('payload');
            $table->string('provider_message_id', 255)->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('abandoned_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wa_outbound_messages');
        Schema::dropIfExists('wa_actions');
        Schema::dropIfExists('wa_link_requests');
        Schema::dropIfExists('wa_inbound_events');
        Schema::dropIfExists('wa_business_bindings');
        Schema::dropIfExists('wa_conversations');
    }
};
