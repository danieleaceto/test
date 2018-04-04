<?php

namespace App\Jobs;

use App\Mail\AccountVerification;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Support\Facades\Mail;

class SendVerificationEmail implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * The user this mail will be sent to.
     *
     * @var User
     */
    protected $user;

    /**
     * The token user for account verification
     *
     * @var string
     */
    protected $token;

    /**
     * Create a new job instance.
     *
     * @param  User  $user
     * @param  string $token
     * @return void
     */
    public function __construct(User $user, string $token)
    {
        $this->user = $user;
        $this->token = $token;
    }

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle()
    {
        $email = new AccountVerification($this->user, $this->token);
        Mail::to($this->user->email)->send($email);

        logger()->info('Activation mail sent to '.$this->user->getInfo());
    }
}
