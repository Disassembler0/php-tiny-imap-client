<?php
// Example - Fetch and save all incoming CSV files

require 'imapclient.php';

$client = new ImapClient('box@domain.com', 'password'); // Connect to mailbox.
foreach ($client->list_messages() as $msg) { // Iterate over all existing messages in the mailbox.
	foreach ($msg->attachments as $attachment) { // Iterate over all attachments of a message.
		$ext = pathinfo($attachment['filename'], PATHINFO_EXTENSION); // Get file extension of an attachment.
		if (strtolower($ext) === 'csv') // If it's CSV, save the attachment to file.
			file_put_contents('/srv/www/import/'.$attachment['filename'], $attachment['data']);
	}
	$msg->delete(); // Delete the message regardless of what was the content.
}
$client->close(); // Close connection to mailbox and expunge deleted messages.
