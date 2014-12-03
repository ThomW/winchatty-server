<?
# WinChatty Server
# Copyright (C) 2013 Brian Luft
# 
# This program is free software; you can redistribute it and/or modify it under the terms of the GNU General Public 
# License as published by the Free Software Foundation; either version 2 of the License, or (at your option) any later 
# version.
# 
# This program is distributed in the hope that it will be useful, but WITHOUT ANY WARRANTY; without even the implied 
# warranty of MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the GNU General Public License for more 
# details.
# 
# You should have received a copy of the GNU General Public License along with this program; if not, write to the Free 
# Software Foundation, Inc., 51 Franklin Street, Fifth Floor, Boston, MA  02110-1301, USA.

require_once 'Global.php';

if (V2_USE_PUSH_SERVER)
{
   # Redirect to node.js
   nsc_jsonHeader();
   $lastId = nsc_getArg('lastEventId', 'INT');
   header("HTTP/1.1 301 Moved Permanently");
   header('Location: http://notifications.winchatty.com:8080/v2/waitForEvent?lastEventId=' . $lastId);
   die();
}

nsc_jsonHeader();
nsc_assertGet();
$filePath = V2_DATA_PATH . 'LastEventID';
$eventsFilePath = V2_DATA_PATH . 'LastEvents';
$lastId = nsc_getArg('lastEventId', 'INT');
$includeParentAuthor = nsc_getArg('includeParentAuthor', 'BIT?', false);

$attempts = 0; # 10 minute timeout
while ($attempts < 600 && intval(file_get_contents($filePath)) <= $lastId)
{
   $attempts++;
   sleep(1);
   # I know, right?  Gets the job done though.  Programming is hard.
}

$lastEvents = unserialize(file_get_contents($eventsFilePath));
$returnEvents = array();
foreach ($lastEvents as $event)
   if ($event['eventId'] > $lastId)
      $returnEvents[] = $event;

if (count($returnEvents) > 100)
{
   # Read from the database instead.
   $pg = nsc_connectToDatabase();

   $rows = nsc_query($pg, 'SELECT id, date, type, data FROM event WHERE id > $1 ORDER BY id', array($lastId));
   if (count($rows) > 0 && intval($rows[0][0]) != $lastId + 1)
      nsc_die('ERR_TOO_MANY_EVENTS', 'Too many events have occurred since the specified last event ID.');

   $returnEvents = array();
   foreach ($rows as $row)
   {
      $eventData = json_decode(strval($row[3]), true);

      if (!$includeParentAuthor && isset($eventData['parentAuthor']))
         unset($eventData['parentAuthor']);

      $returnEvents[] = array(
         'eventId' => intval($row[0]),
         'eventDate' => nsc_date(strtotime($row[1])),
         'eventType' => strval($row[2]),
         'eventData' => $eventData,
      );
   }
}
else
{
   $returnEvents = array_reverse($returnEvents);

   if (!$includeParentAuthor)
   {
      for ($i = 0; $i < count($returnEvents); $i++)
      {
         $event = $returnEvents[$i];
         $eventData = $event['eventData'];
         if (isset($eventData->parentAuthor))
         {
            unset($eventData->parentAuthor);
            $event['eventData'] = $eventData;
            $returnEvents[$i] = $event;
         }
      }
   }
}

if (count($returnEvents) > 0)
   $lastId = $returnEvents[count($returnEvents) - 1]['eventId'];

echo json_encode(array('lastEventId' => $lastId, 'events' => $returnEvents));        
