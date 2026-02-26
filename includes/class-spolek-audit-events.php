<?php
if (!defined('ABSPATH')) exit;

/**
 * Spolek_Audit_Events
 *
 * Jednotné názvy audit eventů (včetně budoucího filtrování/exportu).
 *
 * Pozn.: Sloupec `event` má VARCHAR(50), proto jsou eventy krátké.
 */
final class Spolek_Audit_Events {

    // Vote
    public const VOTE_CREATED        = 'vote.created';
    public const VOTE_CAST_ATTEMPT   = 'vote.cast.attempt';
    public const VOTE_CAST_SAVED     = 'vote.cast.saved';
    public const VOTE_CAST_REJECTED  = 'vote.cast.rejected';
    public const VOTE_CAST_FAILED    = 'vote.cast.failed';

    // Cron close
    public const CRON_CLOSE_START            = 'cron.close.start';
    public const CRON_CLOSE_CALLED           = 'cron.close.called';
    public const CRON_CLOSE_LOCK_BUSY        = 'cron.close.lock_busy';
    public const CRON_CLOSE_RESCHEDULED      = 'cron.close.rescheduled';
    public const CRON_CLOSE_SKIP_PROCESSED   = 'cron.close.skip_processed';
    public const CRON_CLOSE_SKIP_NOT_CLOSED  = 'cron.close.skip_not_closed';
    public const CRON_CLOSE_EXCEPTION        = 'cron.close.exception';
    public const CRON_CLOSE_SILENT_DONE      = 'cron.close.silent.done';
    public const CRON_CLOSE_SILENT_EXCEPTION = 'cron.close.silent.exception';
    public const CRON_CLOSE_RETRY_SCHEDULED  = 'cron.close.retry.scheduled';
    public const CRON_CLOSE_RETRY_GIVE_UP    = 'cron.close.retry.give_up';
    public const CRON_CLOSE_SKIP_MAX         = 'cron.close.skip_max';

    // Cron reminder
    public const CRON_REMINDER_START          = 'cron.reminder.start';
    public const CRON_REMINDER_DONE           = 'cron.reminder.done';
    public const CRON_REMINDER_SKIP_DONE      = 'cron.reminder.skip_done';
    public const CRON_REMINDER_RETRY_SCHEDULED = 'cron.reminder.retry.scheduled';
    public const CRON_REMINDER_RETRY_GIVE_UP   = 'cron.reminder.retry.give_up';

    // PDF
    public const PDF_GENERATED         = 'pdf.generated';
    public const PDF_GENERATION_FAILED = 'pdf.generation_failed';

    // Archive
    public const ARCHIVE_SCAN_ATTEMPT = 'archive.scan.attempt';
    public const ARCHIVE_CREATED      = 'archive.created';
    public const ARCHIVE_FAILED       = 'archive.failed';
    public const ARCHIVE_AUTO_FAILED  = 'archive.auto.failed';
    public const ARCHIVE_SHA_MISMATCH = 'archive.sha_mismatch';

    // Close scan
    public const CLOSE_SCAN_ERROR     = 'close.scan.error';

    // Manual admin actions
    public const CSV_EXPORTED          = 'csv.exported';
    public const ARCHIVE_MANUAL_START  = 'archive.manual.start';
    public const ARCHIVE_MANUAL_DONE   = 'archive.manual.done';
    public const ARCHIVE_MANUAL_FAIL   = 'archive.manual.fail';
    public const ARCHIVE_DOWNLOAD      = 'archive.download';
    public const PURGE_MANUAL_START    = 'purge.manual.start';
    public const PURGE_MANUAL_DONE     = 'purge.manual.done';
    public const PURGE_MANUAL_FAIL     = 'purge.manual.fail';
    public const PURGE_AUTO_DONE      = 'purge.auto.done';
    public const PURGE_AUTO_FAIL      = 'purge.auto.fail';
    public const CLOSE_SCAN_MANUAL     = 'close.scan.manual';
    public const PURGE_SCAN_MANUAL     = 'purge.scan.manual';
    public const ARCHIVE_STORAGE_TEST  = 'archive.storage_test';

    // Self-heal (request-driven watchdog)
    public const SELF_HEAL_TICK      = 'self_heal.tick';
    public const SELF_HEAL_CLOSE_RUN = 'self_heal.close.run';
    public const SELF_HEAL_CLOSE_SKIP = 'self_heal.close.skip';

    // Mail
    public const MAIL_BATCH_START         = 'mail.batch.start';
    public const MAIL_BATCH_DONE          = 'mail.batch.done';
    public const MAIL_RESULT_BATCH_START  = 'mail.result.batch.start';
    public const MAIL_RESULT_BATCH_DONE   = 'mail.result.batch.done';
    public const MAIL_RESULT_SILENT_START = 'mail.result.silent.start';
    public const MAIL_RESULT_SILENT_DONE  = 'mail.result.silent.done';
    public const MAIL_MEMBER_SKIP         = 'mail.member.skip';
    public const MAIL_MEMBER_SILENT       = 'mail.member.silent';
    public const MAIL_MEMBER_NO_EMAIL     = 'mail.member.no_email';
    public const MAIL_MEMBER_FAIL         = 'mail.member.fail';
}
