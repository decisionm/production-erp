import log from 'electron-log';

/**
 * Rotating file log — the audit trail TALLY-SYNC-MASTER-PLAN.md §5 requires
 * ("which voucher, from whom, when"), since nobody on the cloud side has
 * visibility into this machine's local network. Default location:
 * %APPDATA%/tally-sync-agent/logs/main.log on Windows.
 */
log.transports.file.level = 'info';
log.transports.file.maxSize = 5 * 1024 * 1024; // 5MB, electron-log auto-rotates on top of this
log.transports.console.level = process.env.NODE_ENV === 'development' ? 'debug' : false;

export default log;

export function logFilePath(): string {
    return log.transports.file.getFile().path;
}
