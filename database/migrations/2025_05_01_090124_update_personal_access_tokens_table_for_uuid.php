<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('personal_access_tokens', function (Blueprint $table) {
            $table->uuid('tokenable_uuid')->nullable();
        });
    
        // Optional: if there's a way to map bigint IDs to UUIDs, do it here
        // DB::table('personal_access_tokens')->update([...]);
    
        Schema::table('personal_access_tokens', function (Blueprint $table) {
            $table->dropColumn('tokenable_id');
            $table->renameColumn('tokenable_uuid', 'tokenable_id');
        });
    }
    
    

    public function down():void
    {
        Schema::table('personal_access_tokens', function (Blueprint $table) {
            $table->morphs('tokenable');
        });
    }


};
