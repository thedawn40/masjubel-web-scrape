<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $source = [
            'name' => 'Lotus Archi',
            'slug' => 'lotus-archi',
            'url' => 'https://lotusarchi.com/pricing/',
            'is_commodity' => false,
            'updated_at' => now(),
        ];

        if (DB::table('sources')->where('slug', $source['slug'])->exists()) {
            DB::table('sources')
                ->where('slug', $source['slug'])
                ->update($source);

            return;
        }

        DB::table('sources')->insert($source + [
            'is_active' => true,
            'created_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::table('sources')->where('slug', 'lotus-archi')->delete();
    }
};
