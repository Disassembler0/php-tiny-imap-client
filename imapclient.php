<?php
/*
*	Script: imapclient.php
*	Author: Disassembler <disassembler@dasm.cz>
*	Version: 1.0, 5.5.2015
*	Description: Tiny IMAP client simply fetching all messages from INBOX
*/

class ImapClient {
	private $conn; // Mailbox connection (resource)
	
	public function __construct($user, $pass) { // Username and password of the mailbox user
		$this->conn = imap_open('{localhost/norsh/novalidate-cert}INBOX', $user, $pass); // Connect to mailbox INBOX, trust selfsigned certs, don't use preauthenticated session.
	}
	
	public function list_messages() {
		$result = [];
		$stats = imap_check($this->conn); // Get mailbox statistics. Nmsgs property contains total number of messages (both read and unread).
		if ($stats->Nmsgs != 0) {
			$list = imap_fetch_overview($this->conn, '1:'.$stats->Nmsgs); // Fetch overview about individual messages.
			foreach ($list as $msg)
				$result[] = new ImapMessage($this->conn, $msg->msgno); // Create a message object. Pass connection by reference and the sequential identificator of message.
		}
		return $result; // Return list of fully defined Message objects.
	}
	
	public function close() {
		imap_close($this->conn, CL_EXPUNGE); // Close the mailbox connection, expunge messages previously marked for deletion.
	}
}

class ImapMessage {
	private $conn; // Mailbox connection passed from ImapClient (resource)
	public $msg_no; // Sequential message identificator (int)
	public $headers = []; // Message headers ([string => string])
	public $plainbody = ''; // Plaintext message body (string)
	public $htmlbody = ''; // HTML message body (string)
	public $attachments = []; // Message attachments ([int => ['filename' => string, 'data' => bytes]])
	
	public function __construct(&$conn, $msg_no) {
		$this->conn = $conn;
		$this->msg_no = $msg_no;
		$this->fetch_headers(); // Fully define the message based on current connection and sequential identificator.
		$this->fetch_parts();
	}
	
	private function fetch_headers() {
		$headers = imap_fetchheader($this->conn, $this->msg_no, FT_PREFETCHTEXT); // Fetch all headers as one string in RFC2822 format. Preload also body of the message.
		$headers = preg_replace('/\r\n\s+/m', ' ', $headers); // Remove line breaks from header values.
		preg_match_all('/(.+?): (.+)/', $headers, $matches); // Match keys and values of individual headers.
		foreach ($matches[1] as $key => $value) // Create associative array from the matches.
			$this->headers[$value] = trim($matches[2][$key]); // Note: This method is naive and doesn't work with multiple headers with same key (eg. 'Received'). Always returns only last matched value.
	}
	
	private function fetch_parts() {
		$structure = imap_fetchstructure($this->conn, $this->msg_no); // Fetch structure of message to see if text/plain or multipart/mixed is being handled.
		if (!property_exists($structure, 'parts')) // Simple message doesn't have 'parts' property.
			$this->decode_part($structure, 0); // Get contents of implicit part (0 is only to identify there isn't any multipart content, otherwise 0 stands for headers).
		else { // Message is multipart/mixed.
			foreach ($structure->parts as $part_no => $part) // Get contents of all parts.
				$this->decode_part($part, $part_no+1); // +1 as IMAP is counting parts from 1. 0 stands for headers which were already fetched.
		}
		$this->plainbody = trim($this->plainbody); // Trim trailing line endings from body.
		$this->htmlbody = trim($this->htmlbody);
	}
	
	private function decode_part($part, $part_no) {
		$data = $part_no === 0 ? imap_body($this->conn, $this->msg_no) : imap_fetchbody($this->conn, $this->msg_no, $part_no); // Fetch data of the part (or simple body, if 'part_no' == 0)
		if ($part->encoding === 3) // Encoding 3 is 'base64' (plain messages can be encoded as well).
			$data = base64_decode($data);
		elseif ($part->encoding === 4) // Encoding 4 is 'quoted-printable'.
			$data = quoted_printable_decode($data);
		
		if ($part->ifdparameters) { // If Content-disposition parameters exist.
			foreach ($part->dparameters as $param) {
				if (strtolower($param->attribute) === 'filename') // Search for attribute == 'filename', if it exists.
					$this->attachments[] = ['filename' => $param->value, 'data' => $data]; // Add the data as attachment.
			}
		} elseif ($part->ifparameters) { // Else if common parameters exist.
			foreach ($part->parameters as $param) { 
				if (strtolower($param->attribute) === 'name') // Search for attribute == 'name', if it exists.
					$this->attachments[] = ['filename' => $param->value, 'data' => $data]; // Add the data as attachment.
			}
		}
		
		if ($part->type === 0 && $data) { // Type 0 is 'text'.
			if (strtolower($part->subtype) === 'plain') // Messages may be split in different parts because of inline attachments.
				$this->plainbody .= trim($data)."\r\n\r\n"; // Append parts together with blank row.
			else
				$this->htmlbody .= $data.'<br><br>';
		} elseif ($part->type === 2 && $data) // Type 2 is 'message'. This can be embedded message from bounce notification, so it'll be dumped as a raw text.
			$this->plainbody .= $data."\r\n\r\n";
		
		if (property_exists($part, 'parts')) { // Recursively decode the subparts (if any).
			foreach ($part->parts as $subpart_no => $subpart)
				$this->decode_part($subpart, $part_no.'.'.($subpart_no+1));
		}
	}
	
	public function delete() { // Note: This does not delete the message immediately. Complete deletion is done by expunge when the connection is closed.
		return imap_delete($this->conn, $this->msg_no); // Mark the message as deleted.
	}
}
