<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wa_sale_drafts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('conversation_id')->constrained('wa_conversations');
            $table->foreignId('binding_id')->constrained('wa_business_bindings');
            $table->string('step', 30);
            $table->text('state');
            $table->timestamp('expires_at');
            $table->timestamps();
            $table->index(['conversation_id', 'step']);
        });
        Schema::table('wa_actions', function (Blueprint $table) {
            $table->text('payload')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('wa_actions', fn (Blueprint $table) => $table->dropColumn('payload'));
        Schema::dropIfExists('wa_sale_drafts');
    }
};
