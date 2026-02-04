<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('print_templates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('code')->index(); // invoice_a4, invoice_thermal_80, quote_a5, etc.
            $table->string('document_type'); // invoice, quotation, purchase_order, credit_note, delivery_note, payment_receipt
            $table->string('paper_size'); // a3, a4, a5, thermal_80, thermal_58, letter, legal
            $table->string('orientation')->default('portrait'); // portrait, landscape
            $table->text('template_content')->nullable(); // Custom HTML/Blade content
            $table->string('template_file')->nullable(); // Reference to blade file
            $table->json('settings')->nullable(); // Font size, margins, colors, etc.
            $table->json('sections')->nullable(); // Which sections to show/hide
            $table->boolean('show_logo')->default(true);
            $table->boolean('show_qr_code')->default(true);
            $table->boolean('show_signature')->default(false);
            $table->boolean('show_watermark')->default(false);
            $table->string('watermark_text')->nullable();
            $table->string('primary_color')->default('#2c3e50');
            $table->string('secondary_color')->default('#3498db');
            $table->boolean('is_default')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['organization_id', 'code']);
            $table->index(['organization_id', 'document_type', 'paper_size']);
        });

        Schema::create('print_configurations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $table->string('printer_type'); // laser, inkjet, thermal_80, thermal_58, sunmi_v2, sunmi_v2_pro
            $table->string('default_paper_size')->default('a4');
            $table->json('paper_sizes')->nullable(); // Available paper sizes for this config
            $table->json('thermal_settings')->nullable(); // Width, DPI, cut mode
            $table->json('margin_settings')->nullable(); // top, right, bottom, left
            $table->json('font_settings')->nullable(); // family, size, line_height
            $table->boolean('auto_cut')->default(true);
            $table->boolean('open_drawer')->default(false);
            $table->integer('copies')->default(1);
            $table->boolean('is_default')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['organization_id', 'branch_id']);
        });

        // Add print count to documents
        Schema::table('invoices', function (Blueprint $table) {
            $table->integer('print_count')->default(0)->after('notes');
            $table->timestamp('last_printed_at')->nullable()->after('print_count');
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropColumn(['print_count', 'last_printed_at']);
        });
        Schema::dropIfExists('print_configurations');
        Schema::dropIfExists('print_templates');
    }
};
