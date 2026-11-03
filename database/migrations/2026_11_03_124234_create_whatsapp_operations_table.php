<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wa_operations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('conversation_id')->constrained('wa_conversations');
            $table->foreignId('binding_id')->constrained('wa_business_bindings');
            $table->string('kind', 40);
            $table->string('step', 30);
            $table->text('state');
            $table->timestamp('expires_at');
            $table->timestamps();
            $table->index(['conversation_id', 'step']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wa_operations');
    }
};
