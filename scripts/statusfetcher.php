#!/usr/bin/env php
<?php
/*
 * Laconica - a distributed open-source microblogging tool
 * Copyright (C) 2008, Controlez-Vous, Inc.
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.     See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program.     If not, see <http://www.gnu.org/licenses/>.
 */

// Abort if called from a web server
if (isset($_SERVER) && array_key_exists('REQUEST_METHOD', $_SERVER)) {
    print "This script must be run from the command line\n";
    exit();
}

define('INSTALLDIR', realpath(dirname(__FILE__) . '/..'));
define('LACONICA', true);

// Uncomment this to get useful console output
define('SCRIPT_DEBUG', true);

require_once(INSTALLDIR . '/lib/common.php');

$flink = new Foreign_link();
$flink->service = 1; // Twitter
$cnt = $flink->find();

print "Updating Twitter friends subscriptions for $cnt users.\n";


while ($flink->fetch()) {

    if (($flink->noticesync & FOREIGN_NOTICE_RECV) == FOREIGN_NOTICE_RECV) {

        $user = User::staticGet($flink->user_id);

        if (empty($user)) {
            common_log(LOG_WARNING, "Unmatched user for ID " . $flink->user_id);
            print "Unmatched user for ID $flink->user_id\n";
            continue;
        }
        
        print 'Retrieving Friends Timeline for ' . $flink->user_id . "\n";
        
        getTimeline($flink);
        
        if (defined('SCRIPT_DEBUG')) {
            print "\nDONE\n";
        }
    }
}

function getTimeline($flink) 
{

    $fuser = $flink->getForeignUser();

    if (empty($fuser)) {
        common_log(LOG_WARNING, "Unmatched user for ID " . $flink->user_id);
        if (defined('SCRIPT_DEBUG')) {
            print "Unmatched user for ID $flink->user_id\n";
        }
        continue;
    }

    $screenname = $fuser->nickname;

    $url = 'http://twitter.com/statuses/friends_timeline.json';

    $timeline_json = get_twitter_data($url, $fuser->nickname,
        $flink->credentials);

    $timeline = json_decode($timeline_json);
    
    foreach ($timeline as $status) {
        
        // Hacktastic: filter out stuff coming from Laconica
        $source = mb_strtolower(common_config('integration', 'source'));
        
        if (preg_match("/$source/", mb_strtolower($status->source))) {
            continue;
        }
        
        saveStatus($status, $flink);
    }
    
}

function saveStatus($status, $flink)
{
    // Do we have a profile for this Twitter user? 
    
    $id = ensureProfile($status->user);    
    $profile = Profile::staticGet($id);

    if (!$profile) {
        common_log(LOG_ERR, 'Problem saving notice. No associated Profile.');
        if (defined('SCRIPT_DEBUG')) {
            print "Problem saving notice. No associated Profile.\n";
        }
        return null;
    }

    $uri = 'http://twitter.com/' . $status->user->screen_name . 
        '/status/' . $status->id;
	
	// Skip save if notice source is Laconica or Identi.ca?
	
	$notice = Notice::staticGet('uri', $uri);
	
    // check to see if we've already imported the status
    if (!$notice) {
        
        $notice = new Notice();

        $notice->profile_id = $id;

    	$notice->query('BEGIN');

        // XXX: figure out reply_to
    	$notice->reply_to = null;
	
    	// XXX: Should this be common_sql_now() instead of status create date?
    	    	
    	$notice->created = strftime('%Y-%m-%d %H:%M:%S', 
    	    strtotime($status->created_at));
    	$notice->content = $status->text;
    	$notice->rendered = common_render_content($status->text, $notice);
    	$notice->source = 'twitter';
    	$notice->is_local = 0;  
    	$notice->uri = $uri;

        $notice_id = $notice->insert();

        if (!$notice_id) {
            common_log_db_error($notice, 'INSERT', __FILE__);
            if (defined('SCRIPT_DEBUG')) {
                print "Could not save notice!\n";
            }
        }

        # XXX: do we need to change this for remote users?

        $notice->saveReplies();
    
        // XXX: Do we want to polute our tag cloud with hashtags from Twitter?
        $notice->saveTags();
        $notice->saveGroups();   
        
        $notice->query('COMMIT');
        
    }

    if (!Notice_inbox::staticGet('notice_id', $notice->id)) {
        
        // Add to inbox
        $inbox = new Notice_inbox();
        $inbox->user_id = $flink->user_id;
        $inbox->notice_id = $notice->id;
        $inbox->created = common_sql_now();
	
    	$inbox->insert();
	}

}

function ensureProfile($user) 
{
 
    // check to see if there's already a profile for this user
    $profileurl = 'http://twitter.com/' . $user->screen_name;
    
    $profile = Profile::staticGet('profileurl', $profileurl);
    
    if ($profile) {
        
        common_debug("Profile for $profile->nickname found.");
        
        // Check to see if the user's Avatar has changed
        checkAvatar($user, $profile);
        return $profile->id;
        
    } else {
        
        $debugmsg = 'Adding profile and remote profile ' .
            "for Twitter user: $profileurl\n";
        common_debug($debugmsg, __FILE__);
        if (defined('SCRIPT_DEBUG')) {
            print $debugmsg;
        }
        
        $profile = new Profile();
        $profile->query("BEGIN");
        
        $profile->nickname = $user->screen_name;
        $profile->fullname = $user->name;
        $profile->homepage = $user->url;
        $profile->bio = $user->description;
        $profile->location = $user->location;
        $profile->profileurl = $profileurl;
        $profile->created = common_sql_now();

        $id = $profile->insert();

        if (empty($id)) {
            common_log_db_error($profile, 'INSERT', __FILE__);
            if (defined('SCRIPT_DEBUG')) {
                print 'Could not insert Profile: ' . 
                    common_log_objstring($profile) . "\n";
            }
            $profile->query("ROLLBACK");
            return false;
        }        
    
        // check for remote profile 
        $remote_pro = Remote_profile::staticGet('uri', $profileurl);
    
        if (!$remote_pro) {
        
            $remote_pro = new Remote_profile();

            $remote_pro->id = $id;
            $remote_pro->uri = $profileurl;
            $remote_pro->created = common_sql_now();
        
            $rid = $remote_pro->insert();
        
            if (empty($rid)) {            
                common_log_db_error($profile, 'INSERT', __FILE__);
                if (defined('SCRIPT_DEBUG')) {
                    print 'Could not insert Remote_profile: ' . 
                        common_log_objstring($remote_pro) . "\n";
                }
                $profile->query("ROLLBACK");
                return false;
            }        
        }
        
        $profile->query("COMMIT");
        $profile->free();
        unset($profile);

        saveAvatars($user, $id);

        return $id;
    }
}

function checkAvatar($user, $profile)
{            
    common_debug("in check avatar");
    
    $path_parts = pathinfo($user->profile_image_url);
    $newname = 'Twitter_' . $user->id . '_' . 
        $path_parts['basename'];
        
    $oldname = $profile->getAvatar(48)->filename;

    if ($newname != $oldname) {
        
        common_debug("Avatar for Twitter user $profile->nickname has changed.");
        common_debug("old: $oldname new: $newname");
        
        if (defined('SCRIPT_DEBUG')) {
            print "Avatar for Twitter user $user->id has changed.\n";
            print "old: $oldname\n";
            print "new: $newname\n";            
        }

        $img_root = substr($path_parts['basename'], 0, -11);
        $ext = $path_parts['extension'];
        $mediatype = getMediatype($ext);

        foreach (array('mini', 'normal', 'bigger') as $size) {
            $url = $path_parts['dirname'] . '/' . 
                $img_root . '_' . $size . ".$ext";
            $filename = 'Twitter_' . $user->id . '_' . 
                $img_root . "_$size.$ext";

            if (fetchAvatar($url, $filename)) {
                updateAvatar($profile->id, $size, $mediatype, $filename);    
            }
        }
    }
    
}

function getMediatype($ext) 
{
    $mediatype = null;
    
    switch (strtolower($ext)) {
    case 'jpg':
        $mediatype = 'image/jpg';
        break;
    case 'gif':
        $mediatype = 'image/gif';
        break;
    default:
        $mediatype = 'image/png';
    }
    
    return $mediatype;
}


function saveAvatars($user, $id) 
{
    $path_parts = pathinfo($user->profile_image_url);

    // basename minus '_normal.ext'
    
    $ext = $path_parts['extension'];
    $end = strlen('_normal' . $ext);
    $img_root = substr($path_parts['basename'], 0, -($end+1));
    $mediatype = getMediatype($ext);
    
    foreach (array('mini', 'normal', 'bigger') as $size) {
        $url = $path_parts['dirname'] . '/' . 
            $img_root . '_' . $size . ".$ext";
        $filename = 'Twitter_' . $user->id . '_' . 
            $img_root . "_$size.$ext";

        if (fetchAvatar($url, $filename)) {
            newAvatar($id, $size, $mediatype, $filename);
        } else {
            common_log(LOG_WARNING, "Problem fetching Avatar: $url", __FILE__);
            if (defined('SCRIPT_DEBUG')) {
                print "Problem fetching Avatar: $url\n";
            }
        }
    }
}

function updateAvatar($profile_id, $size, $mediatype, $filename) {

    common_debug("updating avatar: $size");

    $profile = Profile::staticGet($profile_id);
    
    if (!$profile) {
        common_debug("Couldn't get profile: $profile_id!");
        if (defined('SCRIPT_DEBUG')) {
            print "Couldn't get profile: $profile_id!\n";
        }
        return;
    }
    
    $sizes = array('mini' => 24, 'normal' => 48, 'bigger' => 73);
    $avatar = $profile->getAvatar($sizes[$size]);
    
    if ($avatar) {
        common_debug("Deleting $size avatar for $profile->nickname.");
        @unlink(INSTALLDIR . '/avatar/' . $avatar->filename);
        $avatar->delete();
    }
   
    newAvatar($profile->id, $size, $mediatype, $filename); 
}

function newAvatar($profile_id, $size, $mediatype, $filename)
{
    $avatar = new Avatar();
    $avatar->profile_id = $profile_id;

    switch($size) {
    case 'mini':
        $avatar->width = 24;
        $avatar->height = 24;
        break;
    case 'normal':
        $avatar->width = 48;
        $avatar->height = 48;
        break;
    default:
    
        // Note: Twitter's big avatars are a different size than 
        // Laconica's (Laconica's = 96)
    
        $avatar->width = 73;
        $avatar->height = 73;
    }

    $avatar->original = 0; // we don't have the original
    $avatar->mediatype = $mediatype;
    $avatar->filename = $filename;
    $avatar->url = Avatar::url($filename);
    
    common_debug("new filename: $avatar->url");
    
    $avatar->created = common_sql_now();

    $id = $avatar->insert();

    if (!$id) {                
        common_log_db_error($avatar, 'INSERT', __FILE__);
        if (defined('SCRIPT_DEBUG')) {
            print "Could not insert avatar!\n";
        }
        
        return null;
    }
    
    common_debug("Saved new $size avatar for $profile_id.");
    
    return $id;
}

function fetchAvatar($url, $filename) 
{
    $avatar_dir = INSTALLDIR . '/avatar/';
    
    $avatarfile = $avatar_dir . $filename;
    
    $out = fopen($avatarfile, 'wb');
    if (!$out) {
        common_log(LOG_WARNING, "Couldn't open file $filename", __FILE__);
        if (defined('SCRIPT_DEBUG')) {
            print "Couldn't open file! $filename\n";
        }
        return false;
    }
    
    common_debug("Fetching avatar: $url", __FILE__);
    if (defined('SCRIPT_DEBUG')) {
        print "Fetching avatar from Twitter: $url\n";
    }
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_FILE, $out);
    curl_setopt($ch, CURLOPT_BINARYTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 0);
    $result = curl_exec($ch);
    curl_close($ch);

    fclose($out);
    
    return $result;
}

