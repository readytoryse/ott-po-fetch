<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('variant_metafield_updates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('run_id')->nullable()->constrained('po_sync_runs')->nullOnDelete();
            $table->string('sku');
            $table->string('variant_gid');
            $table->longText('old_value')->nullable();
            $table->longText('new_value');
            $table->timestamp('metafield_updated_at');
            $table->timestamps();

            $table->index(['sku', 'metafield_updated_at']);
        });

        Schema::create('po_sync_issues', function (Blueprint $table) {
            $table->id();
            $table->foreignId('run_id')->nullable()->constrained('po_sync_runs')->nullOnDelete();
            $table->string('stage', 40);
            $table->string('severity', 20);
            $table->string('sku')->nullable();
            $table->string('variant_gid')->nullable();
            $table->text('message');
            $table->json('details')->nullable();
            $table->timestamps();

            $table->index(['run_id', 'severity']);
            $table->index('sku');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('po_sync_issues');
        Schema::dropIfExists('variant_metafield_updates');
    }
};
