<?php

namespace App\Jobs;

use App\Models\ContactFormSubmission;
use App\Services\Forms\FormSubmissionProcessor;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/** Turns a stored form submission into a contact + CRM conversation (+ AI answer). Runs off the request so the visitor gets an instant reply. */
class ProcessContactFormSubmissionJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public function __construct(public int $submissionId)
    {
    }

    public function handle(FormSubmissionProcessor $processor): void
    {
        $s = ContactFormSubmission::with('form')->find($this->submissionId);
        if (!$s || $s->status === 'processed') {
            return;
        }
        try {
            $processor->process($s);
        } catch (\Throwable $e) {
            $s->update(['status' => 'failed', 'note' => mb_substr($e->getMessage(), 0, 255)]);
            throw $e;
        }
    }
}
