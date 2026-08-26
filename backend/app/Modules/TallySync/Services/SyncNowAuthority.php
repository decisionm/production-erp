<?php

namespace App\Modules\TallySync\Services;

use App\Models\User;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Who may press the Tally Sync page's "Sync Now" — DEC-20260825-002's
 * "Only an Owner/Accounts permission may press it."
 *
 * OWNER/ACCOUNTS IS ALREADY SPELLED IN THIS CODEBASE and is not respelled
 * here: FC-06's reader test (AgentIdentity::mayReadPurchaseDetails) is
 * finance.view / finance.manage — "the Owner and Accounts hold it, the
 * store does not" — and the same pair is what gates purchase rates on
 * MaterialLotResource and GoodsReceiptNoteLineResource. A new permission
 * name would be worse than redundant: RoleService intersects every grant
 * with PermissionService::MODULES, so a `tally-sync.sync-now` that is not
 * in that catalogue is stripped from every role on the next save of the
 * Roles screen and the gate then refuses everyone, silently.
 *
 * TWO GATES, BOTH REQUIRED. The route sits inside `module:tally-sync`, so
 * a POST already demands tally-sync.manage; this adds the Owner/Accounts
 * half on top. A tally-sync.manage holder who is not finance may still
 * retry, dismiss and release ONE voucher they are looking at — what this
 * withholds is the queue-wide press.
 *
 * THE AGENT IS DELIBERATELY NOT HERE. AgentIdentity::mayReadPurchaseDetails
 * lets the sync agent through because it must build the voucher Tally is
 * sent; there is nothing for an agent to press. Sync Now is a person's act
 * on the queue, and the agent's own token would fail module:tally-sync
 * anyway (it holds abilities, not permissions).
 */
final class SyncNowAuthority
{
    /** The FC-06 Owner/Accounts pair, named once so a test can pin it. */
    public const PERMISSIONS = ['finance.view', 'finance.manage'];

    public static function mayRequest(?Authenticatable $actor): bool
    {
        return $actor instanceof User && $actor->hasAnyPermission(self::PERMISSIONS);
    }
}
