<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Collection;

/**
 * Sent to the vendor client when a catalog upload completes and there are
 * more validation messages than can reasonably be displayed on-screen.
 *
 * The report contains every validation error (and in the future, warnings)
 * grouped and formatted for email delivery. The UI shows a truncated
 * summary and directs the user here for the full details.
 *
 * This notification is designed to be extended later with a $warnings
 * collection — the report builder already supports grouping by "Errors"
 * and "Warnings" headings, and will skip any section that is empty.
 */
class CatalogUploadValidationReportNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * @param  string|null  $catalogName  The name of the uploaded catalog
     * @param  string|null  $processedAt  ISO date/time when processing completed
     * @param  int  $totalErrors         Total number of error rows
     * @param  int  $totalWarnings       Total number of warning rows (reserved for future use)
     * @param  Collection<int, object>  $errors  Failed rows with row_number and errors properties
     * @param  Collection<int, object>  $warnings  Warning rows (reserved for future use)
     */
    public function __construct(
        protected ?string $catalogName,
        protected ?string $processedAt,
        protected int $totalErrors,
        protected int $totalWarnings,
        protected Collection $errors,
        protected Collection $warnings,
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)
            ->subject('Catalog Upload Validation Report')
            ->greeting('Catalog Upload Validation Report');

        // Catalog name
        $mail->line('**Upload name:** ' . ($this->catalogName ?? 'Untitled'));

        // Processed date/time
        $processedAt = $this->processedAt
            ? \Carbon\Carbon::parse($this->processedAt)->format('Y-m-d H:i:s T')
            : now()->format('Y-m-d H:i:s T');
        $mail->line('**Processed at:** ' . $processedAt);

        // Summary counts
        $mail->line('**Total error rows:** ' . $this->totalErrors);
        $mail->line('**Total warning rows:** ' . $this->totalWarnings);
        $mail->line('');

        // Error details
        if ($this->errors->isNotEmpty()) {
            $mail->line('## Errors');
            $mail->line('');

            foreach ($this->errors as $row) {
                $rowNumber = $row->row_number ?? '?';
                $messages = is_array($row->errors)
                    ? collect($row->errors)->pluck('message')->implode('; ')
                    : (string) $row->errors;
                $mail->line("- **Row {$rowNumber}:** {$messages}");
            }

            $mail->line('');
        }

        // Warning details (reserved for future use)
        if ($this->warnings->isNotEmpty()) {
            $mail->line('## Warnings');
            $mail->line('');

            foreach ($this->warnings as $row) {
                $rowNumber = $row->row_number ?? '?';
                $messages = is_array($row->errors)
                    ? collect($row->errors)->pluck('message')->implode('; ')
                    : (string) $row->errors;
                $mail->line("- **Row {$rowNumber}:** {$messages}");
            }

            $mail->line('');
        }

        $mail->line('Please review the above issues and re-upload a corrected file.');
        $mail->salutation('— VIT System');

        return $mail;
    }
}
