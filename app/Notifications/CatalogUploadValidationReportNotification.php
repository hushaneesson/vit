<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Sent to the vendor client when a catalog upload completes and there are
 * more validation messages than can reasonably be displayed on-screen.
 *
 * The email presents an aggregated summary of the recorded validation
 * messages (message type + affected row count) and links to the detailed
 * report screen for the full row-level details.
 *
 * Errors and warnings are separate sections; each is skipped when empty.
 * Processing failures are never included here.
 */
class CatalogUploadValidationReportNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * The email presents an aggregated summary of the recorded validation
     * messages (message type + affected row count). Individual row-level
     * errors remain stored on CatalogUploadRow and are not included here.
     *
     * @param  string|null  $catalogName  The name of the uploaded catalog
     * @param  int  $rowsProcessed  Total rows staged for this upload
     * @param  int  $successRows  Rows processed successfully
     * @param  int  $totalErrors  Total number of error rows
     * @param  int  $totalWarnings  Total number of warning rows
     * @param  array<int, array{label: string, count: int}>  $errorSummary  Aggregated error messages
     * @param  array<int, array{label: string, count: int}>  $warningSummary  Aggregated warning messages
     * @param  int|null  $catalogId  Catalog id used to build the detailed report link
     * @param  int|null  $catalogUploadId  Upload id used to build the detailed report link
     */
    public function __construct(
        protected ?string $catalogName,
        protected int $rowsProcessed,
        protected int $successRows,
        protected int $totalErrors,
        protected int $totalWarnings,
        protected array $errorSummary,
        protected array $warningSummary,
        protected ?int $catalogId = null,
        protected ?int $catalogUploadId = null,
    ) {
        $this->onQueue('notifications');
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)
            ->subject('Catalog Upload Validation Report')
            ->greeting('Catalog Upload Validation Report')
            ->line('Your catalog upload has completed processing, but some rows could not be processed.')
            ->line('')
            ->line('**Rows processed:** '.number_format($this->rowsProcessed))
            ->line('**Successful rows:** '.number_format($this->successRows));

        if ($this->totalErrors > 0) {
            $mail->line('**Rows with errors:** '.number_format($this->totalErrors));

            if (! empty($this->errorSummary)) {
                $mail->line('')
                    ->line('**Error summary:**')
                    ->line('');

                foreach ($this->errorSummary as $entry) {
                    $mail->line($this->summaryLine($entry));
                }
            }
        }

        if ($this->totalWarnings > 0) {
            $mail->line('**Warnings:** '.number_format($this->totalWarnings));

            if (! empty($this->warningSummary)) {
                $mail->line('')
                    ->line('**Warning summary:**')
                    ->line('');

                foreach ($this->warningSummary as $entry) {
                    $mail->line($this->summaryLine($entry));
                }
            }
        }

        $mail->line('')
            ->line('Please correct the affected rows and upload the catalog again.');

        return $mail->salutation('— VIT System');
    }

    /**
     * Format one aggregated summary entry, e.g.
     * "• Missing required field \"Item Name\": 1,492 rows".
     *
     * @param  array{label?: string, count?: int}  $entry
     */
    private function summaryLine(array $entry): string
    {
        $label = (string) ($entry['label'] ?? '');
        $count = (int) ($entry['count'] ?? 0);

        return '• '.$label.': '.number_format($count).' '.($count === 1 ? 'row' : 'rows');
    }
}
