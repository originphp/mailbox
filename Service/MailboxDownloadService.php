<?php
namespace Origin\Mailbox\Service;

use Origin\Service\Result;
use Origin\Mailbox\Mailbox;
use Origin\Service\Service;
use Origin\Mailbox\MailFetcher;
use Origin\Core\Exception\Exception;
use Origin\Mailbox\Model\ImapMessage;
use Origin\Mailbox\Model\InboundEmail;

class MailboxDownloadService extends Service
{
    /**
     * @var \Origin\Mailbox\Model\InboundEmail
     */
    protected $InboundEmail;
    
    /**
     * @var \Origin\Mailbox\Model\ImapMessage
     */
    protected $Imap;

    /**
     * Undocumented function
     *
     * @param \Origin\Mailbox\Model\InboundEmail $InboundEmail
     * @param \Origin\Mailbox\Model\ImapMessage $Imap
     * @return void
     */
    protected function initialize(InboundEmail $InboundEmail, ImapMessage $Imap) : void
    {
        $this->InboundEmail = $InboundEmail;
        $this->Imap = $Imap;
    }

    /**
     * If this is IMAP
     *
     * @return boolean
     */
    private function isIMAP() : bool
    {
        return $this->config['protocol'] === 'imap';
    }

    /*
    * Service logic goes here and return a result object or null
    */
    protected function execute(string $account) : ?Result
    {
        $this->config = Mailbox::account($account);
        
        if ($this->isIMAP()) {
            $lastImapMessage = $this->Imap->findByAccount($account);
            if (! $lastImapMessage) {
                $lastImapMessage = $this->Imap->new(['account' => $account]);
            }
        }

        $downloadOptions = isset($lastImapMessage) ? ['messageId' => $lastImapMessage->message_id] : [];

        $messages = (new MailFetcher($this->config))->download($downloadOptions);

        $messageIds = [];

        # Save downloaded messages to database
        foreach ($messages as $message) {
            $inboundEmail = $this->InboundEmail->fromMessage($message);

            if ($this->InboundEmail->existsInDb($inboundEmail)) {
                continue;
            }

            if (! $this->InboundEmail->save($inboundEmail)) {
                throw new Exception('Error saving to database');
            }

            $messageIds[] = $inboundEmail->message_id;

            if ($this->isIMAP()) {
                $lastImapMessage->message_id = $inboundEmail->message_id;
                if (! $this->Imap->save($lastImapMessage)) {
                    throw new Exception('Error updating IMAP table');
                }
            }
        }

        return $this->result([
            'success' => true,
            'data' => $messageIds
        ]);
    }
}
