<?php
/**
 * StatusNet, the distributed open-source microblogging tool
 *
 * Show a the direct messages from or to a user
 *
 * PHP version 5
 *
 * LICENCE: This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 *
 * @category  API
 * @package   StatusNet
 * @author    Zach Copley <zach@status.net>
 * @copyright 2009 StatusNet, Inc.
 * @license   http://www.fsf.org/licensing/licenses/agpl-3.0.html GNU Affero General Public License version 3.0
 * @link      http://status.net/
 */

if (!defined('STATUSNET')) {
    exit(1);
}

require_once INSTALLDIR.'/lib/apiauth.php';

/**
 * Show a list of direct messages from or to the authenticating user
 *
 * @category API
 * @package  StatusNet
 * @author   Zach Copley <zach@status.net>
 * @license  http://www.fsf.org/licensing/licenses/agpl-3.0.html GNU Affero General Public License version 3.0
 * @link     http://status.net/
 */

class ApiDirectMessageAction extends ApiAuthAction
{
    var $messages     = null;
    var $title        = null;
    var $subtitle     = null;
    var $link         = null;
    var $selfuri_base = null;
    var $id           = null;

    /**
     * Take arguments for running
     *
     * @param array $args $_REQUEST args
     *
     * @return boolean success flag
     *
     */

    function prepare($args)
    {
        parent::prepare($args);

        $this->user = $this->auth_user;

        if (empty($this->user)) {
            $this->clientError(_('No such user!'), 404, $this->format);
            return;
        }

        $server   = common_root_url();
        $taguribase = common_config('integration', 'taguri');

        if ($this->arg('sent')) {

            // Action was called by /api/direct_messages/sent.format

            $this->title = sprintf(
                _("Direct messages from %s"),
                $this->user->nickname
            );
            $this->subtitle = sprintf(
                _("All the direct messages sent from %s"),
                $this->user->nickname
            );
            $this->link = $server . $this->user->nickname . '/outbox';
            $this->selfuri_base = common_root_url() . 'api/direct_messages/sent';
            $this->id = "tag:$taguribase:SentDirectMessages:" . $this->user->id;
        } else {
            $this->title = sprintf(
                _("Direct messages to %s"),
                $this->user->nickname
            );
            $this->subtitle = sprintf(
                _("All the direct messages sent to %s"),
                $this->user->nickname
            );
            $this->link = $server . $this->user->nickname . '/inbox';
            $this->selfuri_base = common_root_url() . 'api/direct_messages';
            $this->id = "tag:$taguribase:DirectMessages:" . $this->user->id;
        }

        $this->messages = $this->getMessages();

        return true;
    }

    /**
     * Handle the request
     *
     * Show the messages
     *
     * @param array $args $_REQUEST data (unused)
     *
     * @return void
     */

    function handle($args)
    {
        parent::handle($args);
        $this->showMessages();
    }

    /**
     * Show the messages
     *
     * @return void
     */

    function showMessages()
    {
        switch($this->format) {
        case 'xml':
            $this->showXmlDirectMessages();
            break;
        case 'rss':
            $this->showRssDirectMessages();
            break;
        case 'atom':
            $this->showAtomDirectMessages();
            break;
        case 'json':
            $this->showJsonDirectMessages();
            break;
        default:
            $this->clientError(_('API method not found!'), $code = 404);
            break;
        }
    }

    /**
     * Get notices
     *
     * @return array notices
     */

    function getMessages()
    {
        $message  = new Message();

        if ($this->arg('sent')) {
            $message->from_profile = $this->user->id;
        } else {
            $message->to_profile = $this->user->id;
        }

        if (!empty($this->max_id)) {
            $message->whereAdd('id <= ' . $this->max_id);
        }

        if (!empty($this->since_id)) {
            $message->whereAdd('id > ' . $this->since_id);
        }

        if (!empty($since)) {
            $d = date('Y-m-d H:i:s', $this->since);
            $message->whereAdd("created > '$d'");
        }

        $message->orderBy('created DESC, id DESC');
        $message->limit((($this->page - 1) * $this->count), $this->count);
        $message->find();

        $messages = array();

        while ($message->fetch()) {
            $messages[] = clone($message);
        }

        return $messages;
    }

    /**
     * Is this action read only?
     *
     * @param array $args other arguments
     *
     * @return boolean true
     */

    function isReadOnly($args)
    {
        return true;
    }

    /**
     * When was this notice last modified?
     *
     * @return string datestamp of the latest notice in the stream
     */

    function lastModified()
    {
        if (!empty($this->messages)) {
            return strtotime($this->messages[0]->created);
        }

        return null;
    }

    /**
     * Shows a list of direct messages as Twitter-style XML array
     *
     * @return void
     */

    function showXmlDirectMessages()
    {
        $this->init_document('xml');
        $this->elementStart('direct-messages', array('type' => 'array'));

        foreach ($this->messages as $m) {
            $dm_array = $this->directMessageArray($m);
            $this->showXmlDirectMessage($dm_array);
        }

        $this->elementEnd('direct-messages');
        $this->end_document('xml');
    }

    /**
     * Shows a list of direct messages as a JSON encoded array
     *
     * @return void
     */

    function showJsonDirectMessages()
    {
        $this->init_document('json');

        $dmsgs = array();

        foreach ($this->messages as $m) {
            $dm_array = $this->directMessageArray($m);
            array_push($dmsgs, $dm_array);
        }

        $this->show_json_objects($dmsgs);
        $this->end_document('json');
    }

    /**
     * Shows a list of direct messages as RSS items
     *
     * @return void
     */

    function showRssDirectMessages()
    {
        $this->init_document('rss');

        $this->element('title', null, $this->title);

        $this->element('link', null, $this->link);
        $this->element('description', null, $this->subtitle);
        $this->element('language', null, 'en-us');

        $this->element(
            'atom:link',
            array(
                'type' => 'application/rss+xml',
                'href' => $this->selfuri_base . '.rss',
                'rel' => self
                ),
            null
        );
        $this->element('ttl', null, '40');

        foreach ($this->messages as $m) {
            $entry = $this->rssDirectMessageArray($m);
            $this->show_twitter_rss_item($entry);
        }

        $this->end_twitter_rss();
    }

    /**
     * Shows a list of direct messages as Atom entries
     *
     * @return void
     */

    function showAtomDirectMessages()
    {
        $this->init_document('atom');

        $this->element('title', null, $this->title);
        $this->element('id', null, $this->id);

        $selfuri = common_root_url() . 'api/direct_messages.atom';

        $this->element(
            'link', array(
            'href' => $this->link,
            'rel' => 'alternate',
            'type' => 'text/html'),
            null
        );
        $this->element(
            'link', array(
            'href' => $this->selfuri_base . '.atom', 'rel' => 'self',
            'type' => 'application/atom+xml'),
            null
        );
        $this->element('updated', null, common_date_iso8601('now'));
        $this->element('subtitle', null, $this->subtitle);

        foreach ($this->messages as $m) {
            $entry = $this->rssDirectMessageArray($m);
            $this->showTwitterAtomEntry($entry);
        }

        $this->end_document('atom');
    }

    /**
     * An entity tag for this notice
     *
     * Returns an Etag based on the action name, language, and
     * timestamps of the notice
     *
     * @return string etag
     */

    function etag()
    {
        if (!empty($this->messages)) {

            $last = count($this->messages) - 1;

            return '"' . implode(
                ':',
                array($this->arg('action'),
                      common_language(),
                      strtotime($this->messages[0]->created),
                      strtotime($this->messages[$last]->created)
                )
            )
            . '"';
        }

        return null;
    }

}
