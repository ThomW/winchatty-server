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

class ClassicAdapter
{
   public static function getThread($threadID)
   {
      if ($threadID == 0)
         throw new Exception('Thread ID cannot be 0.');

      #$thread = ThreadParser()->getThread($threadID, false);
      $pg = nsc_connectToDatabase();
      $thread = nsc_v1_getThreadTree($pg, $threadID);

      $participants = self::getParticipants($thread['replies']);
      
      # Make the tree hierarchical.
      $stack = array();
      
      # Add an empty "comments" array to each post.
      foreach ($thread['replies'] as $i => $reply)
      {
         $reply['comments'] = array();
         $thread['replies'][$i] = $reply;
      }

      # Make a lookup table.  Child ID => Parent ID
      $parent_of = array();
      array_push($stack, array());
      foreach ($thread['replies'] as $i => $reply)
      {
         $parent_of[$reply['id']] = $stack[$reply['depth']];
         $stack[$reply['depth'] + 1] = $reply['id'];
      }
      
      # Reverse the lookup table.  Parent ID => Child IDs
      $children_of = array();
      foreach ($parent_of as $child => $parent)
      {
         $parent = strval($parent);
         if (isset($children_of[$parent]))
            $children_of[$parent][] = $child;
         else
            $children_of[$parent] = array($child);
      }

      # Strip the 'depth' field.
      foreach ($thread['replies'] as $i => $reply)
      {
         unset($reply['depth']);
         $thread['replies'][$i] = $reply;
      }

      # Make a lookup table.  Post ID => Post content
      $replies = array();
      foreach ($thread['replies'] as $reply)
         $replies[$reply['id']] = $reply;
      
      # Walk the $children_of table to create the hierarchy.
      $root = $thread['replies'][0];
      self::fillChildren($root, $replies, $children_of);

      $root['participants'] = $participants;
      $root['reply_count'] = count($replies);
      $root['last_reply_id'] = 0;

      return array(
         'story_id'   => $thread['story_id'],
         'last_page'  => 1,
         'page'       => 1,
         'story_name' => $thread['story_name'],
         'comments'   => array($root));
   }
   
   private static function fillChildren(&$post, &$replies, &$children_of)
   {
      $tree = array();
      $id = strval($post['id']);
      
      if (!isset($children_of[$id]))
      {
         # Leaf post.
         return;
      }
      
      foreach ($children_of[$id] as $child_id)
      {
         $child = $replies[$child_id];
         self::fillChildren($child, $replies, $children_of);
         $tree[] = $child;
      }
      
      $post['comments'] = $tree;
   }
   
   public static function getStory($story, $page)
   {
      #$chatty = ChattyParser()->getStory($story, $page);
      $pg = nsc_connectToDatabase();
      $chatty = nsc_v1_getStory($pg, $page);
      
      $json = array(
         'comments'   => array(),
         'page'       => $chatty['current_page'],
         'last_page'  => $chatty['last_page'],
         'story_id'   => $chatty['story_id'],
         'story_name' => $chatty['story_name']);

      # Strip out the replies.
      foreach ($chatty['threads'] as $thread)
      {
         $json['comments'][] = array(
            'comments'      => array(),
            'reply_count'   => $thread['reply_count'],
            'body'          => $thread['body'],
            'date'          => $thread['date'],
            'participants'  => self::getParticipants($thread['replies']),
            'category'      => $thread['category'],
            'last_reply_id' => $thread['last_reply_id'],
            'author'        => (string)$thread['author'],
            'preview'       => strip_tags($thread['preview']),
            'id'            => $thread['id']);
      }
      
      return $json;
   }

   public static function getParticipants($replies)
   {
      $participants      = array();
      $json_participants = array();

      foreach ($replies as $reply)
      {
         $author = $reply['author'];
         if (isset($participants[$author]))
            $participants[$author]++;
         else
            $participants[$author] = 1;
      }
      
      foreach ($participants as $participant => $posts)
      {
         $json_participants[] = array(
            'username'   => (string)$participant,
            'post_count' => $posts);
      }
      
      return $json_participants;
   }
   
   public static function search($terms, $author, $parentAuthor, $page = 1)
   {
      $results = SearchParser()->search($terms, $author, $parentAuthor, '', $page);

      $json    = array(
         'terms'         => $terms,
         'author'        => $author,
         'parent_author' => $parentAuthor,
         'comments'      => array(),
         'last_page'     => 1);

      foreach ($results as $result)
      {
         $json['last_page'] = ceil($result['totalResults'] / 15);
         $json['comments'][] = array(
            'comments'      => array(),
            'last_reply_id' => null,
            'author'        => $result['author'],
            'date'          => $result['date'],
            'story_id'      => $result['story_id'],
            'category'      => null,
            'reply_count'   => null,
            'id'            => $result['id'],
            'story_name'    => $result['story_name'],
            'preview'       => $result['preview'],
            'body'          => null);
      }
      
      return $json;
   }
   
   public static function getMessages($username, $password)
   {
      $o = MessageParser()->getMessages('inbox', $username, $password, 1, 50);
      
      # The iPhone app is pretty touchy about encoding.  Even though it's 
      # formatted as JSON, it still needs to be XML encoded.  WinChatty has
      # no such bug.
      $messages = array();
      
      foreach ($o['messages'] as $message)
      {
         $message['subject'] = htmlentities($message['subject']);
         $message['from']    = htmlentities($message['from']);
         $messages[]         = $message;
      }

      return array(
         'user'     => $username,
         'messages' => $messages);
   }
}
