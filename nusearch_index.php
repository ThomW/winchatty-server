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

require_once 'include/Global.php';

if (php_sapi_name() !== 'cli')
   die('Must be run from the command line.');

define('MAX_NUKED_RETRIES',     1);
define('TOTAL_TIME_SEC',        55);
define('RETRY_INTERVAL_SEC',    25);
define('NEW_POST_INTERVAL_SEC', 10);
define('DELAY_USEC',            0); # 1 sec = 1000000 usec

define('SQL_GET_NEXT_NEWEST_NUKED_POST_ID', 
       "SELECT id FROM nuked_post WHERE reattempts < 1 AND last_date < (NOW() - interval '15 minutes') ORDER BY reattempts, id DESC LIMIT 1");
define('SQL_GET_NEXT_OLDEST_NUKED_POST_ID', 
       "SELECT id FROM nuked_post WHERE reattempts < 1 AND last_date < (NOW() - interval '15 minutes') ORDER BY reattempts, id LIMIT 1");
define('SQL_GET_NEXT_OLD_POST_ID',
       'SELECT next_low_id FROM indexer LIMIT 1');
define('SQL_GET_NEXT_NEW_POST_ID',
       'SELECT next_high_id FROM indexer LIMIT 1');
define('SQL_DECREMENT_NEXT_OLD_POST_ID',
       'UPDATE indexer SET next_low_id = next_low_id - 1');
define('SQL_INCREMENT_NEXT_NEW_POST_ID',
       'UPDATE indexer SET next_high_id = next_high_id + 1');
define('SQL_GET_POST_ID',
       'SELECT id FROM post WHERE id = $1');
define('SQL_GET_NUKED_POST_ID',
       'SELECT id FROM nuked_post WHERE id = $1');
define('SQL_GET_NUKED_POST_RETRIES',
       'SELECT reattempts FROM nuked_post WHERE id = $1');
define('SQL_UPDATE_NUKED_POST',
       'UPDATE nuked_post SET reattempts = reattempts + 1, error = $1, last_date = NOW() WHERE id = $2');
define('SQL_INSERT_NUKED_POST',
       'INSERT INTO nuked_post (id, reattempts, error, last_date) VALUES ($3, $1, $2, NOW())');
define('SQL_DELETE_NUKED_POST',
       'DELETE FROM nuked_post WHERE id = $1');
define('SQL_GET_POST_CATEGORY',
       'SELECT category FROM post WHERE id = $1');
define('SQL_UPDATE_CATEGORY',
       'UPDATE post SET category = $1 WHERE id = $2');
define('SQL_INSERT_THREAD',
       'INSERT INTO thread (id, bump_date, date) VALUES ($1, $2, $3)');
define('SQL_INSERT_POST',
       'INSERT INTO post (id, thread_id, parent_id, author, category, date, body, author_c, body_c) VALUES ($1, $2, $3, $4, $5, $6, $7, $8, $9)');
define('SQL_SELECT_THREAD_ID',
       'SELECT id FROM thread WHERE id = $1');
define('SQL_SELECT_THREAD_POST_IDS',
       'SELECT id FROM post WHERE thread_id = $1');
define('SQL_DELETE_POST',
       'DELETE FROM post WHERE id = $1');
define('SQL_BUMP_THREAD',
       'UPDATE thread SET bump_date = $2 WHERE id = $1');

startIndex();

# Globals
$totalPostsIndexed = 0;
$retryFlipFlop = false;
$cachedThreads = array();
$statusFlag = ' ';
$inserted = false;
$insertedId = false;

function startIndex() # void
{
   global $totalPostsIndexed;
   global $statusFlag;
   global $inserted;
   global $insertedId;
   global $cachedThreads;
   $pg = false;
   $cycleStartTime = time();

   try
   {
      checkInternet();
      $pg = connectToDatabase();

      $story = ChattyParser()->getStory(0, 1);
      $knownLastID = getLastID($story);
      printf("Known last ID: %d\n", $knownLastID);

      $lastNukeRetry = time();
      $lastNewPost = 0;

      while ((time() - $cycleStartTime) < TOTAL_TIME_SEC)
      {
         if ((time() - $lastNukeRetry) >= RETRY_INTERVAL_SEC)
         {
            $statusFlag = 'R';
            beginTransaction($pg);
            $more = retryNukedPost($pg);
            commitTransaction($pg);

            $lastNukeRetry = time();
         }

         $forceReadNewPosts = false;
         if (file_exists('/mnt/ssd/ChattyIndex/ForceReadNewPosts'))
         {
            $forceReadNewPosts = true;
            unlink('/mnt/ssd/ChattyIndex/ForceReadNewPosts');
         }

         if ((time() - $lastNewPost) >= NEW_POST_INTERVAL_SEC || $forceReadNewPosts)
         {
            $statusFlag = 'N';
            $more = true;
            $inserted = false;
            $insertedId = 0;
            while ($more && (time() - $cycleStartTime) < TOTAL_TIME_SEC)
            {
               beginTransaction($pg);
               $ret = downloadNewPost($pg, $knownLastID);
               $exists = $ret[0];
               $id = $ret[1];
               commitTransaction($pg);
               $more = $exists;
            }

            if ($inserted)
            {
               file_put_contents('/mnt/ssd/ChattyIndex/LastID2', intval($insertedId));
               rename('/mnt/ssd/ChattyIndex/LastID2', '/mnt/ssd/ChattyIndex/LastID');
            }

            $lastNewPost = time();
         }

         // Uncomment this to enable indexing old posts
         /*
         $statusFlag = 'O';
         beginTransaction($pg);
         $more = downloadOldPost($pg);
         commitTransaction($pg); 
         */

         // Comment this if indexing old posts is enabled above.
         sleep(1);
      }

      generateFrontPageFile($pg);
   }
   catch (Exception $e)
   {
      printf("\nERROR: %s\n", $e->getMessage());
      if ($pg !== false)
         rollbackTransaction($pg);
   }

   if ($pg !== false)
      disconnectFromDatabase($pg);

   printf("\n$totalPostsIndexed posts indexed.\n");
}

function getLastID($story) # integer
{
   $thread = $story['threads'][0];
   $lastID = 0;
   foreach ($thread['replies'] as $post)
   {
      $postID = intval($post['id']);
      if ($postID > $lastID)
         $lastID = $postID;
   }
   return $lastID;
}

function connectToDatabase() # postgresql
{
   $pg = pg_connect('dbname=chatty user=nusearch password=nusearch');
   if ($pg === false)
      throw new Exception('Failed to connect to chatty database.');
   return $pg;
}

function disconnectFromDatabase($pg) # void
{
   pg_close($pg);
}

function beginTransaction($pg) # void
{
   if (pg_query($pg, 'BEGIN') === false)
      throw new Exception('Failed to begin transaction.');
}

function commitTransaction($pg) # void
{
   if (pg_query($pg, 'COMMIT') === false)
      throw new Exception('Failed to commit transaction.');
}

function rollbackTransaction($pg) # void
{
   if (pg_query($pg, 'ROLLBACK') === false)
      throw new Exception('Failed to rollback transaction.');
}

function retryNukedPost($pg) # bool
{
   $nukedPostID = getNextNukedPostID($pg);
   if ($nukedPostID === false)
      return false; # No more nuked posts

   tryIndexPost($pg, $nukedPostID, false);

   return true; # May be more nuked posts
}

function getNextNukedPostID($pg) # integer (post ID) or false
{
   global $retryFlipFlop;
   $retryFlipFlop = !$retryFlipFlop;
   $id = false;
   $cmd = $retryFlipFlop ? SQL_GET_NEXT_NEWEST_NUKED_POST_ID : SQL_GET_NEXT_OLDEST_NUKED_POST_ID;

   $id = selectValueOrFalse($pg, $cmd, array());
   if ($id === false)
      return false;
   else
      return intval($id);
}

function tryIndexPost($pg, $id, $ignoreNuke) # bool
{
   global $statusFlag;
   global $inserted;
   global $insertedId;
   # $id may be new to the database, or an existing post, or an existing nuked post.
   # Returns true if the post exists, false if the post is nuked.

   if (isPostIndexed($pg, $id))
      return true; # No need to do anything.  It's good.

   $alreadyNuked = isPostNuked($pg, $id);
   $reattempts = $alreadyNuked ? getNukedPostRetries($pg, $id) : 0;
   if ($alreadyNuked && $reattempts >= MAX_NUKED_RETRIES)
      return false; # We've given up on this one.

   $nuked = false;
   $error = false;
   $body = '???';
   $author = '???';
   $date = '???';
   $threadID = 0;

   try
   {
      $thread = getThread($id); # throws exception on failure
      $postWasFound = indexThread($pg, $id, $thread);

      if (!$postWasFound)
      {
         $nuked = true;
         $error = 'Post was not found in the thread.';
      }
      else
      {
         foreach ($thread['replies'] as $reply)
         {
            if ($reply['id'] == $id)
            {
               $body = $reply['body'];
               $author = $reply['author'];
               $date = $reply['date'];
               $threadID = $reply['thread_id'];
               break;
            }
         }
      }
   }
   catch (Exception $e)
   {
      $nuked = true;
      $error = $e->getMessage();
   }

   if ($nuked)
   {
      if ($ignoreNuke)
      {
         # We didn't really expect it to be here anyway.
         printf("%s  %d  ---\n", $statusFlag, $id);
         return false;
      }

      checkInternet();
      printf("%s  %d  * N U K E D *\n", $statusFlag, $id);

      if ($alreadyNuked)
         executeOrThrow($pg, SQL_UPDATE_NUKED_POST, array($error, $id));
      else
         executeOrThrow($pg, SQL_INSERT_NUKED_POST, array(0, $error, $id));
      return false;
   }
   else
   {
      $body = str_replace('<br />' , ' ', $body);
      $body = strip_tags($body);
      while (strpos($body, '  ') !== false)
         $body = str_replace('  ', ' ', $body);
      $body = substr($body, 0, 36);
      while (strlen($body) < 36)
         $body .= ' ';
      $author = substr($author, 0, 10);
      while (strlen($author) < 10)
         $author .= ' ';
      $date = date('m/d/y H:i', strtotime($date));
      printf("%s  %d  %s : %s  %s\n", $statusFlag, $id, $body, $author, $date);

      if ($statusFlag == 'N')
      {
         $inserted = true;
         $insertedId = $id;
      }

      return true;
   }
}

function indexThread($pg, $id, $thread) # bool - whether $id was found among $thread
{
   global $totalPostsIndexed;
   # This will delete nuked_post records if the posts are found here.
   
   $targetId = $id;
   $targetFound = false;

   # See if the thread itself is indexed.
   if (!isset($thread['id']))
      throw new Exception('Thread object does not contain an id.');
   $threadId = intval($thread['id']);
   if ($threadId <= 0)
      throw new Exception('Invalid thread ID.');
   $foundThreadId = selectValueOrFalse($pg, SQL_SELECT_THREAD_ID, array($threadId));
   if ($foundThreadId === false)
   {
      # Need to create the thread record.
      $threadDate = false;
      $bumpDate = 0;
      foreach ($thread['replies'] as $reply)
      {
         if ($threadDate === false)
            $threadDate = strtotime($reply['date']);
         if (strtotime($reply['date']) > $bumpDate)
            $bumpDate = strtotime($reply['date']);
      }
      if ($threadDate === false)
         $threadDate = strtotime('1969-12-31');
      executeOrThrow($pg, SQL_INSERT_THREAD, array($threadId, date('c', $bumpDate), date('c', $threadDate)));
   }

   foreach ($thread['replies'] as $post)
   {
      $id = intval($post['id']);

      if ($targetId == $id)
         $targetFound = true;

      if (isPostNuked($pg, $id))
      {
         # Was nuked in a previous scan, but is obviously no longer nuked.
         executeOrThrow($pg, SQL_DELETE_NUKED_POST, array($id));
      }

      if (isPostIndexed($pg, $id))
      {
         # Already indexed, but we may need to update the moderation flag.
         $oldModFlag = getPostModerationFlag($pg, $id);
         $newModFlag = $post['category'];

         if ($oldModFlag != $newModFlag)
            executeOrThrow($pg, SQL_UPDATE_CATEGORY, array($newModFlag, $id));
      }
      else
      {
         $totalPostsIndexed++;
         if (!isset($post['body']))
            throw new Exception('No post body???');

         # Not indexed.  Insert a new post record.
         $authorC = strtolower($post['author']);
         $bodyC = strtolower(strip_tags($post['body']));
         executeOrThrow($pg, SQL_INSERT_POST, array(
            $id, $threadId, $post['parent_id'], $post['author'], $post['category'], $post['date'], $post['body'], $authorC, $bodyC));

         # Update our text index
         updateIndexForPost($pg,  $id, $bodyC);
      }
   }

   # Find the newest post and bump the thread to its date.
   $newestTime = 0;
   foreach ($thread['replies'] as $post)
      if (strtotime($post['date']) > $newestTime)
         $newestTime = strtotime($post['date']);
   executeOrThrow($pg, SQL_BUMP_THREAD, array($threadId, date('c', $newestTime)));

   # See if there are any posts in the database which have since been nuked
   foreach (selectArrayOrThrow($pg, SQL_SELECT_THREAD_POST_IDS, array($threadId)) as $postId)
   {
      $found = false;
      foreach ($thread['replies'] as $post)
      {
         if ($post['id'] == $postId)
         {
            $found = true;
            break;
         }
      }
      if (!$found)
      {
         # $postId exists in the database but not in real ife.  It has been nuked.
         # We will delete it from our database.
         executeOrThrow($pg, SQL_DELETE_POST, array($postId));
      }
   }

   return $targetFound;
}

function updateIndexForPost($pg, $id, $bodyC)
{
   executeOrThrow($pg, 'INSERT INTO post_index (id, body_c_ts) VALUES ($1, to_tsvector($2))', array($id, $bodyC));
}

function isPostIndexed($pg, $id) # bool
{
   return selectValueOrFalse($pg, SQL_GET_POST_ID, array(intval($id))) !== false;
}

function isPostNuked($pg, $id) # bool
{
   return selectValueOrFalse($pg, SQL_GET_NUKED_POST_ID, array(intval($id))) !== false;
}

function getNukedPostRetries($pg, $id) # integer
{
   return intval(selectValueOrThrow($pg, SQL_GET_NUKED_POST_RETRIES, array(intval($id))));
}

function getPostModerationFlag($pg, $id) # integer
{
   return intval(selectValueOrThrow($pg, SQL_GET_POST_CATEGORY, array(intval($id))));
}

function downloadNewPost($pg, $maxID) # bool
{
   $nextPostID = intval(selectValueOrThrow($pg, SQL_GET_NEXT_NEW_POST_ID, array()));

   $ignoreNuke = $nextPostID > $maxID; # We'll try anyway, but don't mark it as a nuke if it's gone.

   $exists = tryIndexPost($pg, $nextPostID, $ignoreNuke);

   if (!$ignoreNuke || $exists)
      executeOrThrow($pg, SQL_INCREMENT_NEXT_NEW_POST_ID, array());
   return array($exists, $nextPostID);
}

function downloadOldPost($pg) # bool
{
   $nextPostID = intval(selectValueOrThrow($pg, SQL_GET_NEXT_OLD_POST_ID, array()));

   if ($nextPostID <= 0)
   {
      sleep(1);
      return false;
   }

   tryIndexPost($pg, $nextPostID, false);
   executeOrThrow($pg, SQL_DECREMENT_NEXT_OLD_POST_ID, array());
   return true;
}

function selectValueOrFalse($pg, $sql, $args) # value or false
{
   $row = selectRowOrFalse($pg, $sql, $args);
   return $row === false ? false : $row[0];
}

function selectValueOrThrow($pg, $sql, $args) # value
{
   $ret = selectValueOrFalse($pg, $sql, $args);
   if ($ret === false)
      throw new Exception('SQL query returned zero rows.');
   else
      return $ret;
}

function selectRowOrFalse($pg, $sql, $args) # dict or false
{
   $rs = pg_query_params($pg, $sql, $args);
   if ($rs === false)
      throw new Exception('selectValue failed.');
   $row = pg_fetch_row($rs);
   if ($row === false)
      return false;
   else
      return $row;
}

function selectRowOrThrow($pg, $sql, $args) # dict
{
   $ret = selectRowOrFalse($pg, $sql, $args);
   if ($ret === false)
      throw new Exception('SQL query returned zero rows.');
   else
      return $ret;
}

function selectArrayOrThrow($pg, $sql, $args) # array of scalar values
{
   $rs = pg_query_params($pg, $sql, $args);
   if ($rs === false)
      throw new Exception('selectValue failed.');
   $ret = array();
   while (true)
   {
      $row = pg_fetch_row($rs);
      if ($row === false)
         break;
      else
         $ret[] = $row[0];
   }
   return $ret;
}

function executeOrThrow($pg, $sql, $args) # void
{
   if (pg_query_params($pg, $sql, $args) === false)
      throw new Exception('SQL execute failed.');
}

function checkInternet() # void
{
   $curl = curl_init();
   curl_setopt($curl, CURLOPT_FOLLOWLOCATION, true);
   curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
   curl_setopt($curl, CURLOPT_HEADER, true);
   curl_setopt($curl, CURLOPT_ENCODING, 'gzip');
   curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, false);
   curl_setopt($curl, CURLOPT_USERAGENT, 'WinChatty NuSearch');
   curl_setopt($curl, CURLOPT_HTTPHEADER, array('X-Requested-With: libcurl'));
   curl_setopt($curl, CURLOPT_CONNECTTIMEOUT, 5);
   curl_setopt($curl, CURLOPT_URL, "http://www.shacknews.com/robots.txt");
   curl_setopt($curl, CURLOPT_POST, false);
   $robots = curl_exec($curl);
   curl_close($curl);

   if ($robots === false)
      throw new Exception('Internet access problem.');
   if (strpos($robots, 'Disallow:') === false)
      throw new Exception('robots.txt data was not expected.');
}

function flagStringToInt($flag)
{
   switch ($flag)
   {
      case "informative": return 6;
      case "interesting": return 6;
      case "nws":         return 2;
      case "stupid":      return 3;
      case "offtopic":    return 5;
      case "tangent":     return 5;
      case "ontopic":     return 1;
      case "political":   return 4;
      case "religious":   return 4;
      default:            return 1;
   }
}

function getThread($id) # thread object
{
   global $cachedThreads;

   $thread = false;
   if (isset($cachedThreads[strval($id)]))
   {
      $thread = $cachedThreads[strval($id)];
   }
   else
   {
      usleep(DELAY_USEC);
      $thread = ThreadParser()->getThread($id);
      foreach ($thread['replies'] as $reply)
         $cachedThreads[strval($reply['id'])] = $thread;
   }
   $threadReplies = $thread['replies'];

   $threadID = $thread['id'];

   foreach ($threadReplies as $replyIndex => $reply)
   {
      $parentID = false;

      if (!isset($reply['body']) || !isset($reply['date']))  # Who even knows why this happens sometimes.
         throw new Exception("Something went wrong.  This post is missing its body/date.\n");

      # Strip newlines from the body.  It doesn't hurt anything; newlines are indicated with <br/>.
      $body = $reply['body'];
      $body = str_replace("\r", '', $body);
      $body = str_replace("\n", ' ', $body);
      $threadReplies[$replyIndex]['body'] = $body;

      # Assign parent_id and thread_id
      $threadReplies[$replyIndex]['thread_id'] = $threadID;

      if ($replyIndex === 0)
      {
         # The OP's 'parent_id' is 0.
         $parentID = 0;
      }
      else
      {
         # Search upwards for the first post with depth equal to one less than this post's depth.
         $targetDepth = intval($reply['depth']) - 1;

         $parentIndex = $replyIndex;
         while (intval($threadReplies[$parentIndex]['depth']) != $targetDepth)
            $parentIndex--;

         # Pull the ID from the parent post.
         $parentID = intval($threadReplies[$parentIndex]['id']);
      }
      $threadReplies[$replyIndex]['parent_id'] = $parentID;

      # Reformat the date from "Nov 25, 2013 7:10pm PST" into ISO 8601
      $time = strtotime($reply['date']);
      $iso8601 = date('c', $time);
      $threadReplies[$replyIndex]['date'] = $iso8601;

      # Make sure the ID is an integer
      $threadReplies[$replyIndex]['id'] = intval($reply['id']);

      # Convert the category to an integer
      $threadReplies[$replyIndex]['category'] = flagStringToInt($threadReplies[$replyIndex]['category']);
   }

   $thread['replies'] = $threadReplies;
   return $thread;
}

function generateFrontPageFile($pg)
{
   global $totalPostsIndexed;
   $frontPageDataFilePath = search_data_directory . 'FrontPageData';
   $diskFreeBytes = intval(`/bin/df | /bin/grep sdd1 | /usr/bin/awk '{ print $4 }'`);

   /*$oldPostID = intval(selectValueOrThrow($pg, SQL_GET_NEXT_OLD_POST_ID, array())) + 1;
   $newPostID = intval(selectValueOrThrow($pg, SQL_GET_NEXT_NEW_POST_ID, array())) - 1;
   $oldestDate = false;
   $newestDate = false;

   while ($oldestDate === false)
   {
      $oldestDate = selectValueOrFalse($pg, 'SELECT date FROM post WHERE id = $1', array($oldPostID));
      $oldPostID++;
   }

   while ($newestDate === false)
   {
      $newestDate = selectValueOrFalse($pg, 'SELECT date FROM post WHERE id = $1', array($newPostID));
      $newPostID--;
   }*/

   $data = array(
      'thread_count'       => 0, #selectValueOrThrow($pg, 'SELECT COUNT(*) FROM thread', array()),
      'post_count'         => 0, #selectValueOrThrow($pg, 'SELECT COUNT(*) FROM post', array()),
      'nuked_post_count'   => 0, #selectValueOrThrow($pg, 'SELECT COUNT(*) FROM nuked_post', array()),
      'pending_nuke_count' => 0, #selectValueOrThrow($pg, 'SELECT COUNT(*) FROM nuked_post WHERE reattempts < 1', array()),
      'oldest_post_date'   => 0, #strtotime($oldestDate),
      'newest_post_date'   => 0, #strtotime($newestDate),
      'database_size'      => selectValueOrThrow($pg, "SELECT pg_catalog.pg_database_size(d.datname) AS Size FROM pg_catalog.pg_database d WHERE d.datname = 'chatty'", array()),
      'disk_free'          => $diskFreeBytes,
      'last_index'         => time(),
      'last_index_count'   => $totalPostsIndexed,
      'total_time_sec'     => TOTAL_TIME_SEC,
      'uptime'             => `/usr/bin/uptime`
   );
   file_put_contents($frontPageDataFilePath, serialize($data));
}

