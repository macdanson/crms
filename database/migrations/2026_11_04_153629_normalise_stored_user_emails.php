<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * Accounts are looked up by trimmed, lower-case email address
     * (User::normaliseEmail). Accounts that existed before that rule were
     * stored as typed, so on a case-sensitive database they could no longer
     * sign in or reset their password.
     *
     * Compared here in PHP, as a case-insensitive database would see no
     * difference. An address whose lower-case form belongs to another account
     * is left as it is and logged: someone must decide which account to keep.
     */
    public function up(): void
    {
        $emails = DB::table('users')->pluck('email', 'id');
        $taken = $emails->flip();

        foreach ($emails as $id => $email) {
            $normalised = Str::lower(trim($email));

            if ($normalised === $email) {
                continue;
            }

            if ($taken->has($normalised)) {
                Log::warning('Two accounts share an email address apart from its case; one needs fixing by hand.', ['user_id' => $id]);

                continue;
            }

            DB::table('users')->where('id', $id)->update(['email' => $normalised]);

            $taken->put($normalised, $id);
        }
    }

    /**
     * The addresses as first typed are not kept, so this cannot be undone.
     */
    public function down(): void
    {
        //
    }
};
