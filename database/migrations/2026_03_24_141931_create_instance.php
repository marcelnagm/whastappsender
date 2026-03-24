<?php

use App\Models\Instance;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use App\Models\User;
use Illuminate\Database\QueryException;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('instances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->string('name'); // Nome amigável (ex: "Financeiro")
            $table->string('instance_name')->unique(); // Nome técnico na Evolution API
            $table->string('status')->default('disconnected');
            $table->timestamps();
        });

        $users = User::all('id', 'phone');
        try {
            foreach ($users as $user) {
                $ins =     new Instance([
                    'user_id' => $user->id,
                    'name' => $user->phone,
                    'instance_name' => $user->phone
                ]);
                $ins->save();
            }
        } catch (QueryException $ex) {
        }


        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('phone');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('instances');
    }
};
