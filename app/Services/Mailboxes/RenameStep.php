<?php

namespace App\Services\Mailboxes;

/**
 * The steps of a mailbox rename, in order (contract `MailboxRenameStep.step`, feature 003 research
 * R17).
 */
enum RenameStep: string
{
    case SharedFolderPermissionsRemoval = 'sharedFolderPermissionsRemoval';
    case SharedStoreMove = 'sharedStoreMove';
    case Database = 'database';
    case MailStoreMove = 'mailStoreMove';
    case WebmailRename = 'webmailRename';
    case SharedFolderPermissionsRecreation = 'sharedFolderPermissionsRecreation';
}
