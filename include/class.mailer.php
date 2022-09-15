<?php
/*********************************************************************
    class.mailer.php

    osTicket/Mail/Mailer

    Wrapper for sending emails via SMTP / SendMail

    Peter Rotich <peter@osticket.com>
    Copyright (c)  osTicket
    http://www.osticket.com

    Released under the GNU General Public License WITHOUT ANY WARRANTY.
    See LICENSE.TXT for details.

    vim: expandtab sw=4 ts=4 sts=4:
**********************************************************************/
namespace osTicket\Mail;

class Mailer {
    var $email = null;
    var $accounts = [];

    var $ht = array();
    var $attachments = array();
    var $options = array();
    var $eol="\n";

    function __construct(\Email $email=null, array $options=array()) {
        global $cfg;

        // Get all possible outgoing emails accounts (SMTP) to try
        if (($email instanceof \Email)
                && ($smtp=$email->getSmtpAccount(false))
                && $smtp->isActive()) {
            $this->accounts[$smtp->getId()] = $smtp;
        }

        if ($cfg
                && ($smtp=$cfg->getDefaultMTA())
                && $smtp->isActive()) {
            $this->accounts[$smtp->getId()] = $smtp;
            if ($smtp->allowSpoofing() || !$email)
                $email = $smtp->getEmail();
        }

        if (!$email && $cfg && ($email=$cfg->getDefaultEmail())) {
            if (($smtp=$email->getSmtpAccount(false)) && $smtp->isActive())
                $this->accounts[$smtp->getId()] = $smtp;
        }

        $this->email = $email;
        $this->attachments = array();
        $this->options = $options;
        if (isset($this->options['eol']))
            $this->eol = $this->options['eol'];
        elseif (defined('MAIL_EOL') && is_string(MAIL_EOL))
            $this->eol = MAIL_EOL;
    }

    function getEOL() {
        return $this->eol;
    }

    function getSmtpAccounts() {
        return $this->accounts;
    }

    function getEmail() {
        return $this->email;
    }

    /* FROM Address */
    function setFromAddress($from) {
        $this->ht['from'] = $from;
    }

    function getFromAddress($options=array()) {

        if (!$this->ht['from'] && ($email=$this->getEmail())) {
            if (($name = $options['from_name'] ?: $email->getName()))
                $this->ht['from'] =sprintf('"%s" <%s>', $name, $email->getEmail());
            else
                $this->ht['from'] =sprintf('<%s>', $email->getEmail());
        }

        return $this->ht['from'];
    }

    /* attachments */
    function getAttachments() {
        return $this->attachments;
    }

    function addAttachment(\Attachment $attachment) {
        // XXX: This looks too assuming; however, the attachment processor
        // in the ::send() method seems hard coded to expect this format
        $this->attachments[] = $attachment;
    }

    function addAttachmentFile(\AttachmentFile $file) {
        // XXX: This looks too assuming; however, the attachment processor
        // in the ::send() method seems hard coded to expect this format
        $this->attachments[] = $file;
    }

    function addFileObject(\FileObject $file) {
        $this->attachments[] = $file;
    }

    function addAttachments($attachments) {
        foreach ($attachments as $a) {
            if ($a instanceof \Attachment)
                $this->addAttachment($a);
            elseif ($a instanceof \AttachmentFile)
                $this->addAttachmentFile($a);
            elseif ($a instanceof \FileObject)
                $this->addFileObject($a);
        }
    }

    /**
     * getMessageId
     *
     * Generates a unique message ID for an outbound message. Optionally,
     * the recipient can be used to create a tag for the message ID where
     * the user-id and thread-entry-id are encoded in the message-id so
     * the message can be threaded if it is replied to without any other
     * indicator of the thread to which it belongs. This tag is signed with
     * the secret-salt of the installation to guard against false positives.
     *
     * Parameters:
     * $recipient - (EmailContact|null) recipient of the message. The ID of
     *      the recipient is placed in the message id TAG section so it can
     *      be recovered if the email replied to directly by the end user.
     * $options - (array) - options passed to ::send(). If it includes a
     *      'thread' element, the threadId will be recorded in the TAG
     *
     * Returns:
     * (string) - email message id, without leading and trailing <> chars.
     * See the Format below for the structure.
     *
     * Format:
     * VA-B-C, with dash separators and A-C explained below:
     *
     * V: Version code of the generated Message-Id
     * A: Predictable random code — used for loop detection (sysid)
     * B: Random data for unique identifier (rand)
     * C: TAG: Base64(Pack(userid, entryId, threadId, type, Signature)),
     *    '=' chars discarded
     * where Signature is:
     *   Signed Tag value, last 5 chars from
     *        HMAC(sha1, Tag + rand + sysid, SECRET_SALT),
     *   where Tag is:
     *     pack(userId, entryId, threadId, type)
     */
    function getMessageId($recipient, $options=array(), $version='B') {
        $tag = '';
        $rand = \Misc::randCode(5,
            // RFC822 specifies the LHS of the addr-spec can have any char
            // except the specials — ()<>@,;:\".[], dash is reserved as the
            // section separator, and + is reserved for historical reasons
            'abcdefghiklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789_=');
        $sig = $this->getEmail()?$this->getEmail()->getEmail():'@osTicketMailer';
        $sysid = static::getSystemMessageIdCode();
        // Create a tag for the outbound email
        $entry = (isset($options['thread'])
                && ($options['thread'] instanceof \ThreadEntry))
            ? $options['thread'] : false;
        $thread = $entry ? $entry->getThread()
            : (isset($options['thread'])
                    && ($options['thread'] instanceof \Thread)
                ? $options['thread'] : false);

        switch (true) {
        case $recipient instanceof \Staff:
            $utype = 'S';
            break;
        case $recipient instanceof \TicketOwner:
            $utype = 'U';
            break;
        case $recipient instanceof \Collaborator:
            $utype = 'C';
            break;
        case  $recipient instanceof \MailingList:
            $utype = 'M';
            break;
        default:
            $utype = ($options['utype'] ?: is_array($recipient)) ? 'M' : '?';
        }


        $tag = pack('VVVa',
            $recipient instanceof \EmailContact ? $recipient->getUserId() : 0,
            $entry ? $entry->getId() : 0,
            $thread ? $thread->getId() : 0,
            $utype ?: '?'
        );
        // Sign the tag with the system secret salt
        $tag .= substr(hash_hmac('sha1', $tag.$rand.$sysid, SECRET_SALT, true), -5);
        $tag = str_replace('=','',base64_encode($tag));
        return sprintf('B%s-%s-%s-%s',
            $sysid, $rand, $tag, $sig);
    }

    /**
     * decodeMessageId
     *
     * Decodes a message-id generated by osTicket using the ::getMessageId()
     * method of this class. This will digest the received message-id token
     * and return an array with some information about it.
     *
     * Parameters:
     * $mid - (string) message-id from an email Message-Id, In-Reply-To, and
     *      References header.
     *
     * Returns:
     * (array) of information containing all or some of the following keys
     *      'loopback' - (bool) true or false if the message originated by
     *          this osTicket installation.
     *      'version' - (string|FALSE) version code of the message id
     *      'code' - (string) unique but predictable help desk message-id
     *      'id' - (string) random characters serving as the unique id
     *      'entryId' - (int) thread-entry-id from which the message originated
     *      'threadId' - (int) thread-id from which the message originated
     *      'staffId' - (int|null) staff the email was originally sent to
     *      'userId' - (int|null) user the email was originally sent to
     *      'userClass' - (string) class of user the email was sent to
     *          'U' - TicketOwner
     *          'S' - Staff
     *          'C' - Collborator
     *          'M' - Multiple
     *          '?' - Something else
     */
    static function decodeMessageId($mid) {
        // Drop <> tokens
        $mid = trim($mid, '<> ');
        // Drop email domain on rhs
        list($lhs, $sig) = explode('@', $mid, 2);
        // LHS should be tokenized by '-'
        $parts = explode('-', $lhs);

        $rv = array('loopback' => false, 'version' => false);

        // There should be at least two tokens if the message was sent by
        // this system. Otherwise, there's nothing to be detected
        if (count($parts) < 2)
            return $rv;

        $self = get_called_class();
        $decoders = array(
        'A' => function($id, $tag) use ($sig) {
            // Old format was VA-B-C-D@sig, where C was the packed tag and D
            // was blank
            $format = 'Vuid/VentryId/auserClass';
            $chksig = substr(hash_hmac('sha1', $tag.$id, SECRET_SALT), -10);
            if ($tag && $sig == $chksig && ($tag = base64_decode($tag))) {
                // Find user and ticket id
                return unpack($format, $tag);
            }
            return false;
        },
        'B' => function($id, $tag) use ($self) {
            $format = 'Vuid/VentryId/VthreadId/auserClass/a*sig';
            if ($tag && ($tag = base64_decode($tag))) {
                if (!($info = @unpack($format, $tag)) || !isset($info['sig']))
                    return false;
                $sysid = $self::getSystemMessageIdCode();
                $shorttag = substr($tag, 0, 13);
                $chksig = substr(hash_hmac('sha1', $shorttag.$id.$sysid,
                    SECRET_SALT, true), -5);
                if ($chksig == $info['sig']) {
                    return $info;
                }
            }
            return false;
        },
        );

        // Detect the MessageId version, which should be the first char
        $rv['version'] = @$parts[0][0];
        if (!isset($decoders[$rv['version']]))
            // invalid version code
            return null;

        // Drop the leading version code
        list($rv['code'], $rv['id'], $tag) = $parts;
        $rv['code'] = substr($rv['code'], 1);

        // Verify tag signature and unpack the tag
        $info = $decoders[$rv['version']]($rv['id'], $tag);
        if ($info === false)
            return $rv;

        $rv += $info;

        // Attempt to make the user-id more specific
        $classes = array(
            'S' => 'staffId', 'U' => 'userId', 'C' => 'userId',
        );
        if (isset($classes[$rv['userClass']]))
            $rv[$classes[$rv['userClass']]] = $rv['uid'];

        // Round-trip detection - the first section is the local
        // system's message-id code
        $rv['loopback'] = (0 === strcmp($rv['code'],
            static::getSystemMessageIdCode()));

        return $rv;
    }

    static function getSystemMessageIdCode() {
        return substr(str_replace('+', '=',
            base64_encode(md5('mail'.SECRET_SALT, true))),
            0, 6);
    }

    function send($recipients, $subject, $body, $options=null) {
        global $ost, $cfg;

        $messageId = $this->getMessageId($recipients, $options);
        $subject = preg_replace("/(\r\n|\r|\n)/s",'', trim($subject));

         // Create new ostTicket/Mail/Message object
        $message = new Message();
        // Set basic headers
        $message->addFrom($this->getFromAddress($options));
        $message->setSubject($subject);

        $headers = array (
            'Date'=> date('D, d M Y H:i:s O'),
            'Message-ID' => "<{$messageId}>",
            'X-Mailer' =>'osTicket Mailer',
        );

        // Add in the options passed to the constructor
        $options = ($options ?: array()) + $this->options;
        // Message Id Token
        $mid_token = '';
        // Check if the email is threadable
        if (isset($options['thread'])
            && ($options['thread'] instanceof \ThreadEntry)
            && ($thread = $options['thread']->getThread())) {

            // Add email in-reply-to references if not set
            if (!isset($options['inreplyto'])) {

                $entry = null;
                switch (true) {
                case $recipients instanceof \MailingList:
                    $entry = $thread->getLastEmailMessage();
                    break;
                case $recipients instanceof \TicketOwner:
                case $recipients instanceof \Collaborator:
                    $entry = $thread->getLastEmailMessage(array(
                                'user_id' => $recipients->getUserId()));
                    break;
                case $recipients instanceof \Staff:
                    //XXX: is it necessary ??
                    break;
                }

                if ($entry && ($mid=$entry->getEmailMessageId())) {
                    $options['inreplyto'] = $mid;
                    $options['references'] = $entry->getEmailReferences();
                }
            }

            // Embedded message id token
            $mid_token = $messageId;
            // Set Reply-Tag
            if (!isset($options['reply-tag'])) {
                if ($cfg && $cfg->stripQuotedReply())
                    $options['reply-tag'] = $cfg->getReplySeparator() . '<br/><br/>';
                else
                    $options['reply-tag'] = '';
            } elseif ($options['reply-tag'] === false) {
                $options['reply-tag'] = '';
            }
        }

        // Return-Path
        if (isset($options['nobounce']) && $options['nobounce'])
            $headers['Return-Path'] = '<>';
        elseif ($this->getEmail() instanceof \Email)
            $headers['Return-Path'] = sprintf('<%s>',
                    $this->getEmail()->getEmail());

        // Bulk.
        if (isset($options['bulk']) && $options['bulk'])
            $headers+= array('Precedence' => 'bulk');

        // Auto-reply - mark as autoreply and supress all auto-replies
        if (isset($options['autoreply']) && $options['autoreply']) {
            $headers+= array(
                    'Precedence' => 'auto_reply',
                    'X-Autoreply' => 'yes',
                    'X-Auto-Response-Suppress' => 'DR, RN, OOF, AutoReply',
                    'Auto-Submitted' => 'auto-replied');
        }

        // Notice (sort of automated - but we don't want auto-replies back
        if (isset($options['notice']) && $options['notice'])
            $headers+= array(
                    'X-Auto-Response-Suppress' => 'OOF, AutoReply',
                    'Auto-Submitted' => 'auto-generated');
        // In-Reply-To
        if (isset($options['inreplyto']) && $options['inreplyto'])
            $headers += array('In-Reply-To' => $options['inreplyto']);

        // References
        if (isset($options['references']) && $options['references']) {
            if (is_array($options['references']))
                $headers += array('References' =>
                    implode(' ', $options['references']));
            else
                $headers += array('References' => $options['references']);
        }

        // Add Headers
        $message->addHeaders($headers);

        // Add recipients
        if (!is_array($recipients) && (!$recipients instanceof \MailingList))
            $recipients =  array($recipients);
        foreach ($recipients as $recipient) {
            if ($recipient instanceof \ClientSession)
                $recipient = $recipient->getSessionUser();
            switch (true) {
                case $recipient instanceof \EmailRecipient:
                    $addr = sprintf('"%s" <%s>',
                            $recipient->getName(),
                            $recipient->getEmail());
                    switch ($recipient->getType()) {
                        case 'to':
                            $message->addTo($addr);
                            break;
                        case 'cc':
                            $message->addCc($addr);
                            break;
                        case 'bcc':
                            $message->addBcc($addr);
                            break;
                    }
                    break;
                case $recipient instanceof \TicketOwner:
                case $recipient instanceof \Staff:
                    $message->addTo(sprintf('"%s" <%s>',
                                $recipient->getName(),
                                $recipient->getEmail()));
                    break;
                case $recipient instanceof \Collaborator:
                    $message->addCc(sprintf('"%s" <%s>',
                                $recipient->getName(),
                                $recipient->getEmail()));
                    break;
                case $recipient instanceof \EmailAddress:
                    $message->addTo($recipient->getAddress());
                    break;
                default:
                    // Assuming email address.
                    $message->addTo($recipient);
            }
        }

        // Add in extra attachments, if any from template variables
        if ($body instanceof \TextWithExtras
            && ($attachments = $body->getAttachments())) {
            foreach ($attachments as $a) {
                $message->addAttachment($a->getFile());
            }
        }

        // If the message is not explicitly declared to be a text message,
        // then assume that it needs html processing to create a valid text
        // body
        $isHtml = true;
        if (!(isset($options['text']) && $options['text'])) {
            // Embed the data-mid in such a way that it should be included
            // in a response
            if ($options['reply-tag'] || $mid_token) {
                $body = sprintf('<div style="display:none"
                        class="mid-%s">%s</div>%s',
                        $mid_token,
                        $options['reply-tag'],
                        $body);
            }

            $txtbody = rtrim(\Format::html2text($body, 90, false))
                . ($messageId ? "\nRef-Mid: $messageId\n" : '');
            $message->setTextBody($txtbody);
        }
        else {
            $message->setTextBody($body);
            $isHtml = false;
        }

        if ($isHtml && $cfg && $cfg->isRichTextEnabled()) {
            // Pick a domain compatible with pear Mail_Mime
            $matches = array();
            if (preg_match('#(@[0-9a-zA-Z\-\.]+)#', $this->getFromAddress(), $matches)) {
                $domain = $matches[1];
            } else {
                $domain = '@localhost';
            }
            // Format content-ids with the domain, and add the inline images
            // to the email attachment list
            $self = $this;
            $body = preg_replace_callback('/cid:([\w.-]{32})/',
                function($match) use ($domain, $message, $self) {
                    $file = false;
                    foreach ($self->attachments as $id=>$F) {
                        if ($F instanceof \Attachment)
                            $F = $F->getFile();
                        if (strcasecmp($F->getKey(), $match[1]) === 0) {
                            $file = $F;
                            break;
                        }
                    }
                    if (!$file)
                        // Not attached yet attempt to attach it inline
                        $file = \AttachmentFile::lookup($match[1]);
                    if (!$file)
                        return $match[0];
                    $message->addInlineImage($match[1].$domain, $file);
                    // Don't re-attach the image below
                    unset($self->attachments[$file->getId()]);
                    return $match[0].$domain;
                }, $body);
            // Add an HTML body
            $message->setHtmlBody($body);
        }
        //XXX: Attachments
        if(($attachments=$this->getAttachments())) {
            foreach($attachments as $file) {
                // Read the filename from the Attachment if possible
                if ($file instanceof \Attachment) {
                    $filename = $file->getFilename();
                    $file = $file->getFile();
                } elseif ($file instanceof \AttachmentFile) {
                    $filename = $file->getName();
                }  elseif ($file instanceof \FileObject) {
                    $filename = $file->getFilename();
                } else
                    continue;
                $message->addAttachment($file, $filename);
            }
        }

        // set Body & Content Type
        $message->prepare();

        // Try possible SMTP Accounts - connections are cached per request
        // at the account level.
        foreach ($this->getSmtpAccounts() ?: [] as $account) {
            try {
                if (($smtp=$account->getSmtpConnection())
                        && $smtp->sendMessage($message))
                     return $messageId;
            } catch (\Exception $ex) {
                $alert = _S("Unable to email via SMTP")
                    .sprintf(": %1\$s (%2\$s:%3\$d)\n\n%4\$s\n",
                    $account->getEmail()->getEmail(),
                    $account->getHost(),
                    $account->getPort(),
                    $ex->getMessage());
                $this->logError($alert);
                continue;
            }
        }

        //No SMTP or it failed....use php's native mail function.
        $args =  [];
        if (isset($options['from_address']))
            $args[] = '-f '.$options['from_address'];
        elseif ($this->getEmail())
            $args = ['-f '.$this->getEmail()->getEmail()];

        try {
            // ostTicket/Mail/Sendmail transport
            $sendmail =  new  Sendmail($args);
            if ($sendmail->sendMessage($message))
                return $messageId;
        } catch (\Exception $ex) {
            $alert = _S("Unable to email via php mail function")
                .sprintf("\n\n%1\$s\n", $ex->getMessage());
            $this->logError($alert);
        }
        return false;
    }

    function logError($error) {
        global $ost;
        //NOTE: Admin alert override - don't email when having email trouble!
        $ost->logError(_S('Mailer Error'), $error, false);
    }
    function logWarning($warn) {
        global $ost;
        $ost->logWarning(_S('Mailer Error'), $warn, false);
    }
    /******* Static functions ************/

    //Emails using native php mail function - if DB connection doesn't exist.
    //Don't use this function if you can help it.
    static function sendmail($to, $subject, $message, $from, $options=null) {
        $mailer = new Mailer(null, array('notice'=>true, 'nobounce'=>true));
        $mailer->setFromAddress($from);
        return $mailer->send($to, $subject, $message, $options);
    }
}
