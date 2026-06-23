<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('words', function (Blueprint $table) {
            // AI-generated memory aid / mnemonic (Traditional Chinese), e.g. 諧音、
            // 字根字首、聯想法. Shared reference data, generated once per word.
            $table->text('mnemonic')->nullable()->after('toeic_note');
        });
    }

    public function down(): void
    {
        Schema::table('words', function (Blueprint $table) {
            $table->dropColumn('mnemonic');
        });
    }
};
