<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('technicians', function (Blueprint $table) {
            $table->string('trade')->nullable()->after('specialization');
            $table->text('experience_narrative')->nullable()->after('bio');
            $table->string('profile_photo_path')->nullable()->after('experience_narrative');
            $table->enum('vetting_status', ['pending', 'under_review', 'approved', 'rejected'])->default('pending')->after('profile_photo_path');
            $table->foreignId('vetted_by')->nullable()->constrained('users')->nullOnDelete()->after('vetting_status');
            $table->timestamp('vetted_at')->nullable()->after('vetted_by');
            $table->foreignId('onboarded_by')->nullable()->constrained('users')->nullOnDelete()->after('vetted_at');
            $table->boolean('is_active')->default(true)->after('onboarded_by');
        });
    }

    public function down(): void
    {
        Schema::table('technicians', function (Blueprint $table) {
            $table->dropForeign(['vetted_by']);
            $table->dropForeign(['onboarded_by']);
            $table->dropColumn([
                'trade', 'experience_narrative', 'profile_photo_path',
                'vetting_status', 'vetted_by', 'vetted_at', 'onboarded_by', 'is_active'
            ]);
        });
    }
};
