<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('business_settings', function (Blueprint $table) {
            $table->id();
            $table->string('key', 100)->unique();                 // stable identifier, e.g. shipping.flat_fee
            $table->text('value');                                // canonical string form (see App\Enums\SettingType); never secrets
            $table->string('type', 16);                           // string|integer|decimal|boolean|email|url|json
            $table->string('group', 60);                          // logical category, e.g. shipping
            $table->string('description', 255)->nullable();
            $table->boolean('is_public')->default(false);         // only is_public rows are served by the public API
            $table->timestamps();
            $table->index(['group', 'key']);
            $table->index(['is_public', 'group']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('business_settings');
    }
};
