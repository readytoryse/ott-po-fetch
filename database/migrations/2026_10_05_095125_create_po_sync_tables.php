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
        Schema::create('po_sync_runs', function (Blueprint $table) {
            $table->id();
            $table->timestamp('started_at');
            $table->timestamp('finished_at')->nullable();
            $table->string('status', 20)->index();
            $table->unsignedInteger('rows_fetched')->default(0);
            $table->unsignedInteger('new_count')->default(0);
            $table->unsignedInteger('updated_count')->default(0);
            $table->unsignedInteger('removed_count')->default(0);
            $table->unsignedInteger('unchanged_count')->default(0);
            $table->text('error')->nullable();
            $table->timestamps();
        });

        Schema::create('po_lines', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('pol_id')->unique();
            $table->string('poh_order_number')->nullable();
            $table->dateTime('po_placed_datetime')->nullable();
            $table->string('supplier')->nullable();
            $table->string('sku')->nullable();
            $table->text('description')->nullable();
            $table->decimal('qty_ordered', 18, 4);
            $table->decimal('qty_received', 18, 4);
            $table->decimal('qty_outstanding', 18, 4);
            $table->dateTime('supplier_promised_date')->nullable();
            $table->dateTime('date_required')->nullable();
            $table->dateTime('poh_datetime')->nullable();
            $table->char('row_hash', 64);
            $table->timestamp('first_seen_at');
            $table->timestamp('last_seen_at');
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['is_active', 'sku']);
        });

        Schema::create('po_line_changes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('run_id')->constrained('po_sync_runs')->cascadeOnDelete();
            $table->unsignedBigInteger('pol_id');
            $table->string('change_type', 16);
            $table->json('old_values')->nullable();
            $table->json('new_values')->nullable();
            $table->timestamps();

            $table->index(['run_id', 'change_type']);
            $table->index('pol_id');
        });

        Schema::create('variant_metafield_syncs', function (Blueprint $table) {
            $table->id();
            $table->string('sku')->unique();
            $table->string('variant_gid')->nullable();
            $table->json('last_payload')->nullable();
            $table->char('payload_hash', 64)->nullable();
            $table->timestamp('synced_at')->nullable();
            $table->string('status', 20)->default('pending');
            $table->text('error')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('variant_metafield_syncs');
        Schema::dropIfExists('po_line_changes');
        Schema::dropIfExists('po_lines');
        Schema::dropIfExists('po_sync_runs');
    }
};
