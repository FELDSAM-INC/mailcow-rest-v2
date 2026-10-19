<?php

/*
 * Harness of scripts/legacy/generate-qitem-goldens.sh (feature 006 T008): the parsing part of
 * legacy inc/ajax/qitem_details.php (the `id` branch), applied unchanged to one message, with the
 * tag's own vendor libraries. Instead of answering the detail view it prints the parsed values as
 * JSON, with each attachment's SHA-256 instead of the VirusTotal link.
 *
 *   php qitem-details.php <vendor/autoload.php> <message file> <envelope recipient>
 */

require $argv[1];

function addAddresses(&$list, $mail, $headerName) {
  $addresses = $mail->getAddresses($headerName);
  foreach ($addresses as $address) {
    if (filter_var($address['address'], FILTER_VALIDATE_EMAIL)) {
      $list[] = array('address' => $address['address'], 'type' => $headerName);
    }
  }
}

$mailc = array('msg' => file_get_contents($argv[2]), 'rcpt' => $argv[3]);
$tmpdir = sys_get_temp_dir() . '/qitem-' . getmypid() . '/';
mkdir($tmpdir);

$data = array();
$mail_parser = new PhpMimeMailParser\Parser();
$html2text = new Html2Text\Html2Text();
$mail_parser->setText($mailc['msg']);
{
  $recipientsList = array();
  addAddresses($recipientsList, $mail_parser, 'to');
  addAddresses($recipientsList, $mail_parser, 'cc');
  addAddresses($recipientsList, $mail_parser, 'bcc');
  $recipientsList[] = array('address' => $mailc['rcpt'], 'type' => 'smtp');
  $data['recipients'] = $recipientsList;
}
$data['header_from'] = $mail_parser->getHeader('from');
$data['text_plain'] = $mail_parser->getMessageBody('text');
if (!json_encode($data['text_plain'])) $data['text_plain'] = '';
$data['text_html'] = $html2text->convert($mail_parser->getMessageBody('html'));
if (empty($data['text_plain']) && empty($data['text_html'])) {
  $text = trim(substr($mailc['msg'], strpos($mailc['msg'], "\r\n\r\n") + 1));
  $data['text_plain'] = 'Parser failed, assuming HTML';
  $data['text_html'] = $html2text->convert($text);
}
(empty($data['text_plain'])) ? $data['text_plain'] = '-' : null;
$data['subject'] = mb_convert_encoding($mail_parser->getHeader('subject'), "UTF-8", "auto");
(empty($data['subject'])) ? $data['subject'] = '-' : null;
$data['attachments'] = array();
$mail_parser->saveAttachments($tmpdir, true);
$atts = $mail_parser->getAttachments(true);
foreach ($atts as $key => $val) {
  $data['attachments'][$key] = array(
    'name' => $val->getFilename(),
    'type' => $val->getContentType(),
    'size' => @filesize($tmpdir . $val->getFilename()),
    'sha256' => @hash_file('SHA256', $tmpdir . $val->getFilename()),
  );
}

echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE), "\n";
