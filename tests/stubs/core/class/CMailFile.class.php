<?php

declare(strict_types=1);

class CMailFile
{
    public function __construct(
        string $subject = '',
        string $to = '',
        string $from = '',
        string $msg = '',
        array $filename_list = [],
        array $mimetype_list = [],
        array $mimefilename_list = [],
        string $addr_cc = '',
        string $addr_bcc = '',
        int|string $deliveryreceipt = '',
        int $msgishtml = 0,
        string $errors_to = '',
        string $css = '',
        string $trackid = '',
        string $moreinheader = '',
        string $sendcontext = '',
        string $replyto = ''
    ) {
    }

    public function sendfile(): int
    {
        return 1;
    }
}
