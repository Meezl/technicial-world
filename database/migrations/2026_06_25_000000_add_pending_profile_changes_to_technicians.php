<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('technicians', function (Blueprint $table) {
            // Pending changes awaiting admin approval. Shape:
            //   { skills: [...], bio: "...", submitted_at: "..." }
            // Only fields requiring approval are stored here; profile photo
            // and phone are applied immediately.
            $table->json('pending_profile_changes')->nullable()->after('skills');
        });
    }

    public function down(): void
    {
        Schema::table('technicians', function (Blueprint $table) {
            $table->dropColumn('pending_profile_changes');
        });
    }
};
