<?php

use App\Models\Role;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Every account holds at least one IMIS role: create the baseline "User"
     * role and give it to accounts that have none.
     */
    public function up(): void
    {
        $roleId = Role::baseline()->id;

        $withoutRole = DB::table('users')
            ->whereNotIn('id', DB::table('role_user')->select('user_id'))
            ->pluck('id');

        foreach ($withoutRole->chunk(500) as $chunk) {
            DB::table('role_user')->insert($chunk->map(fn ($id) => [
                'role_id' => $roleId,
                'user_id' => $id,
            ])->all());
        }
    }

    public function down(): void
    {
        // Leaves the role in place; it may be assigned by hand by now.
    }
};
